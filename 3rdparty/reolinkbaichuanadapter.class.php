<?php
/**
 * Drop-in JSON <-> Baichuan TCP (port 9000) adapter matching reolinkAPI's public contract.
 * Enables full support for Reolink cameras that only expose TCP port 9000 (e.g. E321, E1 Zoom/Pro variants
 * without HTTP/HTTPS/RTSP/ONVIF).
 */
class reolinkBaichuanAdapter {
  public $is_loggedin = false;
  public $probeMode = false;
  public $batteryChannelMode = false;
  public $lastResponseData = null;

  private $bc;
  private $lastError = '';
  private $abilityXmlCache = null;
  private $motionByCh = array();
  private $aiByCh = array();

  public function __construct($cnxinfo) {
    $ip = trim(strval($cnxinfo['adresseIP'] ?? ''));
    $user = trim(strval($cnxinfo['username'] ?? ''));
    $pass = strval($cnxinfo['password'] ?? '');
    $rawPort = intval(preg_replace('/\D/', '', strval($cnxinfo['port'] ?? '')));
    $port = ($rawPort > 0 && $rawPort !== 80 && $rawPort !== 443) ? $rawPort : reolinkBaichuan::DEFAULT_PORT;

    $this->bc = new reolinkBaichuan($ip, $user, $pass, $port, 4, 6);
    try {
      $this->is_loggedin = $this->bc->openSession();
      $this->ingestPushes();
    } catch (Exception $e) {
      $this->is_loggedin = false;
      $this->lastError = $e->getMessage();
      log::add('reolink', 'error', 'Baichuan login NOK (' . $ip . ':' . $port . ') : ' . $e->getMessage());
    }
  }

  public function getBaichuanClient() {
    return $this->bc;
  }

  public function getLastApiError() {
    return $this->lastError;
  }

  public function SendSnap($channel = 0, $quality = 'sub') {
    if (!$this->is_loggedin) {
      return '';
    }
    return $this->bc->snapshot(intval($channel), $quality);
  }

  private function ingestPushes() {
    $this->bc->drainPushes(0.05);
    foreach ($this->bc->popPushEvents() as $ev) {
      if (($ev['cmdId'] ?? 0) !== 33) {
        continue;
      }
      $sx = @simplexml_load_string($ev['xml'] ?? '');
      if ($sx === false) {
        continue;
      }
      foreach ($sx->xpath('.//AlarmEvent') as $ae) {
        $ch = isset($ae->channelId) ? intval($ae->channelId) : (isset($ae->chnID) ? intval($ae->chnID) : 0);
        $statusStr = isset($ae->status) ? strval($ae->status) : '';
        $aiStr = isset($ae->AItype) ? strval($ae->AItype) : '';
        $md = (strpos($statusStr, 'MD') !== false) || (strpos($aiStr, 'other') !== false);
        $this->motionByCh[$ch] = $md ? 1 : 0;
        $aiState = array();
        foreach (array('people', 'vehicle', 'dog_cat', 'face', 'package') as $k) {
          $aiState[$k] = array('alarm_state' => (strpos($aiStr, $k) !== false) ? 1 : 0, 'support' => 1);
        }
        $this->aiByCh[$ch] = $aiState;
      }
    }
  }

  private static function ok($cmd, $value, $range = null) {
    $out = array(
      'cmd' => $cmd,
      'code' => 0,
      'value' => $value,
    );
    if ($range !== null) {
      $out['range'] = $range;
    }
    return $out;
  }

  private static function okWrite($cmd) {
    return array(
      'cmd' => $cmd,
      'code' => 0,
      'value' => array('rspCode' => 200),
    );
  }

  private function err($cmd, $rspCode, $detail) {
    $this->lastError = $cmd . ': ' . $detail . ' (rspCode ' . $rspCode . ')';
    return array(
      'cmd' => $cmd,
      'code' => 1,
      'error' => array(
        'rspCode' => intval($rspCode),
        'detail' => strval($detail),
      ),
    );
  }

  private static function mapBaichuanStatusToRsp($msg) {
    if (preg_match('/status\s+(\d+)/i', $msg, $m)) {
      $st = intval($m[1]);
      if ($st === 400 || $st === 404 || $st === 405) {
        return -9; // not support -> pruned cleanly by loadCmdFromConf
      }
      if ($st === 401 || $st === 403) {
        return -7;
      }
      return -13;
    }
    return -9;
  }

  public function SendCMD($jsonCmd) {
    $this->lastError = '';
    if (!$this->is_loggedin) {
      try {
        $this->is_loggedin = $this->bc->openSession();
      } catch (Exception $e) {
        $this->lastError = $e->getMessage();
        return false;
      }
    }

    $reqs = is_array($jsonCmd) ? $jsonCmd : json_decode(strval($jsonCmd), true);
    if (!is_array($reqs)) {
      $this->lastError = 'Invalid JSON payload';
      return false;
    }
    if (isset($reqs['cmd'])) {
      $reqs = array($reqs);
    }

    $this->ingestPushes();
    $out = array();
    foreach ($reqs as $req) {
      if (!is_array($req) || empty($req['cmd'])) {
        continue;
      }
      $cmd = strval($req['cmd']);
      $action = intval($req['action'] ?? 0);
      $param = is_array($req['param'] ?? null) ? $req['param'] : array();
      try {
        $out[] = $this->dispatchOne($cmd, $action, $param);
      } catch (Exception $e) {
        $rsp = self::mapBaichuanStatusToRsp($e->getMessage());
        if (!$this->probeMode && $rsp !== -9) {
          log::add('reolink', 'debug', 'Baichuan SendCMD ' . $cmd . ' error: ' . $e->getMessage());
        }
        $out[] = $this->err($cmd, $rsp, $e->getMessage());
      }
    }
    $hasOk = false;
    foreach ($out as $item) {
      if (is_array($item) && !isset($item['error']['rspCode'])) {
        $hasOk = true;
        break;
      }
    }
    $this->lastResponseData = $out;
    if (!$hasOk) {
      return false;
    }
    return $out;
  }

  private function getAbilitiesXml() {
    if ($this->abilityXmlCache === null) {
      $this->abilityXmlCache = $this->bc->requestXml(199, null);
    }
    return $this->abilityXmlCache;
  }

  /**
   * Parse host and channel Support tags from cached cmd 199 XML.
   * @return array{0: array<string,string>, 1: array<string,string>}
   */
  private function parseSupportTags($ch) {
    $hostTags = array();
    $chTags = array();
    try {
      $xml = $this->getAbilitiesXml();
      $sx = @simplexml_load_string($xml);
      if ($sx !== false && isset($sx->Support)) {
        foreach ($sx->Support->children() as $node) {
          $name = $node->getName();
          if ($name === 'item') {
            $cid = intval($node->chnID ?? -1);
            if ($cid === $ch || ($cid === 0 && empty($chTags))) {
              foreach ($node->children() as $cnode) {
                if ($cnode->getName() !== 'subItem') {
                  $chTags[$cnode->getName()] = strval($cnode);
                }
              }
            }
          } else {
            $hostTags[$name] = strval($node);
          }
        }
      }
    } catch (Exception $e) {
    }
    return array($hostTags, $chTags);
  }

  private static function extractChannel($param) {
    if (isset($param['channel'])) {
      return intval($param['channel']);
    }
    foreach ($param as $v) {
      if (is_array($v)) {
        if (isset($v['channel'])) {
          return intval($v['channel']);
        }
        if (isset($v['schedule']['channel'])) {
          return intval($v['schedule']['channel']);
        }
      }
    }
    return 0;
  }

  private function dispatchOne($cmd, $action, $param) {
    $ch = self::extractChannel($param);

    switch ($cmd) {
      case 'GetDevInfo':
        return $this->handleGetDevInfo();

      case 'GetChnTypeInfo':
        return $this->handleGetChnTypeInfo($ch);

      case 'GetP2P':
      case 'GetP2p':
        return $this->handleGetP2p($cmd);

      case 'GetLocalLink':
        return $this->handleGetLocalLink();

      case 'GetNetPort':
        return $this->handleGetNetPort();

      case 'SetNetPort':
        return $this->handleSetNetPort($param);

      case 'GetRtspUrl':
        return self::ok($cmd, array('rtspUrl' => array('channel' => $ch, 'mainStream' => '', 'subStream' => '')));

      case 'GetChannelstatus':
      case 'GetChannelStatus':
        return $this->handleGetChannelStatus($cmd);

      case 'GetHddInfo':
        return $this->handleGetHddInfo();

      case 'GetAbility':
        return $this->handleGetAbility($ch);

      case 'GetEnc':
        return $this->handleGetEnc($ch, $action);

      case 'SetEnc':
        return $this->handleSetEnc($ch, $param);

      case 'GetImage':
        return $this->handleGetImage($ch);

      case 'SetImage':
        return $this->handleSetImage($ch, $param);

      case 'GetIsp':
        return $this->handleGetIsp($ch);

      case 'SetIsp':
        return $this->handleSetIsp($ch, $param);

      case 'GetOsd':
        return $this->handleGetOsd($ch);

      case 'SetOsd':
        return $this->handleSetOsd($ch, $param);

      case 'GetIrLights':
        return $this->handleGetIrLights($ch);

      case 'SetIrLights':
        return $this->handleSetIrLights($ch, $param);

      case 'GetPowerLed':
        return $this->handleGetPowerLed($ch);

      case 'SetPowerLed':
        return $this->handleSetPowerLed($ch, $param);

      case 'GetWhiteLed':
        return $this->handleGetWhiteLed($ch);

      case 'SetWhiteLed':
        return $this->handleSetWhiteLed($ch, $param);

      case 'PtzCtrl':
        return $this->handlePtzCtrl($ch, $param);

      case 'GetPtzPreset':
        return $this->handleGetPtzPreset($ch);

      case 'GetPtzPatrol':
        return $this->handleGetPtzPatrol($ch);

      case 'GetPtzGuard':
        return $this->handleGetPtzGuard($ch);

      case 'SetPtzGuard':
        return $this->handleSetPtzGuard($ch, $param);

      case 'PtzCheck':
        return $this->handlePtzCheck($ch);

      case 'GetZoomFocus':
        return $this->handleGetZoomFocus($ch, $action);

      case 'StartZoomFocus':
        return $this->handleStartZoomFocus($ch, $param);

      case 'GetAutoFocus':
        return $this->handleGetAutoFocus($ch);

      case 'SetAutoFocus':
        return $this->handleSetAutoFocus($ch, $param);

      case 'AudioAlarmPlay':
        return $this->handleAudioAlarmPlay($ch, $param);

      case 'GetAudioCfg':
        return $this->handleGetAudioCfg($ch);

      case 'SetAudioCfg':
        return $this->handleSetAudioCfg($ch, $param);

      case 'GetMdState':
        return self::ok('GetMdState', array('state' => intval($this->motionByCh[$ch] ?? 0)));

      case 'GetAiState':
        return $this->handleGetAiState($ch);

      case 'GetMdAlarm':
      case 'GetAlarm':
        return $this->handleGetMdAlarm($cmd, $ch);

      case 'SetMdAlarm':
      case 'SetAlarm':
        return $this->handleSetMdAlarm($cmd, $ch, $param);

      case 'GetAiCfg':
        return $this->handleGetAiCfg($ch);

      case 'SetAiCfg':
        return $this->handleSetAiCfg($ch, $param);

      case 'GetAiAlarm':
        return $this->handleGetAiAlarm($ch, $param);

      case 'SetAiAlarm':
        return $this->handleSetAiAlarm($ch, $param);

      case 'GetRec':
      case 'GetRecV20':
        return $this->handleGetRec($cmd, $ch);

      case 'SetRec':
      case 'SetRecV20':
        return $this->handleSetRec($cmd, $ch, $param);

      case 'GetEmail':
      case 'GetEmailV20':
        return $this->handleGetTaskEnable($cmd, 217, $ch, 'Email');

      case 'SetEmail':
      case 'SetEmailV20':
        return $this->handleSetTaskEnable($cmd, 217, 216, $ch, $param, 'Email');

      case 'GetPush':
      case 'GetPushV20':
        return $this->handleGetTaskEnable($cmd, 219, $ch, 'Push');

      case 'SetPush':
      case 'SetPushV20':
        return $this->handleSetTaskEnable($cmd, 219, 218, $ch, $param, 'Push');

      case 'GetFtp':
      case 'GetFtpV20':
        return $this->handleGetTaskEnable($cmd, 70, $ch, 'Ftp');

      case 'SetFtp':
      case 'SetFtpV20':
        return $this->handleSetTaskEnable($cmd, 70, 71, $ch, $param, 'Ftp');

      case 'GetAudioAlarm':
      case 'GetAudioAlarmV20':
        return $this->handleGetTaskEnable($cmd, 232, $ch, 'Audio');

      case 'SetAudioAlarm':
      case 'SetAudioAlarmV20':
        return $this->handleSetTaskEnable($cmd, 232, 231, $ch, $param, 'Audio');

      case 'GetPirInfo':
        return $this->handleGetPirInfo($ch);

      case 'SetPirInfo':
        return $this->handleSetPirInfo($ch, $param);

      case 'GetBatteryInfo':
        return $this->handleGetBatteryInfo($ch);

      case 'Reboot':
        $this->bc->requestXml(23, null);
        return self::okWrite('Reboot');

      default:
        return $this->err($cmd, -9, 'Unsupported command in Baichuan-only mode: ' . $cmd);
    }
  }

  private function handleGetDevInfo() {
    $xml = $this->bc->requestXml(80, null);
    $channelNum = 1;
    try {
      $abXml = $this->getAbilitiesXml();
      $cn = $this->bc->xmlValue($abXml, 'channelNum');
      if ($cn !== null && intval($cn) > 0) {
        $channelNum = intval($cn);
      }
    } catch (Exception $e) {
    }
    if ($this->bc->loginXml !== '') {
      $lcn = $this->bc->xmlValue($this->bc->loginXml, 'analogChnNum');
      if ($lcn === null || intval($lcn) <= 0) {
        $lcn = $this->bc->xmlValue($this->bc->loginXml, 'channelNum');
      }
      if ($lcn !== null && intval($lcn) > 0 && $channelNum === 1) {
        $channelNum = intval($lcn);
      }
    }
    $model = $this->bc->xmlValue($xml, 'type') ?? 'Reolink';
    $isNvr = ($channelNum > 1) || (stripos($model, 'RLN') === 0) || (stripos($model, 'Home Hub') !== false);
    return self::ok('GetDevInfo', array(
      'DevInfo' => array(
        'name' => $this->bc->xmlValue($xml, 'name') ?? $model,
        'model' => $model,
        'type' => $isNvr ? 'NVR' : 'IPC',
        'exactType' => $isNvr ? 'NVR' : 'CAM',
        'channelNum' => $channelNum,
        'serial' => $this->bc->xmlValue($xml, 'serialNumber') ?? '',
        'buildDay' => $this->bc->xmlValue($xml, 'buildDay') ?? '',
        'hardVer' => $this->bc->xmlValue($xml, 'hardwareVersion') ?? '',
        'cfgVer' => $this->bc->xmlValue($xml, 'cfgVersion') ?? '',
        'firmVer' => $this->bc->xmlValue($xml, 'firmwareVersion') ?? '',
        'detail' => $this->bc->xmlValue($xml, 'detail') ?? '',
        'pakSuffix' => $this->bc->xmlValue($xml, 'pakSuffix') ?? '',
      ),
    ));
  }

  private function handleGetChnTypeInfo($ch) {
    $xml = $this->bc->requestXml(318, $ch);
    $info = array(
      'boardInfo' => $this->bc->xmlValue($xml, 'hardwareVersion') ?? '',
      'firmVer' => $this->bc->xmlValue($xml, 'firmwareVersion') ?? '',
      'pakSuffix' => $this->bc->xmlValue($xml, 'pakSuffix') ?? '',
      'typeInfo' => $this->bc->xmlValue($xml, 'type') ?? '',
    );
    // Keep top-level keys matching Reolink JSON API (and ChnTypeInfo alias for safety)
    $info['ChnTypeInfo'] = $info;
    return self::ok('GetChnTypeInfo', $info);
  }

  private function handleGetP2p($cmd) {
    $xml = $this->bc->requestXml(114, null);
    $uid = $this->bc->xmlValue($xml, 'uid') ?? '';
    $enable = $this->bc->xmlValue($xml, 'enable');
    return self::ok($cmd, array(
      'P2p' => array(
        'uid' => $uid,
        'enable' => ($enable !== null) ? intval($enable) : ($uid !== '' ? 1 : 0),
      ),
    ));
  }

  private function handleGetLocalLink() {
    $mac = '';
    $ip = $this->bc->getHost();
    $activeLink = 'LAN';
    try {
      $xml76 = $this->bc->requestXml(76, null);
      $mac = $this->bc->xmlValue($xml76, 'mac') ?? '';
      $ipXml = $this->bc->xmlValue($xml76, 'ip');
      if ($ipXml !== null && $ipXml !== '') {
        $ip = $ipXml;
      }
    } catch (Exception $e) {
    }
    try {
      $xml93 = $this->bc->requestXml(93, null);
      $lt = $this->bc->xmlValue($xml93, 'linkType');
      if ($lt !== null && $lt !== '') {
        $activeLink = $lt;
      }
    } catch (Exception $e) {
    }
    return self::ok('GetLocalLink', array(
      'LocalLink' => array(
        'activeLink' => $activeLink,
        'mac' => $mac,
        'static' => array('ip' => $ip),
      ),
    ));
  }

  private function handleGetNetPort() {
    $netPort = array(
      'httpPort' => 80, 'httpEnable' => 0,
      'httpsPort' => 443, 'httpsEnable' => 0,
      'mediaPort' => $this->bc->getPort(), 'mediaEnable' => 1,
      'onvifPort' => 8000, 'onvifEnable' => 0,
      'rtmpPort' => 1935, 'rtmpEnable' => 0,
      'rtspPort' => 554, 'rtspEnable' => 0,
    );
    try {
      $xml = $this->bc->requestXml(37, null);
      $sx = @simplexml_load_string($xml);
      if ($sx !== false) {
        if (isset($sx->ServerPort)) {
          $netPort['mediaPort'] = intval($sx->ServerPort->serverPort ?? $this->bc->getPort());
          $netPort['mediaEnable'] = intval($sx->ServerPort->enable ?? 1);
        }
        if (isset($sx->HttpPort)) {
          $netPort['httpPort'] = intval($sx->HttpPort->httpPort ?? 80);
          $netPort['httpEnable'] = intval($sx->HttpPort->enable ?? 0);
        }
        if (isset($sx->HttpsPort)) {
          $netPort['httpsPort'] = intval($sx->HttpsPort->httpsPort ?? 443);
          $netPort['httpsEnable'] = intval($sx->HttpsPort->enable ?? 0);
        }
        if (isset($sx->OnvifPort)) {
          $netPort['onvifPort'] = intval($sx->OnvifPort->onvifPort ?? 8000);
          $netPort['onvifEnable'] = intval($sx->OnvifPort->enable ?? 0);
        }
        if (isset($sx->RtmpPort)) {
          $netPort['rtmpPort'] = intval($sx->RtmpPort->rtmpPort ?? 1935);
          $netPort['rtmpEnable'] = intval($sx->RtmpPort->enable ?? 0);
        }
        if (isset($sx->RtspPort)) {
          $netPort['rtspPort'] = intval($sx->RtspPort->rtspPort ?? 554);
          $netPort['rtspEnable'] = intval($sx->RtspPort->enable ?? 0);
        }
      }
    } catch (Exception $e) {
      // Pure port-9000 cameras (e.g. E321) may omit cmd 37 completely; return safe defaults
    }
    return self::ok('GetNetPort', array('NetPort' => $netPort));
  }

  private function handleSetNetPort($param) {
    $np = $param['NetPort'] ?? $param;
    $map = array(
      'httpEnable' => 'http',
      'httpsEnable' => 'https',
      'rtspEnable' => 'rtsp',
      'rtmpEnable' => 'rtmp',
      'onvifEnable' => 'onvif',
    );
    foreach ($map as $key => $proto) {
      if (isset($np[$key])) {
        $this->bc->setPortEnabled($proto, intval($np[$key]) === 1);
      }
    }
    return self::okWrite('SetNetPort');
  }

  private function handleGetChannelStatus($cmd) {
    $status = array();
    try {
      $xml = $this->bc->requestXml(145, null);
      $sx = @simplexml_load_string($xml);
      if ($sx !== false) {
        foreach ($sx->xpath('.//ChannelInfo') as $ci) {
          $cid = intval($ci->channelId ?? 0);
          $ls = strtolower(trim(strval($ci->loginState ?? 'online')));
          $status[] = array(
            'channel' => $cid,
            'name' => strval($ci->name ?? ('Ch' . $cid)),
            'online' => ($ls === 'offline' || $ls === 'none') ? 0 : 1,
            'sleep' => ($ls === 'standby') ? 1 : 0,
            'uid' => strval($ci->uid ?? ''),
          );
        }
      }
    } catch (Exception $e) {
      $chCount = 1;
      try {
        $devXml = $this->bc->requestXml(80, null);
        $chCount = max(1, intval($this->bc->xmlValue($devXml, 'channelNum') ?? 1));
      } catch (Exception $e2) {
        $chCount = 1;
      }
      for ($i = 0; $i < $chCount; $i++) {
        $online = 1;
        $typeInfo = '';
        if ($chCount > 1) {
          try {
            $tXml = $this->bc->requestXml(318, $i);
            $typeInfo = trim(strval($this->bc->xmlValue($tXml, 'type') ?? ''));
            $online = ($typeInfo !== '' && strtoupper($typeInfo) !== 'NONE') ? 1 : 0;
          } catch (Exception $e3) {
            $online = 0;
          }
        }
        $entry = array('channel' => $i, 'online' => $online, 'sleep' => 0);
        if ($typeInfo !== '') {
          $entry['typeInfo'] = $typeInfo;
        }
        $status[] = $entry;
      }
    }
    return self::ok($cmd, array('count' => count($status), 'status' => $status));
  }

  private function handleGetHddInfo() {
    $xml = $this->bc->requestXml(102, null);
    $sx = @simplexml_load_string($xml);
    $list = array();
    if ($sx !== false) {
      foreach ($sx->xpath('.//HddInfo') as $h) {
        $cap = isset($h->capacityM) ? floatval($h->capacityM) : floatval($h->capacity ?? 0);
        $rem = isset($h->remainSizeM) ? floatval($h->remainSizeM) : floatval($h->remainSize ?? 0);
        $stype = isset($h->storageType) ? intval($h->storageType) : 2;
        $list[] = array(
          'number' => intval($h->number ?? 0),
          'capacity' => $cap,
          'size' => $rem,
          'format' => intval($h->format ?? 1),
          'mount' => intval($h->mount ?? 1),
          'storageType' => $stype,
        );
      }
    }
    return self::ok('GetHddInfo', array('HddInfo' => $list));
  }

  private function handleGetAbility($ch) {
    list($hostTags, $chTags) = $this->parseSupportTags($ch);
    $v = function ($key, $default = 0) use ($chTags, $hostTags) {
      if (isset($chTags[$key])) {
        return intval($chTags[$key]);
      }
      if (isset($hostTags[$key])) {
        return intval($hostTags[$key]);
      }
      return $default;
    };
    $hasTag = function ($key) use ($chTags, $hostTags) {
      return isset($chTags[$key]) || isset($hostTags[$key]);
    };
    $permit = function ($cond, $ver = 1) {
      return $cond ? array('permit' => 7, 'ver' => max(1, intval($ver))) : array('permit' => 0, 'ver' => 0);
    };

    $ptzType = $v('ptzType', 0);
    $ptzCtrlBits = $v('ptzControl', 0);
    $hasPtz = ($ptzType > 0 || $ptzCtrlBits > 0);
    $ptzDirDefault = 0;
    if ($hasPtz) {
      if ($ptzType === 7) {
        $ptzDirDefault = 2;
      } elseif ($ptzCtrlBits > 1 && (($ptzCtrlBits >> 1) & 1) === 1) {
        $ptzDirDefault = 1;
      }
    }
    $ptzDirVer = $hasTag('ptzDirection') ? $v('ptzDirection', $ptzDirDefault) : $ptzDirDefault;
    $hasZoom = ($ptzType === 1 || $ptzType === 2 || $ptzType === 5 || (($ptzCtrlBits >> 4) & 1) === 1);
    $ledCtrl = $v('ledCtrl', 1);
    $aiBits = $v('aitype', 0);
    $audioVer = $v('audioVersion', 0);

    // Floodlight / spotlight: bit 1 of ledCtrl (or lightType > 0) indicates physical WhiteLed hardware
    // (E321 without spotlight: ledCtrl=1, lightType=0 -> false; E1 Outdoor PoE with spotlight: ledCtrl=3 -> true)
    $hasFloodlight = ((($ledCtrl >> 1) & 1) === 1) || ($v('lightType', 0) > 0);

    // AI auto-tracking: E321 reports autoPt=0 in Support while exposing smartTrack/smartTrackPt in cmd 299
    $hasAiTrack = ($v('autoPt', 0) > 0 || (($aiBits >> 7) & 1) === 1 || (($aiBits >> 13) & 1) === 1);
    if (!$hasAiTrack && $hasPtz && $aiBits > 0) {
      try {
        $aiCfgXml = $this->bc->requestXml(299, $ch);
        if ($this->bc->xmlValue($aiCfgXml, 'smartTrack') !== null || $this->bc->xmlValue($aiCfgXml, 'smartTrackPt') !== null) {
          $hasAiTrack = true;
        }
      } catch (Exception $e) {
      }
    }

    // SD card & pre-record: standalone Wi-Fi cameras (e.g. E321) report diskNum=0 in Support,
    // while Login DeviceInfo has <sdCard>1</sdCard> and Support has storagemode bit 1 (value 2 / 6)
    $loginSdCard = ($this->bc->loginXml !== '') ? intval($this->bc->xmlValue($this->bc->loginXml, 'sdCard') ?? 0) : 0;
    $hasSdCard = ($v('diskNum', 0) > 0 || $v('newRecordType', 0) > 0 || ($v('storagemode', 0) & 2) !== 0 || $loginSdCard > 0);
    $hasPreRec = false;
    if ($hasSdCard) {
      try {
        $rec54 = $this->bc->requestXml(54, $ch);
        $preSec = $this->bc->xmlValue($rec54, 'preRecordTime');
        if ($preSec !== null && intval($preSec) > 0) {
          $hasPreRec = true;
        }
      } catch (Exception $e) {
      }
    }

    $abCh = array(
      'ptzCtrl' => $permit($hasPtz, $ptzCtrlBits > 0 ? $ptzCtrlBits : 1),
      'ptzType' => $permit($hasPtz, max(1, $ptzType)),
      'ptzDirection' => ($hasPtz && $ptzDirVer > 0) ? array('permit' => 6, 'ver' => $ptzDirVer) : array('permit' => 0, 'ver' => 0),
      'ptzPreset' => $permit($v('ptzPreset', 0) > 0 || $hasPtz),
      'ptzPatrol' => $permit($v('ptzPatrol', 0) > 0),
      'ptzGuard' => $permit((($ptzCtrlBits >> 2) & 1) === 1),
      'supportZoom' => $permit($hasZoom),
      'supportFocus' => $permit($hasZoom),
      'ledControl' => $permit(true),
      'powerLed' => $permit((($ledCtrl >> 0) & 1) === 1 || $ledCtrl > 0),
      'supportFLswitch' => $permit($hasFloodlight),
      'supportFLBrightness' => $permit($hasFloodlight),
      'floodLight' => $permit($hasFloodlight),
      'osd' => $permit($v('osdCfg', 1) > 0),
      'enc' => $permit($v('encCtrl', 1) > 0),
      'image' => $permit(true),
      'isp' => $permit(true),
      'ispBright' => $permit(true),
      'ispContrast' => $permit(true),
      'ispSatruation' => $permit(true),
      'ispSharpen' => $permit(true),
      'ispHue' => $permit(true),
      'ispDayNight' => $permit(true),
      'ispAntiFlick' => $permit(true),
      'ispExposureMode' => $permit(true),
      'ispBackLight' => $permit($v('newIspCfg', 0) === 0 && $v('ispBackLight', 0) > 0),
      'ispMirror' => $permit(true),
      'ispFlip' => $permit(true),
      'ispHdr' => $permit($v('newIspCfg', 0) > 0),
      'ispBinningMode' => $permit($v('newIspCfg', 0) > 0),
      'md' => $permit(true),
      'alarmMd' => $permit(true),
      'alarmAudio' => $permit($audioVer > 0 || $v('ipcAudioTalk', 0) > 0 || $v('audioAlarm', 0) > 0),
      'supportAudioCfg' => $permit($audioVer > 0 || $v('ipcAudioTalk', 0) > 0 || $v('audioCfg', 0) > 0),
      'supportAi' => $permit($aiBits > 0),
      'supportAiPeople' => $permit((($aiBits >> 1) & 1) === 1),
      'supportAiVehicle' => $permit((($aiBits >> 2) & 1) === 1),
      'supportAiFace' => $permit((($aiBits >> 3) & 1) === 1),
      'supportAiDogCat' => $permit((($aiBits >> 4) & 1) === 1),
      'supportAiPackage' => $permit((($aiBits >> 17) & 1) === 1 || (($aiBits >> 23) & 1) === 1),
      'aiTrack' => $permit($hasAiTrack),
      'supportAiTrack' => $permit($hasAiTrack),
      'doorbellVersion' => $permit($v('doorbellVersion', 0) > 0, $v('doorbellVersion', 0)),
      'battery' => $permit($v('battery', 0) > 0),
      'sdCard' => $permit($hasSdCard),
      'recPreRecord' => $permit($hasPreRec),
      'push' => $permit($v('pushAlarm', 0) > 0 || $v('pushVersion', 0) > 0),
      'email' => $permit($v('email', 0) > 0 || $v('emailVersion', 0) > 0),
      'ftp' => $permit($v('ftp', 0) > 0),
      'reboot' => $permit(true),
      'p2p' => $permit(true),
    );

    $ability = array_merge($abCh, array(
      'abilityChn' => array(
        $ch => $abCh,
        0 => $abCh,
      ),
    ));
    return self::ok('GetAbility', array('Ability' => $ability));
  }

  private function handleGetEnc($ch, $action) {
    $xml = $this->bc->requestXml(56, $ch);
    $sx = @simplexml_load_string($xml);
    if ($sx === false) {
      throw new Exception('Invalid GetEnc XML');
    }
    $parseStream = function ($node) {
      if (!$node) {
        return null;
      }
      $w = intval($node->width ?? 0);
      $h = intval($node->height ?? 0);
      $res = strval($node->resolutionName ?? ($w . '*' . $h));
      $vt = intval($node->videoEncType ?? 0);
      return array(
        'audio' => intval($node->audio ?? 0),
        'size' => $res,
        'width' => $w,
        'height' => $h,
        'frameRate' => intval($node->frame ?? 15),
        'bitRate' => intval($node->bitRate ?? 1024),
        'vType' => ($vt === 1) ? 'h265' : 'h264',
      );
    };
    $comp = $sx->Compression ?? $sx;
    $main = isset($comp->mainStream) ? $parseStream($comp->mainStream) : array('size' => '', 'frameRate' => 15, 'bitRate' => 2048, 'vType' => 'h264', 'audio' => 0);
    $sub = isset($comp->subStream) ? $parseStream($comp->subStream) : array('size' => '', 'frameRate' => 10, 'bitRate' => 512, 'vType' => 'h264', 'audio' => 0);
    $encVal = array(
      'channel' => $ch,
      'audio' => ($main['audio'] || $sub['audio']) ? 1 : 0,
      'mainStream' => $main,
      'subStream' => $sub,
    );
    $range = null;
    if ($action === 1) {
      $profiles = array();
      try {
        $rXml = $this->bc->requestXml(146, null);
        $rsx = @simplexml_load_string($rXml);
        if ($rsx !== false) {
          foreach ($rsx->xpath('.//StreamInfo') as $si) {
            $prof = array();
            foreach ($si->xpath('.//encodeTable') as $et) {
              $stType = strval($et->type ?? '');
              if ($stType !== 'mainStream' && $stType !== 'subStream') {
                continue;
              }
              $w = intval($et->width ?? ($et->resolution->width ?? 0));
              $h = intval($et->height ?? ($et->resolution->height ?? 0));
              $fpsArr = array_values(array_filter(array_map('intval', explode(',', strval($et->framerateTable ?? '')))));
              $brArr = array_values(array_filter(array_map('intval', explode(',', strval($et->bitrateTable ?? '')))));
              $vtName = (isset($et->videoEncType) && intval($et->videoEncType) === 1) ? 'h265' : 'h264';
              $sizeStr = ($w > 0 && $h > 0) ? ($w . '*' . $h) : '';
              if (!isset($prof[$stType])) {
                $prof[$stType] = array(
                  'size' => $sizeStr,
                  'frameRate' => $fpsArr,
                  'bitRate' => $brArr,
                  'vType' => isset($et->videoEncType) ? array($vtName) : array('h264', 'h265'),
                );
              } elseif (!in_array($vtName, $prof[$stType]['vType'], true)) {
                $prof[$stType]['vType'][] = $vtName;
              }
            }
            if (!empty($prof)) {
              $profiles[] = $prof;
            }
          }
        }
      } catch (Exception $e) {
      }
      if (empty($profiles)) {
        $profiles[] = array(
          'mainStream' => array('size' => $main['size'], 'frameRate' => array($main['frameRate']), 'bitRate' => array($main['bitRate']), 'vType' => array($main['vType'])),
          'subStream' => array('size' => $sub['size'], 'frameRate' => array($sub['frameRate']), 'bitRate' => array($sub['bitRate']), 'vType' => array($sub['vType'])),
        );
      }
      $range = array('Enc' => $profiles);
    }
    return self::ok('GetEnc', array('Enc' => $encVal), $range);
  }

  private function handleSetEnc($ch, $param) {
    $enc = $param['Enc'] ?? $param;
    $xml = $this->bc->requestXml(56, $ch);
    $sx = @simplexml_load_string($xml);
    if ($sx === false) {
      throw new Exception('Invalid GetEnc XML');
    }
    $comp = $sx->Compression;
    if (array_key_exists('audio', $enc)) {
      $aud = intval($enc['audio']) ? '1' : '0';
      if (isset($comp->mainStream->audio)) {
        $comp->mainStream->audio = $aud;
      }
      if (isset($comp->subStream->audio)) {
        $comp->subStream->audio = $aud;
      }
    }
    foreach (array('mainStream', 'subStream') as $stKey) {
      if (!isset($enc[$stKey]) || !is_array($enc[$stKey]) || !isset($comp->{$stKey})) {
        continue;
      }
      $patch = $enc[$stKey];
      $target = $comp->{$stKey};
      if (!empty($patch['size']) && strpos($patch['size'], '*') !== false) {
        list($w, $h) = explode('*', $patch['size'], 2);
        if (isset($target->resolutionName)) {
          $target->resolutionName = $patch['size'];
        }
        if (isset($target->width)) {
          $target->width = intval($w);
        }
        if (isset($target->height)) {
          $target->height = intval($h);
        }
      }
      if (isset($patch['frameRate']) && isset($target->frame)) {
        $target->frame = intval($patch['frameRate']);
      }
      if (isset($patch['bitRate']) && isset($target->bitRate)) {
        $target->bitRate = intval($patch['bitRate']);
      }
      if (isset($patch['vType']) && isset($target->videoEncType)) {
        $target->videoEncType = (strtolower(strval($patch['vType'])) === 'h265') ? '1' : '0';
      }
    }
    $this->bc->requestXml(57, $ch, $sx->asXML());
    return self::okWrite('SetEnc');
  }

  private function handleGetImage($ch) {
    $xml = $this->bc->requestXml(26, $ch);
    $sx = @simplexml_load_string($xml);
    if ($sx === false || !isset($sx->VideoInput)) {
      throw new Exception('Invalid GetImage XML');
    }
    $vi = $sx->VideoInput;
    return self::ok('GetImage', array(
      'Image' => array(
        'channel' => $ch,
        'bright' => intval($vi->bright ?? 128),
        'contrast' => intval($vi->contrast ?? 128),
        'saturation' => intval($vi->saturation ?? 128),
        'hue' => intval($vi->hue ?? 128),
        'sharpen' => intval($vi->sharpen ?? 128),
      ),
    ));
  }

  private function handleSetImage($ch, $param) {
    $img = $param['Image'] ?? $param;
    $xml = $this->bc->requestXml(26, $ch);
    $sx = @simplexml_load_string($xml);
    if ($sx === false || !isset($sx->VideoInput)) {
      throw new Exception('Invalid GetImage XML');
    }
    foreach (array('bright', 'contrast', 'saturation', 'hue', 'sharpen') as $k) {
      if (isset($img[$k]) && isset($sx->VideoInput->{$k})) {
        $sx->VideoInput->{$k} = intval($img[$k]);
      }
    }
    $this->bc->requestXml(25, $ch, $sx->asXML());
    return self::okWrite('SetImage');
  }

  private function handleGetIsp($ch) {
    $xml = $this->bc->requestXml(26, $ch);
    $sx = @simplexml_load_string($xml);
    if ($sx === false) {
      throw new Exception('Invalid GetIsp XML');
    }
    $adv = $sx->InputAdvanceCfg ?? null;
    $dnRaw = strtolower(strval($adv->DayNight->mode ?? ($adv->dayNight ?? 'auto')));
    if (strpos($dnRaw, 'color') !== false || $dnRaw === 'day') {
      $dayNight = 'Color';
    } elseif (strpos($dnRaw, 'black') !== false || $dnRaw === 'night') {
      $dayNight = 'Black&White';
    } else {
      $dayNight = 'Auto';
    }
    $afEnable = intval($adv->PowerLineFrequency->enable ?? 1);
    $afMode = strtolower(strval($adv->PowerLineFrequency->mode ?? '50hz'));
    if (!$afEnable || $afMode === 'off') {
      $antiFlicker = 'Off';
    } elseif (strpos($afMode, '60') !== false) {
      $antiFlicker = '60HZ';
    } elseif (strpos($afMode, '50') !== false) {
      $antiFlicker = '50HZ';
    } else {
      $antiFlicker = 'Other';
    }
    $expRaw = strtolower(strval($adv->Exposure->mode ?? 'auto'));
    $expMap = array('auto' => 'Auto', 'lownoise' => 'LowNoise', 'antismearing' => 'Anti-Smearing', 'manual' => 'Manual');
    $isp = array(
      'channel' => $ch,
      'dayNight' => $dayNight,
      'antiFlicker' => $antiFlicker,
      'exposure' => $expMap[$expRaw] ?? ucfirst($expRaw),
      'rotation' => intval($adv->turnOver ?? ($adv->flip ?? 0)),
      'mirroring' => intval($adv->mirror ?? 0),
      'nr3d' => intval($adv->nr3d ?? 1),
      'whiteBalance' => ucfirst(strtolower(strval($adv->Scene->mode ?? 'auto'))),
      'redGain' => intval($adv->Scene->Redgain->cur ?? 128),
      'blueGain' => intval($adv->Scene->Bluegain->cur ?? 128),
      'backLight' => strval($adv->BackLight->mode ?? 'Off'),
      'blc' => intval($adv->BackLight->blc ?? 128),
      'drc' => intval($adv->BackLight->drc ?? 128),
    );
    if (isset($adv->hdrSwitch)) {
      $isp['hdr'] = intval($adv->hdrSwitch);
    }
    if (isset($adv->binning_mode)) {
      $isp['binningMode'] = intval($adv->binning_mode);
    }
    try {
      $xml296 = $this->bc->requestXml(296, $ch);
      $curThr = $this->bc->xmlValue($xml296, 'cur');
      if ($curThr !== null) {
        $isp['dayNightThreshold'] = intval($curThr);
      }
    } catch (Exception $e) {
    }
    return self::ok('GetIsp', array('Isp' => $isp));
  }

  private function handleSetIsp($ch, $param) {
    $isp = $param['Isp'] ?? $param;
    if (isset($isp['dayNightThreshold'])) {
      $xml296 = $this->bc->requestXml(296, $ch);
      $sx296 = @simplexml_load_string($xml296);
      if ($sx296 !== false) {
        $nodes = $sx296->xpath('.//cur');
        if ($nodes && isset($nodes[0])) {
          $nodes[0][0] = intval($isp['dayNightThreshold']);
          $this->bc->requestXml(297, $ch, $sx296->asXML());
        }
      }
    }
    $xml = $this->bc->requestXml(26, $ch);
    $sx = @simplexml_load_string($xml);
    if ($sx !== false && isset($sx->InputAdvanceCfg)) {
      $adv = $sx->InputAdvanceCfg;
      $touched = false;
      if (isset($isp['hdr']) && isset($adv->hdrSwitch)) {
        $adv->hdrSwitch = intval($isp['hdr']);
        $touched = true;
      }
      if (isset($isp['binningMode']) && isset($adv->binning_mode)) {
        $adv->binning_mode = intval($isp['binningMode']);
        $touched = true;
      }
      if (isset($isp['mirroring']) && isset($adv->mirror)) {
        $adv->mirror = intval($isp['mirroring']);
        $touched = true;
      }
      if (isset($isp['rotation'])) {
        if (isset($adv->turnOver)) {
          $adv->turnOver = intval($isp['rotation']);
          $touched = true;
        } elseif (isset($adv->flip)) {
          $adv->flip = intval($isp['rotation']);
          $touched = true;
        }
      }
      if (isset($isp['antiFlicker']) && isset($adv->PowerLineFrequency)) {
        $af = strtoupper(strval($isp['antiFlicker']));
        if ($af === 'OFF') {
          $adv->PowerLineFrequency->enable = 0;
        } else {
          $adv->PowerLineFrequency->enable = 1;
          $adv->PowerLineFrequency->mode = ($af === '60HZ') ? '60hz' : (($af === '50HZ') ? '50hz' : 'outdoor');
        }
        $touched = true;
      }
      if (isset($isp['dayNight'])) {
        $dn = strtolower(strval($isp['dayNight']));
        $val = (strpos($dn, 'color') !== false) ? 'color' : ((strpos($dn, 'black') !== false) ? 'blackAndWhite' : 'auto');
        if (isset($adv->DayNight->mode)) {
          $adv->DayNight->mode = $val;
          $touched = true;
        } elseif (isset($adv->dayNight)) {
          $adv->dayNight = $val;
          $touched = true;
        }
      }
      if ($touched) {
        $this->bc->requestXml(25, $ch, $sx->asXML());
      }
    }
    return self::okWrite('SetIsp');
  }

  private static function osdCoordsToPos($x, $y) {
    $x = intval($x);
    $y = intval($y);
    if ($y <= 100 && $x <= 100) {
      return 'Upper Left';
    }
    if ($y <= 100 && $x >= 30000 && $x < 60000) {
      return 'Top Center';
    }
    if ($y <= 100 && $x >= 60000) {
      return 'Upper Right';
    }
    if ($y >= 60000 && $x <= 100) {
      return 'Lower Left';
    }
    if ($y >= 60000 && $x >= 30000 && $x < 60000) {
      return 'Bottom Center';
    }
    return 'Lower Right';
  }

  private static function posToOsdCoords($pos) {
    switch ($pos) {
      case 'Upper Left':
        return array(1, 1);
      case 'Top Center':
        return array(32768, 1);
      case 'Upper Right':
        return array(65537, 1);
      case 'Lower Left':
        return array(1, 65536);
      case 'Bottom Center':
        return array(32768, 65536);
      case 'Lower Right':
      default:
        return array(65536, 65536);
    }
  }

  private function handleGetOsd($ch) {
    $xml = $this->bc->requestXml(44, $ch);
    $sx = @simplexml_load_string($xml);
    if ($sx === false) {
      throw new Exception('Invalid GetOsd XML');
    }
    $dt = $sx->OsdDatetime ?? null;
    $cn = $sx->OsdChannelName ?? null;
    return self::ok('GetOsd', array(
      'Osd' => array(
        'channel' => $ch,
        'watermark' => intval($cn->enWatermark ?? 0),
        'osdTime' => array(
          'enable' => intval($dt->enable ?? 0),
          'pos' => self::osdCoordsToPos($dt->topLeftX ?? 65537, $dt->topLeftY ?? 1),
        ),
        'osdChannel' => array(
          'name' => strval($cn->name ?? ''),
          'enable' => intval($cn->enable ?? 0),
          'pos' => self::osdCoordsToPos($cn->topLeftX ?? 65536, $cn->topLeftY ?? 65536),
        ),
      ),
    ));
  }

  private function handleSetOsd($ch, $param) {
    $osd = $param['Osd'] ?? $param;
    $xml = $this->bc->requestXml(44, $ch);
    $sx = @simplexml_load_string($xml);
    if ($sx === false) {
      throw new Exception('Invalid GetOsd XML');
    }
    if (isset($osd['watermark']) && isset($sx->OsdChannelName->enWatermark)) {
      $sx->OsdChannelName->enWatermark = intval($osd['watermark']);
    }
    if (isset($osd['osdTime']) && isset($sx->OsdDatetime)) {
      if (isset($osd['osdTime']['enable'])) {
        $sx->OsdDatetime->enable = intval($osd['osdTime']['enable']);
      }
      if (!empty($osd['osdTime']['pos'])) {
        list($x, $y) = self::posToOsdCoords($osd['osdTime']['pos']);
        $sx->OsdDatetime->topLeftX = $x;
        $sx->OsdDatetime->topLeftY = $y;
      }
    }
    if (isset($osd['osdChannel']) && isset($sx->OsdChannelName)) {
      if (isset($osd['osdChannel']['enable'])) {
        $sx->OsdChannelName->enable = intval($osd['osdChannel']['enable']);
      }
      if (isset($osd['osdChannel']['name'])) {
        $sx->OsdChannelName->name = strval($osd['osdChannel']['name']);
      }
      if (!empty($osd['osdChannel']['pos'])) {
        list($x, $y) = self::posToOsdCoords($osd['osdChannel']['pos']);
        $sx->OsdChannelName->topLeftX = $x;
        $sx->OsdChannelName->topLeftY = $y;
      }
    }
    $this->bc->requestXml(45, $ch, $sx->asXML());
    return self::okWrite('SetOsd');
  }

  private function handleGetIrLights($ch) {
    $xml = $this->bc->requestXml(208, $ch);
    $raw = strtolower(trim(strval($this->bc->xmlValue($xml, 'state') ?? 'auto')));
    if ($raw === 'close' || $raw === 'off') {
      $state = 'Off';
    } elseif ($raw === 'open' || $raw === 'on') {
      $state = 'On';
    } else {
      $state = 'Auto';
    }
    return self::ok('GetIrLights', array('IrLights' => array('channel' => $ch, 'state' => $state)));
  }

  private function handleSetIrLights($ch, $param) {
    $ir = $param['IrLights'] ?? $param;
    $want = strtolower(trim(strval($ir['state'] ?? 'auto')));
    $xmlVal = ($want === 'off') ? 'close' : (($want === 'on') ? 'on' : 'auto');
    $xml = $this->bc->requestXml(208, $ch);
    $sx = @simplexml_load_string($xml);
    if ($sx === false) {
      throw new Exception('Invalid LedState XML');
    }
    $nodes = $sx->xpath('.//state');
    if ($nodes && isset($nodes[0])) {
      $nodes[0][0] = $xmlVal;
    }
    $this->bc->requestXml(209, $ch, $sx->asXML());
    return self::okWrite('SetIrLights');
  }

  private function handleGetPowerLed($ch) {
    $xml = $this->bc->requestXml(208, $ch);
    $ls = strtolower(trim(strval($this->bc->xmlValue($xml, 'lightState') ?? 'open')));
    return self::ok('GetPowerLed', array(
      'PowerLed' => array('channel' => $ch, 'state' => ($ls === 'open' || $ls === 'on') ? 'On' : 'Off'),
    ));
  }

  private function handleSetPowerLed($ch, $param) {
    $pl = $param['PowerLed'] ?? $param;
    $want = strtolower(trim(strval($pl['state'] ?? 'On')));
    $xmlVal = ($want === 'on' || $want === '1') ? 'open' : 'close';
    $xml = $this->bc->requestXml(208, $ch);
    $sx = @simplexml_load_string($xml);
    if ($sx === false) {
      throw new Exception('Invalid LedState XML');
    }
    $nodes = $sx->xpath('.//lightState');
    if ($nodes && isset($nodes[0])) {
      $nodes[0][0] = $xmlVal;
    }
    $this->bc->requestXml(209, $ch, $sx->asXML());
    return self::okWrite('SetPowerLed');
  }

  private function handleGetWhiteLed($ch) {
    list($hostTags, $chTags) = $this->parseSupportTags($ch);
    $ledCtrl = intval($chTags['ledCtrl'] ?? ($hostTags['ledCtrl'] ?? 1));
    $lightType = intval($chTags['lightType'] ?? ($hostTags['lightType'] ?? 0));
    if ((($ledCtrl >> 1) & 1) === 0 && $lightType <= 0) {
      throw new Exception('Baichuan status 400: WhiteLed not supported (ledCtrl=' . $ledCtrl . ', lightType=' . $lightType . ')');
    }
    $xml = $this->bc->requestXml(289, $ch);
    $sx = @simplexml_load_string($xml);
    if ($sx === false) {
      throw new Exception('Invalid FloodlightTask XML');
    }
    $fl = $sx->FloodlightTask ?? $sx;
    $modeBc = intval($fl->alarmMode ?? 0);
    return self::ok('GetWhiteLed', array(
      'WhiteLed' => array(
        'channel' => $ch,
        'state' => intval($fl->status ?? ($fl->preview_auto ?? 0)),
        'mode' => ($modeBc > 0) ? 1 : 0,
        'bright' => intval($fl->brightness_cur ?? 100),
      ),
    ));
  }

  private function handleSetWhiteLed($ch, $param) {
    $wl = $param['WhiteLed'] ?? $param;
    if (array_key_exists('state', $wl) && !isset($wl['bright']) && !isset($wl['mode'])) {
      $st = intval($wl['state']) ? 1 : 0;
      $body = '<?xml version="1.0" encoding="UTF-8" ?>' . "\n"
        . '<body>' . "\n"
        . '<FloodlightManual version="1.1">' . "\n"
        . '<channelId>' . $ch . '</channelId>' . "\n"
        . '<status>' . $st . '</status>' . "\n"
        . '<duration>180</duration>' . "\n"
        . '</FloodlightManual>' . "\n"
        . '</body>';
      $this->bc->requestXml(288, $ch, $body);
      return self::okWrite('SetWhiteLed');
    }
    $xml = $this->bc->requestXml(289, $ch);
    $sx = @simplexml_load_string($xml);
    if ($sx === false) {
      throw new Exception('Invalid FloodlightTask XML');
    }
    $fl = $sx->FloodlightTask ?? $sx;
    if (isset($wl['bright']) && isset($fl->brightness_cur)) {
      $fl->brightness_cur = intval($wl['bright']);
    }
    if (isset($wl['mode']) && isset($fl->alarmMode)) {
      $fl->alarmMode = intval($wl['mode']);
    }
    $this->bc->requestXml(290, $ch, $sx->asXML());
    return self::okWrite('SetWhiteLed');
  }

  private function handlePtzCtrl($ch, $param) {
    $op = strval($param['op'] ?? 'Stop');
    if ($op === 'ToPos') {
      $pid = intval($param['id'] ?? 0);
      $body = '<?xml version="1.0" encoding="UTF-8" ?>' . "\n"
        . '<body>' . "\n"
        . '<PtzPreset version="1.1">' . "\n"
        . '<channelId>' . $ch . '</channelId>' . "\n"
        . '<presetList>' . "\n"
        . '<preset>' . "\n"
        . '<id>' . $pid . '</id>' . "\n"
        . '<command>toPos</command>' . "\n"
        . '</preset>' . "\n"
        . '</presetList>' . "\n"
        . '</PtzPreset>' . "\n"
        . '</body>';
      $this->bc->requestXml(19, $ch, $body);
      return self::okWrite('PtzCtrl');
    }
    $speedTag = isset($param['speed']) ? ('<speed>' . max(1, min(64, intval($param['speed']))) . '</speed>' . "\n") : '';
    $body = '<?xml version="1.0" encoding="UTF-8" ?>' . "\n"
      . '<body>' . "\n"
      . '<PtzControl version="1.1">' . "\n"
      . '<channelId>' . $ch . '</channelId>' . "\n"
      . '<command>' . htmlspecialchars($op, ENT_XML1) . '</command>' . "\n"
      . $speedTag
      . '</PtzControl>' . "\n"
      . '</body>';
    $this->bc->requestXml(18, $ch, $body);
    return self::okWrite('PtzCtrl');
  }

  private function handleGetPtzPreset($ch) {
    $xml = $this->bc->requestXml(190, $ch);
    $sx = @simplexml_load_string($xml);
    $presets = array();
    if ($sx !== false) {
      foreach ($sx->xpath('.//preset') as $p) {
        $name = trim(strval($p->name ?? ''));
        $enable = isset($p->enable) ? intval($p->enable) : ($name !== '' ? 1 : 0);
        $presets[] = array(
          'channel' => $ch,
          'id' => intval($p->id ?? 0),
          'name' => $name,
          'enable' => $enable,
        );
      }
    }
    return self::ok('GetPtzPreset', array('PtzPreset' => $presets));
  }

  private function handleGetPtzPatrol($ch) {
    $xml = $this->bc->requestXml(64, $ch);
    $sx = @simplexml_load_string($xml);
    $patrols = array();
    if ($sx !== false) {
      $idx = 0;
      foreach ($sx->xpath('.//cruise') as $cr) {
        $steps = array();
        foreach ($cr->xpath('.//preset') as $pr) {
          $steps[] = array(
            'id' => intval($pr->id ?? 0),
            'speed' => intval($pr->speed ?? 32),
            'dwellTime' => intval($pr->dwellTime ?? 15),
          );
        }
        $patrols[] = array(
          'channel' => $ch,
          'id' => isset($cr->id) ? intval($cr->id) : $idx,
          'name' => strval($cr->name ?? ''),
          'enable' => !empty($steps) ? 1 : 0,
          'running' => intval($cr->cruising ?? 0),
          'preset' => !empty($steps) ? $steps : null,
        );
        $idx++;
      }
    }
    if (empty($patrols)) {
      list($hostTags, $chTags) = $this->parseSupportTags($ch);
      $ptzPatrolSup = $chTags['ptzPatrol'] ?? ($hostTags['ptzPatrol'] ?? null);
      if ($ptzPatrolSup !== null && intval($ptzPatrolSup) === 0) {
        throw new Exception('Baichuan status 400: PtzPatrol not supported');
      }
    }
    return self::ok('GetPtzPatrol', array('PtzPatrol' => $patrols));
  }

  private function handleGetPtzGuard($ch) {
    $xml = $this->bc->requestXml(332, $ch);
    return self::ok('GetPtzGuard', array(
      'PtzGuard' => array(
        'channel' => $ch,
        'benable' => intval($this->bc->xmlValue($xml, 'benable') ?? 0),
        'bexistPos' => intval($this->bc->xmlValue($xml, 'bvalid') ?? 0),
        'timeout' => intval($this->bc->xmlValue($xml, 'timeout') ?? 60),
      ),
    ));
  }

  private function handleSetPtzGuard($ch, $param) {
    $pg = $param['PtzGuard'] ?? $param;
    $cur = $this->handleGetPtzGuard($ch)['value']['PtzGuard'];
    $enable = isset($pg['benable']) ? intval($pg['benable']) : $cur['benable'];
    $timeout = isset($pg['timeout']) ? intval($pg['timeout']) : $cur['timeout'];
    $cmdStr = (($pg['cmdStr'] ?? '') === 'toPos') ? 'toGrd' : 'setGrd';
    $setPos = intval($pg['bSaveCurrentPos'] ?? 0);
    $body = '<?xml version="1.0" encoding="UTF-8" ?>' . "\n"
      . '<body>' . "\n"
      . '<PtzGuard version="1.1">' . "\n"
      . '<channelId>' . $ch . '</channelId>' . "\n"
      . '<benable>' . $enable . '</benable>' . "\n"
      . '<command>' . $cmdStr . '</command>' . "\n"
      . '<timeout>' . $timeout . '</timeout>' . "\n"
      . '<needSetPos>' . $setPos . '</needSetPos>' . "\n"
      . '<imageName></imageName>' . "\n"
      . '</PtzGuard>' . "\n"
      . '</body>';
    $this->bc->requestXml(331, $ch, $body);
    return self::okWrite('SetPtzGuard');
  }

  private function handlePtzCheck($ch) {
    try {
      $this->bc->requestXml(341, $ch);
    } catch (Exception $e) {
      $this->bc->requestXml(432, $ch);
    }
    return self::okWrite('PtzCheck');
  }

  private function handleGetZoomFocus($ch, $action) {
    $xml = $this->bc->requestXml(294, $ch);
    $sx = @simplexml_load_string($xml);
    if ($sx === false) {
      throw new Exception('Invalid PtzZoomFocus XML');
    }
    $zf = $sx->PtzZoomFocus ?? $sx;
    $zCur = intval($zf->zoom->curPos ?? 0);
    $zMin = intval($zf->zoom->minPos ?? 0);
    $zMax = intval($zf->zoom->maxPos ?? 100);
    $fCur = intval($zf->focus->curPos ?? 0);
    $fMin = intval($zf->focus->minPos ?? 0);
    $fMax = intval($zf->focus->maxPos ?? 100);
    $val = array(
      'ZoomFocus' => array(
        'channel' => $ch,
        'zoom' => array('pos' => $zCur),
        'focus' => array('pos' => $fCur),
      ),
    );
    $range = ($action === 1) ? array(
      'ZoomFocus' => array(
        'channel' => $ch,
        'zoom' => array('pos' => array('min' => $zMin, 'max' => $zMax)),
        'focus' => array('pos' => array('min' => $fMin, 'max' => $fMax)),
      ),
    ) : null;
    return self::ok('GetZoomFocus', $val, $range);
  }

  private function handleStartZoomFocus($ch, $param) {
    $zf = $param['ZoomFocus'] ?? $param;
    $op = strval($zf['op'] ?? 'ZoomPos');
    $pos = intval($zf['pos'] ?? 0);
    $body = '<?xml version="1.0" encoding="UTF-8" ?>' . "\n"
      . '<body>' . "\n"
      . '<StartZoomFocus version="1.1">' . "\n"
      . '<channelId>' . $ch . '</channelId>' . "\n"
      . '<command>' . htmlspecialchars($op, ENT_XML1) . '</command>' . "\n"
      . '<movePos>' . $pos . '</movePos>' . "\n"
      . '</StartZoomFocus>' . "\n"
      . '</body>';
    $this->bc->requestXml(295, $ch, $body);
    return self::okWrite('StartZoomFocus');
  }

  private function handleGetAutoFocus($ch) {
    $xml = $this->bc->requestXml(224, $ch);
    return self::ok('GetAutoFocus', array(
      'AutoFocus' => array('channel' => $ch, 'disable' => intval($this->bc->xmlValue($xml, 'disable') ?? 0)),
    ));
  }

  private function handleSetAutoFocus($ch, $param) {
    $af = $param['AutoFocus'] ?? $param;
    $dis = intval($af['disable'] ?? 0);
    $body = '<?xml version="1.0" encoding="UTF-8" ?>' . "\n"
      . '<body>' . "\n"
      . '<AutoFocus version="1.1">' . "\n"
      . '<channelId>' . $ch . '</channelId>' . "\n"
      . '<disable>' . $dis . '</disable>' . "\n"
      . '</AutoFocus>' . "\n"
      . '</body>';
    $this->bc->requestXml(223, $ch, $body);
    return self::okWrite('SetAutoFocus');
  }

  private function handleAudioAlarmPlay($ch, $param) {
    $mode = strval($param['alarm_mode'] ?? 'times');
    $times = max(1, intval($param['times'] ?? 1));
    $enable = intval($param['manual_switch'] ?? 1);
    if ($mode === 'manul' || $mode === 'manual') {
      $body = '<?xml version="1.0" encoding="UTF-8" ?>' . "\n"
        . '<body>' . "\n"
        . '<audioPlayInfo version="1.1">' . "\n"
        . '<channelId>' . $ch . '</channelId>' . "\n"
        . '<playMode>2</playMode>' . "\n"
        . '<playDuration>10</playDuration>' . "\n"
        . '<playTimes>1</playTimes>' . "\n"
        . '<onOff>' . $enable . '</onOff>' . "\n"
        . '</audioPlayInfo>' . "\n"
        . '</body>';
    } else {
      $body = '<?xml version="1.0" encoding="UTF-8" ?>' . "\n"
        . '<body>' . "\n"
        . '<audioPlayInfo version="1.1">' . "\n"
        . '<channelId>' . $ch . '</channelId>' . "\n"
        . '<playMode>0</playMode>' . "\n"
        . '<playDuration>10</playDuration>' . "\n"
        . '<playTimes>' . $times . '</playTimes>' . "\n"
        . '<onOff>1</onOff>' . "\n"
        . '</audioPlayInfo>' . "\n"
        . '</body>';
    }
    $this->bc->requestXml(263, $ch, $body);
    return self::okWrite('AudioAlarmPlay');
  }

  private function handleGetAudioCfg($ch) {
    $xml = $this->bc->requestXml(264, $ch);
    return self::ok('GetAudioCfg', array(
      'AudioCfg' => array(
        'channel' => $ch,
        'volume' => intval($this->bc->xmlValue($xml, 'volume') ?? 50),
      ),
    ));
  }

  private function handleSetAudioCfg($ch, $param) {
    $ac = $param['AudioCfg'] ?? $param;
    $xml = $this->bc->requestXml(264, $ch);
    $sx = @simplexml_load_string($xml);
    if ($sx === false) {
      throw new Exception('Invalid audioCfg XML');
    }
    if (isset($ac['volume'])) {
      $nodes = $sx->xpath('.//volume');
      if ($nodes && isset($nodes[0])) {
        $nodes[0][0] = intval($ac['volume']);
      }
    }
    $this->bc->requestXml(265, $ch, $sx->asXML());
    return self::okWrite('SetAudioCfg');
  }

  private function handleGetAiState($ch) {
    $cur = $this->aiByCh[$ch] ?? array(
      'people' => array('alarm_state' => 0, 'support' => 1),
      'vehicle' => array('alarm_state' => 0, 'support' => 1),
      'dog_cat' => array('alarm_state' => 0, 'support' => 1),
      'face' => array('alarm_state' => 0, 'support' => 0),
      'package' => array('alarm_state' => 0, 'support' => 0),
    );
    $cur['channel'] = $ch;
    return self::ok('GetAiState', $cur);
  }

  private function handleGetMdAlarm($cmd, $ch) {
    $xml = $this->bc->requestXml(46, $ch);
    $sx = @simplexml_load_string($xml);
    if ($sx === false) {
      throw new Exception('Invalid MD XML');
    }
    $enable = intval($this->bc->xmlValue($xml, 'enable') ?? 1);
    $sens = $this->bc->xmlValue($xml, 'sensitivityDefault');
    if ($sens === null) {
      $sens = $this->bc->xmlValue($xml, 'sensitivity');
    }
    $sensInt = ($sens !== null) ? intval($sens) : 25;
    if ($cmd === 'GetAlarm') {
      return self::ok('GetAlarm', array(
        'Alarm' => array(
          'channel' => $ch,
          'enable' => $enable,
          'schedule' => array('enable' => $enable),
          'sens' => array(array('sensitivity' => $sensInt)),
        ),
      ));
    }
    return self::ok('GetMdAlarm', array(
      'MdAlarm' => array(
        'channel' => $ch,
        'enable' => $enable,
        'useNewSens' => 1,
        'newSens' => array('sensDef' => $sensInt),
      ),
    ));
  }

  private function handleSetMdAlarm($cmd, $ch, $param) {
    $md = $param['MdAlarm'] ?? ($param['Alarm'] ?? $param);
    $xml = $this->bc->requestXml(46, $ch);
    $sx = @simplexml_load_string($xml);
    if ($sx === false) {
      throw new Exception('Invalid MD XML');
    }
    if (isset($md['enable']) && isset($sx->MD->enable)) {
      $sx->MD->enable = intval($md['enable']);
    }
    $sens = $md['newSens']['sensDef'] ?? ($md['sens'][0]['sensitivity'] ?? null);
    if ($sens !== null) {
      $nodes = $sx->xpath('.//sensitivityDefault');
      if ($nodes && isset($nodes[0])) {
        $nodes[0][0] = intval($sens);
      } else {
        foreach ($sx->xpath('.//sensitivity') as $sn) {
          $sn[0] = intval($sens);
        }
      }
    }
    $this->bc->requestXml(47, $ch, $sx->asXML());
    return self::okWrite($cmd);
  }

  private function handleGetAiCfg($ch) {
    $xml = $this->bc->requestXml(299, $ch);
    $raw = $this->bc->xmlValue($xml, 'smartTrack');
    if ($raw === null) {
      $raw = $this->bc->xmlValue($xml, 'smartTrackPt');
    }
    $st = intval($raw ?? 0);
    return self::ok('GetAiCfg', array('channel' => $ch, 'aiTrack' => $st, 'bSmartTrack' => $st));
  }

  private function handleSetAiCfg($ch, $param) {
    $xml = $this->bc->requestXml(299, $ch);
    $sx = @simplexml_load_string($xml);
    if ($sx === false) {
      throw new Exception('Invalid AiCfg XML');
    }
    $val = $param['bSmartTrack'] ?? ($param['aiTrack'] ?? null);
    if ($val !== null) {
      $vInt = intval($val);
      foreach (array('smartTrack', 'smartTrackPt') as $tag) {
        $nodes = $sx->xpath('.//' . $tag);
        if ($nodes && isset($nodes[0])) {
          $nodes[0][0] = $vInt;
        }
      }
    }
    $this->bc->requestXml(300, $ch, $sx->asXML());
    return self::okWrite('SetAiCfg');
  }

  private function handleGetAiAlarm($ch, $param) {
    $aiType = strval($param['ai_type'] ?? 'people');
    $body = '<?xml version="1.0" encoding="UTF-8" ?>' . "\n"
      . '<body>' . "\n"
      . '<AiDetectCfg version="1.1">' . "\n"
      . '<chn>' . $ch . '</chn>' . "\n"
      . '<type>' . htmlspecialchars($aiType, ENT_XML1) . '</type>' . "\n"
      . '</AiDetectCfg>' . "\n"
      . '</body>';
    $xml = $this->bc->requestXml(342, $ch, $body);
    return self::ok('GetAiAlarm', array(
      'AiAlarm' => array(
        'channel' => $ch,
        'ai_type' => $aiType,
        'sensitivity' => intval($this->bc->xmlValue($xml, 'sensitivity') ?? 50),
        'stay_time' => intval($this->bc->xmlValue($xml, 'stayTime') ?? 0),
      ),
    ));
  }

  private function handleSetAiAlarm($ch, $param) {
    $aa = $param['AiAlarm'] ?? $param;
    $aiType = strval($aa['ai_type'] ?? 'people');
    $body = '<?xml version="1.0" encoding="UTF-8" ?>' . "\n"
      . '<body>' . "\n"
      . '<AiDetectCfg version="1.1">' . "\n"
      . '<chn>' . $ch . '</chn>' . "\n"
      . '<type>' . htmlspecialchars($aiType, ENT_XML1) . '</type>' . "\n"
      . '</AiDetectCfg>' . "\n"
      . '</body>';
    $xml = $this->bc->requestXml(342, $ch, $body);
    $sx = @simplexml_load_string($xml);
    if ($sx === false) {
      throw new Exception('Invalid AiDetectCfg XML');
    }
    if (isset($aa['sensitivity'])) {
      $nodes = $sx->xpath('.//sensitivity');
      if ($nodes && isset($nodes[0])) {
        $nodes[0][0] = intval($aa['sensitivity']);
      }
    }
    if (isset($aa['stay_time'])) {
      $nodes = $sx->xpath('.//stayTime');
      if ($nodes && isset($nodes[0])) {
        $nodes[0][0] = intval($aa['stay_time']);
      }
    }
    $this->bc->requestXml(343, $ch, $sx->asXML());
    return self::okWrite('SetAiAlarm');
  }

  private function handleGetRec($cmd, $ch) {
    $xml81 = $this->bc->requestXml(81, $ch);
    $enable = intval($this->bc->xmlValue($xml81, 'enable') ?? 0);
    $postRec = '30 Seconds';
    $overwrite = 1;
    $preRec = null;
    try {
      $xml54 = $this->bc->requestXml(54, $ch);
      $delaySec = intval($this->bc->xmlValue($xml54, 'recordDelayTime') ?? 30);
      $secMap = array(15 => '15 Seconds', 30 => '30 Seconds', 60 => '1 Minute', 120 => '2 Minutes', 300 => '5 Minutes');
      $postRec = $secMap[$delaySec] ?? '30 Seconds';
      $ow = $this->bc->xmlValue($xml54, 'cycleRecord');
      if ($ow === null) {
        $ow = $this->bc->xmlValue($xml54, 'cycle');
      }
      if ($ow !== null) {
        $overwrite = intval($ow);
      }
      $preSec = $this->bc->xmlValue($xml54, 'preRecordTime');
      if ($preSec !== null) {
        $preRec = (intval($preSec) > 0) ? 1 : 0;
      }
    } catch (Exception $e) {
    }
    $recOut = array(
      'channel' => $ch,
      'enable' => $enable,
      'scheduleEnable' => $enable,
      'schedule' => array('channel' => $ch, 'enable' => $enable),
      'overwrite' => $overwrite,
      'postRec' => $postRec,
    );
    if ($preRec !== null) {
      $recOut['preRec'] = $preRec;
    }
    return self::ok($cmd, array('Rec' => $recOut));
  }

  private function handleSetRec($cmd, $ch, $param) {
    $rec = $param['Rec'] ?? $param;
    $enable = $rec['scheduleEnable'] ?? ($rec['schedule']['enable'] ?? ($rec['enable'] ?? null));
    if ($enable !== null) {
      $body = '<?xml version="1.0" encoding="UTF-8" ?>' . "\n"
        . '<body>' . "\n"
        . '<Record version="1.1">' . "\n"
        . '<channelId>' . $ch . '</channelId>' . "\n"
        . '<enable>' . (intval($enable) ? '1' : '0') . '</enable>' . "\n"
        . '</Record>' . "\n"
        . '</body>';
      $this->bc->requestXml(82, $ch, $body);
    }
    if (isset($rec['overwrite']) || isset($rec['postRec']) || isset($rec['preRec'])) {
      $xml54 = $this->bc->requestXml(54, $ch);
      $sx54 = @simplexml_load_string($xml54);
      if ($sx54 !== false) {
        if (isset($rec['overwrite'])) {
          $nodes = $sx54->xpath('.//cycleRecord');
          if (!$nodes || !isset($nodes[0])) {
            $nodes = $sx54->xpath('.//cycle');
          }
          if ($nodes && isset($nodes[0])) {
            $nodes[0][0] = intval($rec['overwrite']);
          }
        }
        if (isset($rec['postRec'])) {
          $strMap = array('15 Seconds' => 15, '30 Seconds' => 30, '1 Minute' => 60, '2 Minutes' => 120, '5 Minutes' => 300);
          if (isset($strMap[$rec['postRec']])) {
            $nodes = $sx54->xpath('.//recordDelayTime');
            if ($nodes && isset($nodes[0])) {
              $nodes[0][0] = $strMap[$rec['postRec']];
            }
          }
        }
        if (isset($rec['preRec'])) {
          $nodes = $sx54->xpath('.//preRecordTime');
          if ($nodes && isset($nodes[0])) {
            $nodes[0][0] = intval($rec['preRec']) ? 10 : 0;
          }
        }
        $this->bc->requestXml(55, $ch, $sx54->asXML());
      }
    }
    return self::okWrite($cmd);
  }

  private function handleGetTaskEnable($cmd, $getCmdId, $ch, $wrapperKey) {
    $xml = $this->bc->requestXml($getCmdId, $ch);
    $en = intval($this->bc->xmlValue($xml, 'enable') ?? 0);
    return self::ok($cmd, array(
      $wrapperKey => array(
        'channel' => $ch,
        'enable' => $en,
        'scheduleEnable' => $en,
        'schedule' => array('channel' => $ch, 'enable' => $en),
      ),
    ));
  }

  private function handleSetTaskEnable($cmd, $getCmdId, $setCmdId, $ch, $param, $wrapperKey) {
    $sub = $param[$wrapperKey] ?? $param;
    $en = $sub['scheduleEnable'] ?? ($sub['schedule']['enable'] ?? ($sub['enable'] ?? 0));
    $xml = $this->bc->requestXml($getCmdId, $ch);
    $sx = @simplexml_load_string($xml);
    if ($sx === false) {
      throw new Exception('Invalid task XML');
    }
    $nodes = $sx->xpath('.//enable');
    if ($nodes && isset($nodes[0])) {
      $nodes[0][0] = intval($en) ? '1' : '0';
    }
    $this->bc->requestXml($setCmdId, $ch, $sx->asXML());
    return self::okWrite($cmd);
  }

  private function handleGetPirInfo($ch) {
    $xml = $this->bc->requestXml(212, $ch);
    return self::ok('GetPirInfo', array(
      'pirInfo' => array(
        'channel' => $ch,
        'enable' => intval($this->bc->xmlValue($xml, 'enable') ?? 0),
        'sensitive' => intval($this->bc->xmlValue($xml, 'sensiValue') ?? 100),
      ),
    ));
  }

  private function handleSetPirInfo($ch, $param) {
    $pi = $param['pirInfo'] ?? $param;
    $xml = $this->bc->requestXml(212, $ch);
    $sx = @simplexml_load_string($xml);
    if ($sx === false) {
      throw new Exception('Invalid PirInfo XML');
    }
    if (isset($pi['enable'])) {
      $nodes = $sx->xpath('.//enable');
      if ($nodes && isset($nodes[0])) {
        $nodes[0][0] = intval($pi['enable']);
      }
    }
    if (isset($pi['sensitive'])) {
      $nodes = $sx->xpath('.//sensiValue');
      if ($nodes && isset($nodes[0])) {
        $nodes[0][0] = intval($pi['sensitive']);
      }
    }
    $this->bc->requestXml(213, $ch, $sx->asXML());
    return self::okWrite('SetPirInfo');
  }

  private function handleGetBatteryInfo($ch) {
    $xml = $this->bc->requestXml(253, $ch);
    $parsed = reolinkBaichuan::parseBatteryXml($xml, $ch);
    if ($parsed === null) {
      throw new Exception('Baichuan status 400 for cmd_id 253');
    }
    return self::ok('GetBatteryInfo', array('Battery' => $parsed));
  }
}
