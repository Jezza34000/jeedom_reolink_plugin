from fastapi import Request, FastAPI
import sys
import json
import os
from xml.dom import minidom

try:
    from jeedom.jeedom import *
except ImportError:
    print("Error: importing module jeedom.jeedom")
    sys.exit(1)

try:
    f = open('jeedomcreds', 'r')
    _callback = f.readline()
    _apikey = f.readline()
    f.close()
except:
    logging.error(f"Unable to read credentials jeedom file")
    sys.exit(1)

app = FastAPI()


@app.post("/inbound_events")
async def get_body(request: Request):
    ip = request.client.host
    logging.debug(f"Incoming XML camera event on webhook from IP={ip}")
    xml_answer = await request.body()
    logging.debug(f"XML frame ={xml_answer}")
    file = minidom.parse(xml_answer.decode('utf-8'))
    models = file.getElementsByTagName('tt:SimpleItem')
    # a Python object (dict):
    send_frame = {
        "message": "motion",
        "ip": ip,
        "motionstate": models[3].attributes['Value'].value
    }
    # convert into JSON:
    message = json.dumps(send_frame)
    logging.debug(f"Sending to jeedom : {message}")
    s = jeedom_com(_apikey, _callback)
    s.send_change_immediate(message)
