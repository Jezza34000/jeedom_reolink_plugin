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
#

import logging
import os
import time
from collections.abc import Mapping
from queue import Queue
from threading import Lock, Thread
import socketserver
from socketserver import TCPServer, StreamRequestHandler
import requests
from urllib3.exceptions import InsecureRequestWarning

requests.packages.urllib3.disable_warnings(InsecureRequestWarning)


class jeedom_com:
    def __init__(self, apikey='', url='', cycle=0.5, retry=3):
        self.apikey = apikey
        self.url = url
        self.cycle = cycle
        self.retry = retry
        self.changes = {}
        self._lock = Lock()
        self._session = requests.Session()
        self._session.verify = False
        if self.cycle > 0:
            Thread(target=self.__thread_changes_async, daemon=True).start()
        logging.debug('Init request module v%s', requests.__version__)

    def __thread_changes_async(self):
        logging.debug('Start changes async thread')
        while True:
            try:
                time.sleep(self.cycle)
                with self._lock:
                    if len(self.changes) == 0:
                        continue
                    changes = self.changes
                    self.changes = {}
                self.__post_change(changes)
            except Exception as error:
                logging.error('Critical error on send_changes_async %s', error)

    def add_changes(self, key, value):
        if key.find('::') != -1:
            tmp_changes = {}
            changes = value
            for k in reversed(key.split('::')):
                if k not in tmp_changes:
                    tmp_changes[k] = {}
                tmp_changes[k] = changes
                changes = tmp_changes
                tmp_changes = {}
            if self.cycle <= 0:
                self.send_change_immediate(changes)
            else:
                with self._lock:
                    self.merge_dict(self.changes, changes)
        else:
            if self.cycle <= 0:
                self.send_change_immediate({key: value})
            else:
                with self._lock:
                    self.changes[key] = value

    def send_change_immediate(self, change):
        Thread(target=self.__post_change, args=(change,), daemon=True).start()

    def __post_change(self, change):
        logging.debug('Send to jeedom: %s', change)
        for i in range(self.retry):
            try:
                r = self._session.post(
                    self.url + '?apikey=' + self.apikey,
                    json=change,
                    timeout=(0.5, 120),
                )
                if r.status_code == requests.codes.ok:
                    return True
                logging.error('Error on send request to jeedom, return code %s', r.status_code)
            except Exception as error:
                logging.error(
                    'Error on send request to jeedom %s retry: %i/%i',
                    error,
                    i,
                    self.retry,
                )
            time.sleep(0.5)
        return False

    def set_change(self, changes):
        with self._lock:
            self.changes = changes

    def get_change(self):
        with self._lock:
            return self.changes

    def merge_dict(self, d1, d2):
        for k, v2 in d2.items():
            v1 = d1.get(k)
            if isinstance(v1, Mapping) and isinstance(v2, Mapping):
                self.merge_dict(v1, v2)
            else:
                d1[k] = v2

    def test(self):
        try:
            response = self._session.get(self.url + '?apikey=' + self.apikey)
            if response.status_code != requests.codes.ok:
                logging.error(
                    'Callback error: %s %s. Please check your network configuration page',
                    response.status_code,
                    response.reason,
                )
                logging.error(response.text)
                return False
        except Exception as e:
            logging.error(
                'Callback result as a unknown error: %s. Please check your network configuration page',
                e,
            )
            return False
        return True


class jeedom_utils:

    @staticmethod
    def convert_log_level(level='error'):
        log_lvl = {
            'debug': logging.DEBUG,
            'info': logging.INFO,
            'notice': logging.WARNING,
            'warning': logging.WARNING,
            'error': logging.ERROR,
            'critical': logging.CRITICAL,
            'none': logging.CRITICAL,
        }
        return log_lvl.get(level, logging.CRITICAL)

    @staticmethod
    def set_log_level(level='error'):
        log_format = '[%(asctime)-15s][%(levelname)s] : %(message)s'
        logging.basicConfig(
            level=jeedom_utils.convert_log_level(level),
            format=log_format,
            datefmt="%Y-%m-%d %H:%M:%S",
        )

    @staticmethod
    def write_pid(path):
        pid = str(os.getpid())
        logging.debug("Writing PID %s to %s", pid, path)
        open(path, 'w').write("%s\n" % pid)


JEEDOM_SOCKET_MESSAGE = Queue()


class JeedomSocketHandler(StreamRequestHandler):
    def handle(self):
        global JEEDOM_SOCKET_MESSAGE
        logging.debug("Client connected to [%s:%d]", *self.client_address)
        lg = self.rfile.readline()
        JEEDOM_SOCKET_MESSAGE.put(lg)
        self.netAdapterClientConnected = False
        logging.debug("Client disconnected from [%s:%d]", *self.client_address)


class jeedom_socket:

    def __init__(self, address='localhost', port=55000):
        self.address = address
        self.port = port
        socketserver.TCPServer.allow_reuse_address = True

    def open(self):
        self.netAdapter = TCPServer((self.address, self.port), JeedomSocketHandler)
        if self.netAdapter:
            logging.debug("Socket interface started")
            Thread(target=self.loopNetServer, daemon=True).start()
        else:
            logging.debug("Cannot start socket interface")

    def loopNetServer(self):
        logging.debug("LoopNetServer Thread started")
        logging.debug("Listening on: [%s:%d]", self.address, self.port)
        self.netAdapter.serve_forever()
        logging.debug("LoopNetServer Thread stopped")

    def close(self):
        self.netAdapter.shutdown()
