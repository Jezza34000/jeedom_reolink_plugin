# This file is part of Jeedom.
#
# Jeedom is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 3 of the License, or
# (at your option) any later version.

"""
Local loopback RTSP backchannel server (127.0.0.1:44013) bridging standard
WebRTC / go2rtc PCMA/PCMU 8 kHz talkback audio to Reolink Baichuan TCP (port 9000)
full-duplex talk (cmd 10 / 201 / 202 / 11, 16 kHz IMA-ADPCM 01wb).

Works uniformly across standalone cameras, Doorbells (PoE/WiFi/Battery) and
cameras behind classic Reolink NVRs (RLN8/RLN16) or Home Hub without writing
any credentials to disk or command lines.
"""

import hmac
import logging
import re
import socket
import struct
import sys
import threading
import time
import uuid

from baichuan_push import (
    _MAGIC,
    _aes128_cfb128,
    _md5_modern,
    _xml_tag_value,
    _xor_bc,
)

DEFAULT_TALK_PORT = 44013

_IMA_STEPS = (
    7, 8, 9, 10, 11, 12, 13, 14, 16, 17, 19, 21, 23, 25, 28, 31, 34, 37, 41, 45,
    50, 55, 60, 66, 73, 80, 88, 97, 107, 118, 130, 143, 157, 173, 190, 209, 230,
    253, 279, 307, 337, 371, 408, 449, 494, 544, 598, 658, 724, 796, 876, 963,
    1060, 1166, 1282, 1411, 1552, 1707, 1878, 2066, 2272, 2499, 2749, 3024, 3327,
    3660, 4026, 4428, 4871, 5358, 5894, 6484, 7132, 7845, 8630, 9493, 10442,
    11487, 12635, 13899, 15289, 16818, 18500, 20350, 22385, 24623, 27086, 29794,
    32767,
)
_IMA_INDEX = (-1, -1, -1, -1, 2, 4, 6, 8, -1, -1, -1, -1, 2, 4, 6, 8)


def _build_alaw_table():
    table = [0] * 256
    for i in range(256):
        v = i ^ 0x55
        t = (v & 0x0F) << 4
        seg = (v & 0x70) >> 4
        if seg == 0:
            t += 8
        elif seg == 1:
            t += 0x108
        else:
            t += 0x108
            t <<= seg - 1
        table[i] = t if (v & 0x80) else -t
    return tuple(table)


def _build_ulaw_table():
    table = [0] * 256
    for i in range(256):
        v = ~i & 0xFF
        t = (((v & 0x0F) << 3) + 0x84) << ((v & 0x70) >> 4)
        table[i] = (0x84 - t) if (v & 0x80) else (t - 0x84)
    return tuple(table)


_ALAW_TABLE = _build_alaw_table()
_ULAW_TABLE = _build_ulaw_table()


def decode_g711(payload: bytes, payload_type: int = 8) -> list:
    tbl = _ULAW_TABLE if payload_type == 0 else _ALAW_TABLE
    return [tbl[b] for b in payload]


def strip_rtp_payload(rtp_packet: bytes):
    if len(rtp_packet) < 12:
        return None, b""
    b0 = rtp_packet[0]
    if (b0 >> 6) != 2:
        return None, b""
    padding = (b0 >> 5) & 1
    extension = (b0 >> 4) & 1
    csrc_count = b0 & 0x0F
    pt = rtp_packet[1] & 0x7F
    offset = 12 + csrc_count * 4
    if len(rtp_packet) < offset:
        return None, b""
    if extension:
        if len(rtp_packet) < offset + 4:
            return None, b""
        ext_words = struct.unpack(">H", rtp_packet[offset + 2 : offset + 4])[0]
        offset += 4 + ext_words * 4
    end = len(rtp_packet)
    if padding and end > offset:
        pad_len = rtp_packet[-1]
        if 0 < pad_len <= (end - offset):
            end -= pad_len
    if offset >= end:
        return pt, b""
    return pt, rtp_packet[offset:end]


class ImaAdpcmPipeline:
    """Stateful resampler (8 kHz -> target Hz) + DVI/IMA-ADPCM + BcMedia 01wb framer."""

    def __init__(self, input_rate: int = 8000, output_rate: int = 16000, samples_per_block: int = 1024):
        self.step_ratio = float(input_rate) / float(output_rate)
        self.samples_per_block = max(2, int(samples_per_block) & ~1)
        self._frac = 0.0
        self._prev = 0
        self._primed = False
        self._buf = []
        self.predictor = 0
        self.step_index = 0

    def _encode_sample(self, sample: int) -> int:
        step = _IMA_STEPS[self.step_index]
        delta = sample - self.predictor
        nibble = 0
        if delta < 0:
            nibble = 8
            delta = -delta
        if delta >= step:
            nibble |= 4
            delta -= step
        if delta >= (step >> 1):
            nibble |= 2
            delta -= step >> 1
        if delta >= (step >> 2):
            nibble |= 1

        diff = step >> 3
        if nibble & 4:
            diff += step
        if nibble & 2:
            diff += step >> 1
        if nibble & 1:
            diff += step >> 2
        pred = self.predictor - diff if (nibble & 8) else self.predictor + diff
        if pred < -32768:
            pred = -32768
        elif pred > 32767:
            pred = 32767
        self.predictor = pred

        idx = self.step_index + _IMA_INDEX[nibble]
        if idx < 0:
            idx = 0
        elif idx > 88:
            idx = 88
        self.step_index = idx
        return nibble

    def encode_adpcm_block(self, samples: list) -> bytes:
        hdr = struct.pack("<hBB", self.predictor, self.step_index, 0)
        out = bytearray(hdr)
        for i in range(0, len(samples), 2):
            n0 = self._encode_sample(samples[i])
            n1 = self._encode_sample(samples[i + 1])
            out.append(((n0 & 0x0F) << 4) | (n1 & 0x0F))
        return bytes(out)

    @staticmethod
    def wrap_bcmedia_01wb(adpcm_block: bytes) -> bytes:
        nibble_bytes = len(adpcm_block) - 4
        payload_size = 4 + len(adpcm_block)
        pad = (8 - (payload_size % 8)) % 8
        hdr = struct.pack(
            "<IHHHH",
            0x62773130,
            payload_size,
            payload_size,
            0x0100,
            nibble_bytes,
        )
        return hdr + adpcm_block + (b"\x00" * pad)

    def feed_pcm_samples(self, samples: list) -> list:
        frames = []
        for cur in samples:
            if not self._primed:
                self._primed = True
                self._prev = cur
                continue
            while self._frac < 1.0:
                interp = int(self._prev + (cur - self._prev) * self._frac)
                self._buf.append(interp)
                if len(self._buf) >= self.samples_per_block:
                    blk = self.encode_adpcm_block(self._buf[: self.samples_per_block])
                    del self._buf[: self.samples_per_block]
                    frames.append(self.wrap_bcmedia_01wb(blk))
                self._frac += self.step_ratio
            self._frac -= 1.0
            self._prev = cur
        return frames


class BaichuanTalkSession:
    """Dedicated short-lived Baichuan TCP session for full-duplex two-way talk."""

    def __init__(self, ip: str, port: int, channel: int, user: str, pwd: str):
        self.ip = ip
        self.port = int(port)
        self.channel = int(channel)
        self.user = user
        self.pwd = pwd
        self.sock = None
        self.aes_key = b""
        self.logged_in = False
        self.talk_open = False
        self._mess_seq = 0
        self._recv_buf = bytearray()
        self.sample_rate = 16000
        self.length_per_encoder = 1024

    def _next_mid(self, ch_id: int) -> int:
        self._mess_seq = (self._mess_seq + 1) % 16777216
        return (self._mess_seq << 8) + (ch_id & 0xFF)

    @staticmethod
    def _build_header(cmd_id: int, mess_len: int, full_mid: int, msg_class: str, offset: int = 0) -> bytes:
        base = _MAGIC + struct.pack("<III", cmd_id, mess_len, full_mid)
        if msg_class == "1465":
            return base + bytes.fromhex("12dc1465")
        return base + bytes.fromhex("00001464") + struct.pack("<I", offset)

    def _send(
        self,
        cmd_id: int,
        ch_id: int,
        body_xml: str = "",
        ext_xml: str = "",
        raw_payload: bytes = b"",
        enc_type: str = "aes",
        msg_class: str = "1464",
    ) -> int:
        ext_b = ext_xml.encode("utf-8") if ext_xml else b""
        body_b = body_xml.encode("utf-8") if body_xml else b""
        offset = len(ext_b)
        mess_len = offset + len(body_b) + len(raw_payload)
        enc = b""
        if offset > 0 or len(body_b) > 0:
            if enc_type == "bc":
                enc = _xor_bc(ext_b, ch_id) + _xor_bc(body_b, ch_id)
            else:
                enc = _aes128_cfb128(ext_b, self.aes_key, True) + _aes128_cfb128(body_b, self.aes_key, True)
        # Binary payload (cmd 202 BcMedia 01wb) is NOT AES-encrypted when <binaryData>1</binaryData> is set
        enc += raw_payload
        full_mid = self._next_mid(ch_id)
        hdr = self._build_header(cmd_id, mess_len, full_mid, msg_class, offset)
        self.sock.sendall(hdr + enc)
        return full_mid

    def _try_parse_one(self, enc_type: str):
        buf = self._recv_buf
        if len(buf) < 4:
            return None
        if bytes(buf[:4]) != _MAGIC:
            pos = buf.find(_MAGIC)
            if pos < 0:
                if len(buf) > 3:
                    del buf[:-3]
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
            status = 200
            offset = 0
        else:
            del buf[:4]
            return None
        total = hdr_len + body_len
        if len(buf) < total:
            return None
        raw = bytes(buf[hdr_len:total])
        del buf[:total]
        body_xml = ""
        if body_len > 0 and status in (200, 201, 300):
            main_enc = raw[offset:] if 0 < offset < body_len else raw
            if enc_type == "bc":
                body_xml = _xor_bc(main_enc, ch_id).decode("utf-8", errors="ignore")
            else:
                body_xml = _aes128_cfb128(main_enc, self.aes_key, False).decode("utf-8", errors="ignore")
        return cmd_id, full_mid, status, body_xml

    def _recv_reply(self, target_cmd: int, target_mid: int, enc_type: str = "aes", timeout: float = 5.0):
        deadline = time.monotonic() + timeout
        while time.monotonic() < deadline:
            parsed = self._try_parse_one(enc_type)
            if parsed is not None:
                rec_cmd, rec_mid, status, body_xml = parsed
                if rec_cmd == target_cmd and rec_mid == target_mid:
                    return status, body_xml
                continue
            self.sock.settimeout(max(0.1, min(1.0, deadline - time.monotonic())))
            chunk = self.sock.recv(16384)
            if not chunk:
                raise ConnectionError("Baichuan talk socket closed by camera")
            self._recv_buf.extend(chunk)
        raise TimeoutError(f"Timeout waiting for Baichuan talk cmd_id={target_cmd}")

    def open(self):
        sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        sock.setsockopt(socket.IPPROTO_TCP, socket.TCP_NODELAY, 1)
        sock.settimeout(5.0)
        sock.connect((self.ip, self.port))
        self.sock = sock

        # 1. Login handshake (cmd 1 pre-login + modern login)
        mid1 = self._send(1, 250, enc_type="bc", msg_class="1465")
        _, nonce_xml = self._recv_reply(1, mid1, enc_type="bc")
        nonce = _xml_tag_value(nonce_xml, "nonce")
        if not nonce:
            raise RuntimeError("Missing Baichuan nonce on talk login")
        self.aes_key = _md5_modern(f"{nonce}-{self.pwd}")[:16].encode("ascii")
        u_md5 = _md5_modern(f"{self.user}{nonce}")
        p_md5 = _md5_modern(f"{self.pwd}{nonce}")
        login_xml = (
            '<?xml version="1.0" encoding="UTF-8" ?>\n<body>\n'
            '<LoginUser version="1.1">\n'
            f"<userName>{u_md5}</userName>\n"
            f"<password>{p_md5}</password>\n"
            "<userVer>1</userVer>\n"
            "</LoginUser>\n"
            '<LoginNet version="1.1">\n<type>LAN</type>\n<udpPort>0</udpPort>\n</LoginNet>\n'
            "</body>"
        )
        mid2 = self._send(1, 250, body_xml=login_xml, enc_type="bc", msg_class="1464")
        st_login, _ = self._recv_reply(1, mid2, enc_type="bc")
        if st_login not in (200, 201):
            raise PermissionError(f"Baichuan talk login rejected ({st_login})")
        self.logged_in = True

        # 2. Query TalkAbility (cmd 10) for exact sampleRate & lengthPerEncoder
        ch_id = self.channel + 1
        ch_ext = (
            '<?xml version="1.0" encoding="UTF-8" ?>\n'
            f'<Extension version="1.1">\n<channelId>{self.channel}</channelId>\n</Extension>\n'
        )
        mid10 = self._send(10, ch_id, ext_xml=ch_ext)
        st10, ab_xml = self._recv_reply(10, mid10)
        duplex = "FDX"
        stream_mode = "followVideoStream"
        if st10 == 200 and ab_xml:
            dup = _xml_tag_value(ab_xml, "duplex")
            if dup:
                duplex = dup.strip()
            sm = _xml_tag_value(ab_xml, "audioStreamMode")
            if sm:
                stream_mode = sm.strip()
            # Pick the adpcm <audioConfig> block if multiple exist
            blocks = re.findall(r"<audioConfig>(.*?)</audioConfig>", ab_xml, flags=re.S)
            chosen = None
            for blk in blocks:
                if (_xml_tag_value(blk, "audioType") or "").strip().lower() == "adpcm":
                    chosen = blk
                    break
            if chosen is None and blocks:
                chosen = blocks[0]
            if chosen:
                sr = _xml_tag_value(chosen, "sampleRate")
                lpe = _xml_tag_value(chosen, "lengthPerEncoder")
                if sr and sr.strip().isdigit():
                    self.sample_rate = int(sr.strip())
                if lpe and lpe.strip().isdigit():
                    self.length_per_encoder = int(lpe.strip())

        talk_cfg_xml = (
            '<?xml version="1.0" encoding="UTF-8" ?>\n<body>\n'
            '<TalkConfig version="1.1">\n'
            f"<channelId>{self.channel}</channelId>\n"
            f"<duplex>{duplex}</duplex>\n"
            f"<audioStreamMode>{stream_mode}</audioStreamMode>\n"
            "<audioConfig>\n"
            "<audioType>adpcm</audioType>\n"
            f"<sampleRate>{self.sample_rate}</sampleRate>\n"
            "<samplePrecision>16</samplePrecision>\n"
            f"<lengthPerEncoder>{self.length_per_encoder}</lengthPerEncoder>\n"
            "<soundTrack>mono</soundTrack>\n"
            "</audioConfig>\n"
            "</TalkConfig>\n</body>"
        )
        mid201 = self._send(201, ch_id, body_xml=talk_cfg_xml, ext_xml=ch_ext)
        st201, _ = self._recv_reply(201, mid201)
        if st201 == 422:
            # Stale talk slot on camera/NVR: reset (cmd 11) and retry once
            mid11 = self._send(11, ch_id, ext_xml=ch_ext)
            self._recv_reply(11, mid11)
            mid201 = self._send(201, ch_id, body_xml=talk_cfg_xml, ext_xml=ch_ext)
            st201, _ = self._recv_reply(201, mid201)
        if st201 != 200:
            raise RuntimeError(f"Baichuan TalkOpen (cmd 201) rejected with status {st201}")
        self.talk_open = True
        logging.info(
            "Baichuan talk opened on %s:%s ch=%s (%s Hz, block=%s)",
            self.ip,
            self.port,
            self.channel,
            self.sample_rate,
            self.length_per_encoder,
        )

    def send_bcmedia_frame(self, bcmedia_frame: bytes):
        if not self.talk_open or not self.sock:
            return
        ch_id = self.channel + 1
        bin_ext = (
            '<?xml version="1.0" encoding="UTF-8" ?>\n'
            "<Extension version=\"1.1\">\n"
            "<binaryData>1</binaryData>\n"
            f"<channelId>{self.channel}</channelId>\n"
            "</Extension>\n"
        )
        self.sock.settimeout(2.0)
        self._send(202, ch_id, ext_xml=bin_ext, raw_payload=bcmedia_frame)
        # Non-blocking drain so camera push packets never fill TCP recv buffer
        try:
            self.sock.setblocking(False)
            while True:
                chunk = self.sock.recv(8192)
                if not chunk:
                    break
        except BlockingIOError:
            pass
        except Exception:
            pass
        finally:
            try:
                self.sock.settimeout(2.0)
            except Exception:
                pass

    def close(self):
        sock = self.sock
        self.sock = None
        if not sock:
            return
        try:
            sock.settimeout(1.5)
            if self.talk_open and self.aes_key:
                self.talk_open = False
                ch_id = self.channel + 1
                ch_ext = (
                    '<?xml version="1.0" encoding="UTF-8" ?>\n'
                    f'<Extension version="1.1">\n<channelId>{self.channel}</channelId>\n</Extension>\n'
                )
                self.sock = sock
                try:
                    mid11 = self._send(11, ch_id, ext_xml=ch_ext)
                    self._recv_reply(11, mid11, timeout=1.5)
                except Exception:
                    pass
            if self.logged_in and self.aes_key:
                self.logged_in = False
                self.sock = sock
                logout_xml = (
                    '<?xml version="1.0" encoding="UTF-8" ?>\n<body>\n'
                    f'<LoginUser version="1.1">\n<userName>{self.user}</userName>\n'
                    "<password></password>\n<userVer>1</userVer>\n</LoginUser>\n</body>"
                )
                try:
                    self._send(2, 250, body_xml=logout_xml)
                except Exception:
                    pass
        finally:
            self.sock = None
            try:
                sock.close()
            except Exception:
                pass


class BaichuanTalkRtspServer:
    """Loopback-only RTSP server accepting go2rtc TCP-interleaved backchannel RTP."""

    def __init__(self, host: str = "127.0.0.1", port: int = DEFAULT_TALK_PORT):
        self.host = host
        self.port = int(port)
        self._lock = threading.Lock()
        self._routes = {}
        self._stop_event = threading.Event()
        self._srv_sock = None
        self._thread = None

    def register_route(
        self,
        eq_id: int,
        ip: str,
        port: int,
        channel: int,
        user: str,
        pwd: str,
        token: str,
    ):
        if not ip or not token:
            return
        with self._lock:
            self._routes[str(int(eq_id))] = {
                "ip": str(ip).strip(),
                "port": int(port or 9000),
                "channel": int(channel or 0),
                "user": str(user or "admin"),
                "pwd": str(pwd or ""),
                "token": str(token),
            }

    def _resolve_route(self, url: str):
        m = re.search(r"/talk/(\d+)/([A-Za-z0-9_-]+)", url)
        if not m:
            return None, None
        eq_id, tok = m.group(1), m.group(2)
        with self._lock:
            route = self._routes.get(eq_id)
        if not route or not hmac.compare_digest(route["token"], tok):
            return None, None
        return f"/talk/{eq_id}/{tok}", dict(route)

    def start(self):
        if self._thread is not None and self._thread.is_alive():
            return
        self._stop_event.clear()
        srv = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        srv.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        srv.bind((self.host, self.port))
        self.port = srv.getsockname()[1]
        srv.listen(8)
        srv.settimeout(1.0)
        self._srv_sock = srv
        self._thread = threading.Thread(
            target=self._accept_loop,
            name=f"baichuan-talk-rtsp-{self.port}",
            daemon=True,
        )
        self._thread.start()
        logging.info("Baichuan RTSP talk bridge listening on %s:%s", self.host, self.port)

    def stop(self):
        self._stop_event.set()
        if self._srv_sock:
            try:
                self._srv_sock.close()
            except Exception:
                pass
            self._srv_sock = None

    def _accept_loop(self):
        while not self._stop_event.is_set():
            try:
                conn, addr = self._srv_sock.accept()
            except socket.timeout:
                continue
            except OSError:
                break
            t = threading.Thread(
                target=self._handle_client,
                args=(conn, addr),
                daemon=True,
            )
            t.start()

    @staticmethod
    def _send_rtsp(conn: socket.socket, status: int, reason: str, cseq: int, headers=None, body: str = ""):
        lines = [f"RTSP/1.0 {status} {reason}", f"CSeq: {cseq}"]
        for k, v in (headers or {}).items():
            lines.append(f"{k}: {v}")
        body_b = body.encode("utf-8") if body else b""
        if body_b:
            lines.append(f"Content-Length: {len(body_b)}")
        raw = ("\r\n".join(lines) + "\r\n\r\n").encode("utf-8") + body_b
        conn.sendall(raw)

    def _handle_client(self, conn: socket.socket, _addr):
        conn.setsockopt(socket.IPPROTO_TCP, socket.TCP_NODELAY, 1)
        conn.settimeout(10.0)
        buf = bytearray()
        session_id = uuid.uuid4().hex[:12]
        rtp_ch = 0
        rtcp_ch = 1
        route = None
        bc_talk = None
        pipeline = None
        last_rtcp_at = time.monotonic()

        try:
            while not self._stop_event.is_set():
                # 1. Drain all complete TCP-interleaved '$' RTP/RTCP frames first
                progressed = False
                while len(buf) >= 4 and buf[0] == 0x24:
                    pkt_len = struct.unpack(">H", bytes(buf[2:4]))[0]
                    if len(buf) < 4 + pkt_len:
                        break
                    ch = buf[1]
                    pkt = bytes(buf[4 : 4 + pkt_len])
                    del buf[: 4 + pkt_len]
                    progressed = True
                    if ch == rtp_ch and bc_talk is not None and pipeline is not None:
                        pt, g711 = strip_rtp_payload(pkt)
                        if g711 and pt in (0, 8):
                            pcm8k = decode_g711(g711, pt)
                            for bc_frame in pipeline.feed_pcm_samples(pcm8k):
                                bc_talk.send_bcmedia_frame(bc_frame)

                # 2. Parse RTSP text request if buffer does not start with partial '$' frame
                if len(buf) > 0 and buf[0] != 0x24:
                    sep = buf.find(b"\r\n\r\n")
                    if sep >= 0:
                        req_txt = bytes(buf[:sep]).decode("utf-8", errors="ignore")
                        del buf[: sep + 4]
                        progressed = True
                        lines = req_txt.split("\r\n")
                        parts = lines[0].split(" ") if lines else []
                        if len(parts) < 2:
                            break
                        method, url = parts[0].upper(), parts[1]
                        m_cseq = re.search(r"CSeq:\s*(\d+)", req_txt, flags=re.I)
                        cseq = int(m_cseq.group(1)) if m_cseq else 1

                        if method == "OPTIONS":
                            self._send_rtsp(
                                conn,
                                200,
                                "OK",
                                cseq,
                                {
                                    "Public": "OPTIONS, DESCRIBE, SETUP, PLAY, RECORD, TEARDOWN, GET_PARAMETER, SET_PARAMETER"
                                },
                            )
                            continue

                        if method in ("GET_PARAMETER", "SET_PARAMETER"):
                            self._send_rtsp(conn, 200, "OK", cseq, {"Session": session_id})
                            continue

                        if method == "DESCRIBE":
                            base_path, resolved = self._resolve_route(url)
                            if not resolved:
                                self._send_rtsp(conn, 404, "Not Found", cseq)
                                break
                            route = resolved
                            sdp = (
                                "v=0\r\n"
                                "o=- 0 0 IN IP4 127.0.0.1\r\n"
                                "s=ReolinkTalk\r\n"
                                "c=IN IP4 127.0.0.1\r\n"
                                "t=0 0\r\n"
                                "m=audio 0 RTP/AVP 8\r\n"
                                "a=rtpmap:8 PCMA/8000\r\n"
                                "a=sendonly\r\n"
                                "a=control:audiobackchannel\r\n"
                            )
                            self._send_rtsp(
                                conn,
                                200,
                                "OK",
                                cseq,
                                {
                                    "Content-Type": "application/sdp",
                                    "Content-Base": f"rtsp://{self.host}:{self.port}{base_path}/",
                                },
                                sdp,
                            )
                            continue

                        if method == "SETUP":
                            if route is None:
                                _, route = self._resolve_route(url)
                            if route is None:
                                self._send_rtsp(conn, 404, "Not Found", cseq)
                                break
                            m_tr = re.search(r"Transport:\s*([^\r\n]+)", req_txt, flags=re.I)
                            tr = m_tr.group(1) if m_tr else ""
                            if "interleaved" not in tr.lower() and "tcp" not in tr.lower():
                                self._send_rtsp(conn, 461, "Unsupported Transport", cseq)
                                break
                            m_il = re.search(r"interleaved=(\d+)-(\d+)", tr, flags=re.I)
                            if m_il:
                                rtp_ch, rtcp_ch = int(m_il.group(1)), int(m_il.group(2))
                            self._send_rtsp(
                                conn,
                                200,
                                "OK",
                                cseq,
                                {
                                    "Transport": f"RTP/AVP/TCP;unicast;interleaved={rtp_ch}-{rtcp_ch};mode=record",
                                    "Session": f"{session_id};timeout=60",
                                },
                            )
                            continue

                        if method in ("PLAY", "RECORD"):
                            if route is None:
                                self._send_rtsp(conn, 454, "Session Not Found", cseq)
                                break
                            if bc_talk is None:
                                bc_talk = BaichuanTalkSession(
                                    route["ip"],
                                    route["port"],
                                    route["channel"],
                                    route["user"],
                                    route["pwd"],
                                )
                                bc_talk.open()
                                pipeline = ImaAdpcmPipeline(
                                    8000,
                                    bc_talk.sample_rate,
                                    bc_talk.length_per_encoder,
                                )
                            self._send_rtsp(conn, 200, "OK", cseq, {"Session": session_id})
                            last_rtcp_at = time.monotonic()
                            continue

                        if method == "TEARDOWN":
                            self._send_rtsp(conn, 200, "OK", cseq, {"Session": session_id})
                            break

                        self._send_rtsp(conn, 501, "Not Implemented", cseq)
                        continue

                # 3. Periodic RTCP RR keepalive so go2rtc never hits its ~30s TCP read timeout
                now = time.monotonic()
                if bc_talk is not None and (now - last_rtcp_at) >= 10.0:
                    last_rtcp_at = now
                    rtcp_rr = b"$" + bytes([rtcp_ch]) + struct.pack(">HBBHI", 8, 0x80, 201, 1, 0x524C4B31)
                    conn.sendall(rtcp_rr)

                if progressed:
                    continue

                conn.settimeout(5.0 if bc_talk is not None else 15.0)
                try:
                    chunk = conn.recv(8192)
                    if not chunk:
                        break
                    buf.extend(chunk)
                except socket.timeout:
                    if bc_talk is None:
                        break
                    continue
        except Exception as exc:
            logging.warning("Baichuan RTSP talk session error: %s", exc)
        finally:
            if bc_talk is not None:
                try:
                    bc_talk.close()
                except Exception:
                    pass
            try:
                conn.close()
            except Exception:
                pass


def run_self_test():
    # 1. G.711 A-law silence (0xD5) decodes near zero
    pcm_silence = decode_g711(bytes([0xD5] * 160), 8)
    assert len(pcm_silence) == 160 and all(abs(s) <= 8 for s in pcm_silence)

    # 2. RTP parser strips header + CSRC + extension cleanly
    rtp = struct.pack(">BBHII", 0x80, 8, 1, 160, 0x11223344) + (b"\xd5" * 160)
    pt, payload = strip_rtp_payload(rtp)
    assert pt == 8 and payload == (b"\xd5" * 160)

    # 3. Resampler + IMA-ADPCM + 01wb framing for both 1024 and 1016 block sizes
    for lpe in (1024, 1016):
        pipe = ImaAdpcmPipeline(8000, 16000, lpe)
        frames = pipe.feed_pcm_samples([0] * 600)
        assert len(frames) == 1, f"Expected 1 frame for lpe={lpe}, got {len(frames)}"
        f0 = frames[0]
        assert len(f0) == 528, f"Unexpected 01wb size {len(f0)} != 528"
        magic, ps1, ps2, sub_magic, half_sz = struct.unpack("<IHHHH", f0[:12])
        assert magic == 0x62773130 and ps1 == ps2 and sub_magic == 0x0100
        assert half_sz == lpe // 2

    # 4. Loopback RTSP OPTIONS -> DESCRIBE -> SETUP -> TEARDOWN handshake + token guard
    srv = BaichuanTalkRtspServer("127.0.0.1", 0)
    srv.register_route(43, "127.0.0.1", 9000, 4, "admin", "secret", "tok123")
    srv.start()
    try:
        with socket.create_connection(("127.0.0.1", srv.port), timeout=3.0) as cli:
            cli.sendall(b"DESCRIBE rtsp://127.0.0.1/talk/43/wrong RTSP/1.0\r\nCSeq: 1\r\n\r\n")
            assert b"404 Not Found" in cli.recv(4096)
        with socket.create_connection(("127.0.0.1", srv.port), timeout=3.0) as cli:
            base = f"rtsp://127.0.0.1:{srv.port}/talk/43/tok123"
            cli.sendall(f"OPTIONS {base} RTSP/1.0\r\nCSeq: 1\r\n\r\n".encode())
            assert b"200 OK" in cli.recv(4096)
            cli.sendall(f"DESCRIBE {base} RTSP/1.0\r\nCSeq: 2\r\n\r\n".encode())
            desc = cli.recv(4096)
            assert b"a=rtpmap:8 PCMA/8000" in desc and b"a=sendonly" in desc
            cli.sendall(
                f"SETUP {base}/audiobackchannel RTSP/1.0\r\nCSeq: 3\r\nTransport: RTP/AVP/TCP;unicast;interleaved=0-1;mode=record\r\n\r\n".encode()
            )
            setup = cli.recv(4096)
            assert b"interleaved=0-1;mode=record" in setup
            cli.sendall(f"TEARDOWN {base}/ RTSP/1.0\r\nCSeq: 4\r\n\r\n".encode())
            assert b"200 OK" in cli.recv(4096)
    finally:
        srv.stop()

    print("baichuan_talk self-test OK")


if __name__ == "__main__":
    if "--self-test" in sys.argv:
        run_self_test()
