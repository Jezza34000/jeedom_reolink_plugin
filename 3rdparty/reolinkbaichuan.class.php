<?php
/**
 * Reolink Baichuan TCP client (port 9000).
 * Supports Privacy Mode (cmd 574/575), Battery/Sleep queries (cmd 253/145),
 * JPEG Snapshot binary streaming (cmd 109), generic XML command transport,
 * and read-only anonymized XML diagnostic export for port-9000-only cameras (e.g. E321).
 * Protocol mirrored from starkillerOG/reolink_aio.
 */
class reolinkBaichuan {
  const HEADER_MAGIC = 'f0debc0a';
  const AES_IV = '0123456789abcdef';
  const DEFAULT_PORT = 9000;
  const XML_KEY = array(0x1F, 0x2D, 0x3C, 0x4B, 0x5A, 0x69, 0x78, 0xFF);

  private $host;
  private $port;
  private $username;
  private $password;
  private $fp = null;
  private $nonce = null;
  private $aesKey = null;
  private $messId = 0;
  private $loggedIn = false;
  private $recvBuf = '';
  private $connectTimeout;
  private $readTimeout;
  public $loginSleep = null;
  public $loginXml = '';
  private $accountDevice = false;
  private $pushEvents = array();

  public function __construct($host, $username, $password, $port = self::DEFAULT_PORT, $connectTimeout = 3, $readTimeout = 6) {
    $this->host = trim(strval($host));
    $this->port = intval($port) > 0 ? intval($port) : self::DEFAULT_PORT;
    $this->username = trim(strval($username));
    $this->password = strval($password);
    $this->connectTimeout = max(1, intval($connectTimeout));
    $this->readTimeout = max(1, intval($readTimeout));
  }

  public function __destruct() {
    if ($this->loggedIn && is_resource($this->fp)) {
      $this->logout();
    } else {
      $this->closeSocket();
    }
  }

  public function getHost() {
    return $this->host;
  }

  public function getPort() {
    return $this->port;
  }

  public function getUsername() {
    return $this->username;
  }

  public function isLoggedIn() {
    return $this->loggedIn && is_resource($this->fp);
  }

  public function isAccountDevice() {
    return $this->accountDevice;
  }

  public function popPushEvents() {
    $ev = $this->pushEvents;
    $this->pushEvents = array();
    return $ev;
  }

  public static function md5Modern($string) {
    return strtoupper(substr(md5($string, false), 0, 31));
  }

  public static function xorBaichuan($buf, $offset) {
    $bufLen = strlen($buf);
    if ($bufLen === 0) {
      return '';
    }
    $keyLen = count(self::XML_KEY);
    $key = '';
    for ($i = 0; $i < $keyLen; $i++) {
      $key .= chr(self::XML_KEY[($offset + $i) % $keyLen] ^ $offset);
    }
    $keystream = substr(str_repeat($key, intval($bufLen / $keyLen) + 1), 0, $bufLen);
    $out = '';
    for ($i = 0; $i < $bufLen; $i++) {
      $out .= chr(ord($buf[$i]) ^ ord($keystream[$i]));
    }
    return $out;
  }

  private function aesCrypt($data, $encrypt) {
    if ($data === '' || $data === null) {
      return '';
    }
    if ($this->aesKey === null) {
      throw new Exception('Baichuan AES key missing (login first)');
    }
    $method = 'aes-128-cfb';
    if ($encrypt) {
      $out = openssl_encrypt($data, $method, $this->aesKey, OPENSSL_RAW_DATA, self::AES_IV);
    } else {
      $out = openssl_decrypt($data, $method, $this->aesKey, OPENSSL_RAW_DATA, self::AES_IV);
    }
    if ($out === false) {
      throw new Exception('Baichuan AES crypt failed');
    }
    return $out;
  }

  private function connect() {
    if (is_resource($this->fp)) {
      return;
    }
    $errno = 0;
    $errstr = '';
    $this->fp = @fsockopen($this->host, $this->port, $errno, $errstr, $this->connectTimeout);
    if (!is_resource($this->fp)) {
      throw new Exception('Baichuan TCP connect failed: ' . $errstr . ' (' . $errno . ')');
    }
    stream_set_timeout($this->fp, $this->readTimeout);
    $this->recvBuf = '';
  }

  private function closeSocket() {
    if (is_resource($this->fp)) {
      @fclose($this->fp);
    }
    $this->fp = null;
    $this->recvBuf = '';
    $this->loggedIn = false;
    $this->nonce = null;
    $this->aesKey = null;
    $this->accountDevice = false;
  }

  private function nextMessId($chId) {
    $this->messId = ($this->messId + 1) % 16777216;
    return ($this->messId << 8) + ($chId & 0xFF);
  }

  private function buildHeader($cmdId, $messLen, $fullMessId, $messageClass, $payloadOffset = 0) {
    $cmd = pack('V', $cmdId);
    $len = pack('V', $messLen);
    $mid = pack('V', $fullMessId);
    $magic = hex2bin(self::HEADER_MAGIC);
    if ($messageClass === '1465') {
      return $magic . $cmd . $len . $mid . hex2bin('12dc1465');
    }
    return $magic . $cmd . $len . $mid . hex2bin('00001464') . pack('V', $payloadOffset);
  }

  private function writePacket($cmdId, $channel, $bodyXml, $extXml, $encType, $messageClass) {
    $this->connect();
    if ($channel === null) {
      $chId = 250;
    } else {
      $chId = intval($channel) + 1;
    }
    $fullMessId = $this->nextMessId($chId);
    $bodyBytes = $bodyXml === '' ? '' : $bodyXml;
    $extBytes = $extXml === '' ? '' : $extXml;
    $extLen = strlen($extBytes);
    $messLen = $extLen + strlen($bodyBytes);
    $payloadOffset = $extLen;

    $encBody = '';
    if ($messLen > 0) {
      if ($encType === 'bc') {
        $encBody = self::xorBaichuan($extBytes, $chId) . self::xorBaichuan($bodyBytes, $chId);
      } else {
        $encBody = $this->aesCrypt($extBytes, true) . $this->aesCrypt($bodyBytes, true);
      }
    }

    $header = $this->buildHeader($cmdId, $messLen, $fullMessId, $messageClass, $payloadOffset);
    $packet = $header . $encBody;
    $total = strlen($packet);
    $sent = 0;
    while ($sent < $total) {
      $written = @fwrite($this->fp, substr($packet, $sent));
      if ($written === false || $written === 0) {
        throw new Exception('Baichuan write failed for cmd_id ' . $cmdId);
      }
      $sent += $written;
    }
    return $fullMessId;
  }

  private function sendRaw($cmdId, $channel, $bodyXml, $extXml, $encType, $messageClass) {
    $fullMessId = $this->writePacket($cmdId, $channel, $bodyXml, $extXml, $encType, $messageClass);
    return $this->recvMatching($cmdId, $fullMessId, $encType);
  }

  /**
   * Send a command that streams a binary payload (e.g. cmd 109 JPEG Snapshot).
   * @return array{xml:string,payload:string}
   */
  public function sendPayload($cmdId, $channel, $bodyXml = '', $extXml = '') {
    $this->ensureLogin();
    if ($extXml === '' && $channel !== null) {
      $extXml = $this->channelExtension($channel);
    }
    $fullMessId = $this->writePacket($cmdId, $channel, $bodyXml, $extXml, 'aes', '1464');
    $deadline = microtime(true) + max(8, $this->readTimeout);
    $xmlResp = '';
    $expectedSize = null;
    $chunks = '';

    while (microtime(true) < $deadline) {
      $parsed = $this->tryParseOne('aes');
      if ($parsed === null) {
        $left = $deadline - microtime(true);
        if ($left <= 0) {
          break;
        }
        $read = array($this->fp);
        $write = null;
        $except = null;
        $sec = max(0, intval($left));
        $usec = max(0, intval(($left - $sec) * 1000000));
        $n = @stream_select($read, $write, $except, $sec, $usec);
        if ($n === false || $n === 0) {
          continue;
        }
        $chunk = @fread($this->fp, 32768);
        if ($chunk === false || $chunk === '') {
          throw new Exception('Baichuan connection closed during payload cmd_id ' . $cmdId);
        }
        $this->recvBuf .= $chunk;
        continue;
      }

      list($recCmdId, $recMessId, $status, $body, $payloadBin) = $parsed;
      if ($recCmdId === intval($cmdId) && $recMessId === intval($fullMessId)) {
        if ($status !== null && !in_array($status, array(200, 201, 300), true)) {
          throw new Exception('Baichuan status ' . $status . ' for cmd_id ' . $cmdId);
        }
        if ($body !== '' && $xmlResp === '') {
          $xmlResp = $body;
          $picSize = $this->xmlValue($xmlResp, 'pictureSize');
          if ($picSize !== null && is_numeric($picSize) && intval($picSize) > 0) {
            $expectedSize = intval($picSize);
          }
        }
        if ($payloadBin !== '') {
          $chunks .= $payloadBin;
          if ($expectedSize !== null && strlen($chunks) >= $expectedSize) {
            // Drain trailing 0-byte end-of-payload marker if already buffered
            $peek = $this->tryParseOne('aes');
            if ($peek !== null && ($peek[0] !== intval($cmdId) || $peek[1] !== intval($fullMessId))) {
              $this->recordPushIfRelevant($peek[0], $peek[3]);
            }
            return array('xml' => $xmlResp, 'payload' => substr($chunks, 0, $expectedSize));
          }
        } elseif ($xmlResp !== '' && strlen($chunks) > 0) {
          return array('xml' => $xmlResp, 'payload' => $chunks);
        }
        continue;
      }
      $this->recordPushIfRelevant($recCmdId, $body);
    }

    if ($chunks !== '') {
      return array('xml' => $xmlResp, 'payload' => $chunks);
    }
    throw new Exception('Baichuan timeout waiting for binary payload cmd_id ' . $cmdId);
  }

  private function recordPushIfRelevant($recCmdId, $body) {
    if ($body !== '') {
      if ($recCmdId === 33) {
        $this->pushEvents[] = array('cmdId' => 33, 'xml' => $body, 'ts' => time());
      }
      if ($recCmdId === 623 || strpos($body, '<sleep>') !== false) {
        log::add('reolink', 'debug', 'Baichuan push cmd_id=' . $recCmdId . ' xml=' . preg_replace('/\s+/', ' ', trim($body)));
      }
    }
  }

  private function recvMatching($cmdId, $fullMessId, $encType) {
    $deadline = microtime(true) + $this->readTimeout;
    while (microtime(true) < $deadline) {
      $parsed = $this->tryParseOne($encType);
      if ($parsed === null) {
        $left = $deadline - microtime(true);
        if ($left <= 0) {
          break;
        }
        $read = array($this->fp);
        $write = null;
        $except = null;
        $sec = max(0, intval($left));
        $usec = max(0, intval(($left - $sec) * 1000000));
        $n = @stream_select($read, $write, $except, $sec, $usec);
        if ($n === false || $n === 0) {
          continue;
        }
        $chunk = @fread($this->fp, 16384);
        if ($chunk === false || $chunk === '') {
          throw new Exception('Baichuan connection closed while waiting for cmd_id ' . $cmdId);
        }
        $this->recvBuf .= $chunk;
        continue;
      }

      list($recCmdId, $recMessId, $status, $body) = $parsed;
      if ($recCmdId === intval($cmdId) && $recMessId === intval($fullMessId)) {
        if ($status !== null && !in_array($status, array(200, 201, 300), true)) {
          throw new Exception('Baichuan status ' . $status . ' for cmd_id ' . $cmdId);
        }
        return $body;
      }
      $this->recordPushIfRelevant($recCmdId, $body);
    }
    throw new Exception('Baichuan timeout waiting for cmd_id ' . $cmdId);
  }

  public function subscribeEvents($drainSec = 0.2) {
    $this->ensureLogin();
    // channel = 250 maps to wire chId = 251 (START_ALARM_REPORT / cmd 31)
    $this->sendRaw(31, 250, '', '', 'aes', '1464');
    $this->drainPushes($drainSec);
  }

  public function drainPushes($durationSec) {
    if (!is_resource($this->fp)) {
      return;
    }
    $deadline = microtime(true) + max(0.05, floatval($durationSec));
    while (microtime(true) < $deadline) {
      $parsed = $this->tryParseOne('aes');
      if ($parsed !== null) {
        list($recCmdId, $recMessId, $status, $body) = $parsed;
        $this->recordPushIfRelevant($recCmdId, $body);
        continue;
      }
      $left = $deadline - microtime(true);
      if ($left <= 0) {
        break;
      }
      $read = array($this->fp);
      $write = null;
      $except = null;
      $sec = max(0, intval($left));
      $usec = max(0, intval(($left - $sec) * 1000000));
      $n = @stream_select($read, $write, $except, $sec, $usec);
      if ($n === false || $n === 0) {
        continue;
      }
      $chunk = @fread($this->fp, 8192);
      if ($chunk === false || $chunk === '') {
        break;
      }
      $this->recvBuf .= $chunk;
    }
  }

  /**
   * @return array{0:int,1:int,2:?int,3:string,4:string}|null
   */
  private function tryParseOne($encTypeHint) {
    $buf = $this->recvBuf;
    $dataLen = strlen($buf);
    if ($dataLen < 4) {
      return null;
    }
    if (bin2hex(substr($buf, 0, 4)) !== self::HEADER_MAGIC) {
      $pos = strpos($buf, hex2bin(self::HEADER_MAGIC));
      if ($pos === false) {
        $this->recvBuf = substr($buf, -3);
        return null;
      }
      $this->recvBuf = substr($buf, $pos);
      $buf = $this->recvBuf;
      $dataLen = strlen($buf);
    }
    if ($dataLen < 20) {
      return null;
    }

    $recCmdId = unpack('V', substr($buf, 4, 4))[1];
    $recLenBody = unpack('V', substr($buf, 8, 4))[1];
    $recMessId = unpack('V', substr($buf, 12, 4))[1];
    $messClass = bin2hex(substr($buf, 18, 2));
    $lenHeader = 20;
    $status = null;
    $hasOffsetField = false;
    $rawPayloadOffset = 0;

    if ($messClass === '1464' || $messClass === '0000') {
      $lenHeader = 24;
      if ($dataLen < 24) {
        return null;
      }
      $status = unpack('v', substr($buf, 16, 2))[1];
      $rawPayloadOffset = unpack('V', substr($buf, 20, 4))[1];
      $hasOffsetField = true;
    } elseif ($messClass === '1465' || $messClass === '1466') {
      $lenHeader = 20;
    } else {
      $this->recvBuf = substr($buf, 4);
      return null;
    }

    $lenChunk = $recLenBody + $lenHeader;
    if ($dataLen < $lenChunk) {
      return null;
    }

    // Determine XML body vs attached binary payload (e.g. cmd 109 JPEG snapshot chunks):
    // - XML-only response: rawPayloadOffset == 0 (or >= recLenBody) and starts with encrypted XML
    // - XML + binary in single frame: 0 < rawPayloadOffset < recLenBody
    // - Pure binary continuation frame: status==200, rawPayloadOffset==0, body is raw binary (not <?xml)
    $fullFrame = substr($buf, 0, $lenChunk);
    $this->recvBuf = substr($buf, $lenChunk);

    if ($status !== null && !in_array($status, array(200, 201, 300), true)) {
      return array($recCmdId, $recMessId, $status, '', '');
    }

    if ($recLenBody === 0) {
      return array($recCmdId, $recMessId, $status, '', '');
    }

    $headerChunk = substr($fullFrame, 0, $lenHeader);
    $allData = substr($fullFrame, $lenHeader);

    if ($hasOffsetField && $rawPayloadOffset > 0 && $rawPayloadOffset < $recLenBody) {
      $encXml = substr($allData, 0, $rawPayloadOffset);
      $payloadBin = substr($allData, $rawPayloadOffset);
      $body = $this->decryptBody($encXml, $headerChunk, $lenHeader, $recCmdId, $encTypeHint);
      if ($payloadBin !== '' && $body !== '' && preg_match('/<encryptLen>(\d+)<\/encryptLen>/i', $body, $m)) {
        $encLen = intval($m[1]);
        if ($encLen > 0 && $encLen <= strlen($payloadBin)) {
          $payloadBin = $this->aesCrypt(substr($payloadBin, 0, $encLen), false) . substr($payloadBin, $encLen);
        }
      }
      return array($recCmdId, $recMessId, $status, $body, $payloadBin);
    }

    $body = $this->decryptBody($allData, $headerChunk, $lenHeader, $recCmdId, $encTypeHint);
    // Pure binary stream chunk (e.g. cmd 109 JPEG data packet where rawPayloadOffset == 0)
    if ($recCmdId === 109 && strpos($body, '<?xml') !== 0) {
      return array($recCmdId, $recMessId, $status, '', $allData);
    }

    return array($recCmdId, $recMessId, $status, $body, '');
  }

  private function decryptBody($encBody, $headerChunk, $lenHeader, $cmdId, $encTypeHint) {
    if ($encBody === '') {
      return '';
    }
    $recEncOffset = ord($headerChunk[12]);
    $recEncType = ($lenHeader === 20) ? bin2hex(substr($headerChunk, 16, 2)) : '';

    $recBody = '';
    if (($lenHeader === 20 && in_array($recEncType, array('01dd', '12dd'), true)) || $encTypeHint === 'bc') {
      $recBody = self::xorBaichuan($encBody, $recEncOffset);
    } elseif (($lenHeader === 20 && in_array($recEncType, array('02dd', '03dd'), true)) || ($lenHeader === 24 && $encTypeHint === 'aes')) {
      try {
        $recBody = $this->aesCrypt($encBody, false);
      } catch (Exception $e) {
        $recBody = self::xorBaichuan($encBody, $recEncOffset);
      }
    } elseif ($recEncType === '00dd') {
      $recBody = $encBody;
    } else {
      try {
        if ($this->aesKey !== null) {
          $recBody = $this->aesCrypt($encBody, false);
        }
      } catch (Exception $e) {
        $recBody = '';
      }
      if ($recBody === '' || strpos($recBody, '<?xml') !== 0) {
        $recBody = self::xorBaichuan($encBody, $recEncOffset);
      }
    }

    if (strpos($recBody, '<?xml') !== 0) {
      $alt = self::xorBaichuan($encBody, $recEncOffset);
      if (strpos($alt, '<?xml') === 0) {
        return $alt;
      }
    }
    return $recBody;
  }

  public function channelExtension($channel) {
    return '<?xml version="1.0" encoding="UTF-8" ?>' . "\n"
      . '<Extension version="1.1">' . "\n"
      . '<channelId>' . intval($channel) . '</channelId>' . "\n"
      . '</Extension>' . "\n";
  }

  public function openSession() {
    $this->ensureLogin();
    return $this->loggedIn;
  }

  public function requestXml($cmdId, $channel = null, $bodyXml = '', $extXml = '') {
    $this->ensureLogin();
    if ($extXml === '' && $channel !== null) {
      $extXml = $this->channelExtension($channel);
    }
    return $this->sendRaw($cmdId, $channel, $bodyXml, $extXml, 'aes', '1464');
  }

  /**
   * Capture a JPEG image via Baichuan cmd 109 (works when HTTP/RTSP are disabled or absent).
   */
  public function snapshot($channel = 0, $quality = 'sub') {
    $ch = intval($channel);
    $streams = ($quality === 'main') ? array('main', 'sub') : array('sub', 'main');
    $lastErr = '';
    foreach ($streams as $st) {
      try {
        $body = '<?xml version="1.0" encoding="UTF-8" ?>' . "\n"
          . '<body>' . "\n"
          . '<Snap version="1.1">' . "\n"
          . '<channelId>' . $ch . '</channelId>' . "\n"
          . '<logicChannel>0</logicChannel>' . "\n"
          . '<time>0</time>' . "\n"
          . '<fullFrame>0</fullFrame>' . "\n"
          . '<streamType>' . $st . '</streamType>' . "\n"
          . '</Snap>' . "\n"
          . '</body>';
        $res = $this->sendPayload(109, $ch, $body, $this->channelExtension($ch));
        $img = $res['payload'] ?? '';
        if (strlen($img) > 128 && substr($img, 0, 2) === "\xFF\xD8") {
          return $img;
        }
        $lastErr = 'Invalid JPEG header (' . strlen($img) . ' bytes)';
      } catch (Exception $e) {
        $lastErr = $e->getMessage();
      }
    }
    if ($lastErr !== '') {
      log::add('reolink', 'debug', 'Baichuan snapshot ch=' . $ch . ' failed: ' . $lastErr);
    }
    return '';
  }

  private function ensureLogin() {
    if ($this->loggedIn && is_resource($this->fp)) {
      return;
    }
    $this->loggedIn = false;
    $this->connect();

    $nonceXml = $this->sendRaw(1, null, '', '', 'bc', '1465');
    if ($this->xmlValue($nonceXml, 'sigVer') === 'v3') {
      $this->accountDevice = true;
    }
    $nonce = $this->xmlValue($nonceXml, 'nonce');
    if ($nonce === null || $nonce === '') {
      if ($this->accountDevice) {
        throw new Exception('Baichuan Account Device (sigVer=v3): login local bloqué');
      }
      throw new Exception('Baichuan nonce missing');
    }
    $this->nonce = $nonce;
    $this->aesKey = self::md5Modern($nonce . '-' . $this->password);
    $this->aesKey = substr($this->aesKey, 0, 16);

    $userHash = self::md5Modern($this->username . $nonce);
    $passHash = self::md5Modern($this->password . $nonce);
    $loginXml = '<?xml version="1.0" encoding="UTF-8" ?>' . "\n"
      . '<body>' . "\n"
      . '<LoginUser version="1.1">' . "\n"
      . '<userName>' . $userHash . '</userName>' . "\n"
      . '<password>' . $passHash . '</password>' . "\n"
      . '<userVer>1</userVer>' . "\n"
      . '</LoginUser>' . "\n"
      . '<LoginNet version="1.1">' . "\n"
      . '<type>LAN</type>' . "\n"
      . '<udpPort>0</udpPort>' . "\n"
      . '</LoginNet>' . "\n"
      . '</body>';

    try {
      $mess = $this->sendRaw(1, null, $loginXml, '', 'bc', '1464');
    } catch (Exception $e) {
      if ($this->accountDevice || strpos($e->getMessage(), 'status 406') !== false) {
        throw new Exception('Baichuan Account Device (sigVer=v3): login local bloqué (406)');
      }
      throw $e;
    }
    $this->loggedIn = true;
    $this->loginXml = $mess;
    $sleep = $this->xmlValue($mess, 'sleep');
    $this->loginSleep = ($sleep === null || $sleep === '') ? null : $sleep;
  }

  public function logout() {
    try {
      if ($this->loggedIn && is_resource($this->fp)) {
        $xml = '<?xml version="1.0" encoding="UTF-8" ?>' . "\n"
          . '<body>' . "\n"
          . '<LoginUser version="1.1">' . "\n"
          . '<userVer>1</userVer>' . "\n"
          . '</LoginUser>' . "\n"
          . '</body>';
        try {
          $this->sendRaw(2, null, $xml, '', 'aes', '1464');
        } catch (Exception $e) {
        }
      }
    } finally {
      $this->closeSocket();
    }
  }

  public function xmlValue($xml, $key) {
    if ($xml === null || $xml === '') {
      return null;
    }
    try {
      $sx = @simplexml_load_string($xml);
      if ($sx === false) {
        return null;
      }
      $nodes = $sx->xpath('.//' . $key);
      if (!$nodes || !isset($nodes[0])) {
        return null;
      }
      return strval($nodes[0]);
    } catch (Exception $e) {
      return null;
    }
  }

  private function remoteAbilityForChannel($abilitiesXml, $channel) {
    if ($abilitiesXml === null || $abilitiesXml === '') {
      return 0;
    }
    try {
      $sx = @simplexml_load_string($abilitiesXml);
      if ($sx === false) {
        return 0;
      }
      $ch = intval($channel);
      foreach ($sx->xpath('.//item') as $item) {
        $chn = isset($item->chnID) ? intval($item->chnID) : null;
        if ($chn === $ch && isset($item->remoteAbility)) {
          return intval($item->remoteAbility);
        }
        foreach ($item->xpath('./subItem') as $sub) {
          $subCh = isset($sub->chnID) ? intval($sub->chnID) : null;
          if ($subCh === $ch && isset($sub->remoteAbility)) {
            return intval($sub->remoteAbility);
          }
        }
      }
      foreach ($sx->xpath('.//subItem') as $sub) {
        $subCh = isset($sub->chnID) ? intval($sub->chnID) : null;
        if ($subCh === $ch && isset($sub->remoteAbility)) {
          return intval($sub->remoteAbility);
        }
      }
      $host = $sx->xpath('.//remoteAbility');
      if ($host && isset($host[0])) {
        return intval($host[0]);
      }
    } catch (Exception $e) {
      return 0;
    }
    return 0;
  }

  private function parseSleep($xml) {
    $sleep = $this->xmlValue($xml, 'sleep');
    if ($sleep === null || $sleep === '') {
      return null;
    }
    return intval($sleep) === 1;
  }

  public function probePrivacySupport($channel, $isNvr) {
    try {
      $this->ensureLogin();
      if (!$isNvr && $this->loginSleep === null) {
        return null;
      }
      $abilities = $this->sendRaw(199, null, '', '', 'aes', '1464');
      if ($this->remoteAbilityForChannel($abilities, $channel) <= 0) {
        return null;
      }
      $stateXml = $this->sendRaw(574, $channel, '', $this->channelExtension($channel), 'aes', '1464');
      return $this->parseSleep($stateXml);
    } catch (Exception $e) {
      log::add('reolink', 'debug', 'Baichuan probePrivacySupport: ' . $e->getMessage());
      return null;
    } finally {
      $this->logout();
    }
  }

  public function getPrivacyMode($channel) {
    try {
      $this->ensureLogin();
      $stateXml = $this->sendRaw(574, $channel, '', $this->channelExtension($channel), 'aes', '1464');
      return $this->parseSleep($stateXml);
    } catch (Exception $e) {
      log::add('reolink', 'debug', 'Baichuan getPrivacyMode: ' . $e->getMessage());
      return null;
    } finally {
      $this->logout();
    }
  }

  /**
   * Read NetPort enables (cmd 37). Keys: http|https|rtsp|rtmp|onvif|server => bool.
   * @return array<string,bool>
   */
  public function getPorts() {
    $out = array();
    $xml = $this->sendRaw(37, null, '', '', 'aes', '1464');
    if ($xml === null || $xml === '') {
      return $out;
    }
    $sx = @simplexml_load_string($xml);
    if ($sx === false) {
      return $out;
    }
    foreach ($sx->children() as $protoNode) {
      $tag = strtolower(strval($protoNode->getName()));
      $proto = preg_replace('/port$/', '', $tag);
      if ($proto === '') {
        continue;
      }
      $enable = null;
      if (isset($protoNode->enable)) {
        $enable = intval($protoNode->enable) === 1;
      }
      if ($enable !== null) {
        $out[$proto] = $enable;
      }
    }
    return $out;
  }

  /** Enable/disable a NetPort via cmd 36 (HttpPort|HttpsPort|RtspPort|RtmpPort|OnvifPort). */
  public function setPortEnabled($proto, $enable) {
    $proto = strtolower(strval($proto));
    $map = array(
      'http' => 'HttpPort',
      'https' => 'HttpsPort',
      'rtsp' => 'RtspPort',
      'rtmp' => 'RtmpPort',
      'onvif' => 'OnvifPort',
    );
    if (!isset($map[$proto])) {
      throw new Exception('Baichuan setPortEnabled: proto inconnu ' . $proto);
    }
    $tag = $map[$proto];
    $enableStr = $enable ? '1' : '0';
    $body = '<?xml version="1.0" encoding="UTF-8" ?>' . "\n"
      . '<body>' . "\n"
      . '<' . $tag . ' version="1.1">' . "\n"
      . '<enable>' . $enableStr . '</enable>' . "\n"
      . '</' . $tag . '>' . "\n"
      . '</body>';
    $this->sendRaw(36, null, $body, '', 'aes', '1464');
  }

  /**
   * Set privacy sleep (cmd 575) then poll cmd 574 (like reolink_aio).
   */
  public function setPrivacyMode($channel, $enable) {
    try {
      $this->ensureLogin();
      $enableStr = $enable ? '1' : '0';
      $body = "\n" . '<?xml version="1.0" encoding="UTF-8" ?>' . "\n"
        . '<body>' . "\n"
        . '<sleepState version="1.1">' . "\n"
        . '<operate>2</operate>' . "\n"
        . '<sleep>' . $enableStr . '</sleep>' . "\n"
        . '</sleepState>' . "\n"
        . '</body>';
      $this->sendRaw(575, $channel, $body, $this->channelExtension($channel), 'aes', '1464');
      log::add('reolink', 'debug', 'Baichuan cmd 575 sleep=' . $enableStr . ' canal=' . intval($channel));

      $state = null;
      for ($i = 0; $i < 4; $i++) {
        if ($i > 0) {
          usleep(400000);
        }
        $stateXml = $this->sendRaw(574, $channel, '', $this->channelExtension($channel), 'aes', '1464');
        $state = $this->parseSleep($stateXml);
        $modeVal = $this->xmlValue($stateXml, 'mode');
        $opVal = $this->xmlValue($stateXml, 'operate');
        $panVal = $this->xmlValue($stateXml, 'panPos');
        $tiltVal = $this->xmlValue($stateXml, 'tiltPos');
        log::add(
          'reolink',
          'debug',
          'Baichuan cmd 574 poll#' . ($i + 1)
            . ' sleep=' . ($state === null ? 'null' : ($state ? '1' : '0'))
            . ' mode=' . ($modeVal ?? '-')
            . ' operate=' . ($opVal ?? '-')
            . ' pan=' . ($panVal ?? '-')
            . ' tilt=' . ($tiltVal ?? '-')
        );
        if ($state !== null && $state === (bool) $enable) {
          break;
        }
      }
      if ($state === null || $state !== (bool) $enable) {
        log::add('reolink', 'error', 'Baichuan setPrivacyMode: état 574 non aligné (attendu ' . $enableStr . ')');
        return false;
      }

      if (!$enable) {
        $this->drainPushes(2.2);
      }
      return true;
    } catch (Exception $e) {
      log::add('reolink', 'error', 'Baichuan setPrivacyMode: ' . $e->getMessage());
      return false;
    } finally {
      $this->logout();
    }
  }

  public static function parseBatteryXml($xml, $channel = 0) {
    if ($xml === null || $xml === '') {
      return null;
    }
    $sx = @simplexml_load_string($xml);
    if ($sx === false) {
      return null;
    }
    $nodes = $sx->xpath('.//BatteryInfo');
    if (!$nodes || empty($nodes)) {
      return null;
    }
    $target = null;
    foreach ($nodes as $node) {
      if (isset($node->channelId)) {
        if (intval($node->channelId) === intval($channel)) {
          $target = $node;
          break;
        }
      } elseif ($target === null) {
        $target = $node;
      }
    }
    if ($target === null || !isset($target->batteryPercent)) {
      return null;
    }
    $chargeRaw = isset($target->chargeStatus) ? strtolower(trim(strval($target->chargeStatus))) : 'discharging';
    if ($chargeRaw === 'none' || $chargeRaw === '0' || $chargeRaw === 'discharging') {
      $chargeStatus = 0;
    } elseif ($chargeRaw === '1' || $chargeRaw === 'charging') {
      $chargeStatus = 1;
    } elseif ($chargeRaw === '2' || $chargeRaw === 'chargecomplete') {
      $chargeStatus = 2;
    } else {
      $chargeStatus = 0;
    }
    $out = array(
      'batteryPercent' => max(0, min(100, intval($target->batteryPercent))),
      'chargeStatus' => $chargeStatus,
    );
    if (isset($target->temperature) && trim(strval($target->temperature)) !== '') {
      $out['temperature'] = intval($target->temperature);
    }
    if (isset($target->adapterStatus)) {
      $out['adapterStatus'] = strval($target->adapterStatus);
    }
    if (isset($target->lowPower)) {
      $out['lowPower'] = intval($target->lowPower);
    }
    return $out;
  }

  public static function parseChannelSleepXml($xml, $channel = 0) {
    if ($xml === null || $xml === '') {
      return null;
    }
    $sx = @simplexml_load_string($xml);
    if ($sx === false) {
      return null;
    }
    $nodes = $sx->xpath('.//ChannelInfo');
    if (!$nodes || empty($nodes)) {
      return null;
    }
    foreach ($nodes as $node) {
      if (isset($node->channelId) && intval($node->channelId) !== intval($channel)) {
        continue;
      }
      if (isset($node->loginState)) {
        return strtolower(trim(strval($node->loginState))) === 'standby';
      }
    }
    return null;
  }

  /**
   * Read battery info via Baichuan cmd 253 (fallback when HTTP GetBatteryInfo is unavailable).
   * @return array<string,mixed>|null
   */
  public function getBatteryInfo($channel) {
    try {
      $this->ensureLogin();
      $xml = $this->sendRaw(253, $channel, '', $this->channelExtension($channel), 'aes', '1464');
      return self::parseBatteryXml($xml, $channel);
    } catch (Exception $e) {
      log::add('reolink', 'debug', 'Baichuan getBatteryInfo: ' . $e->getMessage());
      return null;
    } finally {
      $this->logout();
    }
  }

  /**
   * Read device UID via Baichuan cmd 114 (fallback when HTTP GetP2p is restricted or omitted).
   * @return string|null
   */
  public function getUid() {
    try {
      $this->ensureLogin();
      $xml = $this->sendRaw(114, null, '', '', 'aes', '1464');
      $uid = trim(strval($this->xmlValue($xml, 'uid') ?? ''));
      return ($uid !== '') ? $uid : null;
    } catch (Exception $e) {
      log::add('reolink', 'debug', 'Baichuan getUid: ' . $e->getMessage());
      return null;
    } finally {
      $this->logout();
    }
  }

  public static function parseSceneSupportXml($supportXml) {
    if ($supportXml === null || $supportXml === '') {
      return false;
    }
    if (preg_match('/<sceneModeCfg>\s*(\d+)\s*<\/sceneModeCfg>/i', $supportXml, $m)) {
      return intval($m[1]) > 0;
    }
    return false;
  }

  public static function parseSceneListIdsXml($xml) {
    $ids = array();
    if ($xml === null || $xml === '') {
      return $ids;
    }
    if (preg_match_all('/<id>\s*(-?\d+)\s*<\/id>/i', $xml, $m)) {
      foreach ($m[1] as $rawId) {
        $id = intval($rawId);
        if ($id >= 0 && !in_array($id, $ids, true)) {
          $ids[] = $id;
        }
      }
    }
    sort($ids, SORT_NUMERIC);
    return $ids;
  }

  public static function parseSceneCfgXml($xml) {
    if ($xml === null || $xml === '') {
      return null;
    }
    $sx = @simplexml_load_string($xml);
    if ($sx === false) {
      return null;
    }
    $nodes = $sx->xpath('.//sceneCfg');
    $cfg = ($nodes && !empty($nodes)) ? $nodes[0] : $sx;
    if (!isset($cfg->id)) {
      return null;
    }
    return array(
      'id' => intval($cfg->id),
      'valid' => (intval($cfg->valid ?? 0) === 1),
      'name' => trim(strval($cfg->name ?? '')),
    );
  }

  public static function parseSceneModeXml($xml) {
    if ($xml === null || $xml === '' || stripos($xml, 'sceneModeCfg') === false) {
      return null;
    }
    $sx = @simplexml_load_string($xml);
    if ($sx === false) {
      return null;
    }
    $nodes = $sx->xpath('.//sceneModeCfg');
    if (!$nodes || empty($nodes)) {
      return null;
    }
    $node = $nodes[0];
    if (!isset($node->enable)) {
      return null;
    }
    if (intval($node->enable) !== 1) {
      return -1;
    }
    return isset($node->curSceneId) ? intval($node->curSceneId) : -1;
  }

  public static function buildSceneCfgRequestXml($sceneId) {
    return '<?xml version="1.0" encoding="UTF-8" ?>' . "\n"
      . '<body>' . "\n"
      . '<sceneCfg version="1.1">' . "\n"
      . '<id>' . intval($sceneId) . '</id>' . "\n"
      . '</sceneCfg>' . "\n"
      . '</body>';
  }

  public static function buildSceneModeSetXml($sceneId) {
    $id = intval($sceneId);
    if ($id < 0) {
      return '<?xml version="1.0" encoding="UTF-8" ?>' . "\n"
        . '<body>' . "\n"
        . '<sceneModeCfg version="1.1">' . "\n"
        . '<enable>0</enable>' . "\n"
        . '</sceneModeCfg>' . "\n"
        . '</body>';
    }
    return '<?xml version="1.0" encoding="UTF-8" ?>' . "\n"
      . '<body>' . "\n"
      . '<sceneModeCfg version="1.1">' . "\n"
      . '<enable>1</enable>' . "\n"
      . '<curSceneId>' . $id . '</curSceneId>' . "\n"
      . '</sceneModeCfg>' . "\n"
      . '</body>';
  }

  public function probeSceneSupport() {
    try {
      $this->ensureLogin();
      $xml199 = $this->sendRaw(199, null, '', '', 'aes', '1464');
      if (!self::parseSceneSupportXml($xml199)) {
        return false;
      }
      $this->sendRaw(603, null, '', '', 'aes', '1464');
      return true;
    } catch (Exception $e) {
      log::add('reolink', 'debug', 'Baichuan probeSceneSupport: ' . $e->getMessage());
      return false;
    } finally {
      $this->logout();
    }
  }

  public function getScenes() {
    try {
      $this->ensureLogin();
      $xml603 = $this->sendRaw(603, null, '', '', 'aes', '1464');
      $ids = self::parseSceneListIdsXml($xml603);
      $scenes = array();
      foreach ($ids as $sid) {
        try {
          $xml604 = $this->sendRaw(604, null, self::buildSceneCfgRequestXml($sid), '', 'aes', '1464');
          $parsed = self::parseSceneCfgXml($xml604);
          if (is_array($parsed) && !empty($parsed['valid'])) {
            $scenes[$sid] = ($parsed['name'] !== '') ? $parsed['name'] : ('Scene ' . $sid);
          }
        } catch (Exception $inner) {
          log::add('reolink', 'debug', 'Baichuan sceneCfg id=' . $sid . ': ' . $inner->getMessage());
        }
      }
      $xml601 = $this->sendRaw(601, null, '', '', 'aes', '1464');
      $active = self::parseSceneModeXml($xml601);
      return array(
        'activeSceneId' => ($active !== null) ? $active : -1,
        'scenes' => $scenes,
      );
    } catch (Exception $e) {
      log::add('reolink', 'debug', 'Baichuan getScenes: ' . $e->getMessage());
      return null;
    } finally {
      $this->logout();
    }
  }

  public function getActiveSceneId() {
    try {
      $this->ensureLogin();
      $xml601 = $this->sendRaw(601, null, '', '', 'aes', '1464');
      return self::parseSceneModeXml($xml601);
    } catch (Exception $e) {
      log::add('reolink', 'debug', 'Baichuan getActiveSceneId: ' . $e->getMessage());
      return null;
    } finally {
      $this->logout();
    }
  }

  public function setScene($sceneId) {
    try {
      $this->ensureLogin();
      $this->sendRaw(602, null, self::buildSceneModeSetXml($sceneId), '', 'aes', '1464');
      $xml601 = $this->sendRaw(601, null, '', '', 'aes', '1464');
      $active = self::parseSceneModeXml($xml601);
      return ($active !== null) ? $active : (intval($sceneId) < 0 ? -1 : intval($sceneId));
    } finally {
      $this->logout();
    }
  }

  /**
   * Read channel standby/sleep state via Baichuan cmd 145 (ChannelInfoList).
   * @return bool|null
   */
  public function getChannelSleepState($channel) {
    try {
      $this->ensureLogin();
      $xml = $this->sendRaw(145, null, '', '', 'aes', '1464');
      return self::parseChannelSleepXml($xml, $channel);
    } catch (Exception $e) {
      log::add('reolink', 'debug', 'Baichuan getChannelSleepState: ' . $e->getMessage());
      return null;
    } finally {
      $this->logout();
    }
  }

  /**
   * Redact sensitive tags from Baichuan XML prior to diagnostic export.
   */
  public static function redactSensitiveXml($xml) {
    $xml = strval($xml);
    if ($xml === '') {
      return '';
    }
    $tags = array(
      'password', 'pwd', 'passwd', 'uid', 'serialNumber', 'nonce',
      'userName', 'mac', 'key', 'psk', 'url', 'token',
    );
    $pattern = '/<(' . implode('|', $tags) . ')([^>]*)>([^<]+)<\/\1>/i';
    return preg_replace($pattern, '<$1$2>***REDACTED***</$1>', $xml);
  }

  /**
   * Run a read-only Baichuan audit across all standard host & channel cmd_ids + cmd 109 JPEG snapshot.
   * Safe to share publicly on GitHub issues/PRs (sensitive tags redacted).
   */
  public function runDiagnosticReport($channel = 0, $isChannelChild = false) {
    $ch = intval($channel);
    $t0 = microtime(true);
    $report = array(
      'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
      'port' => $this->port,
      'channel' => $ch,
      'isChannelChild' => (bool) $isChannelChild,
      'loginOk' => false,
      'loginMs' => 0,
      'loginSleep' => null,
      'accountDevice' => false,
      'error' => '',
      'summary' => array(),
      'snapshot' => array('ok' => false, 'bytes' => 0, 'validJpeg' => false, 'error' => ''),
      'commands' => array(),
      'xmlReport' => '',
    );

    try {
      $this->ensureLogin();
      $report['loginOk'] = true;
      $report['loginMs'] = intval(round((microtime(true) - $t0) * 1000));
      $report['loginSleep'] = $this->loginSleep;
      $report['accountDevice'] = $this->accountDevice;

      $abilityBody = '<?xml version="1.0" encoding="UTF-8" ?>' . "\n"
        . '<body>' . "\n"
        . '<AbilityInfo version="1.1">' . "\n"
        . '<userName>' . htmlspecialchars($this->username, ENT_XML1) . '</userName>' . "\n"
        . '<module>image, video</module>' . "\n"
        . '</AbilityInfo>' . "\n"
        . '</body>';

      $probes = array(
        array(80, null, 'GetDevInfo (VersionInfo)', ''),
        array(199, null, 'GetAbility (Support)', ''),
        array(151, null, 'GetAbilityInfo (image/video)', $abilityBody),
        array(146, null, 'GetStreamInfoList (Enc ranges)', ''),
        array(37, null, 'GetNetPort', ''),
        array(114, null, 'GetP2p (UID)', ''),
        array(76, null, 'GetLocalLink (NetInfo)', ''),
        array(93, null, 'GetLinkType', ''),
        array(102, null, 'GetHddInfo', ''),
        array(145, null, 'GetChannelInfoList', ''),
        array(601, null, 'GetSceneModeCfg', ''),
        array(603, null, 'GetSceneListID', ''),
        array(318, $ch, 'GetChnTypeInfo', ''),
        array(56, $ch, 'GetEnc (Compression)', ''),
        array(26, $ch, 'GetImage / GetIsp (VideoInput)', ''),
        array(296, $ch, 'GetDayNightThreshold', ''),
        array(44, $ch, 'GetOsd', ''),
        array(208, $ch, 'GetIrLights / GetPowerLed (LedState)', ''),
        array(289, $ch, 'GetWhiteLed (FloodlightTask)', ''),
        array(190, $ch, 'GetPtzPreset', ''),
        array(332, $ch, 'GetPtzGuard', ''),
        array(64, $ch, 'GetPtzPatrol (PtzCruise)', ''),
        array(294, $ch, 'GetZoomFocus', ''),
        array(433, $ch, 'GetPtzCurPos', ''),
        array(224, $ch, 'GetAutoFocus', ''),
        array(46, $ch, 'GetMdAlarm (MD)', ''),
        array(299, $ch, 'GetAiCfg', ''),
        array(264, $ch, 'GetAudioCfg', ''),
        array(212, $ch, 'GetPirInfo', ''),
        array(81, $ch, 'GetRec (Record)', ''),
        array(54, $ch, 'GetRecordCfg (postRec/packTime)', ''),
        array(217, $ch, 'GetEmail (EmailTask)', ''),
        array(219, $ch, 'GetPush (PushTask)', ''),
        array(70, $ch, 'GetFtp (FtpTask)', ''),
        array(232, $ch, 'GetAudioAlarm (AudioTask)', ''),
        array(253, $ch, 'GetBatteryInfo', ''),
        array(574, $ch, 'GetPrivacyMode (sleepState)', ''),
      );

      // Include login response first (contains initial DevInfo/StreamInfo on standalone cams)
      $report['commands'][] = array(
        'cmdId' => 1,
        'scope' => 'host',
        'name' => 'Login Response',
        'ok' => true,
        'status' => 200,
        'bytes' => strlen($this->loginXml),
        'xml' => self::redactSensitiveXml($this->loginXml),
      );

      $okCount = 1;
      $failCount = 0;
      foreach ($probes as $p) {
        list($cid, $targetCh, $label, $bodyXml) = $p;
        $ext = ($targetCh !== null) ? $this->channelExtension($targetCh) : '';
        try {
          $xml = $this->sendRaw($cid, $targetCh, $bodyXml, $ext, 'aes', '1464');
          $clean = self::redactSensitiveXml($xml);
          $report['commands'][] = array(
            'cmdId' => $cid,
            'scope' => ($targetCh === null) ? 'host' : ('ch:' . $targetCh),
            'name' => $label,
            'ok' => true,
            'status' => 200,
            'bytes' => strlen($xml),
            'xml' => $clean,
          );
          $okCount++;
        } catch (Exception $e) {
          $st = 0;
          if (preg_match('/status\s+(\d+)/i', $e->getMessage(), $m)) {
            $st = intval($m[1]);
          }
          $report['commands'][] = array(
            'cmdId' => $cid,
            'scope' => ($targetCh === null) ? 'host' : ('ch:' . $targetCh),
            'name' => $label,
            'ok' => false,
            'status' => $st,
            'bytes' => 0,
            'error' => $e->getMessage(),
            'xml' => '',
          );
          $failCount++;
        }
      }

      // Test binary snapshot cmd 109 (read-only)
      try {
        $jpeg = $this->snapshot($ch, 'sub');
        $len = strlen($jpeg);
        $valid = ($len > 128 && substr($jpeg, 0, 2) === "\xFF\xD8" && substr($jpeg, -2) === "\xFF\xD9");
        $report['snapshot'] = array(
          'ok' => $valid,
          'bytes' => $len,
          'validJpeg' => $valid,
          'headHex' => bin2hex(substr($jpeg, 0, 4)),
          'tailHex' => bin2hex(substr($jpeg, -2)),
          'error' => $valid ? '' : 'Empty or incomplete JPEG',
        );
      } catch (Exception $e) {
        $report['snapshot'] = array(
          'ok' => false,
          'bytes' => 0,
          'validJpeg' => false,
          'error' => $e->getMessage(),
        );
      }

      // Drain 150ms for any spontaneous push (e.g. cmd 33)
      $this->drainPushes(0.15);
      $pushes = $this->popPushEvents();

      $devXml = '';
      $chnXml = '';
      foreach ($report['commands'] as $entry) {
        if ($entry['cmdId'] === 80 && $entry['ok']) {
          $devXml = $entry['xml'];
        }
        if ($entry['cmdId'] === 318 && $entry['ok']) {
          $chnXml = $entry['xml'];
        }
      }
      $report['summary'] = array(
        'hostModel' => $this->xmlValue($devXml, 'type') ?? '',
        'hostFirmVer' => $this->xmlValue($devXml, 'firmwareVersion') ?? '',
        'hostHardVer' => $this->xmlValue($devXml, 'hardwareVersion') ?? '',
        'channelModel' => $this->xmlValue($chnXml, 'type') ?? '',
        'channelFirmVer' => $this->xmlValue($chnXml, 'firmwareVersion') ?? '',
        'commandsOk' => $okCount,
        'commandsRejected' => $failCount,
        'snapshotOk' => $report['snapshot']['ok'],
        'snapshotBytes' => $report['snapshot']['bytes'],
        'pushCount' => count($pushes),
      );

      // Build consolidated XML export
      $xmlLines = array();
      $xmlLines[] = '<?xml version="1.0" encoding="UTF-8"?>';
      $xmlLines[] = '<ReolinkBaichuanDiagnostic generatedAt="' . $report['generatedAt'] . '" port="' . $this->port . '" channel="' . $ch . '" loginMs="' . $report['loginMs'] . '" loginSleep="' . htmlspecialchars(strval($this->loginSleep ?? ''), ENT_XML1) . '">';
      $xmlLines[] = '  <Summary hostModel="' . htmlspecialchars($report['summary']['hostModel'], ENT_XML1) . '" hostFirmVer="' . htmlspecialchars($report['summary']['hostFirmVer'], ENT_XML1) . '" channelModel="' . htmlspecialchars($report['summary']['channelModel'], ENT_XML1) . '" channelFirmVer="' . htmlspecialchars($report['summary']['channelFirmVer'], ENT_XML1) . '" commandsOk="' . $okCount . '" commandsRejected="' . $failCount . '" snapshotOk="' . ($report['snapshot']['ok'] ? '1' : '0') . '" snapshotBytes="' . $report['snapshot']['bytes'] . '" />';
      foreach ($report['commands'] as $entry) {
        $attrs = 'cmdId="' . $entry['cmdId'] . '" scope="' . htmlspecialchars($entry['scope'], ENT_XML1) . '" name="' . htmlspecialchars($entry['name'], ENT_XML1) . '" ok="' . ($entry['ok'] ? '1' : '0') . '" status="' . intval($entry['status']) . '" bytes="' . intval($entry['bytes']) . '"';
        if (!$entry['ok'] || trim($entry['xml']) === '') {
          $errAttr = !empty($entry['error']) ? ' error="' . htmlspecialchars($entry['error'], ENT_XML1) . '"' : '';
          $xmlLines[] = '  <Command ' . $attrs . $errAttr . ' />';
        } else {
          $inner = preg_replace('/^\s*<\?xml[^>]*\?>\s*/i', '', trim($entry['xml']));
          $xmlLines[] = '  <Command ' . $attrs . '>';
          $xmlLines[] = $inner;
          $xmlLines[] = '  </Command>';
        }
      }
      $xmlLines[] = '</ReolinkBaichuanDiagnostic>';
      $report['xmlReport'] = implode("\n", $xmlLines) . "\n";
    } catch (Exception $e) {
      $report['error'] = $e->getMessage();
    } finally {
      $this->logout();
    }
    return $report;
  }

  public static function resolvePort($eqLogic) {
    if (!is_object($eqLogic)) {
      return self::DEFAULT_PORT;
    }
    $bcPort = intval(preg_replace('/\D/', '', strval($eqLogic->getConfiguration('baichuan_port', ''))));
    if ($bcPort > 0) {
      return $bcPort;
    }
    $cnxType = strtolower(trim(strval($eqLogic->getConfiguration('cnxtype', ''))));
    if ($cnxType === 'baichuan') {
      $cfgPort = intval(preg_replace('/\D/', '', strval($eqLogic->getConfiguration('port', ''))));
      if ($cfgPort > 0 && $cfgPort !== 80 && $cfgPort !== 443) {
        return $cfgPort;
      }
    } else {
      $mediaPort = intval(preg_replace('/\D/', '', strval($eqLogic->getConfiguration('mediaPort', ''))));
      if ($mediaPort > 0 && $mediaPort !== 80 && $mediaPort !== 443) {
        return $mediaPort;
      }
    }
    return self::DEFAULT_PORT;
  }

  public static function fromEqLogic($eqLogic) {
    if (!is_object($eqLogic)) {
      return null;
    }
    $host = $eqLogic->getConfiguration('adresseip');
    $user = $eqLogic->getConfiguration('login');
    $pass = $eqLogic->getConfiguration('password');
    if (empty($host) || empty($user) || empty($pass)) {
      return null;
    }
    return new self($host, $user, $pass, self::resolvePort($eqLogic));
  }
}

require_once __DIR__ . '/reolinkbaichuanadapter.class.php';
