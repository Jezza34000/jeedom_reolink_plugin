from fastapi import FastAPI, Request
import logging
import os
import re
import sys

from jeedom.jeedom import jeedom_com

_callback = os.environ.get('REOLINK_JEEDOM_CALLBACK', '')
_apikey = os.environ.get('REOLINK_JEEDOM_APIKEY', '')
if not _callback or not _apikey:
    logging.error("Missing REOLINK_JEEDOM_CALLBACK / REOLINK_JEEDOM_APIKEY")
    sys.exit(1)

jeedom_cnx = jeedom_com(_apikey, _callback, cycle=0)
detect_state_by_ip = {}
event_table_by_ip = {}

MOTION_RE = re.compile(r'IsMotion" Value="true"')
EVENT_RE = re.compile(
    r'<wsnt:.*?ConcreteSet">(.*?)</wsnt:Topic>.*?<tt:Data>.*?Value="(.*?)" /></tt:Data>'
)
TOPIC_RE = re.compile(r'tns1:.*/(.*)')

app = FastAPI()


@app.post("/inbound_events", status_code=200)
async def get_body(request: Request):
    ip = request.client.host
    logging.debug("Incoming XML camera event on webhook from IP=%s", ip)
    body = (await request.body()).decode('utf-8', errors='ignore')

    new_detect_state = 1 if MOTION_RE.search(body) else 0
    if detect_state_by_ip.get(ip, 0) != new_detect_state:
        detect_state_by_ip[ip] = new_detect_state
        jeedom_cnx.send_change_immediate({
            "message": "motion",
            "ip": ip,
            "motionstate": new_detect_state,
        })

    event_states = event_table_by_ip.setdefault(ip, {})
    for topic, value in EVENT_RE.findall(body):
        topic_match = TOPIC_RE.search(topic)
        if not topic_match:
            continue
        event_name = "Ev" + topic_match.group(1)
        status = 0 if value == "false" else 1
        logging.debug("received event %s %s", event_name, status)
        if event_states.get(event_name) == status:
            continue
        event_states[event_name] = status
        logging.debug("%s --> %s", event_name, status)
        jeedom_cnx.send_change_immediate({
            "message": event_name,
            "ip": ip,
            "motionstate": status,
        })
