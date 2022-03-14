from fastapi import Request, FastAPI
import sys
import json
import os
import re

try:
    from jeedom.jeedom import *
except ImportError:
    print("Error: importing module jeedom.jeedom")
    sys.exit(1)

try:
    f = open('jeedomcreds', 'r')
    _callback = f.readline().rstrip("\n")
    _apikey = f.readline().rstrip("\n")
    f.close()
except:
    logging.error(f"Unable to read credentials jeedom file")
    sys.exit(1)

jeedom_cnx = jeedom_com(_apikey, _callback)
detect_state = 0
app = FastAPI()


@app.post("/inbound_events", status_code=200)
async def get_body(request: Request):
    global detect_state
    ip = request.client.host
    logging.debug(f"Incoming XML camera event on webhook from IP={ip}")
    xml_answer = await request.body()

    new_detect_state = 0
    if re.search('IsMotion" Value="true"', xml_answer.decode('utf-8')):
        new_detect_state = 1

    if detect_state != new_detect_state:
        detect_state = new_detect_state
        send_frame = {
            "message": "motion",
            "ip": ip,
            "motionstate": detect_state
        }
        # convert into JSON:
        message = json.dumps(send_frame)
        jeedom_cnx.send_change_immediate(json.loads(message))
    return
