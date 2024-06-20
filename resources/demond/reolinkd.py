# This file is part of Jeedom.
#
# Jeedom is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 3 of the License, or
# (at your option) any later version.
#
# Jeedom is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
import logging
import signal
import socket
import subprocess
import sys
import time
import traceback
import json
import argparse
import uvicorn
import subscription_manager
import asyncio
from multiprocessing import Process

try:
    from jeedom.jeedom import *
except ImportError:
    print("Error: importing module jeedom.jeedom")
    sys.exit(1)


def read_socket():
    global JEEDOM_SOCKET_MESSAGE
    if not JEEDOM_SOCKET_MESSAGE.empty():
        logging.debug("Message received in socket JEEDOM_SOCKET_MESSAGE")
        message = JEEDOM_SOCKET_MESSAGE.get().decode('utf-8')
        message = json.loads(message)
        if message['apikey'] != _apikey:
            logging.error("Invalid apikey from socket : " + str(message))
            return
        try:
            # ============================================
            # Receive message for handle

            if message['action'] == "sethook":
                _cam_ip = message['cam_ip']
                _cam_onvif_port = message['cam_onvif_port']
                _cam_user = message['cam_user']
                _cam_pwd = message['cam_pwd']
                logging.debug(f"Requested to set the webhook inside CAM IP={_cam_ip}")

                result_resp = {
                    "message": "subscription",
                    "ip": _cam_ip
                }

                if chk_ping(_cam_ip) is False:
                    logging.error(f"CAM IP={_cam_ip} is not reachable. (Please check that the camera is switched ON and correctly connected to the network)")
                    result_resp['state'] = 0
                    result_resp['details'] = "Camera is not reachable"
                else:
                    if check_onvif(_cam_ip, int(_cam_onvif_port)) is False:
                        logging.error(f"CAM IP={_cam_ip} is not ONVIF capable. (Please check the camera settings if the ONVIF protocol is enabled correctly)")
                        result_resp['state'] = 0
                        result_resp['details'] = "Camera is not ONVIF capable"
                    else:
                        if asyncio.run(subscribe_onvif(_cam_ip, _cam_onvif_port, _cam_user, _cam_pwd)):
                            logging.debug("Subscribe OK")
                            result_resp['state'] = 1
                        else:
                            logging.info("Unable to subscribe ONVIF event")
                            result_resp['state'] = 0
                            result_resp['details'] = "Unable to subscribe ONVIF event"
                # ============================================
                # Send result to jeedom
                message = json.dumps(result_resp)
                jeedom_cnx.send_change_immediate(json.loads(message))
            else:
                logging.debug("Message received is not supported")
        except Exception as e:
            logging.error('Send command to demon error : ' + str(e))


async def subscribe_onvif(cam_ip, cam_onvif_port, cam_user, cam_pwd):
    logging.debug(f"Request subscription_manager to set webhook on camera = {cam_ip}:{cam_onvif_port}")
    sman = subscription_manager.Manager(cam_ip, cam_onvif_port, cam_user, cam_pwd)
    res = await sman.subscribe(f"http://{_webhook_ip}:{_webhook_port}/inbound_events")
    logging.debug(f"Starting local hook on : http://{_webhook_ip}:{_webhook_port}/inbound_events")
    return res


def listen():
    jeedom_socket.open()
    try:
        while 1:
            time.sleep(0.5)
            read_socket()
    except KeyboardInterrupt:
        shutdown()
# ----------------------------------------------------------------------------
# UVICORN Handler


def get_logger_text():
    if logging.root.level == logging.DEBUG:
        return "debug"
    elif logging.root.level == logging.INFO:
        return "info"
    elif logging.root.level == logging.WARNING:
        return "warning"
    elif logging.root.level == logging.ERROR:
        return "error"
    elif logging.root.level == logging.CRITICAL:
        return "critical"


def run_uvicorn():
    logging.info('Starting webhook...')
    uvicorn.run(app="camhook:app", host="0.0.0.0", port=_webhook_port, log_level=get_logger_text())


def start_uvicorn():
    global proc
    proc = Process(target=run_uvicorn, args=(), daemon=True)
    proc.start()


def stop_uvicorn():
    global proc
    if proc:
        proc.join(0.25)

# ----------------------------------------------------------------------------


def handler(signum=None, frame=None):
    logging.debug("Signal %i caught, exiting..." % int(signum))
    shutdown()


def shutdown():
    logging.debug("Shutdown")
    logging.debug("Removing PID file " + str(_pidfile))
    try:
        os.remove(_pidfile)
    except:
        pass
    try:
        jeedom_socket.close()
    except:
        pass
    logging.debug("Exit 0")
    sys.stdout.flush()
    exit(0)


def check_onvif(cam_ip, cam_onvif_port):
    sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    result = sock.connect_ex((cam_ip, cam_onvif_port))
    sock.close()
    if result == 0:
        return True
    else:
        return False


def chk_ping(host):
    command = ['ping', '-c', '1', host]
    response = subprocess.call(command, stdout=subprocess.DEVNULL)

    if response == 0:
        return True
    else:
        return False

# ----------------------------------------------------------------------------
local_ip = (([ip for ip in socket.gethostbyname_ex(socket.gethostname())[2] if not ip.startswith("127.")] or
             [[(s.connect(("8.8.8.8", 53)), s.getsockname()[0], s.close()) for s in
               [socket.socket(socket.AF_INET, socket.SOCK_DGRAM)]][0][1]]) + ["no IP found"])[0]


if os.path.exists("jeedomcreds"):
    logging.debug('Removing previous cred files')
    os.remove("jeedomcreds")

_log_level = "error"
_socket_port = 44009
_socket_host = 'localhost'
_device = 'auto'
_pidfile = '/tmp/demond.pid'
_apikey = ''
_callback = ''
_webhook_ip = local_ip
_webhook_port = '44010'
_cycle = 0.3

parser = argparse.ArgumentParser(
    description='Desmond Daemon for Jeedom plugin')
parser.add_argument("--device", help="Device", type=str)
parser.add_argument("--loglevel", help="Log Level for the daemon", type=str)
parser.add_argument("--callback", help="Callback", type=str)
parser.add_argument("--apikey", help="Apikey", type=str)
parser.add_argument("--cycle", help="Cycle to send event", type=str)
parser.add_argument("--pid", help="Pid file", type=str)
parser.add_argument("--socketport", help="Port for server", type=str)
parser.add_argument("--webhook_ip", help="IP for webhook", type=str)
parser.add_argument("--webhook_port", help="Port for webhook", type=str)
args = parser.parse_args()

if args.device:
    _device = args.device
if args.loglevel:
    _log_level = args.loglevel
if args.callback:
    _callback = args.callback
if args.apikey:
    _apikey = args.apikey
if args.pid:
    _pidfile = args.pid
if args.cycle:
    _cycle = float(args.cycle)
if args.socketport:
    _socketport = args.socketport
if args.webhook_ip:
    _webhook_ip = str(args.webhook_ip)
if args.webhook_port:
    _webhook_port = int(args.webhook_port)

_socket_port = int(_socket_port)

jeedom_utils.set_log_level(_log_level)
jeedom_cnx = jeedom_com(_apikey, _callback)

logging.info('Start demond')
logging.info('Log level : %s', _log_level)
logging.info('Socket port : %s', _socket_port)
logging.info('Socket host : %s', _socket_host)
logging.info('PID file : %s', _pidfile)
logging.info('Apikey : %s', _apikey)
logging.info('Device : %s', _device)
logging.info('Webhook IP : %s', _webhook_ip)
logging.info('Webhook port : %s', _webhook_port)

try:
    logging.info('Write creds file for camhook')
    lines = [_callback, _apikey]
    with open('jeedomcreds', 'w') as f:
        for line in lines:
            f.write(line)
            f.write('\n')
except Exception as e:
    logging.debug('Unable to write creds file: %s', e)

start_uvicorn()

signal.signal(signal.SIGINT, handler)
signal.signal(signal.SIGTERM, handler)

try:
    jeedom_utils.write_pid(str(_pidfile))
    jeedom_socket = jeedom_socket(port=_socket_port, address=_socket_host)
    listen()
except Exception as e:
    logging.error('Fatal error : %s', e)
    logging.info(traceback.format_exc())
    shutdown()
