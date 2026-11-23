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
import argparse
import asyncio
import json
import logging
import os
import signal
import socket
import subprocess
import sys
import time
import traceback
from multiprocessing import Process

import subscription_manager
import uvicorn
from baichuan_push import BaichuanPushManager
from jeedom.jeedom import JEEDOM_SOCKET_MESSAGE, jeedom_com, jeedom_socket, jeedom_utils

_ENV_CALLBACK = 'REOLINK_JEEDOM_CALLBACK'
_ENV_APIKEY = 'REOLINK_JEEDOM_APIKEY'

proc = None
go2rtc_proc = None
bc_push_manager = None
_GO2RTC_DIR = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', 'bin'))
_GO2RTC_BIN = os.path.join(_GO2RTC_DIR, 'go2rtc')
_GO2RTC_CFG = os.path.join(_GO2RTC_DIR, 'go2rtc.yaml')
# Inline config: no go2rtc.yaml on disk (avoids RTSP passwords + :8554 LAN expose)
_GO2RTC_INLINE_CFG = (
    '{"api":{"listen":"127.0.0.1:1984"},'
    '"rtsp":{"listen":""},'
    '"webrtc":{"listen":":8555"},'
    '"log":{"level":"warn"}}'
)
_go2rtc_check_at = 0.0


def read_socket():
    while not JEEDOM_SOCKET_MESSAGE.empty():
        logging.debug("Message received in socket JEEDOM_SOCKET_MESSAGE")
        message = json.loads(JEEDOM_SOCKET_MESSAGE.get().decode('utf-8'))
        if message.get('apikey') != _apikey:
            logging.error("Invalid apikey from socket: %s", message)
            continue
        try:
            action = message.get('action')
            if action == "syncbaichuan":
                if bc_push_manager is not None:
                    bc_push_manager.sync_cameras(message.get('cameras') or [])
                continue

            if action != "sethook":
                logging.debug("Message received is not supported: %s", action)
                continue

            cam_ip = message['cam_ip']
            cam_onvif_port = message['cam_onvif_port']
            cam_user = message['cam_user']
            cam_pwd = message['cam_pwd']
            logging.debug("Requested to set the webhook inside CAM IP=%s", cam_ip)

            result_resp = {
                "message": "subscription",
                "ip": cam_ip,
            }

            if check_onvif(cam_ip, int(cam_onvif_port)) is False:
                logging.error(
                    "CAM IP=%s ONVIF port %s unreachable",
                    cam_ip,
                    cam_onvif_port,
                )
                result_resp['state'] = 0
                result_resp['details'] = "Camera ONVIF port unreachable"
            elif asyncio.run(subscribe_onvif(cam_ip, cam_onvif_port, cam_user, cam_pwd)):
                logging.debug("Subscribe OK")
                result_resp['state'] = 1
            else:
                logging.info("Unable to subscribe ONVIF event")
                result_resp['state'] = 0
                result_resp['details'] = "Unable to subscribe ONVIF event"

            jeedom_cnx.send_change_immediate(result_resp)
        except Exception as e:
            logging.error('Send command to demon error: %s', e)


async def subscribe_onvif(cam_ip, cam_onvif_port, cam_user, cam_pwd):
    logging.debug(
        "Request subscription_manager to set webhook on camera = %s:%s",
        cam_ip,
        cam_onvif_port,
    )
    sman = subscription_manager.Manager(cam_ip, cam_onvif_port, cam_user, cam_pwd)
    res = await sman.subscribe(f"http://{_webhook_ip}:{_webhook_port}/inbound_events")
    logging.debug(
        "Starting local hook on: http://%s:%s/inbound_events",
        _webhook_ip,
        _webhook_port,
    )
    return res


def listen():
    global _go2rtc_check_at
    socket_server.open()
    try:
        while True:
            time.sleep(0.5)
            read_socket()
            now = time.time()
            if now >= _go2rtc_check_at:
                _go2rtc_check_at = now + 10.0
                ensure_go2rtc()
    except KeyboardInterrupt:
        shutdown()


def get_logger_text():
    if logging.root.level == logging.DEBUG:
        return "debug"
    if logging.root.level == logging.INFO:
        return "info"
    if logging.root.level == logging.WARNING:
        return "warning"
    if logging.root.level == logging.ERROR:
        return "error"
    if logging.root.level == logging.CRITICAL:
        return "critical"
    return "error"


def run_uvicorn():
    logging.info('Starting webhook...')
    uvicorn.run(
        app="camhook:app",
        host="0.0.0.0",
        port=_webhook_port,
        log_level=get_logger_text(),
    )


def start_uvicorn():
    global proc
    proc = Process(target=run_uvicorn, args=(), daemon=True)
    proc.start()


def stop_uvicorn():
    global proc
    if not proc:
        return
    if proc.is_alive():
        proc.terminate()
    proc.join(1.0)
    proc = None


def start_go2rtc():
    global go2rtc_proc
    if not os.path.isfile(_GO2RTC_BIN) or not os.access(_GO2RTC_BIN, os.X_OK):
        logging.warning("go2rtc binary missing at %s", _GO2RTC_BIN)
        return
    # Drop legacy yaml from older plugin versions (passwords / :8554)
    try:
        if os.path.isfile(_GO2RTC_CFG):
            os.remove(_GO2RTC_CFG)
    except OSError as e:
        logging.warning("Unable to remove legacy go2rtc.yaml: %s", e)
    try:
        go2rtc_proc = subprocess.Popen(
            [_GO2RTC_BIN, '-c', _GO2RTC_INLINE_CFG],
            cwd=_GO2RTC_DIR,
            stdout=subprocess.DEVNULL,
            stderr=None,
        )
        logging.info("go2rtc started pid=%s", go2rtc_proc.pid)
    except Exception as e:
        logging.error("Unable to start go2rtc: %s", e)
        go2rtc_proc = None


def ensure_go2rtc():
    global go2rtc_proc
    if not os.path.isfile(_GO2RTC_BIN) or not os.access(_GO2RTC_BIN, os.X_OK):
        return
    if go2rtc_proc is not None and go2rtc_proc.poll() is None:
        return
    if go2rtc_proc is not None:
        logging.warning(
            "go2rtc exited with code %s, restarting",
            go2rtc_proc.returncode,
        )
        go2rtc_proc = None
    start_go2rtc()


def stop_go2rtc():
    global go2rtc_proc
    if not go2rtc_proc:
        return
    try:
        go2rtc_proc.terminate()
        go2rtc_proc.wait(timeout=3)
    except Exception:
        try:
            go2rtc_proc.kill()
        except Exception:
            pass
    go2rtc_proc = None


def handler(signum=None, frame=None):
    logging.debug("Signal %i caught, exiting...", int(signum))
    shutdown()


def shutdown():
    logging.debug("Shutdown")
    logging.debug("Removing PID file %s", _pidfile)
    try:
        os.remove(_pidfile)
    except OSError:
        pass
    try:
        socket_server.close()
    except Exception:
        pass
    if bc_push_manager is not None:
        try:
            bc_push_manager.stop_all()
        except Exception:
            pass
    stop_uvicorn()
    stop_go2rtc()
    logging.debug("Exit 0")
    sys.stdout.flush()
    sys.exit(0)


def check_onvif(cam_ip, cam_onvif_port):
    sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    sock.settimeout(3)
    try:
        result = sock.connect_ex((cam_ip, cam_onvif_port))
    finally:
        sock.close()
    return result == 0


local_ip = (([ip for ip in socket.gethostbyname_ex(socket.gethostname())[2] if not ip.startswith("127.")] or
             [[(s.connect(("8.8.8.8", 53)), s.getsockname()[0], s.close()) for s in
               [socket.socket(socket.AF_INET, socket.SOCK_DGRAM)]][0][1]]) + ["no IP found"])[0]

_log_level = "error"
_socket_port = 44009
_socket_host = 'localhost'
_device = 'auto'
_pidfile = '/tmp/demond.pid'
_apikey = ''
_callback = ''
_webhook_ip = local_ip
_webhook_port = 44010
_cycle = 0

parser = argparse.ArgumentParser(description='Desmond Daemon for Jeedom plugin')
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
    _socket_port = int(args.socketport)
if args.webhook_ip:
    _webhook_ip = str(args.webhook_ip)
if args.webhook_port:
    _webhook_port = int(args.webhook_port)

jeedom_utils.set_log_level(_log_level)
jeedom_cnx = jeedom_com(_apikey, _callback, _cycle)
bc_push_manager = BaichuanPushManager(jeedom_cnx)

logging.info('Start demond')
logging.info('Log level: %s', _log_level)
logging.info('Socket port: %s', _socket_port)
logging.info('Socket host: %s', _socket_host)
logging.info('PID file: %s', _pidfile)
logging.info('Apikey: %s', _apikey)
logging.info('Device: %s', _device)
logging.info('Webhook IP: %s', _webhook_ip)
logging.info('Webhook port: %s', _webhook_port)

os.environ[_ENV_CALLBACK] = _callback or ''
os.environ[_ENV_APIKEY] = _apikey or ''

start_go2rtc()
start_uvicorn()

signal.signal(signal.SIGINT, handler)
signal.signal(signal.SIGTERM, handler)

try:
    jeedom_utils.write_pid(str(_pidfile))
    socket_server = jeedom_socket(port=_socket_port, address=_socket_host)
    listen()
except Exception as e:
    logging.error('Fatal error: %s', e)
    logging.info(traceback.format_exc())
    shutdown()
