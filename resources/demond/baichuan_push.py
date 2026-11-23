# This file is part of Jeedom.
#
# Jeedom is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 3 of the License, or
# (at your option) any later version.

"""
Real-time Baichuan TCP (port 9000) push event subscriber for Reolink cameras
configured in 'Baichuan (port 9000 uniquement)' mode (e.g. E321, E1, E1 Pro, Lumus).

Uses zero external pip packages: stdlib + ctypes binding to OpenSSL libcrypto
(already installed on every Debian/Jeedom host) for AES-128-CFB128 framing.
"""

import ctypes
import ctypes.util
import hashlib
import logging
import re
import socket
import struct
import threading
import time
import xml.etree.ElementTree as ET

_MAGIC = b"\xf0\xde\xbc\x0a"
_BC_KEY = bytes([0x1F, 0x2D, 0x3C, 0x4B, 0x5A, 0x69, 0x78, 0xFF])
_BC_IV = b"0123456789abcdef"


def _load_libcrypto():
    candidates = []
    found = ctypes.util.find_library("crypto")
    if found:
        candidates.append(found)
    candidates.extend(["libcrypto.so.3", "libcrypto.so.1.1", "libcrypto.so"])
    last_err = None
    for name in candidates:
        try:
            lib = ctypes.CDLL(name)
            lib.EVP_CIPHER_CTX_new.argtypes = []
            lib.EVP_CIPHER_CTX_new.restype = ctypes.c_void_p
            lib.EVP_CIPHER_CTX_free.argtypes = [ctypes.c_void_p]
            lib.EVP_CIPHER_CTX_free.restype = None
            lib.EVP_aes_128_cfb128.argtypes = []
            lib.EVP_aes_128_cfb128.restype = ctypes.c_void_p
            lib.EVP_CipherInit_ex.argtypes = [
                ctypes.c_void_p,
                ctypes.c_void_p,
                ctypes.c_void_p,
                ctypes.c_char_p,
                ctypes.c_char_p,
                ctypes.c_int,
            ]
            lib.EVP_CipherInit_ex.restype = ctypes.c_int
            lib.EVP_CipherUpdate.argtypes = [
                ctypes.c_void_p,
                ctypes.c_void_p,
                ctypes.POINTER(ctypes.c_int),
                ctypes.c_char_p,
                ctypes.c_int,
            ]
            lib.EVP_CipherUpdate.restype = ctypes.c_int
            lib.EVP_CipherFinal_ex.argtypes = [
                ctypes.c_void_p,
                ctypes.c_void_p,
                ctypes.POINTER(ctypes.c_int),
            ]
            lib.EVP_CipherFinal_ex.restype = ctypes.c_int
            return lib
        except Exception as exc:
            last_err = exc
    raise RuntimeError(f"Unable to load OpenSSL libcrypto: {last_err}")


_LIBCRYPTO = _load_libcrypto()


def _md5_modern(text: str) -> str:
    # Reolink's C char[32] buffer truncates MD5 hex strings to 31 uppercase chars
    return hashlib.md5(text.encode("utf-8")).hexdigest().upper()[:31]


def _xor_bc(data: bytes, offset: int) -> bytes:
    off = offset & 0xFF
    return bytes((b ^ _BC_KEY[(i + off) % 8] ^ off) & 0xFF for i, b in enumerate(data))


def _aes128_cfb128(data: bytes, key16: bytes, encrypt: bool) -> bytes:
    if not data:
        return b""
    ctx = _LIBCRYPTO.EVP_CIPHER_CTX_new()
    if not ctx:
        raise RuntimeError("EVP_CIPHER_CTX_new failed")
    try:
        # Fresh IV buffer per call: EVP_Cipher* mutates the IV buffer in place
        iv_buf = ctypes.create_string_buffer(_BC_IV, 16)
        out_buf = ctypes.create_string_buffer(len(data) + 16)
        out_len1 = ctypes.c_int(0)
        out_len2 = ctypes.c_int(0)
        if _LIBCRYPTO.EVP_CipherInit_ex(
            ctx,
            _LIBCRYPTO.EVP_aes_128_cfb128(),
            None,
            key16,
            ctypes.cast(iv_buf, ctypes.c_char_p),
            1 if encrypt else 0,
        ) != 1:
            raise RuntimeError("EVP_CipherInit_ex failed")
        if _LIBCRYPTO.EVP_CipherUpdate(
            ctx, out_buf, ctypes.byref(out_len1), data, len(data)
        ) != 1:
            raise RuntimeError("EVP_CipherUpdate failed")
        if _LIBCRYPTO.EVP_CipherFinal_ex(
            ctx, ctypes.byref(out_buf, out_len1.value), ctypes.byref(out_len2)
        ) != 1:
            raise RuntimeError("EVP_CipherFinal_ex failed")
        return out_buf.raw[: out_len1.value + out_len2.value]
    finally:
        _LIBCRYPTO.EVP_CIPHER_CTX_free(ctx)


def _xml_tag_value(xml_str: str, tag: str):
    m = re.search(rf"<{re.escape(tag)}>\s*([^<]*?)\s*</{re.escape(tag)}>", xml_str)
    return m.group(1) if m else None


class _BaichuanPushWorker:
    def __init__(self, ip: str, port: int, user: str, pwd: str, jeedom_cnx):
        self.ip = ip
        self.port = int(port)
        self.user = user
        self.pwd = pwd
        self.jeedom_cnx = jeedom_cnx
        self.stop_event = threading.Event()
        self.thread = threading.Thread(
            target=self._run_loop,
            name=f"baichuan-push-{ip}:{port}",
            daemon=True,
        )
        self._mess_seq = 0
        self._state_cache = {}
        self._sleep_state = None
        self._reported_sub_ok = False
        self._reported_sub_err = False

    def start(self):
        self.thread.start()

    def stop(self):
        self.stop_event.set()

    def matches(self, user: str, pwd: str) -> bool:
        return self.user == user and self.pwd == pwd and self.thread.is_alive()

    def _next_mess_id(self, ch_id: int) -> int:
        self._mess_seq = (self._mess_seq + 1) % 16777216
        return (self._mess_seq << 8) + (ch_id & 0xFF)

    @staticmethod
    def _build_header(
        cmd_id: int, mess_len: int, full_mess_id: int, msg_class: str, payload_offset: int = 0
    ) -> bytes:
        base = _MAGIC + struct.pack("<III", cmd_id, mess_len, full_mess_id)
        if msg_class == "1465":
            return base + bytes.fromhex("12dc1465")
        return base + bytes.fromhex("00001464") + struct.pack("<I", payload_offset)

    def _send_packet(
        self,
        sock: socket.socket,
        cmd_id: int,
        ch_id: int,
        body_xml: str,
        enc_type: str,
        msg_class: str,
        aes_key: bytes,
    ) -> int:
        full_mid = self._next_mess_id(ch_id)
        raw_body = body_xml.encode("utf-8") if body_xml else b""
        if raw_body:
            if enc_type == "bc":
                enc_body = _xor_bc(raw_body, ch_id)
            else:
                enc_body = _aes128_cfb128(raw_body, aes_key, True)
        else:
            enc_body = b""
        hdr = self._build_header(cmd_id, len(enc_body), full_mid, msg_class, 0)
        sock.sendall(hdr + enc_body)
        return full_mid

    @staticmethod
    def _try_parse_one(buf: bytearray, enc_type: str, aes_key: bytes):
        if len(buf) < 20:
            return None
        if bytes(buf[:4]) != _MAGIC:
            pos = buf.find(_MAGIC)
            if pos < 0:
                buf.clear()
                return None
            del buf[:pos]
            if len(buf) < 20:
                return None

        cmd_id, body_len, full_mid = struct.unpack("<III", bytes(buf[4:16]))
        ch_id = full_mid & 0xFF
        msg_class = bytes(buf[18:20]).hex()
        if msg_class in ("1464", "0000"):
            if len(buf) < 24:
                return None
            hdr_len = 24
            status = struct.unpack("<H", bytes(buf[16:18]))[0]
            offset = struct.unpack("<I", bytes(buf[20:24]))[0]
        elif msg_class in ("1465", "1466"):
            hdr_len = 20
            status = None
            offset = 0
        else:
            del buf[:4]
            return None

        total = hdr_len + body_len
        if len(buf) < total:
            return None

        raw = bytes(buf[hdr_len:total])
        del buf[:total]

        ext_xml = ""
        body_xml = ""
        if body_len > 0:
            if 0 < offset <= body_len:
                ext_enc = raw[:offset]
                main_enc = raw[offset:]
            else:
                ext_enc = b""
                main_enc = raw
            if enc_type == "bc":
                if ext_enc:
                    ext_xml = _xor_bc(ext_enc, ch_id).decode("utf-8", errors="ignore")
                body_xml = _xor_bc(main_enc, ch_id).decode("utf-8", errors="ignore")
            else:
                if ext_enc:
                    ext_xml = _aes128_cfb128(ext_enc, aes_key, False).decode(
                        "utf-8", errors="ignore"
                    )
                body_xml = _aes128_cfb128(main_enc, aes_key, False).decode(
                    "utf-8", errors="ignore"
                )

        return cmd_id, full_mid, status, ext_xml, body_xml

    def _recv_matching(
        self,
        sock: socket.socket,
        buf: bytearray,
        target_cmd: int,
        target_mid: int,
        enc_type: str,
        aes_key: bytes,
        timeout_sec: float = 6.0,
    ) -> str:
        deadline = time.monotonic() + timeout_sec
        while time.monotonic() < deadline and not self.stop_event.is_set():
            parsed = self._try_parse_one(buf, enc_type, aes_key)
            if parsed is not None:
                rec_cmd, rec_mid, status, ext_xml, body_xml = parsed
                if rec_cmd == target_cmd and rec_mid == target_mid:
                    if status is not None and status not in (200, 201, 300):
                        raise RuntimeError(
                            f"Baichuan status {status} on cmd_id={target_cmd}"
                        )
                    return body_xml
                if enc_type == "aes":
                    self._handle_push(rec_cmd, ext_xml, body_xml)
                continue

            sock.settimeout(max(0.2, min(1.5, deadline - time.monotonic())))
            chunk = sock.recv(16384)
            if not chunk:
                raise ConnectionError("Baichuan socket closed by camera")
            buf.extend(chunk)
        raise TimeoutError(f"Timeout waiting for Baichuan cmd_id={target_cmd}")

    def _emit_events_batch(self, events_list: list):
        if not events_list:
            return
        payload = {
            "message": "baichuan_events",
            "ip": self.ip,
            "events": events_list,
        }
        logging.debug("Baichuan push batch %s", payload)
        self.jeedom_cnx.send_change_immediate(payload)

    def _handle_push(self, cmd_id: int, ext_xml: str, body_xml: str):
        clean_xml = (body_xml or "").strip("\x00 \t\r\n")
        if not clean_xml:
            return

        if cmd_id == 33:
            try:
                root = ET.fromstring(clean_xml)
            except ET.ParseError:
                logging.debug("Unparseable cmd 33 XML from %s: %r", self.ip, clean_xml[:200])
                return

            ext_ch = _xml_tag_value(ext_xml, "channelId")
            default_ch = int(ext_ch) if (ext_ch and ext_ch.isdigit()) else 0
            batch = []

            for ae in root.findall(".//AlarmEvent"):
                ch_txt = ae.findtext("channelId") or ae.findtext("chnID")
                ch = int(ch_txt) if (ch_txt and ch_txt.strip().isdigit()) else default_ch
                status_txt = ae.findtext("status") or ""
                ai_txt = ae.findtext("AItype") or ""

                status_tokens = {
                    t.strip().upper() for t in status_txt.split(",") if t.strip()
                }
                ai_tokens = {
                    t.strip().lower() for t in ai_txt.split(",") if t.strip()
                }

                md_val = 1 if ("MD" in status_tokens or "MD" in status_txt or "other" in ai_tokens) else 0
                vis_val = 1 if ("VISITOR" in status_tokens or "visitor" in ai_tokens) else 0

                # Emit AI types before motion so scenarios triggered by EvMotion read up-to-date AI states
                ev_states = [
                    ("EvPeopleDetect", 1 if "people" in ai_tokens else 0),
                    ("EvVehicleDetect", 1 if "vehicle" in ai_tokens else 0),
                    ("EvDogCatDetect", 1 if ("dog_cat" in ai_tokens or "pet" in ai_tokens) else 0),
                    ("EvFaceDetect", 1 if "face" in ai_tokens else 0),
                    ("EvPackageDetect", 1 if "package" in ai_tokens else 0),
                    ("EvVisitor", vis_val),
                    ("motion", md_val),
                ]

                for ev_name, val in ev_states:
                    key = (ch, ev_name)
                    prev = self._state_cache.get(key)
                    if prev is None:
                        self._state_cache[key] = val
                        if val == 1 or ev_name == "motion":
                            batch.append({"channel": int(ch), "event": ev_name, "state": int(val)})
                    elif prev != val:
                        self._state_cache[key] = val
                        batch.append({"channel": int(ch), "event": ev_name, "state": int(val)})

            self._emit_events_batch(batch)

        elif cmd_id == 623 or "<sleep>" in clean_xml:
            sleep_txt = _xml_tag_value(clean_xml, "sleep")
            if sleep_txt is not None and sleep_txt.lstrip("-").isdigit():
                ch_txt = _xml_tag_value(clean_xml, "channelId") or _xml_tag_value(ext_xml, "channelId")
                sleep_ch = int(ch_txt) if (ch_txt and ch_txt.strip().isdigit()) else 0
                sleep_val = 1 if int(sleep_txt) == 1 else 0
                if self._sleep_state != (sleep_ch, sleep_val):
                    self._sleep_state = (sleep_ch, sleep_val)
                    self._emit_events_batch([{"channel": sleep_ch, "event": "EvPrivacy", "state": sleep_val}])

    def _run_single_session(self):
        buf = bytearray()
        aes_key = b""
        logged_in = False
        sock = socket.create_connection((self.ip, self.port), timeout=5.0)
        try:
            sock.setsockopt(socket.IPPROTO_TCP, socket.TCP_NODELAY, 1)
            # 1. Pre-login nonce (cmd 1, msg_class 1465)
            mid1 = self._send_packet(sock, 1, 250, "", "bc", "1465", b"")
            nonce_xml = self._recv_matching(sock, buf, 1, mid1, "bc", b"", 6.0)
            nonce = _xml_tag_value(nonce_xml, "nonce")
            if not nonce:
                sig_ver = _xml_tag_value(nonce_xml, "sigVer")
                if sig_ver == "v3":
                    raise PermissionError("Camera requires ECDSA signature login (sigVer=v3)")
                raise RuntimeError("Missing Baichuan login nonce")

            aes_key = _md5_modern(f"{nonce}-{self.pwd}")[:16].encode("ascii")
            u_md5 = _md5_modern(f"{self.user}{nonce}")
            p_md5 = _md5_modern(f"{self.pwd}{nonce}")
            login_xml = (
                '<?xml version="1.0" encoding="UTF-8" ?>\n'
                "<body>\n"
                '<LoginUser version="1.1">\n'
                f"<userName>{u_md5}</userName>\n"
                f"<password>{p_md5}</password>\n"
                "<userVer>1</userVer>\n"
                "</LoginUser>\n"
                '<LoginNet version="1.1">\n'
                "<type>LAN</type>\n"
                "<udpPort>0</udpPort>\n"
                "</LoginNet>\n"
                "</body>"
            )
            # 2. Modern login (cmd 1, msg_class 1464, still XOR-encrypted)
            mid2 = self._send_packet(sock, 1, 250, login_xml, "bc", "1464", aes_key)
            self._recv_matching(sock, buf, 1, mid2, "bc", aes_key, 6.0)
            logged_in = True

            # 3. Subscribe to push AlarmEventList (cmd 31 on ch_id=251)
            mid31 = self._send_packet(sock, 31, 251, "", "aes", "1464", aes_key)
            self._recv_matching(sock, buf, 31, mid31, "aes", aes_key, 6.0)

            logging.info("Baichuan push listener connected to %s:%s", self.ip, self.port)
            if not self._reported_sub_ok:
                self._reported_sub_ok = True
                self._reported_sub_err = False
                self.jeedom_cnx.send_change_immediate(
                    {"message": "subscription", "ip": self.ip, "state": 1}
                )

            last_recv = time.monotonic()
            last_keepalive = last_recv
            events_seen = bool(self._state_cache)

            while not self.stop_event.is_set():
                while True:
                    parsed = self._try_parse_one(buf, "aes", aes_key)
                    if parsed is None:
                        break
                    rec_cmd, _rec_mid, _status, ext_xml, body_xml = parsed
                    last_recv = time.monotonic()
                    if rec_cmd == 33:
                        events_seen = True
                    self._handle_push(rec_cmd, ext_xml, body_xml)

                now = time.monotonic()
                if (now - last_recv) > 130.0:
                    raise TimeoutError("Baichuan push stream idle timeout")

                if (now - last_keepalive) >= 50.0:
                    last_keepalive = now
                    if events_seen:
                        self._send_packet(sock, 93, 250, "", "aes", "1464", aes_key)
                    else:
                        self._send_packet(sock, 31, 251, "", "aes", "1464", aes_key)

                sock.settimeout(1.5)
                try:
                    chunk = sock.recv(16384)
                    if not chunk:
                        raise ConnectionError("Baichuan push socket closed by camera")
                    buf.extend(chunk)
                    last_recv = time.monotonic()
                except socket.timeout:
                    continue
        finally:
            if logged_in and aes_key:
                try:
                    sock.settimeout(1.0)
                    logout_xml = (
                        '<?xml version="1.0" encoding="UTF-8" ?>\n'
                        "<body>\n"
                        '<LoginUser version="1.1">\n'
                        f"<userName>{self.user}</userName>\n"
                        "<password></password>\n"
                        "<userVer>1</userVer>\n"
                        "</LoginUser>\n"
                        "</body>"
                    )
                    self._send_packet(sock, 2, 250, logout_xml, "aes", "1464", aes_key)
                except Exception:
                    pass
            try:
                sock.close()
            except Exception:
                pass

    def _run_loop(self):
        backoff = 5.0
        while not self.stop_event.is_set():
            started = time.monotonic()
            try:
                self._run_single_session()
                backoff = 5.0
            except PermissionError as exc:
                logging.warning("Baichuan push unsupported on %s:%s: %s", self.ip, self.port, exc)
                if not self._reported_sub_err:
                    self._reported_sub_err = True
                    self.jeedom_cnx.send_change_immediate(
                        {
                            "message": "subscription",
                            "ip": self.ip,
                            "state": 0,
                            "details": str(exc),
                        }
                    )
                self.stop_event.wait(300.0)
            except Exception as exc:
                if self.stop_event.is_set():
                    break
                if (time.monotonic() - started) > 60.0:
                    backoff = 5.0
                logging.warning(
                    "Baichuan push %s:%s disconnected (%s), retrying in %.0fs",
                    self.ip,
                    self.port,
                    exc,
                    backoff,
                )
                self.stop_event.wait(backoff)
                backoff = min(45.0, backoff * 2.0)


class BaichuanPushManager:
    def __init__(self, jeedom_cnx):
        self._jeedom_cnx = jeedom_cnx
        self._lock = threading.Lock()
        self._workers = {}

    def sync_cameras(self, cameras: list):
        desired = {}
        for cam in cameras or []:
            ip = str(cam.get("ip") or "").strip()
            port = int(cam.get("port") or 9000)
            user = str(cam.get("user") or "admin")
            pwd = str(cam.get("pwd") or "")
            if ip:
                desired[(ip, port)] = (user, pwd)

        to_stop = []
        with self._lock:
            for key in list(self._workers.keys()):
                if key not in desired:
                    to_stop.append(self._workers.pop(key))

            for (ip, port), (user, pwd) in desired.items():
                existing = self._workers.get((ip, port))
                if existing is not None and existing.matches(user, pwd):
                    continue
                if existing is not None:
                    to_stop.append(existing)
                worker = _BaichuanPushWorker(ip, port, user, pwd, self._jeedom_cnx)
                self._workers[(ip, port)] = worker
                worker.start()

        for worker in to_stop:
            worker.stop()

    def stop_all(self):
        with self._lock:
            workers = list(self._workers.values())
            self._workers.clear()
        for worker in workers:
            worker.stop()
        for worker in workers:
            worker.thread.join(timeout=1.5)
