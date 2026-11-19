"""
Reolink Camera subscription
"""
from datetime import datetime, timedelta, timezone
import base64
import hashlib
import logging
import re
import uuid
import aiohttp
import asyncio
import templates

TERMINATION_TIME = 15
DEFAULT_TIMEOUT = 30


def _utc_now():
    return datetime.now(timezone.utc).replace(tzinfo=None)


class Manager:
    def __init__(self, host, port, username, password, timeout=DEFAULT_TIMEOUT):
        self._host = host
        self._username = username
        self._password = password[:31]
        self._timeout = aiohttp.ClientTimeout(total=timeout)
        self._subscribe_url = f"http://{host}:{port}/onvif/event_service"
        self._manager_url = None
        self._termination_time = None
        self._time_difference = None

    @property
    def renewtimer(self):
        if self._time_difference is None or self._termination_time is None:
            return 0

        remote_time = _utc_now() + timedelta(seconds=self._time_difference)
        diff = self._termination_time - remote_time
        seconds = max(0, int(diff.total_seconds()))
        logging.debug("SMAN : Host %s should renew in: %i seconds...", self._host, seconds)
        return seconds

    async def convert_time(self, time):
        try:
            return datetime.strptime(time, "%Y-%m-%dT%H:%M:%SZ")
        except ValueError:
            return None

    async def calc_time_difference(self, local_time, remote_time):
        return remote_time.timestamp() - local_time.timestamp()

    async def get_digest(self):
        created = _utc_now().strftime("%Y-%m-%dT%H:%M:%S.000Z")

        raw_nonce = uuid.uuid4().bytes
        nonce = base64.b64encode(raw_nonce)

        sha1 = hashlib.sha1()
        sha1.update(raw_nonce + created.encode("utf8") + self._password.encode("utf8"))
        raw_digest = sha1.digest()
        digest_pwd = base64.b64encode(raw_digest)

        return {
            "UsernameToken": str(uuid.uuid4()),
            "Username": self._username,
            "PasswordDigest": digest_pwd.decode("utf8"),
            "Nonce": nonce.decode("utf8"),
            "Created": created,
        }

    async def send(self, headers, data):
        try:
            async with aiohttp.ClientSession(
                timeout=self._timeout,
                trust_env=True,
                connector=aiohttp.TCPConnector(verify_ssl=False),
            ) as session:
                logging.debug("SMAN : Reolink host %s (Subscription) request", self._host)
                async with session.post(
                    url=self._subscribe_url,
                    data=data,
                    headers=headers,
                    allow_redirects=False,
                ) as response:
                    response_xml = await response.text()
                    logging.debug(
                        "SMAN : Reolink host %s (Subscription) got response status: %s.",
                        self._host,
                        response.status,
                    )
                    if response.status == 200:
                        return response_xml
                    logging.warning(
                        "SMAN : Subscription process ended with wrong HTTP status: %s",
                        response.status,
                    )
                    return None
        except aiohttp.ClientConnectorError as conn_err:
            logging.debug('SMAN : Host %s: Connection error %s', self._host, str(conn_err))
        except asyncio.TimeoutError:
            logging.debug(
                'SMAN : Host %s: connection timeout exception. Please check the connection to this camera.',
                self._host,
            )
        except Exception as err:
            logging.debug('SMAN : Host %s: Unknown exception occurred: %s', self._host, err)
        return None

    async def extract_value(self, data, element):
        matches = re.findall(rf"{element}>(.+?)<", data)
        if not matches:
            return None
        return matches[0]

    async def subscribe(self, webhook_url):
        headers = {**templates.HEADERS, **templates.SUBSCRIBE_ACTION}
        parameters = {
            "Address": webhook_url,
            "InitialTerminationTime": f"PT{TERMINATION_TIME}M",
        }
        parameters.update(await self.get_digest())
        local_time = _utc_now()
        xml = templates.SUBSCRIBE_XML.format(**parameters)
        response = await self.send(headers, xml)
        if response is None:
            return False

        self._manager_url = await self.extract_value(response, "Address")
        current_time = await self.extract_value(response, "CurrentTime")
        remote_time = await self.convert_time(current_time)
        termination_time = await self.extract_value(response, "TerminationTime")
        self._termination_time = await self.convert_time(termination_time)

        if (
            self._manager_url is None
            or remote_time is None
            or self._termination_time is None
        ):
            logging.error(
                "SMAN : Host: %s failed to subscribe. Required response parameters not available.",
                self._host,
            )
            return False

        self._time_difference = await self.calc_time_difference(local_time, remote_time)
        logging.debug(
            "SMAN : Local time: %s, camera time: %s (difference: %s), termination time: %s",
            local_time.strftime('%Y-%m-%d %H:%M'),
            remote_time.strftime('%Y-%m-%d %H:%M'),
            self._time_difference,
            self._termination_time.strftime('%Y-%m-%d %H:%M'),
        )
        return True

    async def renew(self):
        headers = {**templates.HEADERS, **templates.RENEW_ACTION}
        parameters = {
            "To": self._manager_url,
            "TerminationTime": f"PT{TERMINATION_TIME}M",
        }
        parameters.update(await self.get_digest())
        local_time = _utc_now()
        xml = templates.RENEW_XML.format(**parameters)
        response = await self.send(headers, xml)
        if response is None:
            await self.unsubscribe()
            return False

        current_time = await self.extract_value(response, "CurrentTime")
        remote_time = await self.convert_time(current_time)
        if remote_time is None:
            logging.error(
                "SMAN : Host: %s failed to renew subscription. Expected response not available.",
                self._host,
            )
            await self.unsubscribe()
            return False

        self._time_difference = await self.calc_time_difference(local_time, remote_time)
        self._termination_time += timedelta(minutes=TERMINATION_TIME)
        logging.debug(
            "SMAN : Local time: %s, camera time: %s (difference: %s), termination time: %s",
            local_time.strftime('%Y-%m-%d %H:%M'),
            remote_time.strftime('%Y-%m-%d %H:%M'),
            self._time_difference,
            self._termination_time.strftime('%Y-%m-%d %H:%M'),
        )
        return True

    async def unsubscribe(self):
        headers = {**templates.HEADERS, **templates.UNSUBSCRIBE_ACTION}
        parameters = {"To": self._manager_url}
        parameters.update(await self.get_digest())
        xml = templates.UNSUBSCRIBE_XML.format(**parameters)
        await self.send(headers, xml)
        self._termination_time = None
        self._time_difference = 0
        self._manager_url = None
        return True
