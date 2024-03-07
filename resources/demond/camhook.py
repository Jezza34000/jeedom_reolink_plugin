from fastapi import Request, FastAPI
import sys
import json
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
	logging.error(f"Unable to read credentials jeedom file, retry...")
	sys.exit(1)

try:
	os.remove("jeedomcreds")
except:
	pass

jeedom_cnx = jeedom_com(_apikey, _callback)
detect_state = 0
# BEGIN  added by t0urista to handle ONVIF events
eventTable = {}  
# END  added by t0urista to handle ONVIF events
app = FastAPI()


@app.post("/inbound_events", status_code=200)
async def get_body(request: Request):

	global detect_state

# BEGIN  added by t0urista to handle ONVIF events
	global eventTable
# END  added by t0urista to handle ONVIF events

    
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


# BEGIN  added by t0urista to handle ONVIF events
	pattern=r'<wsnt:.*?ConcreteSet">(.*?)</wsnt:Topic>.*?<tt:Data>.*?Value="(.*?)" /></tt:Data>'
	event_status_all=re.findall(pattern,xml_answer.decode('utf-8'))

#	logging.debug(event_status_all)
	for event_status in event_status_all:
#		logging.debug(event_status)   
		event=re.findall(r'tns1:.*/(.*)', event_status[0])
		event="Ev" + event[0]
#		logging.debug(event[0])
#       logging.debug(tuple[0]) 
		status=0
		if(event_status[1]!="false"):
			status=1
		logging.debug(f"received event {event}  {status}")
		eventFound=0
		for eventName in eventTable:
#			print(eventName)
			if (eventName==event):
				eventFound=1
				if (eventTable[eventName]!=status):
					logging.debug(f"{event} --> {status}")
					eventTable[eventName]=status
					send_frame = {
						"message": event,
						"ip": ip,
						"motionstate": status
					}
        			# convert into JSON:
					message = json.dumps(send_frame)
					jeedom_cnx.send_change_immediate(json.loads(message))
		if (eventFound==0):
			eventTable[event] = status
			logging.debug(f"{event} --> {status}")
			send_frame = {
				"message": event,
				"ip": ip,
				"motionstate": status
			}
			# convert into JSON:
			message = json.dumps(send_frame)
			jeedom_cnx.send_change_immediate(json.loads(message))
# END  added by t0urista to handle ONVIF events

	return
