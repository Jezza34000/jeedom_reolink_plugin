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
    logging.debug(f"Incoming XML camera event on webhook ")
    xml_answer = await request.body()
    file = minidom.parse(xml_answer)
    models = file.getElementsByTagName('tt:SimpleItem')
    # a Python object (dict):
    received_frame = {
        "motion": models[3].attributes['Value'].value,
    }
    # convert into JSON:
    message = json.dumps(received_frame)
    logging.debug(f"Sending to jeedom : {message}")
    s = jeedom_com(_apikey, _callback)
    s.send_change_immediate(message)
