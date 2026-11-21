<?php
/**
 * Minimal Reolink Baichuan TCP client (port 9000) for Privacy Mode only.
 * Protocol mirrored from starkillerOG/reolink_aio (cmd 1/2/199/574/575).
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
  private $accountDevice = false;

  public function __construct($host, $username, $password, $port = self::DEFAULT_PORT, $connectTimeout = 3, $readTimeout = 5) {
    $this->host = $host;
    $this->port = intval($port) > 0 ? intval($port) : self::DEFAULT_PORT;
    $this->username = $username;
    $this->password = $password;
    $this->connectTimeout = max(1, intval($connectTimeout));
    $this->readTimeout = max(1, intval($readTimeout));
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

  private function sendRaw($cmdId, $channel, $bodyXml, $extXml, $encType, $messageClass) {
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

    return $this->recvMatching($cmdId, $fullMessId, $encType);
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
        $chunk = @fread($this->fp, 8192);
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
      if ($recCmdId === 623 || strpos($body, '<sleep>') !== false) {
        log::add('reolink', 'debug', 'Baichuan push cmd_id=' . $recCmdId . ' xml=' . preg_replace('/\s+/', ' ', trim($body)));
      }
    }
    throw new Exception('Baichuan timeout waiting for cmd_id ' . $cmdId);
  }

  private function drainPushes($durationSec) {
    if (!is_resource($this->fp)) {
      return;
    }
    $deadline = microtime(true) + max(0.1, floatval($durationSec));
    while (microtime(true) < $deadline) {
      $parsed = $this->tryParseOne('aes');
      if ($parsed !== null) {
        list($recCmdId, $recMessId, $status, $body) = $parsed;
        if ($recCmdId === 623 || strpos($body, '<sleep>') !== false) {
          log::add('reolink', 'debug', 'Baichuan wake push cmd_id=' . $recCmdId . ' xml=' . preg_replace('/\s+/', ' ', trim($body)));
        }
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
   * @return array{0:int,1:int,2:?int,3:string}|null
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
    $recPayloadOffset = $recLenBody;

    if ($messClass === '1464' || $messClass === '0000') {
      $lenHeader = 24;
      if ($dataLen < 24) {
        return null;
      }
      $status = unpack('v', substr($buf, 16, 2))[1];
      $recPayloadOffset = unpack('V', substr($buf, 20, 4))[1];
      if ($recPayloadOffset === 0) {
        $recPayloadOffset = $recLenBody;
      }
    } elseif ($messClass === '1465') {
      $lenHeader = 20;
    } elseif ($messClass === '1466') {
      $lenHeader = 20;
    } else {
      $this->recvBuf = substr($buf, 4);
      return null;
    }

    $lenChunk = $recLenBody + $lenHeader;
    if ($dataLen < $lenChunk) {
      return null;
    }

    $chunk = substr($buf, 0, $lenHeader + $recPayloadOffset);
    $this->recvBuf = substr($buf, $lenChunk);

    if ($status !== null && !in_array($status, array(200, 201, 300), true)) {
      return array($recCmdId, $recMessId, $status, '');
    }

    $encBody = substr($chunk, $lenHeader);
    $body = $this->decryptBody($encBody, $chunk, $lenHeader, $recCmdId, $encTypeHint);
    return array($recCmdId, $recMessId, $status, $body);
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

  private function channelExtension($channel) {
    return '<?xml version="1.0" encoding="UTF-8" ?>' . "\n"
      . '<Extension version="1.1">' . "\n"
      . '<channelId>' . intval($channel) . '</channelId>' . "\n"
      . '</Extension>' . "\n";
  }

  private function ensureLogin() {
    if ($this->loggedIn) {
      return;
    }
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

  private function xmlValue($xml, $key) {
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
   * Do not touch NetPort (cmd 37/36) or close the Baichuan session immediately on wake:
   * let the camera emit push cmd 623 and start its internal web/RTSP daemons cleanly.
   */
  public function setPrivacyMode($channel, $enable) {
    try {
      $this->ensureLogin();
      $enableStr = $enable ? '1' : '0';
      // Exact match with reolink_aio xmls.SetPrivacyMode
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
    $port = $eqLogic->getConfiguration('baichuan_port', self::DEFAULT_PORT);
    return new self($host, $user, $pass, $port);
  }
}
