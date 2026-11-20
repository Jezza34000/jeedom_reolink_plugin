from fastapi import FastAPI, Request
import logging
import os
import sys
from os.path import basename
from xml.etree import ElementTree as XML

from jeedom.jeedom import jeedom_com

_callback = os.environ.get('REOLINK_JEEDOM_CALLBACK', '')
_apikey = os.environ.get('REOLINK_JEEDOM_APIKEY', '')
if not _callback or not _apikey:
    logging.error("Missing REOLINK_JEEDOM_CALLBACK / REOLINK_JEEDOM_APIKEY")
    sys.exit(1)

jeedom_cnx = jeedom_com(_apikey, _callback, cycle=0)
event_state = {}

NS_WSNT = '{http://docs.oasis-open.org/wsn/b-2}'
NS_TT = '{http://www.onvif.org/ver10/schema}'
KNOWN_RULES = {
    'Motion': 'IsMotion',
    'MotionAlarm': 'IsMotion',
    'FaceDetect': 'State',
    'PeopleDetect': 'State',
    'VehicleDetect': 'State',
    'DogCatDetect': 'State',
    'Package': 'State',
    'Visitor': 'State',
}
EVENT_NAME = {
    'Motion': 'motion',
    'MotionAlarm': 'motion',
    'FaceDetect': 'EvFaceDetect',
    'PeopleDetect': 'EvPeopleDetect',
    'VehicleDetect': 'EvVehicleDetect',
    'DogCatDetect': 'EvDogCatDetect',
    'Package': 'EvPackageDetect',
    'Visitor': 'EvVisitor',
}

app = FastAPI()


def _simple_item(message, name):
    return message.find(f'.//{NS_TT}SimpleItem[@Name="{name}"]')


def _parse_channel(message):
    for name in ('Source', 'VideoSourceConfigurationToken'):
        el = _simple_item(message, name)
        if el is not None and 'Value' in el.attrib:
            try:
                return int(el.attrib['Value'])
            except ValueError:
                return None
    return None


@app.post("/inbound_events", status_code=200)
async def get_body(request: Request):
    ip = request.client.host
    logging.debug("Incoming XML camera event on webhook from IP=%s", ip)
    body = (await request.body()).decode('utf-8', errors='ignore')
    try:
        root = XML.fromstring(body)
    except XML.ParseError:
        logging.debug("Invalid ONVIF XML from IP=%s", ip)
        return

    for message in root.iter(f'{NS_WSNT}NotificationMessage'):
        topic_el = message.find(f"{NS_WSNT}Topic[@Dialect='http://www.onvif.org/ver10/tev/topicExpression/ConcreteSet']")
        if topic_el is None or not topic_el.text:
            continue
        rule = basename(topic_el.text.strip())
        if rule not in KNOWN_RULES:
            continue
        data_el = _simple_item(message, KNOWN_RULES[rule])
        if data_el is None or 'Value' not in data_el.attrib:
            continue
        state = 1 if data_el.attrib['Value'] == 'true' else 0
        channel = _parse_channel(message)
        event_name = EVENT_NAME[rule]
        key = (ip, channel, event_name)
        if event_state.get(key) == state:
            continue
        event_state[key] = state
        logging.debug("ONVIF %s ip=%s channel=%s state=%s", event_name, ip, channel, state)
        payload = {
            "message": event_name,
            "ip": ip,
            "motionstate": state,
        }
        if channel is not None:
            payload["channel"] = channel
        jeedom_cnx.send_change_immediate(payload)
