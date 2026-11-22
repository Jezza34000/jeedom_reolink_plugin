<?php

/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */
/* * ***************************Includes********************************* */
require_once __DIR__  . '/../../../../core/php/core.inc.php';
require_once dirname(__FILE__) . '/../../3rdparty/reolinkapi.class.php';
require_once dirname(__FILE__) . '/../../3rdparty/reolinkbaichuan.class.php';

const CMD_SEND_QTY = 8;

class reolink extends eqLogic {

  const PYTHON_PATH = __DIR__ . '/../../resources/venv/bin/python3';
  const BATTERY_PASSIVE_WAKE_INTERVAL = 3600;
  const BATTERY_WAKE_INTERVAL = 21600;
  const MULTI_HOST_TYPES = array('NVR', 'WIFI_NVR', 'HOMEHUB');
  const NONE_WAKING_COMMANDS = array(
    'GetIsp',
    'GetEvents',
    'GetMdState',
    'GetAiState',
    'GetIrLights',
    'GetBatteryInfo',
    'GetPirInfo',
    'GetPowerLed',
    'GetAutoFocus',
    'GetDeviceAudioCfg',
    'GetManualRec',
    'GetImage',
    'GetBuzzerAlarmV20',
    'GetEmailV20',
    'GetEmail',
    'GetPushV20',
    'GetPush',
    'GetFtpV20',
    'GetFtp',
    'GetRecV20',
    'GetRec',
    'GetAudioAlarmV20',
    'GetMdAlarm',
    'GetAlarm',
    'GetStateLight',
    'GetHddInfo',
    'GetChannelstatus',
    'GetChannelStatus',
    'GetPushCfg',
    'GetScene',
  );

  /************* Static methods ************/
  // Leading underscore required: Jeedom utils::o2a() persists non-_ properties into eqLogic INSERT/UPDATE
  private static $_cnxByIp = array();

  public static function getReolinkConnection($id) {
    $camera = reolink::byId($id, 'reolink');
    if (!is_object($camera)) {
      log::add('reolink', 'warning', 'getReolinkConnection : équipement introuvable (' . $id . ')');
      return NULL;
    }
    $adresseIP = $camera->getConfiguration('adresseip');
    $port = $camera->getConfiguration('port');
    $username = $camera->getConfiguration('login');
    $password = $camera->getConfiguration('password');
    $cnxtype = $camera->getConfiguration('cnxtype');

    if (!empty($adresseIP) && !empty($username) && !empty($password)) {
      $cacheKey = $adresseIP . '|' . strval($port);
      if (isset(self::$_cnxByIp[$cacheKey]) && is_object(self::$_cnxByIp[$cacheKey])) {
        return self::$_cnxByIp[$cacheKey];
      }
      $cnxinfo = array("adresseIP" => $adresseIP, "port" => $port, "username" => $username, "password" => $password, "cnxtype" => $cnxtype);
      $camcnx = new reolinkAPI($cnxinfo);
      self::$_cnxByIp[$cacheKey] = $camcnx;
      return $camcnx;
    } else {
      log::add('reolink', 'warning', "Information de connexion manquantes : connexion à la caméra impossible");
      return NULL;
    }
  }

  public function decrypt() {
    $this->setConfiguration('password', utils::decrypt($this->getConfiguration('password')));
  }

  public function encrypt() {
    $this->setConfiguration('password', utils::encrypt($this->getConfiguration('password')));
  }

  public function getChannel() {
    return intval($this->getConfiguration('defined_channel', 0));
  }

  public function isChannelChild() {
    $defCh = $this->getConfiguration('defined_channel', '');
    return ($defCh !== '' && $defCh !== null);
  }

  public function isNvrParent() {
    if ($this->isChannelChild()) {
      return false;
    }
    $hostType = strtoupper(strval($this->getConfiguration('hostExactType', $this->getConfiguration('exactType', ''))));
    $devType = strtoupper(strval($this->getConfiguration('type', '')));
    $hostChannels = intval($this->getConfiguration('hostChannelNum', $this->getConfiguration('channelNum', 1)));
    return (in_array($hostType, self::MULTI_HOST_TYPES, true)
      || in_array($devType, self::MULTI_HOST_TYPES, true)
      || $hostChannels > 1);
  }

  public function isStandaloneCamera() {
    return !$this->isNvrParent() && !$this->isChannelChild();
  }

  public function hasBattery() {
    if ($this->isNvrParent()) {
      return false;
    }
    if (intval($this->getConfiguration('hasBattery', 0)) === 1) {
      return true;
    }
    return is_object($this->getCmd(null, 'BatteryPercent'));
  }

  public function isBatterySleeping() {
    if (!$this->hasBattery()) {
      return false;
    }
    $sleepCmd = $this->getCmd(null, 'BatterySleepState');
    if (is_object($sleepCmd)) {
      return (intval($sleepCmd->execCmd()) === 1);
    }
    return false;
  }

  public static function normalizeChargeStatus($raw) {
    if (is_int($raw) || (is_string($raw) && is_numeric($raw))) {
      $val = intval($raw);
      return in_array($val, array(0, 1, 2), true) ? $val : 0;
    }
    $str = strtolower(trim(strval($raw)));
    if ($str === 'charging') {
      return 1;
    }
    if ($str === 'chargecomplete') {
      return 2;
    }
    return 0;
  }

  public static function formatChargeStatus($chargeStatus) {
    switch (intval($chargeStatus)) {
      case 1:
        return __('En charge', __FILE__);
      case 2:
        return __('Charge terminée', __FILE__);
      default:
        return __('Décharge', __FILE__);
    }
  }

  public function applyBatteryInfo($bat) {
    if (!is_array($bat)) {
      return false;
    }
    if (isset($bat['batteryPercent']) && is_numeric($bat['batteryPercent'])) {
      $pct = max(0, min(100, intval($bat['batteryPercent'])));
      $this->checkAndUpdateCmd('BatteryPercent', $pct);
      $this->batteryStatus($pct);
    }
    if (array_key_exists('chargeStatus', $bat)) {
      $cs = self::normalizeChargeStatus($bat['chargeStatus']);
      $this->checkAndUpdateCmd('BatteryCharging', ($cs === 1) ? 1 : 0);
      $this->checkAndUpdateCmd('BatteryChargeStatus', self::formatChargeStatus($cs));
    }
    if (isset($bat['temperature']) && is_numeric($bat['temperature'])) {
      $this->checkAndUpdateCmd('BatteryTemperature', intval($bat['temperature']));
    }
    if (array_key_exists('sleeping', $bat) && $bat['sleeping'] !== null && $bat['sleeping'] !== '') {
      $this->checkAndUpdateCmd('BatterySleepState', intval($bat['sleeping']) === 1 ? 1 : 0);
    }
    return true;
  }

  public static function shouldWakeBatteryChannel($camera, $forceWake = false, $isSleeping = true) {
    if ($forceWake) {
      return true;
    }
    if (!is_object($camera)) {
      return false;
    }
    $lastWake = intval(cache::byKey('reolink::battery_last_wake::' . $camera->getId())->getValue(0));
    $now = time();
    if (!$isSleeping && ($now - $lastWake) > self::BATTERY_PASSIVE_WAKE_INTERVAL) {
      return true;
    }
    if (($now - $lastWake) > self::BATTERY_WAKE_INTERVAL) {
      return true;
    }
    return false;
  }

  public static function isNonWakingPayload($payloadJson) {
    $decoded = json_decode(strval($payloadJson), true);
    if (!is_array($decoded) || empty($decoded['cmd'])) {
      return false;
    }
    return in_array(strval($decoded['cmd']), self::NONE_WAKING_COMMANDS, true);
  }

  /**
   * Standalone camera with Privacy ON or still waking HTTP after disable.
   * Blocks concurrent HTTPS Login/Snap storms (plugin Caméra widget).
   */
  public function isStandalonePrivacyBlocking() {
    if (!$this->isStandaloneCamera()) {
      return false;
    }
    $priv = $this->getCmd(null, 'SetPrivacyModeState');
    if (is_object($priv) && intval($priv->execCmd()) === 1) {
      return true;
    }
    $until = intval(cache::byKey('reolink::privacy_wakeup::' . $this->getId())->getValue(0));
    return ($until > time());
  }

  /**
   * Poll CGI endpoint until HTTP(S) + FastCGI actually answer HTTP 200 JSON after privacy wake-up.
   */
  public static function waitHttpPortReady($eqLogic, $timeoutSec = 20) {
    if (!is_object($eqLogic)) {
      return false;
    }
    $host = trim(strval($eqLogic->getConfiguration('adresseip')));
    if ($host === '') {
      return false;
    }
    $cnxtype = strtolower(strval($eqLogic->getConfiguration('cnxtype', 'https')));
    if ($cnxtype !== 'http' && $cnxtype !== 'https') {
      $cnxtype = 'https';
    }
    $port = intval(preg_replace('/\D/', '', strval($eqLogic->getConfiguration('port', ''))));
    if ($port <= 0) {
      $port = ($cnxtype === 'http') ? 80 : 443;
    }
    $url = $cnxtype . '://' . $host . ':' . $port . '/cgi-bin/api.cgi?cmd=GetDevInfo';
    $probeBody = '[{"cmd":"GetDevInfo","action":0,"param":{}}]';
    $startedAt = microtime(true);
    $deadline = $startedAt + max(1, intval($timeoutSec));
    while (microtime(true) < $deadline) {
      $ch = curl_init($url);
      curl_setopt($ch, CURLOPT_POST, true);
      curl_setopt($ch, CURLOPT_POSTFIELDS, $probeBody);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
      curl_setopt($ch, CURLOPT_TIMEOUT, 3);
      curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
      curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
      curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
      $resp = curl_exec($ch);
      $httpCode = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
      curl_close($ch);
      if ($httpCode === 200 && is_string($resp) && strpos(ltrim($resp), '[') === 0) {
        $elapsed = round(microtime(true) - $startedAt, 2);
        log::add('reolink', 'debug', 'waitHttpPortReady: CGI ' . $cnxtype . '://' . $host . ':' . $port . ' prêt en ' . $elapsed . 's');
        return true;
      }
      usleep(500000);
    }
    log::add('reolink', 'warning', 'waitHttpPortReady: CGI ' . $cnxtype . '://' . $host . ':' . $port . ' encore indisponible après ' . $timeoutSec . 's');
    return false;
  }

  public static function resetConnectionCache($eqLogic) {
    if (!is_object($eqLogic)) {
      return;
    }
    $key = strval($eqLogic->getConfiguration('adresseip')) . '|' . strval($eqLogic->getConfiguration('port'));
    if (isset(self::$_cnxByIp[$key])) {
      unset(self::$_cnxByIp[$key]);
    }
  }

  public static function clearLinkedCameraCache($reolinkEq) {
    if (!is_object($reolinkEq) || !class_exists('camera')) {
      return;
    }
    try {
      $reolinkId = intval($reolinkEq->getId());
      $ip = strval($reolinkEq->getConfiguration('adresseip'));
      $channel = strval($reolinkEq->getChannel());
      foreach (eqLogic::byType('camera') as $camEq) {
        $linkedId = intval($camEq->getConfiguration('reolink_eqLogic_id', 0));
        $sameTarget = ($linkedId === $reolinkId)
          || ($linkedId === 0
            && strval($camEq->getConfiguration('ip')) === $ip
            && strval($camEq->getConfiguration('reolink_channel', '')) === $channel);
        if ($sameTarget) {
          cache::delete('camera' . $camEq->getId() . 'cache');
          cache::delete('camera' . $camEq->getId() . 'inprogress');
        }
      }
    } catch (Exception $e) {
      log::add('reolink', 'debug', 'clearLinkedCameraCache: ' . $e->getMessage());
    }
  }

  /**
   * Command scope: host = NVR parent or standalone device; channel = channel child or standalone.
   */
  public function allowsCommandScope($scope) {
    $scope = ($scope === null || $scope === '') ? 'channel' : strval($scope);
    if ($scope === 'any') {
      return true;
    }
    if ($this->isNvrParent()) {
      return ($scope === 'host');
    }
    if ($this->isChannelChild()) {
      return ($scope === 'channel');
    }
    // Standalone IPC: host + channel commands
    return ($scope === 'host' || $scope === 'channel');
  }

  public function getImage() {
    $file = dirname(__FILE__) . '/../../desktop/img/camera' . $this->getId() . '.png';
    if (file_exists($file) && filesize($file) > 100) {
      return 'plugins/reolink/desktop/img/camera' . $this->getId() . '.png?v=' . filemtime($file);
    }
    return parent::getImage();
  }

  public static function buildRtspTemplate($rawUrl, $channel = 0, $isSub = true) {
    $rawUrl = trim(strval($rawUrl));
    if (strpos($rawUrl, 'rtsp://#username#:#password#@#ip#:#port#/') === 0) {
      return $rawUrl;
    }
    if ($rawUrl !== '' && preg_match('~^rtsp://(?:[^/@]+@)?[^/:]+(?::\d+)?(/.*)$~i', $rawUrl, $m)) {
      return 'rtsp://#username#:#password#@#ip#:#port#' . $m[1];
    }
    $chStr = str_pad(strval(intval($channel) + 1), 2, '0', STR_PAD_LEFT);
    $path = $isSub ? ('/h264Preview_' . $chStr . '_sub') : ('/Preview_' . $chStr . '_main');
    return 'rtsp://#username#:#password#@#ip#:#port#' . $path;
  }

  public function resolveRtspUrl($templateOrUrl, $isSub = true) {
    $tpl = reolink::buildRtspTemplate($templateOrUrl, $this->getChannel(), $isSub);
    $rtspPort = intval(preg_replace('/\D/', '', strval($this->getConfiguration('rtspPort', '554'))));
    if ($rtspPort <= 0) {
      $rtspPort = 554;
    }
    $replace = array(
      '#username#' => rawurlencode(strval($this->getConfiguration('login'))),
      '#password#' => rawurlencode(strval($this->getConfiguration('password'))),
      '#ip#' => strval($this->getConfiguration('adresseip')),
      '#port#' => strval($rtspPort),
    );
    return str_replace(array_keys($replace), array_values($replace), $tpl);
  }

  public static function downloadCamIcon($id, $model, $camera = null) {
    if ($model === '' || $model === null) {
      return '';
    }
    $modelURL = rawurlencode(strval($model));
    $iconurl = "https://cdn.reolink.com/wp-content/assets/app/model-images/$modelURL/light_off.png";
    if (!is_object($camera)) {
      $camera = reolink::byId($id, 'reolink');
    }
    if (is_object($camera)) {
      $camera->setConfiguration('camicon', $iconurl);
    }
    $dir = realpath(dirname(__FILE__) . '/../../desktop');
    if ($dir === false) {
      return $iconurl;
    }
    if (!file_exists($dir . '/img')) {
      mkdir($dir . '/img', 0775, true);
    }
    $fileToWrite = $dir . '/img/camera' . $id . '.png';
    $ch = curl_init($iconurl);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_BINARYTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $rawdata = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($httpcode == 200 && $rawdata !== false && strlen($rawdata) > 100) {
      $fp = fopen($fileToWrite, 'w');
      fwrite($fp, $rawdata);
      fclose($fp);
      @chown($fileToWrite, 'www-data');
      @chgrp($fileToWrite, 'www-data');
    } else {
      log::add('reolink', 'warning', 'Icône CDN HTTP ' . $httpcode . ' pour modèle ' . $model . ' (URL ' . $iconurl . ')');
    }
    curl_close($ch);
    return $iconurl;
  }

  public static function mapModelToCameraDevice($model) {
    $m = strtolower(strval($model));
    if ($m === '') {
      return '';
    }
    if (strpos($m, 'trackmix') !== false) {
      return 'reolink.trackMix';
    }
    if (strpos($m, 'e1 pro') !== false) {
      return 'reolink.E1_Pro';
    }
    if (strpos($m, 'e1 zoom') !== false || strpos($m, 'e1 outdoor') !== false) {
      return 'reolink.E1_Zoom';
    }
    if (strpos($m, 'rlc-410-5') !== false) {
      return 'reolink.rlc-410-5mp';
    }
    if (strpos($m, 'rlc-410') !== false) {
      return 'reolink.rlc-410w';
    }
    return '';
  }

  public static function applyAiStateCmds($eqLogic, $aiValue) {
    if (!is_array($aiValue) || !is_object($eqLogic)) {
      return;
    }
    $map = array(
      'people' => 'EvPeopleDetect',
      'vehicle' => 'EvVehicleDetect',
      'dog_cat' => 'EvDogCatDetect',
      'face' => 'EvFaceDetect',
      'package' => 'EvPackageDetect',
    );
    foreach ($map as $key => $cmd) {
      if (!array_key_exists($key, $aiValue)) {
        continue;
      }
      $v = $aiValue[$key];
      $state = null;
      if (is_array($v)) {
        $state = $v['alarm_state'] ?? null;
      } elseif (is_numeric($v)) {
        $state = intval($v);
      }
      if ($state !== null) {
        $eqLogic->checkAndUpdateCmd($cmd, $state);
      }
    }
  }

  public static function TryConnect($id) {
    $reolinkConn = reolink::getReolinkConnection($id);
    if (is_object($reolinkConn) && $reolinkConn->is_loggedin == true) {
      log::add('reolink', 'info', 'Connection à la caméra réussie');
      return true;
    } else {
      log::add('reolink', 'error', 'Connection à la caméra NOK');
      return false;
    }
  }

  public static function GetCamNFO($id) {
    log::add('reolink', 'debug', 'Obtention des informations de la caméra');
    $camera = reolink::byId($id, 'reolink');
    if (!is_object($camera)) {
      return false;
    }
    if ($camera->isStandalonePrivacyBlocking()) {
      log::add('reolink', 'debug', 'GetCamNFO ignoré : mode confidentialité / réveil HTTP');
      return false;
    }
    $channel = $camera->getChannel();
    $isChannel = $camera->isChannelChild();

    $reolinkConn = reolink::getReolinkConnection($id);
    if (!is_object($reolinkConn)) {
      return false;
    }

    $batch = array(
      array('cmd' => 'GetDevInfo'),
      array('cmd' => 'GetP2P'),
      array('cmd' => 'GetLocalLink'),
      array('cmd' => 'GetNetPort'),
    );
    if (!$camera->isNvrParent()) {
      $batch[] = array('cmd' => 'GetAiState', 'action' => 0, 'param' => array('channel' => $channel));
      $batch[] = array('cmd' => 'GetRtspUrl', 'action' => 0, 'param' => array('channel' => $channel));
    }
    if ($isChannel) {
      $batch[] = array('cmd' => 'GetChnTypeInfo', 'action' => 0, 'param' => array('channel' => $channel));
      $batch[] = array('cmd' => 'GetChannelstatus');
    }
    $deviceInfo = $reolinkConn->SendCMD(json_encode($batch));
    if (!$deviceInfo || !isset($deviceInfo[0]['value']['DevInfo'])) {
      return false;
    }

    $devInfo = $deviceInfo[0]['value']['DevInfo'];
    // Always keep host/NVR identity separately (needed for channel cameras behind NVR)
    $camera->setConfiguration('hostExactType', $devInfo['exactType'] ?? ($devInfo['type'] ?? ''));
    $camera->setConfiguration('hostChannelNum', $devInfo['channelNum'] ?? 1);
    $camera->setConfiguration('hostModel', $devInfo['model'] ?? '');
    $camera->setConfiguration('hostName', $devInfo['name'] ?? '');
    $camera->setConfiguration('hostFirmVer', $devInfo['firmVer'] ?? '');

    if ($camera->isNvrParent() || $camera->isStandaloneCamera()) {
      foreach ($devInfo as $key => $value) {
        $camera->setConfiguration($key, $value);
      }
      reolink::downloadCamIcon($id, $devInfo['model'] ?? '', $camera);
    } else {
      // Channel child: do not overwrite camera model with NVR DevInfo
      $camera->setConfiguration('exactType', $devInfo['exactType'] ?? 'NVR');
      $camera->setConfiguration('channelNum', $devInfo['channelNum'] ?? 1);
      $camera->setConfiguration('nvrModel', $devInfo['model'] ?? '');
      $camera->setConfiguration('nvrName', $devInfo['name'] ?? '');
      $camera->setConfiguration('nvrFirmVer', $devInfo['firmVer'] ?? '');
      $camera->setConfiguration('nvrHardVer', $devInfo['hardVer'] ?? '');
    }

    $byCmd = array();
    foreach ($deviceInfo as $item) {
      if (isset($item['cmd'])) {
        $byCmd[$item['cmd']] = $item;
      }
    }
    // API may answer GetChannelstatus with that exact casing
    if (!isset($byCmd['GetChannelstatus']) && isset($byCmd['GetChannelStatus'])) {
      $byCmd['GetChannelstatus'] = $byCmd['GetChannelStatus'];
    }

    if (isset($byCmd['GetP2P']['value']['P2p']['uid']) && !$isChannel) {
      $camera->setConfiguration('uid', $byCmd['GetP2P']['value']['P2p']['uid']);
    }
    if (isset($byCmd['GetLocalLink']['value']['LocalLink']['activeLink'])) {
      $camera->setConfiguration('linkconnection', $byCmd['GetLocalLink']['value']['LocalLink']['activeLink']);
    }
    if (isset($byCmd['GetAiState']['value'])) {
      $camera->setConfiguration('supportai', 'Oui');
    } elseif (!$camera->isNvrParent()) {
      $camera->setConfiguration('supportai', 'Non');
    }
    if (isset($byCmd['GetNetPort']['value']['NetPort']) && is_array($byCmd['GetNetPort']['value']['NetPort'])) {
      foreach ($byCmd['GetNetPort']['value']['NetPort'] as $key => $value) {
        if (strpos($key, 'Enable') !== false) {
          $value = str_replace(array('0', '1'), array('Désactivé', 'Actif'), strval($value));
        }
        $camera->setConfiguration($key, $value);
      }
    }

    if ($isChannel) {
      $typeInfo = $byCmd['GetChnTypeInfo']['value']['typeInfo'] ?? null;
      if ($typeInfo) {
        $camera->setConfiguration('model', $typeInfo);
        $camera->setConfiguration('camModel', $typeInfo);
        if (isset($byCmd['GetChnTypeInfo']['value']['firmVer'])) {
          $camera->setConfiguration('firmVer', $byCmd['GetChnTypeInfo']['value']['firmVer']);
        }
        if (isset($byCmd['GetChnTypeInfo']['value']['boardInfo'])) {
          $camera->setConfiguration('hardVer', $byCmd['GetChnTypeInfo']['value']['boardInfo']);
          $camera->setConfiguration('boardInfo', $byCmd['GetChnTypeInfo']['value']['boardInfo']);
        }
        reolink::downloadCamIcon($id, $typeInfo, $camera);
      }
      $statuses = $byCmd['GetChannelstatus']['value']['status'] ?? array();
      foreach ($statuses as $st) {
        if (!is_array($st) || intval($st['channel'] ?? -1) !== $channel) {
          continue;
        }
        if (!empty($st['name'])) {
          $camera->setConfiguration('name', $st['name']);
        }
        if (!empty($st['uid'])) {
          $camera->setConfiguration('uid', $st['uid']);
        }
        if (!empty($st['typeInfo']) && !$typeInfo) {
          $camera->setConfiguration('model', $st['typeInfo']);
          $camera->setConfiguration('camModel', $st['typeInfo']);
          reolink::downloadCamIcon($id, $st['typeInfo'], $camera);
        }
        if (array_key_exists('sleep', $st) && is_object($camera->getCmd(null, 'BatterySleepState'))) {
          $camera->checkAndUpdateCmd('BatterySleepState', intval($st['sleep']) === 1 ? 1 : 0);
        }
        break;
      }
    }

    // Build tokenized RTSP templates (never store plaintext credentials in URLs)
    if (!$camera->isNvrParent()) {
      $rawMain = '';
      $rawSub = '';
      if (isset($byCmd['GetRtspUrl']['value']['rtspUrl']) && is_array($byCmd['GetRtspUrl']['value']['rtspUrl'])) {
        $rtsp = $byCmd['GetRtspUrl']['value']['rtspUrl'];
        $rawMain = strval($rtsp['mainStream'] ?? '');
        $rawSub = strval($rtsp['subStream'] ?? '');
      }
      $camera->setConfiguration('rtspMainUrl', reolink::buildRtspTemplate($rawMain, $channel, false));
      $camera->setConfiguration('rtspSubUrl', reolink::buildRtspTemplate($rawSub, $channel, true));
    }

    $camera->save();
    return true;
  }

  public static function getSnapshot($_cameraEqLogic) {
    if (!is_object($_cameraEqLogic)) {
      return '';
    }
    $reolinkId = intval($_cameraEqLogic->getConfiguration('reolink_eqLogic_id', 0));
    $reolinkEq = ($reolinkId > 0) ? reolink::byId($reolinkId, 'reolink') : null;
    if (!is_object($reolinkEq)) {
      $ip = strval($_cameraEqLogic->getConfiguration('ip'));
      $ch = $_cameraEqLogic->getConfiguration('reolink_channel', '');
      foreach (eqLogic::byType('reolink', true) as $eq) {
        if (strval($eq->getConfiguration('adresseip')) !== $ip) {
          continue;
        }
        if ($ch !== '' && strval($eq->getConfiguration('defined_channel', '')) !== strval($ch)) {
          continue;
        }
        if ($ch === '' && $eq->isNvrParent()) {
          continue;
        }
        $reolinkEq = $eq;
        break;
      }
    }
    if (!is_object($reolinkEq) || $reolinkEq->isNvrParent()) {
      return '';
    }
    return reolink::captureSnapshotSafe($reolinkEq, 'sub');
  }

  public static function captureSnapshotSafe($reolinkEq, $quality = 'sub') {
    if (!is_object($reolinkEq) || $reolinkEq->isNvrParent()) {
      return '';
    }
    if ($reolinkEq->isStandalonePrivacyBlocking()) {
      return '';
    }
    $quality = ($quality === 'main') ? 'main' : 'sub';
    $snapCacheKey = 'reolink::last_snap::' . $reolinkEq->getId() . '::' . $quality;
    if ($reolinkEq->hasBattery() && $reolinkEq->isBatterySleeping()) {
      $cachedB64 = strval(cache::byKey($snapCacheKey)->getValue(''));
      if ($cachedB64 !== '') {
        $decoded = base64_decode($cachedB64, true);
        if ($decoded !== false && $decoded !== '') {
          return $decoded;
        }
      }
      return '';
    }
    $cnx = reolink::getReolinkConnection($reolinkEq->getId());
    if (!is_object($cnx)) {
      return '';
    }
    $jpeg = $cnx->SendSnap($reolinkEq->getChannel(), $quality);
    if ($jpeg === false || $jpeg === '') {
      return '';
    }
    if ($reolinkEq->hasBattery()) {
      cache::set($snapCacheKey, base64_encode($jpeg));
    }
    return $jpeg;
  }

  public static function syncCameraPlugin($id = null) {
    try {
      $camPlugin = plugin::byId('camera');
    } catch (Exception $e) {
      throw new Exception(__('Plugin Caméra absent ou inactif', __FILE__));
    }
    if (!is_object($camPlugin) || !$camPlugin->isActive()) {
      throw new Exception(__('Plugin Caméra absent ou inactif', __FILE__));
    }
    if (!class_exists('camera')) {
      include_file('core', 'camera', 'class', 'camera');
    }
    $targets = array();
    if ($id !== null) {
      $eq = reolink::byId($id, 'reolink');
      if (!is_object($eq) || $eq->isNvrParent()) {
        throw new Exception(__('Équipement Reolink invalide', __FILE__));
      }
      $targets[] = $eq;
    } else {
      foreach (eqLogic::byType('reolink', true) as $eq) {
        if (!$eq->isNvrParent()) {
          $targets[] = $eq;
        }
      }
    }
    $synced = 0;
    foreach ($targets as $reolinkEq) {
      if ($reolinkEq->getConfiguration('rtspSubUrl') == '') {
        reolink::GetCamNFO($reolinkEq->getId());
        $reolinkEq = reolink::byId($reolinkEq->getId(), 'reolink');
      }
      $ip = strval($reolinkEq->getConfiguration('adresseip'));
      $channel = intval($reolinkEq->getChannel());
      $camEq = null;
      foreach (eqLogic::byType('camera') as $c) {
        if (intval($c->getConfiguration('reolink_eqLogic_id', 0)) === intval($reolinkEq->getId())) {
          $camEq = $c;
          break;
        }
      }
      if (!is_object($camEq)) {
        foreach (eqLogic::byType('camera') as $c) {
          if (strval($c->getConfiguration('ip')) === $ip
            && strval($c->getConfiguration('reolink_channel', '')) === strval($channel)) {
            $camEq = $c;
            break;
          }
        }
      }
      if (!is_object($camEq)) {
        $camEq = new camera();
        $camEq->setEqType_name('camera');
        $camEq->setName('Caméra ' . $reolinkEq->getName());
        $camEq->setIsEnable(1);
        $camEq->setIsVisible(1);
        $camEq->setObject_id($reolinkEq->getObject_id());
      } else if (intval($reolinkEq->getObject_id()) > 0) {
        $camEq->setObject_id($reolinkEq->getObject_id());
      }
      $cnxType = strtolower(trim(strval($reolinkEq->getConfiguration('cnxtype', ''))));
      $proto = ($cnxType === 'https') ? 'https' : 'http';
      $configuredPort = preg_replace('/\D/', '', strval($reolinkEq->getConfiguration('port', '')));
      if ($configuredPort !== '') {
        $webPort = $configuredPort;
      } else if ($proto === 'https') {
        $webPort = preg_replace('/\D/', '', strval($reolinkEq->getConfiguration('httpsPort', '443'))) ?: '443';
      } else {
        $webPort = preg_replace('/\D/', '', strval($reolinkEq->getConfiguration('httpPort', '80'))) ?: '80';
      }
      $rtspPort = preg_replace('/\D/', '', strval($reolinkEq->getConfiguration('rtspPort', '554'))) ?: '554';
      $subTpl = reolink::buildRtspTemplate($reolinkEq->getConfiguration('rtspSubUrl'), $channel, true);
      $mainTpl = reolink::buildRtspTemplate($reolinkEq->getConfiguration('rtspMainUrl'), $channel, false);
      if ($reolinkEq->getConfiguration('rtspSubUrl') !== $subTpl || $reolinkEq->getConfiguration('rtspMainUrl') !== $mainTpl) {
        $reolinkEq->setConfiguration('rtspSubUrl', $subTpl);
        $reolinkEq->setConfiguration('rtspMainUrl', $mainTpl);
        $reolinkEq->save();
      }
      $camStreamUrl = ($cnxType === 'baichuan') ? '' : str_replace('#port#', $rtspPort, $subTpl);
      $model = strval($reolinkEq->getConfiguration('camModel', $reolinkEq->getConfiguration('model', '')));
      $deviceId = reolink::mapModelToCameraDevice($model);
      $camEq->setConfiguration('ip', $ip);
      $camEq->setConfiguration('port', $webPort);
      $camEq->setConfiguration('username', $reolinkEq->getConfiguration('login'));
      $camEq->setConfiguration('password', $reolinkEq->getConfiguration('password'));
      $camEq->setConfiguration('protocole', $proto);
      $camEq->setConfiguration('urlStream', 'reolink::getSnapshot');
      $camEq->setConfiguration('cameraStreamAccessUrl', $camStreamUrl);
      $camEq->setConfiguration('rtsp_option', '-rtsp_transport tcp');
      $camEq->setConfiguration('reolink_channel', strval($channel));
      $camEq->setConfiguration('reolink_eqLogic_id', intval($reolinkEq->getId()));
      $camEq->setConfiguration('reolink_model', $model);
      if ($deviceId !== '') {
        // Set both to avoid camera plugin re-import overwriting our Snap/RTSP bridge
        $camEq->setConfiguration('device', $deviceId);
        $camEq->setConfiguration('applyDevice', $deviceId);
      }
      if ($model !== '' && strpos($camEq->getName(), $model) === false && strpos($camEq->getName(), 'Caméra ') === 0) {
        $camEq->setName('Caméra ' . $reolinkEq->getName() . ' (' . $model . ')');
      }
      $camEq->save();
      $synced++;
    }
    return $synced;
  }

  public static function ensureGo2rtcStreams($id) {
    $camera = reolink::byId($id, 'reolink');
    if (!is_object($camera) || $camera->isNvrParent()) {
      throw new Exception(__('Équipement Reolink invalide', __FILE__));
    }
    if ($camera->getConfiguration('rtspSubUrl') == '' || $camera->getConfiguration('rtspMainUrl') == '') {
      reolink::GetCamNFO($id);
      $camera = reolink::byId($id, 'reolink');
    }
    $streams = array(
      'reolink_' . $id . '_sub' => $camera->resolveRtspUrl($camera->getConfiguration('rtspSubUrl'), true),
      'reolink_' . $id . '_main' => $camera->resolveRtspUrl($camera->getConfiguration('rtspMainUrl'), false),
    );
    $registered = 0;
    foreach ($streams as $name => $src) {
      if ($src === '' || $src === null) {
        continue;
      }
      // PATCH = in-memory update/create without rewriting go2rtc.yaml
      $url = 'http://127.0.0.1:1984/api/streams?name=' . rawurlencode($name) . '&src=' . rawurlencode($src);
      $ch = curl_init($url);
      curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
      curl_setopt($ch, CURLOPT_TIMEOUT, 5);
      $body = curl_exec($ch);
      $code = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
      $err = curl_error($ch);
      curl_close($ch);
      if ($code < 200 || $code >= 300) {
        log::add('reolink', 'warning', 'go2rtc stream ' . $name . ' HTTP ' . $code . ' ' . strval($body) . ' ' . $err);
        if ($code === 0) {
          throw new Exception(__('Service go2rtc injoignable (127.0.0.1:1984) : vérifiez que les dépendances sont à jour et que le démon Reolink est démarré', __FILE__));
        }
        throw new Exception(__('Échec enregistrement flux go2rtc', __FILE__) . ' (' . $name . ' HTTP ' . $code . ')');
      }
      $registered++;
    }
    if ($registered === 0) {
      throw new Exception(__('Aucune URL RTSP disponible pour cette caméra', __FILE__));
    }
    return array_keys($streams);
  }

  public static function GetCamAbility($id) {
    log::add('reolink', 'debug', 'Interrogation de la caméra sur ses capacités hardware/software...');
    $camera = reolink::byId($id, 'reolink');
    if (!is_object($camera)) {
      return false;
    }
    if ($camera->isNvrParent()) {
      log::add('reolink', 'info', 'GetCamAbility ignoré : équipement NVR parent');
      return false;
    }
    if ($camera->isStandalonePrivacyBlocking()) {
      log::add('reolink', 'debug', 'GetCamAbility ignoré : mode confidentialité / réveil HTTP');
      return false;
    }
    $reolinkConn = reolink::getReolinkConnection($id);
    if (!is_object($reolinkConn)) {
      return false;
    }

    $username = $camera->getConfiguration('login');
    if (empty($username)) {
      $username = "admin";
    }
    $channel = $camera->getChannel();

    $deviceAbility = $reolinkConn->SendCMD('[{"cmd":"GetAbility","param":{"User":{"userName":"' . $username . '"}}}]');
    if (!is_array($deviceAbility) || !isset($deviceAbility[0]['value']['Ability'])) {
      log::add('reolink', 'error', 'GetAbility : réponse invalide');
      return false;
    }

    log::add('reolink', 'debug', print_r($deviceAbility, true));

    $ab1 = $deviceAbility[0]['value']['Ability'];
    $abilityChn = $ab1['abilityChn'] ?? array();
    unset($ab1['abilityChn']);
    $ab2 = $abilityChn[$channel] ?? ($abilityChn[0] ?? array());
    if (!is_array($ab2)) {
      $ab2 = array();
    }
    $deviceAbility = array_merge($ab1, $ab2);

    // NVR E1 Outdoor / TrackMix: GetAbility may report ptzPatrol.permit=0 or ver=0 while Get/SetPtzPatrol work
    $ptzPatrolPermit = intval($deviceAbility['ptzPatrol']['permit'] ?? 0);
    $ptzPatrolVer = intval($deviceAbility['ptzPatrol']['ver'] ?? 0);
    $ptzPresetPermit = intval($deviceAbility['ptzPreset']['permit'] ?? 0);
    if (($ptzPatrolPermit == 0 || $ptzPatrolVer <= 0) && $ptzPresetPermit != 0) {
      $patrol = $reolinkConn->SendCMD('[{"cmd":"GetPtzPatrol","action":0,"param":{"channel":' . $channel . '}}]');
      if (is_array($patrol) && isset($patrol[0]['value']['PtzPatrol']) && is_array($patrol[0]['value']['PtzPatrol'])) {
        $deviceAbility['ptzPatrol'] = array(
          'permit' => max(1, $ptzPatrolPermit),
          'ver' => max(1, $ptzPatrolVer),
        );
        log::add('reolink', 'info', 'ptzPatrol activé via GetPtzPatrol (canal ' . $channel . ')');
      }
    }

    // Legacy firmwares: zoom/focus under short keys
    if (!isset($deviceAbility['supportZoom']) && isset($deviceAbility['zoom'])) {
      $deviceAbility['supportZoom'] = $deviceAbility['zoom'];
    }
    if (!isset($deviceAbility['supportFocus']) && isset($deviceAbility['focus'])) {
      $deviceAbility['supportFocus'] = $deviceAbility['focus'];
    }

    // Privacy Mode (Baichuan TCP) — triple-guard like reolink_aio (no false positives)
    $deviceAbility['privacyMode'] = array('permit' => 0, 'ver' => 0);
    $bc = reolinkBaichuan::fromEqLogic($camera);
    if (is_object($bc)) {
      $isNvr = ($camera->isNvrParent() || $camera->isChannelChild());
      $privacy = $bc->probePrivacySupport($channel, $isNvr);
      if ($privacy !== null) {
        $deviceAbility['privacyMode'] = array('permit' => 1, 'ver' => 1);
        log::add('reolink', 'info', 'privacyMode détecté via Baichuan (canal ' . $channel . ', sleep=' . ($privacy ? '1' : '0') . ')');
      }
    }

    // Battery cameras behind NVR / Home Hub (reolink_aio: battery + sleep + temperature)
    $batVer = intval($deviceAbility['battery']['ver'] ?? 0);
    $batPermit = intval($deviceAbility['battery']['permit'] ?? 0);
    $hasBat = ($batVer > 0 && $batPermit > 0);
    $batData = null;
    if ($hasBat) {
      $batResp = $reolinkConn->SendCMD('[{"cmd":"GetBatteryInfo","action":0,"param":{"channel":' . intval($channel) . '}}]');
      if (is_array($batResp) && isset($batResp[0]['value']['Battery']) && is_array($batResp[0]['value']['Battery'])) {
        $batData = $batResp[0]['value']['Battery'];
      } elseif (is_object($bc)) {
        $batData = $bc->getBatteryInfo($channel);
      }
    }
    $deviceAbility['battery'] = array('permit' => $hasBat ? 4 : 0, 'ver' => $hasBat ? max(1, $batVer) : 0);
    $hasTemp = ($hasBat && is_array($batData) && array_key_exists('temperature', $batData) && $batData['temperature'] !== null && $batData['temperature'] !== '');
    $deviceAbility['batteryTemp'] = array('permit' => $hasTemp ? 4 : 0, 'ver' => $hasTemp ? 1 : 0);
    $hasSleep = false;
    if ($hasBat) {
      $stResp = $reolinkConn->SendCMD('[{"cmd":"GetChannelstatus","action":0,"param":{}}]');
      $stList = $stResp[0]['value']['status'] ?? array();
      if (is_array($stList)) {
        foreach ($stList as $stEntry) {
          if (is_array($stEntry) && intval($stEntry['channel'] ?? -1) === intval($channel) && array_key_exists('sleep', $stEntry)) {
            $hasSleep = true;
            break;
          }
        }
      }
      if (!$hasSleep && is_array($batData) && array_key_exists('sleeping', $batData)) {
        $hasSleep = true;
      }
      if (!$hasSleep && is_object($bc) && $bc->getChannelSleepState($channel) !== null) {
        $hasSleep = true;
      }
      log::add('reolink', 'info', 'Caméra sur batterie détectée sur canal ' . $channel . ' (sleep=' . ($hasSleep ? '1' : '0') . ', temp=' . ($hasTemp ? '1' : '0') . ')');
    }
    $deviceAbility['batterySleep'] = array('permit' => $hasSleep ? 4 : 0, 'ver' => $hasSleep ? 1 : 0);
    if (intval($camera->getConfiguration('hasBattery', -1)) !== ($hasBat ? 1 : 0)) {
      $camera->setConfiguration('hasBattery', $hasBat ? 1 : 0);
      $camera->save(true);
    }

    // Cross-check AI type support with GetAiState (more accurate than GetAbility alone)
    $aiMap = array(
      'people' => 'supportAiPeople',
      'vehicle' => 'supportAiVehicle',
      'dog_cat' => 'supportAiDogCat',
      'face' => 'supportAiFace',
      'package' => 'supportAiPackage',
    );
    $aiState = $reolinkConn->SendCMD('[{"cmd":"GetAiState","action":0,"param":{"channel":' . intval($channel) . '}}]');
    if (is_array($aiState) && isset($aiState[0]['value']) && is_array($aiState[0]['value'])) {
      foreach ($aiMap as $aiKey => $abilityKey) {
        if (!array_key_exists($aiKey, $aiState[0]['value'])) {
          continue;
        }
        $entry = $aiState[0]['value'][$aiKey];
        $supported = is_array($entry) ? (intval($entry['support'] ?? 0) === 1) : false;
        $deviceAbility[$abilityKey] = array('permit' => $supported ? 6 : 0, 'ver' => $supported ? 1 : 0);
      }
    }

    // Firmware check ability: prefer upgrade, fallback supportRemoteUpgrade
    if ((!isset($deviceAbility['upgrade']) || intval($deviceAbility['upgrade']['permit'] ?? 0) === 0)
      && isset($deviceAbility['supportRemoteUpgrade'])
      && intval($deviceAbility['supportRemoteUpgrade']['permit'] ?? 0) > 0) {
      $deviceAbility['upgrade'] = $deviceAbility['supportRemoteUpgrade'];
    }

    log::add('reolink', 'debug', 'GetAbility à récupérer : ' . count($deviceAbility) . ' items (canal ' . $channel . ')');
    return count($deviceAbility) > 1 ? $deviceAbility : false;
  }

  public static function updatePTZpreset($id, $data) {
    $camera = reolink::byId($id, 'reolink');
    if (!is_object($camera) || !is_array($data) || !isset($data['value']['PtzPreset'])) {
      return false;
    }
    $cmd = $camera->getCmd(null, 'SetPtzByPreset');
    $ptzlist = "";
    log::add('reolink', 'debug',  'PTZ à parser = ' . print_r($data['value']['PtzPreset'], true));
    if (is_object($cmd)) {
      foreach ($data['value']['PtzPreset'] as $key => $value) {
        if ($value['enable'] == 1) {
          log::add('reolink', 'debug',  'Ajout du PTZ preset = ' . $value['id'] . '|' . $value['name']);
          $ptzlist .=  $value['id'] . '|' . $value['name'] . ";";
        }
      }
      $ptzlist = substr($ptzlist, 0, -1);
      $cmd->setConfiguration('listValue', $ptzlist);
      $cmd->save();
      $cmd->getEqLogic()->refreshWidget();
      return true;
    } else {
      return false;
    }
  }

  public static function updatePTZpatrol($id, $data) {
    $camera = reolink::byId($id, 'reolink');
    if (!is_object($camera) || !is_array($data) || !isset($data['value']['PtzPatrol'])) {
      return false;
    }
    reolink::applyPtzPatrolState($camera, $data);
    return true;
  }

  /**
   * Strip cumulative preset prefix leaked across patrol slots 0..5 by Reolink C++ firmwares
   * (E1 Zoom, E1 Outdoor, TrackMix, RLC-823S2, NVR RLN8/RLN16).
   */
  public static function normalizePatrols($patrols) {
    if (!is_array($patrols) || count($patrols) < 2) {
      return is_array($patrols) ? $patrols : array();
    }
    $stepSig = function ($s) {
      if (!is_array($s)) {
        return '';
      }
      return intval($s['id'] ?? -1) . ':' . intval($s['speed'] ?? 0) . ':' . intval($s['dwellTime'] ?? 0);
    };
    $isCumulative = false;
    $prevRaw = null;
    foreach ($patrols as $idx => $p) {
      $cur = (is_array($p) && isset($p['preset']) && is_array($p['preset'])) ? array_values($p['preset']) : array();
      if ($idx > 0 && is_array($prevRaw) && count($prevRaw) > 0 && count($cur) >= count($prevRaw)) {
        $prefixMatch = true;
        for ($k = 0; $k < count($prevRaw); $k++) {
          if ($stepSig($cur[$k]) !== $stepSig($prevRaw[$k])) {
            $prefixMatch = false;
            break;
          }
        }
        if ($prefixMatch && (empty($p['enable']) || count($cur) > count($prevRaw))) {
          $isCumulative = true;
          break;
        }
      }
      if (count($cur) > 0) {
        $prevRaw = $cur;
      }
    }
    if (!$isCumulative) {
      return $patrols;
    }

    $acc = array();
    $out = array();
    foreach ($patrols as $p) {
      if (!is_array($p)) {
        $out[] = $p;
        continue;
      }
      $raw = (isset($p['preset']) && is_array($p['preset'])) ? array_values($p['preset']) : array();
      $accLen = count($acc);
      $hasPrefix = ($accLen > 0 && count($raw) >= $accLen);
      if ($hasPrefix) {
        for ($k = 0; $k < $accLen; $k++) {
          if ($stepSig($raw[$k]) !== $stepSig($acc[$k])) {
            $hasPrefix = false;
            break;
          }
        }
      }
      if ($hasPrefix) {
        $own = array_slice($raw, $accLen);
        if (count($own) === 0 && !empty($p['enable'])) {
          $own = $raw;
        } else {
          $acc = $raw;
        }
      } else {
        $own = $raw;
        if (count($raw) > 0) {
          $acc = $raw;
        }
      }
      if (empty($p['enable']) && count($own) === 0) {
        $p['preset'] = null;
      } else {
        $p['preset'] = count($own) > 0 ? array_values($own) : null;
      }
      $out[] = $p;
    }
    return $out;
  }

  public static function applyPtzPatrolState($camera, $data) {
    if (!is_object($camera) || !is_array($data) || !isset($data['value']['PtzPatrol'])) {
      return;
    }
    $normalized = reolink::normalizePatrols($data['value']['PtzPatrol']);
    $list = '';
    $running = 0;
    $activeId = null;
    foreach ($normalized as $patrol) {
      if (!is_array($patrol)) {
        continue;
      }
      $id = intval($patrol['id'] ?? -1);
      if ($id < 0) {
        continue;
      }
      if (!empty($patrol['running'])) {
        $running = 1;
        $activeId = $id;
      }
      if (empty($patrol['enable']) && empty($patrol['preset'])) {
        continue;
      }
      $name = trim(strval($patrol['name'] ?? ''));
      if ($name === '') {
        $name = 'Ronde ' . $id;
      }
      $list .= $id . '|' . $name . ';';
    }
    $list = rtrim($list, ';');
    if ($list === '') {
      $list = '0|Ronde 0';
    }
    $cmd = $camera->getCmd(null, 'StartPtzPatrol');
    if (!is_object($cmd)) {
      $cmd = $camera->getCmd(null, 'SetPTZpatrol');
    }
    if (is_object($cmd)) {
      $cmd->setConfiguration('listValue', $list);
      if ($activeId !== null) {
        $cmd->setConfiguration('lastPatrolId', $activeId);
      }
      $cmd->save();
    }
    $camera->checkAndUpdateCmd('PtzPatrolState', $running);
    $camera->checkAndUpdateCmd('PtzPatrolId', $running ? intval($activeId ?? -1) : -1);
    $camera->refreshWidget();
  }

  public static function getPatrolData($id) {
    $camera = reolink::byId($id, 'reolink');
    $cnx = reolink::getReolinkConnection($id);
    if (!is_object($camera) || !is_object($cnx)) {
      throw new Exception(__('Caméra introuvable', __FILE__));
    }
    if ($camera->isNvrParent()) {
      throw new Exception(__('Les rondes PTZ ne s\'appliquent pas à un NVR parent', __FILE__));
    }
    $channel = $camera->getChannel();
    $res = $cnx->SendCMD('[{"cmd":"GetPtzPreset","action":1,"param":{"channel":' . $channel . '}},{"cmd":"GetPtzPatrol","action":0,"param":{"channel":' . $channel . '}}]');
    if (!is_array($res)) {
      throw new Exception(__('Impossible de lire les presets/rondes PTZ', __FILE__));
    }
    $presets = array();
    $patrols = array();
    foreach ($res as $item) {
      if (($item['cmd'] ?? '') === 'GetPtzPreset' && isset($item['value']['PtzPreset'])) {
        foreach ($item['value']['PtzPreset'] as $preset) {
          if (!empty($preset['enable'])) {
            $presets[] = array(
              'id' => intval($preset['id']),
              'name' => strval($preset['name'] ?? ('Preset ' . $preset['id'])),
            );
          }
        }
      }
      if (($item['cmd'] ?? '') === 'GetPtzPatrol' && isset($item['value']['PtzPatrol'])) {
        $patrols = reolink::normalizePatrols($item['value']['PtzPatrol']);
        reolink::applyPtzPatrolState($camera, $item);
      }
    }
    return array(
      'channel' => $channel,
      'presets' => $presets,
      'patrols' => $patrols,
    );
  }

  public static function savePatrol($id, $patrolId, $enable, $steps) {
    $camera = reolink::byId($id, 'reolink');
    $cnx = reolink::getReolinkConnection($id);
    if (!is_object($camera) || !is_object($cnx)) {
      throw new Exception(__('Caméra introuvable', __FILE__));
    }
    if ($camera->isNvrParent()) {
      throw new Exception(__('Les rondes PTZ ne s\'appliquent pas à un NVR parent', __FILE__));
    }
    $channel = $camera->getChannel();
    $patrolId = intval($patrolId);
    if ($patrolId < 0 || $patrolId > 5) {
      throw new Exception(__('Identifiant de ronde invalide (0 à 5)', __FILE__));
    }
    if (!is_array($steps)) {
      $steps = array();
    }
    $preset = array();
    foreach ($steps as $step) {
      if (!is_array($step)) {
        continue;
      }
      $preset[] = array(
        'id' => intval($step['id']),
        'speed' => max(1, min(64, intval($step['speed'] ?? 32))),
        'dwellTime' => max(1, min(300, intval($step['dwellTime'] ?? 30))),
      );
    }
    if (intval($enable) && count($preset) < 1) {
      throw new Exception(__('Une ronde active nécessite au moins un preset', __FILE__));
    }
    $payload = array(array(
      'cmd' => 'SetPtzPatrol',
      'action' => 0,
      'param' => array(
        'channel' => $channel,
        'PtzPatrol' => array(
          'channel' => $channel,
          'id' => $patrolId,
          'enable' => intval($enable) ? 1 : 0,
          'preset' => $preset,
        ),
      ),
    ));
    $res = $cnx->SendCMD(json_encode($payload));
    if (!is_array($res) || !isset($res[0]) || (intval($res[0]['code'] ?? 1) != 0 && intval($res[0]['value']['rspCode'] ?? 0) != 200)) {
      $apiErr = $cnx->getLastApiError();
      throw new Exception(__('Échec SetPtzPatrol', __FILE__) . ($apiErr !== '' ? ' : ' . $apiErr : ''));
    }
    $refresh = $cnx->SendCMD('[{"cmd":"GetPtzPatrol","action":0,"param":{"channel":' . $channel . '}}]');
    // TrackMix PoE firmware ignores SetPtzPatrol with enable=0, keeping slot enabled; sending enable=1 + empty preset clears and disables it
    if (!intval($enable) && is_array($refresh) && isset($refresh[0]['value']['PtzPatrol']) && is_array($refresh[0]['value']['PtzPatrol'])) {
      $stillEnabled = false;
      foreach ($refresh[0]['value']['PtzPatrol'] as $slot) {
        if (is_array($slot) && intval($slot['id'] ?? -1) === $patrolId && !empty($slot['enable'])) {
          $stillEnabled = true;
          break;
        }
      }
      if ($stillEnabled) {
        $clearPayload = array(array(
          'cmd' => 'SetPtzPatrol',
          'action' => 0,
          'param' => array(
            'channel' => $channel,
            'PtzPatrol' => array(
              'channel' => $channel,
              'id' => $patrolId,
              'enable' => 1,
              'preset' => array(),
            ),
          ),
        ));
        $cnx->SendCMD(json_encode($clearPayload));
        $refresh = $cnx->SendCMD('[{"cmd":"GetPtzPatrol","action":0,"param":{"channel":' . $channel . '}}]');
      }
    }
    if (is_array($refresh) && isset($refresh[0])) {
      reolink::applyPtzPatrolState($camera, $refresh[0]);
    }
    return true;
  }

  public static function runPatrol($id, $op, $patrolId = null) {
    $camera = reolink::byId($id, 'reolink');
    $cnx = reolink::getReolinkConnection($id);
    if (!is_object($camera) || !is_object($cnx)) {
      throw new Exception(__('Caméra introuvable', __FILE__));
    }
    if ($camera->isNvrParent()) {
      throw new Exception(__('Les rondes PTZ ne s\'appliquent pas à un NVR parent', __FILE__));
    }
    $channel = $camera->getChannel();
    $op = ($op === 'StopPatrol') ? 'StopPatrol' : 'StartPatrol';
    if ($patrolId === null || $patrolId === '') {
      $cmd = $camera->getCmd(null, 'StartPtzPatrol');
      if (!is_object($cmd)) {
        $cmd = $camera->getCmd(null, 'SetPTZpatrol');
      }
      $patrolId = is_object($cmd) ? intval($cmd->getConfiguration('lastPatrolId', 0)) : 0;
    }
    $patrolId = intval($patrolId);
    $payload = '[{"cmd":"PtzCtrl","action":0,"param":{"channel":' . $channel . ',"op":"' . $op . '","id":' . $patrolId . '}}]';
    $res = $cnx->SendCMD($payload);
    if (!is_array($res) || !isset($res[0]) || (intval($res[0]['code'] ?? 1) != 0 && intval($res[0]['value']['rspCode'] ?? 0) != 200)) {
      $apiErr = $cnx->getLastApiError();
      throw new Exception(__('Échec PtzCtrl ' . $op, __FILE__) . ($apiErr !== '' ? ' : ' . $apiErr : ''));
    }
    $startCmd = $camera->getCmd(null, 'StartPtzPatrol');
    if (is_object($startCmd)) {
      $startCmd->setConfiguration('lastPatrolId', $patrolId);
      $startCmd->save();
    }
    // NVR often keeps running=0; reflect last action immediately
    if ($op === 'StartPatrol') {
      $camera->checkAndUpdateCmd('PtzPatrolState', 1);
      $camera->checkAndUpdateCmd('PtzPatrolId', $patrolId);
    } else {
      $camera->checkAndUpdateCmd('PtzPatrolState', 0);
      $camera->checkAndUpdateCmd('PtzPatrolId', -1);
    }
    $refresh = $cnx->SendCMD('[{"cmd":"GetPtzPatrol","action":0,"param":{"channel":' . $channel . '}}]');
    if (is_array($refresh) && isset($refresh[0]['value']['PtzPatrol'])) {
      $anyRunning = 0;
      foreach ($refresh[0]['value']['PtzPatrol'] as $patrol) {
        if (!empty($patrol['running'])) {
          $anyRunning = 1;
          break;
        }
      }
      reolink::applyPtzPatrolState($camera, $refresh[0]);
      if (!$anyRunning && $op === 'StartPatrol') {
        $camera->checkAndUpdateCmd('PtzPatrolState', 1);
        $camera->checkAndUpdateCmd('PtzPatrolId', $patrolId);
      }
    }
    return true;
  }

  public static function refreshNFO($id, $forceWake = false) {
    $camcmd = reolink::byId($id, 'reolink');
    if (!is_object($camcmd)) {
      log::add('reolink', 'debug', 'refreshNFO ignoré : équipement introuvable');
      return;
    }

    // Privacy Mode via Baichuan before HTTP login (HTTP is down when privacy is ON on standalone cams)
    $privacyInfo = $camcmd->getCmd(null, 'SetPrivacyModeState');
    if (is_object($privacyInfo)) {
      $bc = reolinkBaichuan::fromEqLogic($camcmd);
      if (is_object($bc)) {
        $privacy = $bc->getPrivacyMode($camcmd->getChannel());
        if ($privacy !== null) {
          $camcmd->checkAndUpdateCmd('SetPrivacyModeState', $privacy ? 1 : 0);
          if ($privacy && $camcmd->isStandaloneCamera()) {
            log::add('reolink', 'debug', 'Caméra en mode confidentialité, rafraîchissement HTTP ignoré');
            return;
          }
        }
      }
    }
    if ($camcmd->isStandalonePrivacyBlocking()) {
      log::add('reolink', 'debug', 'refreshNFO ignoré : réveil HTTP après Mode confidentialité');
      return;
    }

    $camcnx = reolink::getReolinkConnection($id);
    $cmdget = NULL;

    if (!is_object($camcnx) || $camcnx->is_loggedin == false) {
      return false;
    }

    if (intval($camcmd->getConfiguration('needs_cmd_resync', 0)) === 1) {
      log::add('reolink', 'info', 'Resync commandes différé pour ' . $camcmd->getHumanName());
      $camcmd->loadCmdFromConf($id, true);
      // Reload camera after possible prune
      $camcmd = reolink::byId($id, 'reolink');
      if (!is_object($camcmd)) {
        return false;
      }
    }

    log::add('reolink', 'debug', 'Rafraichissement des informations de la caméra...');

    $channel = $camcmd->getChannel();
    $isBatteryCam = $camcmd->hasBattery();
    $shouldWakeBattery = true;

    // Battery camera behind NVR / Home Hub: read non-waking battery & sleep status first
    if ($isBatteryCam) {
      $batSleeping = $camcmd->isBatterySleeping();
      $batOnline = true;
      $sleepUpdated = false;
      $batUpdated = false;
      $preBatch = '[{"cmd":"GetChannelstatus","action":0,"param":{}},{"cmd":"GetBatteryInfo","action":0,"param":{"channel":' . intval($channel) . '}}]';
      $preRes = $camcnx->SendCMD($preBatch);
      $preData = is_array($preRes) ? $preRes : $camcnx->lastResponseData;
      if (is_array($preData)) {
        foreach ($preData as $pItem) {
          $pCmd = $pItem['cmd'] ?? '';
          if (($pCmd === 'GetChannelstatus' || $pCmd === 'GetChannelStatus') && isset($pItem['value']['status']) && is_array($pItem['value']['status'])) {
            foreach ($pItem['value']['status'] as $stEntry) {
              if (!is_array($stEntry) || intval($stEntry['channel'] ?? -1) !== intval($channel)) {
                continue;
              }
              $batOnline = (intval($stEntry['online'] ?? 1) === 1);
              $camcmd->checkAndUpdateCmd('ChannelOnline', $batOnline ? 1 : 0);
              if ($batOnline && array_key_exists('sleep', $stEntry)) {
                $batSleeping = (intval($stEntry['sleep']) === 1);
                $camcmd->checkAndUpdateCmd('BatterySleepState', $batSleeping ? 1 : 0);
                $sleepUpdated = true;
              }
              break;
            }
          } elseif ($pCmd === reolinkAPI::CAM_GET_BATTERYINFO && isset($pItem['value']['Battery']) && is_array($pItem['value']['Battery'])) {
            $camcmd->applyBatteryInfo($pItem['value']['Battery']);
            $batUpdated = true;
            if (array_key_exists('sleeping', $pItem['value']['Battery'])) {
              $batSleeping = (intval($pItem['value']['Battery']['sleeping']) === 1);
              $sleepUpdated = true;
            }
          }
        }
      }
      if (!$batOnline) {
        log::add('reolink', 'debug', 'Canal batterie ' . $channel . ' hors ligne, rafraîchissement ignoré');
        return;
      }
      if (!$batUpdated || (!$sleepUpdated && is_object($camcmd->getCmd(null, 'BatterySleepState')))) {
        $bcFallback = reolinkBaichuan::fromEqLogic($camcmd);
        if (is_object($bcFallback)) {
          if (!$batUpdated) {
            $bcBat = $bcFallback->getBatteryInfo($channel);
            if (is_array($bcBat)) {
              $camcmd->applyBatteryInfo($bcBat);
            }
          }
          if (!$sleepUpdated && is_object($camcmd->getCmd(null, 'BatterySleepState'))) {
            $bcSleep = $bcFallback->getChannelSleepState($channel);
            if ($bcSleep !== null) {
              $batSleeping = (bool) $bcSleep;
              $camcmd->checkAndUpdateCmd('BatterySleepState', $batSleeping ? 1 : 0);
            }
          }
        }
      }
      $shouldWakeBattery = reolink::shouldWakeBatteryChannel($camcmd, $forceWake, $batSleeping);
      if ($shouldWakeBattery) {
        cache::set('reolink::battery_last_wake::' . $id, time());
        $camcnx->batteryChannelMode = true;
        log::add('reolink', 'debug', 'Caméra batterie canal ' . $channel . ' : rafraîchissement complet autorisé (force=' . ($forceWake ? '1' : '0') . ', sleep=' . ($batSleeping ? '1' : '0') . ')');
      } else {
        log::add('reolink', 'debug', 'Caméra batterie canal ' . $channel . ' : économie batterie (NONE_WAKING_COMMANDS uniquement)');
      }
    }

    $unsupported = $camcmd->getConfiguration('unsupported_payloads', array());
    if (!is_array($unsupported)) {
      $unsupported = array();
    }
    $failCounts = $camcmd->getConfiguration('unsupported_fail_counts', array());
    if (!is_array($failCounts)) {
      $failCounts = array();
    }
    $unsupportedMap = array_fill_keys($unsupported, true);
    $dirtyUnsupported = false;

    // Prepare request with INFO needed
    $cmdarr = [];
    $ipKey = strval($camcmd->getConfiguration('adresseip'));
    $fwCacheKey = 'last_firmware_check_' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $ipKey);
    $lastFwCheck = intval(config::byKey($fwCacheKey, __CLASS__, 0));
    $skipCheckFirmware = ($lastFwCheck > 0 && (time() - $lastFwCheck) < 43200);
    foreach (reolinkCmd::byEqLogicId($id) as $cmd) {
      $payload = $cmd->getConfiguration('payload');
      if ($cmd->getType() == "info" && $payload != NULL) {
        $payload = str_replace('#CHANNEL#', $channel, $payload);
        $payload = str_replace('\\', '', $payload);
        if (isset($unsupportedMap[$payload])) {
          continue;
        }
        if ($skipCheckFirmware && strpos($payload, '"cmd":"CheckFirmware"') !== false) {
          continue;
        }
        if ($isBatteryCam) {
          // Already polled in preBatch above
          if (strpos($payload, '"cmd":"GetBatteryInfo"') !== false || strpos($payload, '"cmd":"GetChannelstatus"') !== false) {
            continue;
          }
          if (!$shouldWakeBattery && !reolink::isNonWakingPayload($payload)) {
            continue;
          }
        }
        if (!in_array($payload, $cmdarr)) {
          $cmdarr[] = $payload;
        }
      }
    }
    if (empty($cmdarr)) {
      $camcnx->batteryChannelMode = false;
      return;
    }
    $cmd_block = array_chunk($cmdarr, config::byKey('cmdblock', __CLASS__, CMD_SEND_QTY));

    foreach ($cmd_block as $key => &$value) {
      $cmdget = "";
      foreach ($value as &$value2) {
        $cmdget .= $value2 . ",";
      }
      $cmdget = substr($cmdget, 0, -1);
      log::add('reolink', 'debug', 'Envoi ' . ($key + 1) . '/' . count($cmd_block) . ' payload multiple GetSetting = ' . $cmdget);
      $res = $camcnx->SendCMD("[$cmdget]");
      $data = is_array($res) ? $res : $camcnx->lastResponseData;
      if (!is_array($data)) {
        continue;
      }

      $mdSeen = false;
      $mdOk = false;
      $channelAlive = false;
      foreach ($data as $json_data) {
        if (($json_data['cmd'] ?? '') === reolinkAPI::CAM_GET_MDSTATE && isset($json_data['value']['state'])) {
          $channelAlive = true;
        }
        if (!isset($json_data['error']['rspCode']) && isset($json_data['value'])) {
          $channelAlive = true;
        }
      }

      // Runtime learning of unsupported payloads
      $transient = array(-5, -6, -7, -8, -21, -99, -105);
      $immediate = array(-1, -4, -9, -24, -26);
      $soft = array(-12, -17);
      foreach ($value as $idx => $sentPayload) {
        $item = $data[$idx] ?? null;
        if (!is_array($item)) {
          continue;
        }
        $sentCmd = json_decode($sentPayload, true);
        $sentCmdName = is_array($sentCmd) ? ($sentCmd['cmd'] ?? '') : '';
        if ($sentCmdName === reolinkAPI::CAM_GET_MDSTATE) {
          continue;
        }
        if (!isset($item['error']['rspCode'])) {
          if (isset($failCounts[$sentPayload])) {
            unset($failCounts[$sentPayload]);
            $dirtyUnsupported = true;
          }
          continue;
        }
        $rsp = intval($item['error']['rspCode']);
        if (in_array($rsp, $transient, true) || !$channelAlive) {
          continue;
        }
        if ($isBatteryCam && in_array($rsp, array(-12, -13, -17), true)) {
          continue;
        }
        if (in_array($rsp, $immediate, true)) {
          if (!isset($unsupportedMap[$sentPayload])) {
            $unsupportedMap[$sentPayload] = true;
            $dirtyUnsupported = true;
            log::add('reolink', 'info', 'Payload non supporté (runtime) : ' . $sentCmdName . ' rsp=' . $rsp);
          }
          continue;
        }
        if (in_array($rsp, $soft, true)) {
          $failCounts[$sentPayload] = intval($failCounts[$sentPayload] ?? 0) + 1;
          $dirtyUnsupported = true;
          if ($failCounts[$sentPayload] >= 3 && !isset($unsupportedMap[$sentPayload])) {
            $unsupportedMap[$sentPayload] = true;
            unset($failCounts[$sentPayload]);
            log::add('reolink', 'info', 'Payload non supporté après 3 échecs : ' . $sentCmdName . ' rsp=' . $rsp);
          }
        }
      }

      foreach ($data as &$json_data) {
        if (($json_data['cmd'] ?? '') === reolinkAPI::CAM_GET_MDSTATE) {
          $mdSeen = true;
          if (isset($json_data['value']['state'])) {
            $mdOk = true;
          }
        }
        if (!isset($json_data['value'])) {
          continue;
        }
        log::add('reolink', 'debug', 'Lecture info > ' . preg_replace('/\s+/', '', print_r($json_data, true)));

        switch ($json_data['cmd']) {
          case reolinkAPI::CAM_GET_REC:
            $camcmd->checkAndUpdateCmd('SetRecordState', $json_data['value']['Rec']['schedule']['enable']);
            $camcmd->checkAndUpdateCmd('SetPreRecordState', $json_data['value']['Rec']['preRec']);
            $camcmd->checkAndUpdateCmd('SetOverwriteState', $json_data['value']['Rec']['overwrite']);
            $camcmd->checkAndUpdateCmd('SetPostRecordState', $json_data['value']['Rec']['postRec']);
            break;

          case reolinkAPI::CAM_GET_RECV20:
            $v = $json_data['value']['Rec'];
            $camcmd->checkAndUpdateCmd('SetRecordStateV20', $v['scheduleEnable'] ?? ($v['enable'] ?? 0));
            if (isset($v['preRec'])) {
              $camcmd->checkAndUpdateCmd('SetPreRecordStateV20', $v['preRec']);
            }
            $camcmd->checkAndUpdateCmd('SetOverwriteStateV20', $v['overwrite']);
            $camcmd->checkAndUpdateCmd('SetPostRecordStateV20', $v['postRec']);
            break;

          case reolinkAPI::CAM_GET_HDDINFO:
            $hdd = $json_data['value']['HddInfo'][0] ?? null;
            if (!is_array($hdd)) {
              $camcmd->checkAndUpdateCmd('driveAvailable', 0);
              break;
            }
            $available = (intval($hdd['format'] ?? 0) === 1 && intval($hdd['mount'] ?? 0) === 1);
            $camcmd->checkAndUpdateCmd('driveAvailable', $available ? 1 : 0);
            // API: capacity = total Mb, size = remaining Mb (not used space)
            if (isset($hdd['size'], $hdd['capacity']) && is_numeric($hdd['size']) && is_numeric($hdd['capacity']) && floatval($hdd['capacity']) > 0) {
              $percAvailable = round((floatval($hdd['size']) * 100) / floatval($hdd['capacity']), 0, PHP_ROUND_HALF_DOWN);
              $camcmd->checkAndUpdateCmd('driveSpaceAvailable', $percAvailable);
            }
            $storageType = intval($hdd['storageType'] ?? 0);
            if ($storageType === 1) {
              $camcmd->checkAndUpdateCmd('driveType', 'HDD');
            } elseif ($storageType === 2) {
              $camcmd->checkAndUpdateCmd('driveType', 'Sdcard');
            }
            break;

          case reolinkAPI::CAM_GET_OSD:
            $camcmd->checkAndUpdateCmd('SetWatermarkState', $json_data['value']['Osd']['watermark']);
            $camcmd->checkAndUpdateCmd('SetOsdTimeState', $json_data['value']['Osd']['osdTime']['enable']);
            $camcmd->checkAndUpdateCmd('SetOsdChannelState', $json_data['value']['Osd']['osdChannel']['enable']);
            $camcmd->checkAndUpdateCmd('SetPosOsdTimeState', $json_data['value']['Osd']['osdTime']['pos']);
            $camcmd->checkAndUpdateCmd('SetPosOsdChannelState', $json_data['value']['Osd']['osdChannel']['pos']);
            break;

          case reolinkAPI::CAM_GET_FTP:
            $camcmd->checkAndUpdateCmd('SetFTPState', $json_data['value']['Ftp']['schedule']['enable']);
            break;

          case reolinkAPI::CAM_GET_FTPV20:
            $v = $json_data['value']['Ftp'];
            $camcmd->checkAndUpdateCmd('SetFTPStateV20', $v['scheduleEnable'] ?? ($v['enable'] ?? 0));
            break;

          case reolinkAPI::CAM_GET_PUSH:
            $camcmd->checkAndUpdateCmd('SetPushState', $json_data['value']['Push']['schedule']['enable']);
            break;

          case reolinkAPI::CAM_GET_PUSHV20:
            $v = $json_data['value']['Push'];
            $camcmd->checkAndUpdateCmd('SetPushStateV20', $v['scheduleEnable'] ?? ($v['enable'] ?? 0));
            break;

          case reolinkAPI::CAM_GET_PUSHCFG:
            $camcmd->checkAndUpdateCmd('SetPushCfgState', $json_data['value']['PushCfg']['pushInterval']);
            break;

          case reolinkAPI::CAM_GET_EMAIL:
            $camcmd->checkAndUpdateCmd('SetEmailState', $json_data['value']['Email']['schedule']['enable']);
            break;

          case reolinkAPI::CAM_GET_EMAILV20:
            $v = $json_data['value']['Email'];
            $camcmd->checkAndUpdateCmd('SetEmailStateV20', $v['scheduleEnable'] ?? ($v['enable'] ?? 0));
            break;

          case reolinkAPI::CAM_GET_ENC:
            $enc = $json_data['value']['Enc'];
            $audio = $enc['audio'] ?? ($json_data['value']['audio'] ?? null);
            if ($audio !== null) {
              $camcmd->checkAndUpdateCmd('SetMicrophoneState', $audio);
            }
            if (isset($enc['mainStream'])) {
              $camcmd->checkAndUpdateCmd('SetResolutionst1State', $enc['mainStream']['size']);
              $camcmd->checkAndUpdateCmd('SetFPSst1State', $enc['mainStream']['frameRate']);
              $camcmd->checkAndUpdateCmd('SetBitratest1State', $enc['mainStream']['bitRate']);
              if (isset($enc['mainStream']['vType'])) {
                $camcmd->checkAndUpdateCmd('SetEncodingst1State', $enc['mainStream']['vType']);
              }
            }
            if (isset($enc['subStream'])) {
              $camcmd->checkAndUpdateCmd('SetResolutionst2State', $enc['subStream']['size']);
              $camcmd->checkAndUpdateCmd('SetFPSst2State', $enc['subStream']['frameRate']);
              $camcmd->checkAndUpdateCmd('SetBitratest2State', $enc['subStream']['bitRate']);
              if (isset($enc['subStream']['vType'])) {
                $camcmd->checkAndUpdateCmd('SetEncodingst2State', $enc['subStream']['vType']);
              }
            }
            // Auto-fill dynamic SetEnc lists when missing (existing equipments)
            $needEncLists = false;
            foreach (array('SetResolutionst1', 'SetEncodingst1', 'SetFPSst1', 'SetBitratest1') as $encLid) {
              $encCmd = $camcmd->getCmd(null, $encLid);
              if (is_object($encCmd) && strval($encCmd->getConfiguration('listValue', '')) === '') {
                $needEncLists = true;
                break;
              }
            }
            if ($needEncLists) {
              reolink::applyEncListValues($camcmd, $camcnx, $channel);
            }
            break;

          case reolinkAPI::CAM_GET_ISP:
            $isp = $json_data['value']['Isp'];
            $camcmd->checkAndUpdateCmd('SetRotationState', $isp['rotation'] ?? null);
            $camcmd->checkAndUpdateCmd('SetMirroringState', $isp['mirroring'] ?? null);
            $camcmd->checkAndUpdateCmd('SetAntiFlickerState', $isp['antiFlicker'] ?? null);
            $camcmd->checkAndUpdateCmd('SetBackLightState', $isp['backLight'] ?? null);
            $camcmd->checkAndUpdateCmd('SetBlcState', $isp['blc'] ?? null);
            $camcmd->checkAndUpdateCmd('SetBlueGainState', $isp['blueGain'] ?? null);
            $camcmd->checkAndUpdateCmd('SetDayNightState', $isp['dayNight'] ?? null);
            $camcmd->checkAndUpdateCmd('SetDrcState', $isp['drc'] ?? null);
            $camcmd->checkAndUpdateCmd('SetNr3dState', $isp['nr3d'] ?? null);
            $camcmd->checkAndUpdateCmd('SetRedGainState', $isp['redGain'] ?? null);
            $camcmd->checkAndUpdateCmd('SetWhiteBalanceState', $isp['whiteBalance'] ?? null);
            $camcmd->checkAndUpdateCmd('SetExposureState', $isp['exposure'] ?? null);
            if (array_key_exists('dayNightThreshold', $isp)) {
              $camcmd->checkAndUpdateCmd('SetDayNightThresholdState', $isp['dayNightThreshold']);
            }
            if (array_key_exists('binningMode', $isp)) {
              $camcmd->checkAndUpdateCmd('SetBinningModeState', $isp['binningMode']);
            }
            if (array_key_exists('hdr', $isp)) {
              $camcmd->checkAndUpdateCmd('SetHdrState', $isp['hdr']);
            }
            break;

          case reolinkAPI::CAM_GET_IRLIGHTS:
            $camcmd->checkAndUpdateCmd('SetIrLightsState', $json_data['value']['IrLights']['state']);
            break;

          case reolinkAPI::CAM_GET_IMAGE:
            $camcmd->checkAndUpdateCmd('SetBrightState', $json_data['value']['Image']['bright']);
            $camcmd->checkAndUpdateCmd('SetContrastState', $json_data['value']['Image']['contrast']);
            $camcmd->checkAndUpdateCmd('SetSaturationState', $json_data['value']['Image']['saturation']);
            $camcmd->checkAndUpdateCmd('SetHueState', $json_data['value']['Image']['hue']);
            $camcmd->checkAndUpdateCmd('SetSharpenState', $json_data['value']['Image']['sharpen']);
            break;

          case reolinkAPI::CAM_GET_WHITELED:
            $camcmd->checkAndUpdateCmd('SetWhitLedState', $json_data['value']['WhiteLed']['state']);
            $camcmd->checkAndUpdateCmd('SetWhiteLedModeState', $json_data['value']['WhiteLed']['mode']);
            $camcmd->checkAndUpdateCmd('SetWhitLedLuxState', $json_data['value']['WhiteLed']['bright']);
            break;

          case reolinkAPI::CAM_GET_PTZPRESET:
            break;

          case reolinkAPI::CAM_GET_PTZPATROL:
            reolink::applyPtzPatrolState($camcmd, $json_data);
            break;

          case reolinkAPI::CAM_GET_PTZGUARD:
            $camcmd->checkAndUpdateCmd('CheckIsExistsPtzGuardPoint', $json_data['value']['PtzGuard']['bexistPos']);
            $camcmd->checkAndUpdateCmd('SetAutoReturnPtzGuardPointState', $json_data['value']['PtzGuard']['benable']);
            $camcmd->checkAndUpdateCmd('SetIntervalAutoReturnPtzGuardPointState', $json_data['value']['PtzGuard']['timeout']);
            break;

          case reolinkAPI::CAM_PTZCHECK:
            break;

          case reolinkAPI::CAM_GET_MDSTATE:
            $camcmd->checkAndUpdateCmd('MdState', intval($json_data['value']['state']));
            $camcmd->checkAndUpdateCmd('ChannelOnline', 1);
            break;

          case reolinkAPI::CAM_GET_PTZCHECKSTATE:
            switch ((int) $json_data['value']['PtzCheckState']) {
              case 0:
                $camcmd->checkAndUpdateCmd('SetPtzCheckState', 'REQUISE');
                break;
              case 1:
                $camcmd->checkAndUpdateCmd('SetPtzCheckState', 'EN COURS');
                break;
              case 2:
                $camcmd->checkAndUpdateCmd('SetPtzCheckState', 'TERMINEE');
                break;
            }
            log::add('reolink', 'debug', 'Statut Calibration : ' . $json_data['value']['PtzCheckState']);
            break;

          case reolinkAPI::CAM_GET_ALARM:
            $enable = $json_data['value']['Alarm']['enable']
              ?? ($json_data['value']['Alarm']['schedule']['enable'] ?? null);
            if ($enable !== null) {
              $camcmd->checkAndUpdateCmd('SetMdEnableState', $enable);
            }
            break;

          case reolinkAPI::CAM_GET_BUZZERALARMV20:
            $buzzer = $json_data['value']['Buzzer'] ?? array();
            if (array_key_exists('scheduleEnable', $buzzer)) {
              $camcmd->checkAndUpdateCmd('SetBuzzerStateV20', $buzzer['scheduleEnable']);
            }
            if (array_key_exists('enable', $buzzer)) {
              $camcmd->checkAndUpdateCmd('SetNvrBuzzerState', $buzzer['enable']);
            }
            break;

          case reolinkAPI::CAM_GET_MANUALREC:
            $camcmd->checkAndUpdateCmd('SetManualRecordState', $json_data['value']['Rec']['enable'] ?? 0);
            break;

          case reolinkAPI::CAM_CHECKFIRMWARE:
            $camcmd->checkAndUpdateCmd('NewFirmwareState', $json_data['value']['newFirmware'] ?? 0);
            $ipForFw = strval($camcmd->getConfiguration('adresseip'));
            if ($ipForFw !== '') {
              config::save('last_firmware_check_' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $ipForFw), time(), __CLASS__);
            }
            break;

          case reolinkAPI::CAM_GET_NTP:
            $camcmd->checkAndUpdateCmd('SetNtpEnableState', $json_data['value']['Ntp']['enable'] ?? 0);
            break;

          case reolinkAPI::CAM_GET_AUDIOALARM:
            $camcmd->checkAndUpdateCmd('SetAudioAlarmState', $json_data['value']['Audio']['schedule']['enable']);
            break;

          case reolinkAPI::CAM_GET_AUDIOALARMV20:
            $camcmd->checkAndUpdateCmd('SetAudioAlarmStateV20', $json_data['value']['Audio']['enable']);
            break;

          case reolinkAPI::CAM_AUDIOALARMPLAY:
            break;

          case reolinkAPI::CAM_GET_AUDIOCFG:
            $camcmd->checkAndUpdateCmd('SetSirenVolumeState', $json_data['value']['AudioCfg']['volume']);
            break;

          case reolinkAPI::CAM_GET_POWERLED:
            $camcmd->checkAndUpdateCmd('SetPowerLedState', $json_data['value']['PowerLed']['state']);
            break;

          case reolinkAPI::CAM_GET_ABILITY:
            $ab1 = $json_data['value']['Ability'];
            unset($ab1['abilityChn']);
            $ab2 = $json_data['value']['Ability']['abilityChn']['0'];
            // $camcnx->ability_settings = array_merge($ab1, $ab2); FIXME: ability_settings does not exist and is not used anywhere
            break;

          case reolinkAPI::CAM_GET_AUTOFOCUS:
            $camcmd->checkAndUpdateCmd('SetAutoFocusState', $json_data['value']['AutoFocus']['disable']);
            break;

          case reolinkAPI::CAM_GET_MASK:
            $camcmd->checkAndUpdateCmd('SetMaskState', $json_data['value']['Mask']['enable']);
            break;

          case reolinkAPI::CAM_GET_AUTOMAINT:
            $camcmd->checkAndUpdateCmd('SetAutoMaintState', $json_data['value']['AutoMaint']['enable']);
            break;

          case reolinkAPI::CAM_GET_UPNP:
            $camcmd->checkAndUpdateCmd('SetUpnpState', $json_data['value']['Upnp']['enable']);
            break;

          case reolinkAPI::CAM_GET_P2P:
            $camcmd->checkAndUpdateCmd('SetUidP2pState', $json_data['value']['P2p']['enable']);
            break;

          case reolinkAPI::CAM_GET_ZOOMFOCUS:
            $camcmd->checkAndUpdateCmd('SetZoomState', $json_data['value']['ZoomFocus']['zoom']['pos']);
            $camcmd->checkAndUpdateCmd('SetFocusState', $json_data['value']['ZoomFocus']['focus']['pos']);
            break;

          case reolinkAPI::CAM_GET_PERFORMANCE:
            $camcmd->checkAndUpdateCmd('SetCpuUsedState', $json_data['value']['Performance']['cpuUsed']);
            $camcmd->checkAndUpdateCmd('SetNetThroughputState', $json_data['value']['Performance']['netThroughput']);
            $camcmd->checkAndUpdateCmd('SetCodecRateState', $json_data['value']['Performance']['codecRate']);
            break;

          case reolinkAPI::CAM_GET_AICFG:
            $camcmd->checkAndUpdateCmd('SetaiTrackState', $json_data['value']['bSmartTrack'] ?? ($json_data['value']['aiTrack'] ?? 0));
            break;

          case reolinkAPI::CAM_GET_MDALARM:
            $cmdSens = reolinkCmd::byEqLogicIdAndLogicalId($id, 'SetMdDefaultSensitivityState');
            $sens = $json_data['value']['MdAlarm']['newSens']['sensDef'] ?? ($json_data['value']['MdAlarm']['sens'][0]['sensitivity'] ?? null);
            if (is_object($cmdSens) && $sens !== null) {
              $revert_value = $cmdSens->getConfiguration('revertvalue', 0);
              $camcmd->checkAndUpdateCmd('SetMdDefaultSensitivityState', $revert_value - $sens);
            }
            break;

          case reolinkAPI::CAM_GET_AIALARM:
            $s1 = null;
            $s2 = null;
            switch ($json_data['value']['AiAlarm']['ai_type']) {
              case "people":
                $s1 = 'SetSdSensitivityPeopleState';
                $s2 = 'SetAlarmDelayPeopleState';
                break;
              case "vehicle":
                $s1 = 'SetSdSensitivityVehicleState';
                $s2 = 'SetAlarmDelayVehicleState';
                break;
              case "dog_cat":
                $s1 = 'SetSdSensitivityDogCatState';
                $s2 = 'SetAlarmDelayDogCatState';
                break;
              case "face":
                $s1 = 'SetSdSensitivityFaceState';
                $s2 = 'SetAlarmDelayFaceState';
                break;
              case "package":
                $s1 = 'SetSdSensitivityPackageState';
                $s2 = 'SetAlarmDelayPackageState';
                break;
            }
            if ($s1 !== null) {
              $camcmd->checkAndUpdateCmd($s1, $json_data['value']['AiAlarm']['sensitivity']);
              $camcmd->checkAndUpdateCmd($s2, $json_data['value']['AiAlarm']['stay_time']);
            }
            break;

          case reolinkAPI::CAM_GET_BATTERYINFO:
            if (isset($json_data['value']['Battery']) && is_array($json_data['value']['Battery'])) {
              $camcmd->applyBatteryInfo($json_data['value']['Battery']);
            }
            break;

          case 'GetChannelstatus':
          case 'GetChannelStatus':
            $stItems = $json_data['value']['status'] ?? array();
            if (is_array($stItems)) {
              foreach ($stItems as $stEntry) {
                if (is_array($stEntry) && intval($stEntry['channel'] ?? -1) === intval($channel)) {
                  $camcmd->checkAndUpdateCmd('ChannelOnline', intval($stEntry['online'] ?? 1) === 1 ? 1 : 0);
                  if (array_key_exists('sleep', $stEntry)) {
                    $camcmd->checkAndUpdateCmd('BatterySleepState', intval($stEntry['sleep']) === 1 ? 1 : 0);
                  }
                  break;
                }
              }
            }
            break;

          default:
            log::add('reolink', 'debug', 'Switch command not found : ' . print_r($json_data, true));
            $res = false;
        }
      }
      if ($mdSeen) {
        $camcmd->checkAndUpdateCmd('ChannelOnline', $mdOk ? 1 : 0);
      }
    } #END foreach

    $camcnx->batteryChannelMode = false;

    if ($dirtyUnsupported) {
      $camcmd->setConfiguration('unsupported_payloads', array_keys($unsupportedMap));
      $camcmd->setConfiguration('unsupported_fail_counts', $failCounts);
      $camcmd->save();
    }
  } #End refreshNFO


  /*
   * Permet de définir les possibilités de personnalisation du widget (en cas d'utilisation de la fonction 'toHtml' par exemple)
   * Tableau multidimensionnel - exemple: array('custom' => true, 'custom::layout' => false)
	public static $_widgetPossibility = array();
   */

  /*     * ***********************Methode static*************************** */

  public static function cron() {
    $eqLogics = eqLogic::byType('reolink', true);
    /** @var reolink */
    foreach ($eqLogics as $camera) {
      $autorefresh = $camera->getConfiguration('autorefresh', '*/15 * * * *');
      if ($autorefresh != '') {
        try {
          $c = new Cron\CronExpression(checkAndFixCron($autorefresh), new Cron\FieldFactory);
          if ($c->isDue()) {
            log::add('reolink', 'debug', '#### CRON refresh ' . $camera->getHumanName());
            $camera->refreshNFO($camera->getId());
          }
        } catch (Exception $exc) {
          log::add('reolink', 'error', __('Expression cron non valide pour ', __FILE__) . $camera->getHumanName() . ' : ' . $autorefresh);
        }
      }
    }
  }


  // Fonction exécutée automatiquement toutes les 10 minutes par Jeedom
  public static function cron10() {
    // Refresh motion detection subscription (1 souscription par couple IP:port ONVIF)
    $seen = array();
    $eqLogics = eqLogic::byType('reolink', true);
    // Prefer channel cameras over NVR parent for credentials/intent
    usort($eqLogics, function ($a, $b) {
      return intval($a->isNvrParent()) - intval($b->isNvrParent());
    });
    foreach ($eqLogics as $camera) {
      if ($camera->isStandalonePrivacyBlocking()) {
        continue;
      }

      $camera_contact_point = $camera->getConfiguration('adresseip');
      if (filter_var($camera_contact_point, FILTER_VALIDATE_IP)) {
        $camera_ip = $camera_contact_point;
      } else {
        $camera_ip = gethostbyname($camera_contact_point);
      }

      $port_onvif = $camera->getConfiguration('port_onvif', $camera->getConfiguration('onvifPort', '8000'));
      if ($port_onvif == '') {
        $port_onvif = '8000';
      }
      $hook_key = $camera_ip . ':' . $port_onvif;
      if (isset($seen[$hook_key])) {
        continue;
      }
      $seen[$hook_key] = true;
      // Sending info to Daemon
      $params['action'] = 'sethook';
      $params['cam_ip'] = $camera_ip;
      $params['cam_onvif_port'] = $port_onvif;
      $params['cam_user'] = $camera->getConfiguration('login');
      $params['cam_pwd'] = $camera->getConfiguration('password');

      log::add('reolink', 'debug', 'CRON mise à jour souscription ONVIF events Cam=' . $camera->getConfiguration('adresseip'));
      reolink::sendToDaemon($params);
    }
  }

  public static function deamon_info() {
    $return = array();
    $return['log'] = __CLASS__;
    $return['state'] = 'nok';
    $pid_file = jeedom::getTmpFolder(__CLASS__) . '/deamon.pid';
    if (file_exists($pid_file)) {
      if (@posix_getsid(trim(file_get_contents($pid_file)))) {
        $return['state'] = 'ok';
      } else {
        shell_exec(system::getCmdSudo() . 'rm -rf ' . $pid_file . ' 2>&1 > /dev/null');
      }
    }
    $return['launchable'] = 'ok';
    $list_camera = eqLogic::byType('reolink');

    if (count($list_camera) == 0) {
      $return['launchable'] = 'nok';
      $return['launchable_message'] = __('Aucune caméra n\'est configuré', __FILE__);
    }
    return $return;
  }

  public static function deamon_start() {
    self::deamon_stop();
    $deamon_info = self::deamon_info();
    if ($deamon_info['launchable'] != 'ok') {
      throw new Exception(__('Veuillez vérifier la configuration', __FILE__));
    }


    $webhook_ip = "";
    $callbackip_cfg = config::byKey('ipwebhook', __CLASS__, '0');
    if ($callbackip_cfg == 1) {
      // Internal
      $webhook_ip = network::getNetworkAccess('internal', 'ip');
    } elseif ($callbackip_cfg == 2) {
      // External
      $webhook_ip = network::getNetworkAccess('external', 'ip');
    } elseif ($callbackip_cfg == 3) {
      // Personnalised
      $webhook_ip = config::byKey('webhookdefinedip', __CLASS__, '');
    }

    $path = realpath(dirname(__FILE__) . '/../../resources/demond');
    $cmd = self::PYTHON_PATH . " {$path}/reolinkd.py";
    $cmd .= ' --loglevel ' . log::convertLogLevel(log::getLogLevel(__CLASS__));
    $cmd .= ' --socketport ' . config::byKey('socketport', __CLASS__, '44009');
    $cmd .= ' --callback ' . network::getNetworkAccess('internal', 'proto:127.0.0.1:port:comp') . '/plugins/reolink/core/php/jeeReolink.php';
    $cmd .= ' --apikey ' . jeedom::getApiKey(__CLASS__);
    $cmd .= ' --pid ' . jeedom::getTmpFolder(__CLASS__) . '/deamon.pid';
    if ($webhook_ip != "") {
      $cmd .= ' --webhook_ip ' . $webhook_ip;
    }
    $cmd .= ' --webhook_port ' . config::byKey('webhookport', __CLASS__, '44010');
    log::add(__CLASS__, 'info', 'Lancement démon');
    $result = exec($cmd . ' >> ' . log::getPathToLog('reolink_daemon') . ' 2>&1 &');
    $i = 0;
    $deamon_info = self::deamon_info();
    while ($i < 20) {
      $deamon_info = self::deamon_info();
      if ($deamon_info['state'] == 'ok') {
        break;
      }
      sleep(1);
      $i++;
    }
    if ($deamon_info['state'] != 'ok') {
      log::add(__CLASS__, 'error', __('Impossible de lancer le démon, vérifiez le log', __FILE__), 'unableStartDeamon');
      return false;
    }
    message::removeAll(__CLASS__, 'unableStartDeamon');
    return true;
  }

  public static function deamon_stop() {
    $pid_file = jeedom::getTmpFolder(__CLASS__) . '/deamon.pid';
    if (file_exists($pid_file)) {
      $pid = intval(trim(file_get_contents($pid_file)));
      if ($pid > 0) {
        system::kill($pid, false);
        usleep(300000);
        system::kill($pid);
      }
    }
    system::kill('reolinkd.py');
    system::kill('resources/bin/go2rtc');
    sleep(1);
  }

  public static function sendToDaemon($params) {
    $deamon_info = self::deamon_info();
    if ($deamon_info['state'] != 'ok') {
      log::add('reolink', 'error', 'Envoi des infos de webhook impossible le daemon n\'est pas démarré');
      return false;
    }
    $params['apikey'] = jeedom::getApiKey(__CLASS__);
    $payLoad = json_encode($params);
    $socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    if ($socket === false) {
      log::add('reolink', 'error', 'sendToDaemon : socket_create échoué');
      return false;
    }
    $timeout = array('sec' => 3, 'usec' => 0);
    @socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, $timeout);
    @socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, $timeout);
    $port = intval(config::byKey('socketport', __CLASS__, '44009'));
    if (!@socket_connect($socket, '127.0.0.1', $port)) {
      $err = socket_strerror(socket_last_error($socket));
      @socket_close($socket);
      log::add('reolink', 'error', 'sendToDaemon : connexion échouée (' . $err . ')');
      return false;
    }
    $written = @socket_write($socket, $payLoad, strlen($payLoad));
    @socket_close($socket);
    if ($written === false) {
      log::add('reolink', 'error', 'sendToDaemon : écriture échouée');
      return false;
    }
    return true;
  }

  public static function dependancy_install() {
    log::remove(__CLASS__ . '_update');
    return array('script' => __DIR__ . '/../../resources/install_#stype#.sh', 'log' => log::getPathToLog(__CLASS__ . '_update'));
  }

  public static function dependancy_info() {
    $return = array();
    $return['log'] = log::getPathToLog(__CLASS__ . '_update');
    $return['progress_file'] = jeedom::getTmpFolder(__CLASS__) . '/dependance';
    $return['state'] = 'ok';
    $go2rtcBin = __DIR__ . '/../../resources/bin/go2rtc';
    if (file_exists(jeedom::getTmpFolder(__CLASS__) . '/dependance')) {
      $return['state'] = 'in_progress';
    } elseif (!file_exists(self::PYTHON_PATH)) {
      $return['state'] = 'nok';
    } elseif (!is_executable($go2rtcBin)) {
      $return['state'] = 'nok';
    } elseif (exec(self::PYTHON_PATH . ' -m pip list | grep -Ewc "aiosignal|aiohttp|uvicorn|fastapi|urllib3|requests|charset-normalizer"') < 7) {
      $return['state'] = 'nok';
    }
    return $return;
  }

  // Fonction exécutée automatiquement avant la mise à jour de l'équipement
  public function preUpdate() {
    if ($this->getConfiguration('adresseip') == NULL) {
      throw new Exception(__('L\'IP ou le nom d\'hôte est obligatoire', __FILE__));
    }
    if ($this->getConfiguration('login') == NULL) {
      throw new Exception(__('Le champ login est obligatoire', __FILE__));
    }
    if ($this->getConfiguration('password') == NULL) {
      throw new Exception(__('Le mot de passe ne peut pas être vide', __FILE__));
    }
    // Champs OK
  }


  public function migrateLegacyPayloads() {
    $fixes = array(
      'SetaiTrackState' => '{"cmd":"GetAiCfg","action":0,"param":{"channel":#CHANNEL#}}',
      'SetPowerLedState' => '{"cmd":"GetPowerLed","action":0,"param":{"channel":#CHANNEL#}}',
      'reboot' => '{}',
      'SetIrLights' => '{"IrLights":{"channel":#CHANNEL#,"state":#OPTSELECTEDSTR#}}',
      'SetPushV20' => '{"Push":{"scheduleEnable":#OPTSELECTEDINT#,"schedule":{"channel":#CHANNEL#}}}',
      'SetEmailV20' => '{"Email":{"scheduleEnable":#OPTSELECTEDINT#,"schedule":{"channel":#CHANNEL#}}}',
      'SetFTPV20' => '{"Ftp":{"scheduleEnable":#OPTSELECTEDINT#,"schedule":{"channel":#CHANNEL#}}}',
      'SetAudioAlarmV20' => '{"Audio":{"enable":#OPTSELECTEDINT#,"schedule":{"channel":#CHANNEL#}}}',
      'SetRecordV20' => '{"Rec":{"channel":#CHANNEL#,"scheduleEnable":#OPTSELECTEDINT#}}',
      'SetPush' => '{"Push":{"schedule":{"channel":#CHANNEL#,"enable":#OPTSELECTEDINT#}}}',
      'SetEmail' => '{"Email":{"schedule":{"channel":#CHANNEL#,"enable":#OPTSELECTEDINT#}}}',
      'SetFTP' => '{"Ftp":{"schedule":{"channel":#CHANNEL#,"enable":#OPTSELECTEDINT#}}}',
      'SetAudioAlarm' => '{"Audio":{"schedule":{"channel":#CHANNEL#,"enable":#OPTSELECTEDINT#}}}',
    );
    foreach ($fixes as $logicalId => $newPayload) {
      $cmd = $this->getCmd(null, $logicalId);
      if (!is_object($cmd)) {
        continue;
      }
      $cur = strval($cmd->getConfiguration('payload'));
      if ($logicalId === 'SetaiTrackState' || $logicalId === 'SetPowerLedState') {
        if ($cur === '' || strpos($cur, '#CHANNEL#') !== false || strpos($cur, 'channel') !== false) {
          continue;
        }
      } elseif ($cur === $newPayload) {
        continue;
      }
      $cmd->setConfiguration('payload', $newPayload);
      $cmd->save();
      log::add('reolink', 'info', 'Payload legacy mis à jour : ' . $logicalId);
    }

    $staticListUpdates = array(
      'SetPTZmoove' => 'Stop|Stop;Left|Gauche;Right|Droite;Up|Haut;Down|Bas;LeftUp|Haut gauche;RightUp|Haut droite;LeftDown|Bas gauche;RightDown|Bas droite',
      'SetIrLights' => 'Auto|Auto;On|Activer;Off|Désactiver',
    );
    foreach ($staticListUpdates as $logicalId => $rawList) {
      $cmd = $this->getCmd(null, $logicalId);
      if (!is_object($cmd)) {
        continue;
      }
      $translated = reolink::translateCmdListValue($rawList);
      if (strval($cmd->getConfiguration('listValue', '')) !== $translated) {
        $cmd->setConfiguration('listValue', $translated);
        $cmd->save();
        log::add('reolink', 'info', 'Liste des options mise à jour : ' . $logicalId);
      }
    }
  }

  public static function buildRmwPayload($camcnx, $actionAPI, $channel, $patchArray) {
    $map = array(
      'SetRecV20' => array('GetRecV20', 'Rec', true),
      'SetEnc' => array('GetEnc', 'Enc', true),
      'SetIsp' => array('GetIsp', 'Isp', true),
      'SetOsd' => array('GetOsd', 'Osd', true),
      'SetAiCfg' => array('GetAiCfg', null, true),
      'SetAlarm' => array('GetAlarm', 'Alarm', true),
      'SetNtp' => array('GetNtp', 'Ntp', false),
      'SetWhiteLed' => array('GetWhiteLed', 'WhiteLed', true),
      'SetBuzzerAlarmV20' => array('GetBuzzerAlarmV20', 'Buzzer', true),
      'SetAiAlarm' => array('GetAiAlarm', 'AiAlarm', true),
    );
    if (!isset($map[$actionAPI]) || !is_object($camcnx) || !is_array($patchArray)) {
      return null;
    }
    list($getCmd, $rootKey, $withChannel) = $map[$actionAPI];
    if ($actionAPI === 'SetAiAlarm') {
      $aiType = $patchArray['AiAlarm']['ai_type'] ?? ($patchArray['ai_type'] ?? '');
      if ($aiType === '') {
        return null;
      }
      $getPayload = '[{"cmd":"GetAiAlarm","action":0,"param":{"channel":' . intval($channel) . ',"ai_type":"' . $aiType . '"}}]';
    } elseif ($withChannel) {
      $getPayload = '[{"cmd":"' . $getCmd . '","action":0,"param":{"channel":' . intval($channel) . '}}]';
    } else {
      $getPayload = '[{"cmd":"' . $getCmd . '","action":0}]';
    }
    $res = $camcnx->SendCMD($getPayload);
    if (!is_array($res) || !isset($res[0]['value']) || !is_array($res[0]['value'])) {
      return null;
    }
    $cur = $res[0]['value'];
    if ($actionAPI === 'SetAiCfg') {
      $val = $patchArray['aiTrack'] ?? ($patchArray['bSmartTrack'] ?? null);
      if ($val !== null && array_key_exists('bSmartTrack', $cur)) {
        unset($patchArray['aiTrack']);
        $patchArray['bSmartTrack'] = $val;
      }
      return array_replace_recursive($cur, $patchArray);
    }
    if ($rootKey === null || !isset($cur[$rootKey]) || !is_array($cur[$rootKey])) {
      return null;
    }
    return array_replace_recursive($cur, $patchArray);
  }

  /**
   * Clamp SetEnc stream fields to the range profile matching the target size.
   */
  public static function clampEncToRange($camcnx, $channel, $mergedEnc, $changedStream = null) {
    if (!is_object($camcnx) || !is_array($mergedEnc) || !isset($mergedEnc['Enc']) || !is_array($mergedEnc['Enc'])) {
      return $mergedEnc;
    }
    $res = $camcnx->SendCMD('[{"cmd":"GetEnc","action":1,"param":{"channel":' . intval($channel) . '}}]');
    if (!is_array($res) || !isset($res[0]['range']['Enc']) || !is_array($res[0]['range']['Enc'])) {
      return $mergedEnc;
    }
    $ranges = $res[0]['range']['Enc'];
    $streams = ($changedStream !== null) ? array($changedStream) : array('mainStream', 'subStream');
    foreach ($streams as $stream) {
      if (!isset($mergedEnc['Enc'][$stream]) || !is_array($mergedEnc['Enc'][$stream])) {
        continue;
      }
      $size = $mergedEnc['Enc'][$stream]['size'] ?? null;
      if ($size === null || $size === '') {
        continue;
      }
      $profile = null;
      foreach ($ranges as $p) {
        if (is_array($p) && ($p[$stream]['size'] ?? null) === $size) {
          $profile = $p[$stream];
          break;
        }
      }
      if ($profile === null) {
        continue;
      }
      if (!empty($size) && strpos($size, '*') !== false) {
        list($w, $h) = explode('*', $size, 2);
        $mergedEnc['Enc'][$stream]['width'] = intval($w);
        $mergedEnc['Enc'][$stream]['height'] = intval($h);
      }
      $fpsAllowed = array_map('intval', (array) ($profile['frameRate'] ?? array()));
      $brAllowed = array_map('intval', (array) ($profile['bitRate'] ?? array()));
      $vtAllowed = array_map('strval', (array) ($profile['vType'] ?? array()));
      if (!empty($fpsAllowed) && isset($mergedEnc['Enc'][$stream]['frameRate'])) {
        $fps = intval($mergedEnc['Enc'][$stream]['frameRate']);
        if (!in_array($fps, $fpsAllowed, true)) {
          $mergedEnc['Enc'][$stream]['frameRate'] = max($fpsAllowed);
        }
      }
      if (!empty($brAllowed) && isset($mergedEnc['Enc'][$stream]['bitRate'])) {
        $br = intval($mergedEnc['Enc'][$stream]['bitRate']);
        if (!in_array($br, $brAllowed, true)) {
          $mergedEnc['Enc'][$stream]['bitRate'] = max($brAllowed);
        }
      }
      if (!empty($vtAllowed) && isset($mergedEnc['Enc'][$stream]['vType'])) {
        $vt = strval($mergedEnc['Enc'][$stream]['vType']);
        if (!in_array($vt, $vtAllowed, true)) {
          $mergedEnc['Enc'][$stream]['vType'] = $vtAllowed[0];
        }
      }
    }
    return $mergedEnc;
  }

  public static function isAbilityOk($abilities, $abilityNeed, $subAbility = null) {
    if ($abilityNeed === 'none' || $abilityNeed === '' || $abilityNeed === null) {
      return true;
    }
    if (!isset($abilities[$abilityNeed]) || !is_array($abilities[$abilityNeed])) {
      return false;
    }
    $ab = $abilities[$abilityNeed];
    if (intval($ab['permit'] ?? 0) == 0) {
      return false;
    }
    if (array_key_exists('ver', $ab) && intval($ab['ver']) <= 0) {
      return false;
    }
    if ($subAbility !== null && $subAbility !== '') {
      if (!isset($abilities[$subAbility]) || !is_array($abilities[$subAbility])) {
        return false;
      }
      $sub = $abilities[$subAbility];
      if (intval($sub['permit'] ?? 0) == 0) {
        return false;
      }
      if (array_key_exists('ver', $sub) && intval($sub['ver']) <= 0) {
        return false;
      }
    }
    return true;
  }

  public static function isCmdUsedExternally($cmd) {
    if (!is_object($cmd) || !method_exists($cmd, 'getUsedBy')) {
      return false;
    }
    $eqId = intval($cmd->getEqLogic_id());
    $used = $cmd->getUsedBy();
    foreach (array('scenario', 'object', 'interactDef', 'view', 'plan', 'plan3d') as $key) {
      if (!empty($used[$key])) {
        return true;
      }
    }
    if (!empty($used['eqLogic'])) {
      foreach ($used['eqLogic'] as $eq) {
        if (is_object($eq) && intval($eq->getId()) !== $eqId) {
          return true;
        }
      }
    }
    // plugin::customUsedBy often returns empty arrays (ignore empty)
    if (!empty($used['plugin']) && is_array($used['plugin'])) {
      foreach ($used['plugin'] as $items) {
        if (!empty($items)) {
          return true;
        }
      }
    }
    if (!empty($used['cmd'])) {
      foreach ($used['cmd'] as $other) {
        if (!is_object($other)) {
          continue;
        }
        if (intval($other->getEqLogic_id()) !== $eqId) {
          return true;
        }
        // Ignore internal valueFrom link on same equipment
        if ($other->getConfiguration('valueFrom') == $cmd->getLogicalId() || $cmd->getConfiguration('valueFrom') == $other->getLogicalId()) {
          continue;
        }
        if (intval($other->getValue()) === intval($cmd->getId()) || intval($cmd->getValue()) === intval($other->getId())) {
          continue;
        }
        return true;
      }
    }
    return false;
  }

  /**
   * Probe Get* payloads in batches. Returns map payloadJson => ['ok'=>bool,'rsp'=>int,'value'=>array|null]
   */
  public static function probePayloads($cnx, $payloads) {
    $results = array();
    if (!is_object($cnx) || empty($payloads)) {
      return $results;
    }
    $cnx->probeMode = true;
    $chunks = array_chunk(array_values($payloads), config::byKey('cmdblock', __CLASS__, CMD_SEND_QTY));
    foreach ($chunks as $chunk) {
      $body = '[' . implode(',', $chunk) . ']';
      $res = $cnx->SendCMD($body);
      $data = is_array($res) ? $res : $cnx->lastResponseData;
      foreach ($chunk as $idx => $payload) {
        $item = (is_array($data) && isset($data[$idx])) ? $data[$idx] : null;
        $ok = is_array($item) && !isset($item['error']['rspCode']) && isset($item['value']);
        $rsp = is_array($item) ? intval($item['error']['rspCode'] ?? 0) : -999;
        $results[$payload] = array(
          'ok' => $ok,
          'rsp' => $rsp,
          'value' => $ok ? ($item['value'] ?? null) : null,
          'range' => $ok ? ($item['range'] ?? null) : null,
        );
      }
    }
    $cnx->probeMode = false;
    return $results;
  }

  public static function isPermanentUnsupportedRsp($rsp, $isBatteryCam = false) {
    $code = intval($rsp);
    if ($isBatteryCam && in_array($code, array(-12, -13, -17), true)) {
      return false;
    }
    return in_array($code, array(-1, -4, -9, -12, -17, -24, -26), true);
  }

  /**
   * Translate listValue labels (value|Label;...) keeping API values intact.
   */
  public static function translateCmdListValue($listValue) {
    $listValue = strval($listValue);
    if ($listValue === '') {
      return $listValue;
    }
    $out = array();
    foreach (explode(';', $listValue) as $pair) {
      $bits = explode('|', $pair, 2);
      if (count($bits) === 2) {
        $out[] = $bits[0] . '|' . __($bits[1], __FILE__);
      } else {
        $out[] = $pair;
      }
    }
    return implode(';', $out);
  }

  public function loadCmdFromConf($id, $pruneUnsupported = true) {
    $camera = reolink::byId($id, 'reolink');
    if (!is_object($camera)) {
      return false;
    }

    $camera->migrateLegacyPayloads();
    $camera->setConfiguration('needs_cmd_resync', 1);
    $camera->save();

    $devAbilityReturn = reolink::GetCamAbility($id);
    if (!$devAbilityReturn) {
      // NVR parent: GetCamAbility is channel-oriented; synthesize from GetDevInfo/GetAbility host
      if ($camera->isNvrParent()) {
        $cnxTmp = reolink::getReolinkConnection($id);
        if (is_object($cnxTmp)) {
          $ab = $cnxTmp->SendCMD('[{"cmd":"GetAbility","param":{"User":{"userName":"' . $camera->getConfiguration('login', 'admin') . '"}}}]');
          if (is_array($ab) && isset($ab[0]['value']['Ability'])) {
            $devAbilityReturn = $ab[0]['value']['Ability'];
            unset($devAbilityReturn['abilityChn']);
            if ((!isset($devAbilityReturn['upgrade']) || intval($devAbilityReturn['upgrade']['permit'] ?? 0) === 0)
              && isset($devAbilityReturn['supportRemoteUpgrade'])) {
              $devAbilityReturn['upgrade'] = $devAbilityReturn['supportRemoteUpgrade'];
            }
          }
        }
      }
      if (!$devAbilityReturn) {
        log::add('reolink', 'debug', 'Erreur lors de l\'obtention des capacités hardware/software de la caméra (resync différé)');
        return false;
      }
    }

    $cnx = reolink::getReolinkConnection($id);
    if (!is_object($cnx) || $cnx->is_loggedin == false) {
      log::add('reolink', 'debug', 'Caméra hors ligne pour loadCmdFromConf (resync différé)');
      return false;
    }

    $channel = $camera->getChannel();
    if (!$camera->isNvrParent()) {
      $mdProbe = $cnx->SendCMD('[{"cmd":"GetMdState","action":0,"param":{"channel":' . $channel . '}}]');
      $channelOnline = is_array($mdProbe) && isset($mdProbe[0]['value']['state']);
      if (!$channelOnline) {
        $mdRsp = intval(($cnx->lastResponseData[0]['error']['rspCode'] ?? 0));
        log::add('reolink', 'info', 'Canal ' . $channel . ' hors ligne (rsp=' . $mdRsp . '), nettoyage reporté');
        return false;
      }
    }

    log::add('reolink', 'debug', 'Chargement des commandes depuis le fichiers de config : ' . dirname(__FILE__) . '/../config/reolinkapicmd.json');
    $content = file_get_contents(dirname(__FILE__) . '/../config/reolinkapicmd.json');
    if (!is_json($content)) {
      log::add('reolink', 'error', 'Format du fichier de configuration n\'est pas du JSON valide !');
      return false;
    }
    $device = json_decode($content, true);
    if (!is_array($device) || !isset($device['commands'])) {
      log::add('reolink', 'error', 'Pas de configuration valide trouvé dans le fichier');
      return false;
    }

    // Dual API families: prefer V20 when Get*V20 works
    $dualFamilies = array(
      'push' => array(
        'v20' => array('SetPushStateV20', 'SetPushV20'),
        'v1' => array('SetPushState', 'SetPush'),
        'probeV20' => '{"cmd":"GetPushV20","action":0,"param":{"channel":#CHANNEL#}}',
        'probeV1' => '{"cmd":"GetPush"}',
      ),
      'sdCard' => array(
        'v20' => array('SetRecordStateV20', 'SetRecordV20', 'SetPreRecordStateV20', 'SetPreRecordV20', 'SetOverwriteStateV20', 'SetOverwriteV20', 'SetPostRecordStateV20', 'SetPostRecordV20'),
        'v1' => array('SetRecordState', 'SetRecord', 'SetPreRecordState', 'SetPreRecord', 'SetOverwriteState', 'SetOverwrite', 'SetPostRecordState', 'SetPostRecord'),
        'probeV20' => '{"cmd":"GetRecV20","action":0,"param":{"channel":#CHANNEL#}}',
        'probeV1' => '{"cmd":"GetRec","action":0,"param":{"channel":#CHANNEL#}}',
      ),
      'email' => array(
        'v20' => array('SetEmailStateV20', 'SetEmailV20'),
        'v1' => array('SetEmailState', 'SetEmail'),
        'probeV20' => '{"cmd":"GetEmailV20","action":0,"param":{"channel":#CHANNEL#}}',
        'probeV1' => '{"cmd":"GetEmail","action":1}',
      ),
      'ftp' => array(
        'v20' => array('SetFTPStateV20', 'SetFTPV20'),
        'v1' => array('SetFTPState', 'SetFTP'),
        'probeV20' => '{"cmd":"GetFtpV20","action":0,"param":{"channel":#CHANNEL#}}',
        'probeV1' => '{"cmd":"GetFtp","action":1}',
      ),
      'alarmAudio' => array(
        'v20' => array('SetAudioAlarmStateV20', 'SetAudioAlarmV20'),
        'v1' => array('SetAudioAlarmState', 'SetAudioAlarm'),
        'probeV20' => '{"cmd":"GetAudioAlarmV20","action":0,"param":{"channel":#CHANNEL#}}',
        'probeV1' => '{"cmd":"GetAudioAlarm"}',
      ),
    );

    // Collect unique Get* payloads from JSON candidates with ability OK
    // (covers dual-family V1/V20 probes when ability permits)
    $probeList = array();
    foreach ($device['commands'] as $command) {
      if (($command['type'] ?? '') !== 'info') {
        continue;
      }
      if (!$camera->allowsCommandScope($command['scope'] ?? 'channel')) {
        continue;
      }
      $payloadTpl = $command['configuration']['payload'] ?? null;
      if ($payloadTpl === null || $payloadTpl === '') {
        continue;
      }
      if (!reolink::isAbilityOk($devAbilityReturn, $command['abilityneed'] ?? 'none', $command['subability'] ?? null)) {
        continue;
      }
      $payload = str_replace('#CHANNEL#', $channel, $payloadTpl);
      $payload = str_replace('\\', '', $payload);
      $probeList[] = $payload;
    }
    $isBatChannel = (!$camera->isNvrParent() && (reolink::isAbilityOk($devAbilityReturn, 'battery') || $camera->hasBattery()));
    $cnx->batteryChannelMode = $isBatChannel;
    $probeList = array_values(array_unique($probeList));
    $probeResults = reolink::probePayloads($cnx, $probeList);

    // Resolve dual families (channel/standalone only)
    $allowedLogical = array();
    $deniedLogical = array();
    if ($camera->isNvrParent()) {
      foreach ($dualFamilies as $fam) {
        foreach (array_merge($fam['v20'], $fam['v1']) as $lid) {
          $deniedLogical[$lid] = true;
        }
      }
    }
    foreach ($dualFamilies as $abilityKey => $fam) {
      if ($camera->isNvrParent()) {
        break;
      }
      if (!reolink::isAbilityOk($devAbilityReturn, $abilityKey)) {
        foreach (array_merge($fam['v20'], $fam['v1']) as $lid) {
          $deniedLogical[$lid] = true;
        }
        continue;
      }
      $p20 = str_replace('#CHANNEL#', $channel, $fam['probeV20']);
      $p1 = str_replace('#CHANNEL#', $channel, $fam['probeV1']);
      $ok20 = !empty($probeResults[$p20]['ok']);
      $ok1 = !empty($probeResults[$p1]['ok']);
      if ($ok20) {
        foreach ($fam['v20'] as $lid) {
          $allowedLogical[$lid] = true;
        }
        foreach ($fam['v1'] as $lid) {
          $deniedLogical[$lid] = true;
        }
        // preRec only if present in GetRecV20 value
        if ($abilityKey === 'sdCard') {
          $hasPreRec = isset($probeResults[$p20]['value']['Rec']['preRec']);
          if (!$hasPreRec) {
            $deniedLogical['SetPreRecordStateV20'] = true;
            $deniedLogical['SetPreRecordV20'] = true;
            unset($allowedLogical['SetPreRecordStateV20'], $allowedLogical['SetPreRecordV20']);
          }
        }
      } elseif ($ok1) {
        foreach ($fam['v1'] as $lid) {
          $allowedLogical[$lid] = true;
        }
        foreach ($fam['v20'] as $lid) {
          $deniedLogical[$lid] = true;
        }
        if ($abilityKey === 'sdCard') {
          $hasPreRec = isset($probeResults[$p1]['value']['Rec']['preRec']);
          if (!$hasPreRec) {
            $deniedLogical['SetPreRecordState'] = true;
            $deniedLogical['SetPreRecord'] = true;
            unset($allowedLogical['SetPreRecordState'], $allowedLogical['SetPreRecord']);
          }
        }
      } else {
        foreach (array_merge($fam['v20'], $fam['v1']) as $lid) {
          $deniedLogical[$lid] = true;
        }
      }
    }

    $unsupportedPayloads = array();
    $cmd_order = 0;

    if (!$camera->isNvrParent()) {
      $ispProbe = $cnx->SendCMD('[{"cmd":"GetIsp","action":0,"param":{"channel":' . intval($channel) . '}}]');
      $hasHdr = is_array($ispProbe) && array_key_exists('hdr', $ispProbe[0]['value']['Isp'] ?? array());
      if (!$hasHdr) {
        $deniedLogical['SetHdrState'] = true;
        $deniedLogical['SetHdr'] = true;
      }
    }

    foreach ($device['commands'] as $command) {
      $logicalId = $command['logicalId'] ?? null;
      if ($logicalId === null) {
        continue;
      }
      if (!$camera->allowsCommandScope($command['scope'] ?? 'channel')) {
        $deniedLogical[$logicalId] = true;
        continue;
      }
      if (isset($deniedLogical[$logicalId])) {
        continue;
      }
      if (!reolink::isAbilityOk($devAbilityReturn, $command['abilityneed'] ?? 'none', $command['subability'] ?? null)) {
        continue;
      }
      // Dual family override: if family resolved, require allowed
      $inDual = false;
      foreach ($dualFamilies as $fam) {
        if (in_array($logicalId, $fam['v20'], true) || in_array($logicalId, $fam['v1'], true)) {
          $inDual = true;
          break;
        }
      }
      if ($inDual && !isset($allowedLogical[$logicalId])) {
        continue;
      }

      // Probe Get* payload when present
      $payloadTpl = $command['configuration']['payload'] ?? null;
      if (($command['type'] ?? '') === 'info' && $payloadTpl) {
        $payload = str_replace('#CHANNEL#', $channel, str_replace('\\', '', $payloadTpl));
        if (isset($probeResults[$payload]) && !$probeResults[$payload]['ok']) {
          // Battery info / channel sleep have Baichuan fallbacks when HTTP CGI is unsupported
          $isBatteryCmd = in_array($command['abilityneed'] ?? '', array('battery', 'batterySleep'), true);
          if (!$isBatteryCmd && reolink::isPermanentUnsupportedRsp($probeResults[$payload]['rsp'], $isBatChannel)) {
            $unsupportedPayloads[$payload] = true;
            $deniedLogical[$logicalId] = true;
            continue;
          }
        }
      }
      // Actions linked to denied info
      $valueFrom = $command['configuration']['valueFrom'] ?? '';
      if ($valueFrom !== '' && isset($deniedLogical[$valueFrom])) {
        continue;
      }

      $cmd = $camera->getCmd(null, $logicalId);
      if (!is_object($cmd)) {
        foreach ($camera->getCmd() as $liste_cmd) {
          if ($liste_cmd->getName() == ($command['name'] ?? '')) {
            $cmd = $liste_cmd;
            break;
          }
        }
      }

      if (is_object($cmd)) {
        $dirty = false;
        // Refresh payload from JSON if present
        if ($payloadTpl) {
          $cmd->setConfiguration('payload', $payloadTpl);
          $dirty = true;
        }
        $helpTpl = strval($command['configuration']['help'] ?? '');
        if ($helpTpl !== '') {
          $cmd->setConfiguration('help', __($helpTpl, __FILE__));
          $dirty = true;
        }
        $valuesHelpTpl = strval($command['configuration']['valuesHelp'] ?? '');
        if ($valuesHelpTpl !== '') {
          $cmd->setConfiguration('valuesHelp', __($valuesHelpTpl, __FILE__));
          $dirty = true;
        }
        $dynamicSelects = array(
          'SetPtzByPreset', 'StartPtzPatrol', 'SetPTZpatrol',
          'SetResolutionst1', 'SetFPSst1', 'SetBitratest1', 'SetEncodingst1',
          'SetResolutionst2', 'SetFPSst2', 'SetBitratest2', 'SetEncodingst2',
        );
        $listTpl = strval($command['configuration']['listValue'] ?? '');
        if ($listTpl !== '' && !in_array($logicalId, $dynamicSelects, true)) {
          $localizedList = reolink::translateCmdListValue($listTpl);
          if (strval($cmd->getConfiguration('listValue', '')) !== $localizedList) {
            $cmd->setConfiguration('listValue', $localizedList);
            $dirty = true;
          }
        }
        if ($dirty) {
          $cmd->save();
        }
        log::add('reolink', 'debug', 'Commande déjà présente : ' . $command['name']);
        continue;
      }

      // Localize name / listValue / help at creation (FR keys → current Jeedom language)
      $command['name'] = __($command['name'], __FILE__);
      if (isset($command['configuration']) && is_array($command['configuration'])) {
        if (!empty($command['configuration']['help'])) {
          $command['configuration']['help'] = __($command['configuration']['help'], __FILE__);
        }
        if (!empty($command['configuration']['valuesHelp'])) {
          $command['configuration']['valuesHelp'] = __($command['configuration']['valuesHelp'], __FILE__);
        }
        if (!empty($command['configuration']['listValue'])) {
          $command['configuration']['listValue'] = reolink::translateCmdListValue($command['configuration']['listValue']);
        }
      }
      log::add('reolink', 'info', '-> Ajout de : ' . $command['name']);
      $cmd = new reolinkCmd();
      $cmd->setOrder($cmd_order);
      $cmd->setEqLogic_id($camera->getId());
      utils::a2o($cmd, $command);
      $cmd->save();
      if ($valueFrom != "") {
        $cmdlogic = reolinkCmd::byEqLogicIdAndLogicalId($camera->getId(), $valueFrom);
        if (is_object($cmdlogic)) {
          $cmd->setValue($cmdlogic->getId());
          $cmd->save();
          log::add('reolink', 'debug', '--> Valeur lier depuis : ' . $valueFrom . " (" . $cmdlogic->getId() . ")");
        } else {
          log::add('reolink', 'warning', 'X--> Liaison impossible objet introuvable : ' . $valueFrom);
        }
      }
      $cmd_order++;
    }

    // Prune unsupported existing commands
    $keptUnsupported = array();
    if ($pruneUnsupported) {
      foreach ($camera->getCmd() as $existing) {
        $lid = $existing->getLogicalId();
        if ($lid === '' || $lid === null) {
          continue;
        }
        // Never prune refresh (and channel ONVIF/motion infos on camera equipments)
        $keepAlways = array('refresh');
        if (!$camera->isNvrParent()) {
          $keepAlways = array_merge($keepAlways, array('ChannelOnline', 'EvMotion', 'EvFaceDetect', 'EvPeopleDetect', 'EvVehicleDetect', 'EvDogCatDetect', 'EvPackageDetect', 'EvMotionAlarm', 'EvVisitor', 'EvLastOnvifFull', 'EvLastOnvifName', 'EvLastOnvifState', 'MdState'));
        }
        if (in_array($lid, $keepAlways, true)) {
          continue;
        }
        $payloadTpl = $existing->getConfiguration('payload');
        $payload = $payloadTpl ? str_replace('#CHANNEL#', $channel, str_replace('\\', '', $payloadTpl)) : null;
        $isBatteryExisting = in_array($lid, array('BatteryPercent', 'BatteryCharging', 'BatteryChargeStatus', 'BatteryTemperature', 'BatterySleepState'), true);
        $probeFailed = (!$isBatteryExisting && $payload !== null && isset($probeResults[$payload]) && !$probeResults[$payload]['ok']
          && reolink::isPermanentUnsupportedRsp($probeResults[$payload]['rsp'], $isBatChannel));

        $shouldRemove = isset($deniedLogical[$lid]) || $probeFailed;
        // Also remove if ability/subability/scope from JSON would deny
        if (!$shouldRemove) {
          $foundInConf = false;
          foreach ($device['commands'] as $command) {
            if (($command['logicalId'] ?? '') !== $lid) {
              continue;
            }
            $foundInConf = true;
            if (!$camera->allowsCommandScope($command['scope'] ?? 'channel')) {
              $shouldRemove = true;
            } elseif (!reolink::isAbilityOk($devAbilityReturn, $command['abilityneed'] ?? 'none', $command['subability'] ?? null)) {
              $shouldRemove = true;
            }
            break;
          }
          // Orphan camera cmds on NVR parent (legacy) not in host scope
          if (!$foundInConf && $camera->isNvrParent()) {
            $shouldRemove = true;
          }
        }
        if (!$shouldRemove) {
          continue;
        }
        // Mute Get* only when the API call itself fails (not when a sibling field like preRec is denied)
        if ($probeFailed && $existing->getType() === 'info' && $payload !== null) {
          $unsupportedPayloads[$payload] = true;
        }
        if (reolink::isCmdUsedExternally($existing)) {
          if ($probeFailed && $existing->getType() === 'info' && $payload !== null) {
            $keptUnsupported[$payload] = true;
          }
          log::add('reolink', 'warning', 'Commande non supportée conservée (utilisée) : ' . $existing->getName() . ' (' . $lid . ')');
          continue;
        }
        log::add('reolink', 'info', 'Commande non supportée supprimée : ' . $existing->getName() . ' (' . $lid . ')');
        $existing->remove();
      }
    }

    // Remplace l'ancienne commande cachée SetPTZpatrol si les nouvelles existent
    $legacy = $camera->getCmd(null, 'SetPTZpatrol');
    if (is_object($legacy) && is_object($camera->getCmd(null, 'StartPtzPatrol'))) {
      if (!reolink::isCmdUsedExternally($legacy)) {
        $legacy->remove();
        log::add('reolink', 'info', 'Commande legacy SetPTZpatrol supprimée');
      }
    }

    if (!$camera->isNvrParent()) {
      reolink::applyEncListValues($camera, $cnx, $channel);
      reolink::applyZoomFocusRanges($camera, $cnx, $channel);
    }
    $cnx->batteryChannelMode = false;

    $camera->setConfiguration('unsupported_payloads', array_keys(array_merge($unsupportedPayloads, $keptUnsupported)));
    $camera->setConfiguration('unsupported_fail_counts', array());
    $camera->setConfiguration('needs_cmd_resync', 0);
    $camera->save();
    return $cmd_order;
  }

  public static function applyEncListValues($camera, $cnx, $channel) {
    if (!is_object($camera) || !is_object($cnx)) {
      return;
    }
    $res = $cnx->SendCMD('[{"cmd":"GetEnc","action":1,"param":{"channel":' . intval($channel) . '}}]');
    if (!is_array($res) || !isset($res[0]['range']['Enc']) || !is_array($res[0]['range']['Enc'])) {
      return;
    }
    $ranges = $res[0]['range']['Enc'];
    $cur = $res[0]['value']['Enc'] ?? array();
    $sizes1 = array();
    $sizes2 = array();
    $fps1 = array();
    $fps2 = array();
    $br1 = array();
    $br2 = array();
    $vt1 = array();
    $vt2 = array();
    foreach ($ranges as $profile) {
      if (!is_array($profile)) {
        continue;
      }
      if (!empty($profile['mainStream']['size'])) {
        $sizes1[$profile['mainStream']['size']] = true;
      }
      if (!empty($profile['subStream']['size'])) {
        $sizes2[$profile['subStream']['size']] = true;
      }
      foreach ((array) ($profile['mainStream']['frameRate'] ?? array()) as $v) { $fps1[$v] = true; }
      foreach ((array) ($profile['subStream']['frameRate'] ?? array()) as $v) { $fps2[$v] = true; }
      foreach ((array) ($profile['mainStream']['bitRate'] ?? array()) as $v) { $br1[$v] = true; }
      foreach ((array) ($profile['subStream']['bitRate'] ?? array()) as $v) { $br2[$v] = true; }
      foreach ((array) ($profile['mainStream']['vType'] ?? array()) as $v) { $vt1[$v] = true; }
      foreach ((array) ($profile['subStream']['vType'] ?? array()) as $v) { $vt2[$v] = true; }
    }
    // Prefer ranges matching current resolution profile when available
    $curSize1 = $cur['mainStream']['size'] ?? null;
    $curSize2 = $cur['subStream']['size'] ?? null;
    foreach ($ranges as $profile) {
      if ($curSize1 && ($profile['mainStream']['size'] ?? null) === $curSize1) {
        $fps1 = array_fill_keys((array) ($profile['mainStream']['frameRate'] ?? array_keys($fps1)), true);
        $br1 = array_fill_keys((array) ($profile['mainStream']['bitRate'] ?? array_keys($br1)), true);
        $vt1 = array_fill_keys((array) ($profile['mainStream']['vType'] ?? array_keys($vt1)), true);
      }
      if ($curSize2 && ($profile['subStream']['size'] ?? null) === $curSize2) {
        $fps2 = array_fill_keys((array) ($profile['subStream']['frameRate'] ?? array_keys($fps2)), true);
        $br2 = array_fill_keys((array) ($profile['subStream']['bitRate'] ?? array_keys($br2)), true);
        $vt2 = array_fill_keys((array) ($profile['subStream']['vType'] ?? array_keys($vt2)), true);
      }
    }
    $toList = function ($keys) {
      $out = array();
      foreach (array_keys($keys) as $k) {
        $out[] = $k . '|' . $k;
      }
      return implode(';', $out);
    };
    $map = array(
      'SetResolutionst1' => $toList($sizes1),
      'SetFPSst1' => $toList($fps1),
      'SetBitratest1' => $toList($br1),
      'SetEncodingst1' => $toList($vt1),
      'SetResolutionst2' => $toList($sizes2),
      'SetFPSst2' => $toList($fps2),
      'SetBitratest2' => $toList($br2),
      'SetEncodingst2' => $toList($vt2),
    );
    foreach ($map as $lid => $list) {
      if ($list === '') {
        continue;
      }
      $cmd = $camera->getCmd(null, $lid);
      if (!is_object($cmd)) {
        continue;
      }
      $cmd->setConfiguration('listValue', $list);
      $cmd->save();
    }
  }

  public static function applyZoomFocusRanges($camera, $cnx, $channel) {
    if (!is_object($camera) || !is_object($cnx)) {
      return;
    }
    $zoomCmd = $camera->getCmd(null, 'SetZoom');
    $zoomInfo = $camera->getCmd(null, 'SetZoomState');
    $focusCmd = $camera->getCmd(null, 'SetFocus');
    $focusInfo = $camera->getCmd(null, 'SetFocusState');
    if (!is_object($zoomCmd) && !is_object($zoomInfo) && !is_object($focusCmd) && !is_object($focusInfo)) {
      return;
    }
    $res = $cnx->SendCMD('[{"cmd":"GetZoomFocus","action":1,"param":{"channel":' . intval($channel) . '}}]');
    if (!is_array($res) || !isset($res[0]['range']['ZoomFocus']) || !is_array($res[0]['range']['ZoomFocus'])) {
      return;
    }
    $zfRange = $res[0]['range']['ZoomFocus'];
    $zfCur = $res[0]['value']['ZoomFocus'] ?? array();
    $applyPair = function ($actionCmd, $infoCmd, $bounds, $curVal) {
      if (!is_array($bounds) || !isset($bounds['min']) || !isset($bounds['max'])) {
        return;
      }
      $min = intval($bounds['min']);
      $max = intval($bounds['max']);
      if ($max <= $min) {
        return;
      }
      if (is_object($actionCmd)) {
        $newValuesHelp = __('Curseur de', __FILE__) . ' ' . $min . ' ' . __('à', __FILE__) . ' ' . $max;
        $changed = (strval($actionCmd->getConfiguration('minValue', '')) !== strval($min))
          || (strval($actionCmd->getConfiguration('maxValue', '')) !== strval($max))
          || (strval($actionCmd->getConfiguration('valuesHelp', '')) !== $newValuesHelp);
        $actionCmd->setConfiguration('minValue', $min);
        $actionCmd->setConfiguration('maxValue', $max);
        $actionCmd->setConfiguration('valuesHelp', $newValuesHelp);
        if ($changed) {
          $actionCmd->save();
        }
      }
      if (is_object($infoCmd)) {
        $changedInfo = (strval($infoCmd->getConfiguration('minValue', '')) !== strval($min))
          || (strval($infoCmd->getConfiguration('maxValue', '')) !== strval($max));
        $infoCmd->setConfiguration('minValue', $min);
        $infoCmd->setConfiguration('maxValue', $max);
        if ($changedInfo) {
          $infoCmd->save();
        }
        if ($curVal !== null) {
          $infoCmd->event(intval($curVal));
        }
      }
    };
    $applyPair(
      $zoomCmd,
      $zoomInfo,
      $zfRange['zoom']['pos'] ?? null,
      $zfCur['zoom']['pos'] ?? null
    );
    $applyPair(
      $focusCmd,
      $focusInfo,
      $zfRange['focus']['pos'] ?? null,
      $zfCur['focus']['pos'] ?? null
    );
  }

  /*
     * Non obligatoire : permet de modifier l'affichage du widget (également utilisable par les commandes)
      public function toHtml($_version = 'dashboard') {

      }
     */

  /*
     * Non obligatoire : permet de déclencher une action après modification de variable de configuration
    public static function postConfig_<Variable>() {
    }
     */

  /*
     * Non obligatoire : permet de déclencher une action avant modification de variable de configuration
    public static function preConfig_<Variable>() {
    }
     */

  /*     * **********************Getteur Setteur*************************** */
}

class reolinkCmd extends cmd {
  /*     * *************************Attributs****************************** */

  /*
      public static $_widgetPossibility = array();
    */

  /*     * ***********************Methode static*************************** */


  /*     * *********************Methode d'instance************************* */

  /*
     * Non obligatoire permet de demander de ne pas supprimer les commandes même si elles ne sont pas dans la nouvelle configuration de l'équipement envoyé en JS
      public function dontRemoveCmd() {
      return true;
      }
     */

  // Exécution d'une commande
  public function execute($_options = array()) {
    log::add('reolink', 'debug', 'Action demandé : ' . $this->getLogicalId());
    $EqId = $this->getEqLogic_id();
    $eqLogic = $this->getEqLogic();
    $channel = is_object($eqLogic) ? $eqLogic->getChannel() : 0;

    switch ($this->getLogicalId()) {
      case 'refresh':
        reolink::refreshNFO($EqId, true);
        break;
      case 'GetPtzPreset':
        $camcnx = reolink::getReolinkConnection($EqId);
        $data = $camcnx->SendCMD('[{"cmd":"GetPtzPreset","action":1,"param":{"channel":' . $channel . '}}]');
        if (is_array($data) && isset($data[0])) {
          reolink::updatePTZpreset($EqId, $data[0]);
        }
        break;
      case 'GetPtzPatrol':
        $camcnx = reolink::getReolinkConnection($EqId);
        $data = $camcnx->SendCMD('[{"cmd":"GetPtzPatrol","action":0,"param":{"channel":' . $channel . '}}]');
        if (is_array($data) && isset($data[0])) {
          reolink::updatePTZpatrol($EqId, $data[0]);
        }
        break;
      case 'StartPtzPatrol':
        reolink::runPatrol($EqId, 'StartPatrol', $_options['select'] ?? null);
        break;
      case 'StopPtzPatrol':
        reolink::runPatrol($EqId, 'StopPatrol');
        break;
      case 'SetPTZpatrol':
        // Legacy command: StartPatrol/StopPatrol without patrol id
        $op = $_options['select'] ?? '';
        reolink::runPatrol($EqId, $op === 'StopPatrol' ? 'StopPatrol' : 'StartPatrol', 0);
        break;
      case 'SetSpeed':
        $this->setConfiguration('speedvalue', $_options['slider']);
        $this->save();
        break;
      case 'SetPrivacyMode':
        $bc = reolinkBaichuan::fromEqLogic($eqLogic);
        if (!is_object($bc)) {
          throw new Exception(__('Connexion Baichuan impossible (IP/identifiants manquants)', __FILE__));
        }
        $enable = intval($_options['select'] ?? 0) === 1;
        $ip = is_object($eqLogic) ? strval($eqLogic->getConfiguration('adresseip')) : '';
        // Keep privacy state ON + wakeup cache so plugin Caméra snapshots skip HTTPS during reboot
        if (!$enable && is_object($eqLogic) && $eqLogic->isStandaloneCamera()) {
          cache::set('reolink::privacy_wakeup::' . $EqId, time() + 25);
          if ($ip !== '') {
            cache::set('reolink::privacy_wakeup_ip::' . $ip, time() + 35);
          }
        }
        if (!$bc->setPrivacyMode($channel, $enable)) {
          cache::delete('reolink::privacy_wakeup::' . $EqId);
          throw new Exception(__('Échec Mode confidentialité (Baichuan)', __FILE__));
        }
        $httpReady = true;
        if (!$enable && is_object($eqLogic) && $eqLogic->isStandaloneCamera()) {
          $httpReady = reolink::waitHttpPortReady($eqLogic, 20);
          cache::delete('reolink::privacy_wakeup::' . $EqId);
          if ($httpReady && $ip !== '') {
            cache::delete('reolink::privacy_wakeup_ip::' . $ip);
          }
          reolink::resetConnectionCache($eqLogic);
        }
        if (is_object($eqLogic)) {
          $eqLogic->checkAndUpdateCmd('SetPrivacyModeState', $enable ? 1 : 0);
          if (!$enable) {
            reolink::clearLinkedCameraCache($eqLogic);
            if ($httpReady && !$eqLogic->isNvrParent()) {
              $deamonInfo = reolink::deamon_info();
              if (($deamonInfo['state'] ?? '') === 'ok'
                && ($eqLogic->getConfiguration('rtspSubUrl') != '' || $eqLogic->getConfiguration('rtspMainUrl') != '')) {
                try {
                  reolink::ensureGo2rtcStreams($EqId);
                } catch (Exception $e) {
                  log::add('reolink', 'debug', 'Post-privacy go2rtc refresh: ' . $e->getMessage());
                }
              }
              reolink::refreshNFO($EqId);
            }
          }
        }
        break;
      case 'CheckFirmware':
        $camcnx = reolink::getReolinkConnection($EqId);
        $res = $camcnx->SendCMD('[{"cmd":"CheckFirmware"}]');
        if (is_array($res) && isset($res[0]['value']['newFirmware']) && is_object($eqLogic)) {
          $eqLogic->checkAndUpdateCmd('NewFirmwareState', $res[0]['value']['newFirmware']);
          $ipForFw = strval($eqLogic->getConfiguration('adresseip'));
          if ($ipForFw !== '') {
            config::save('last_firmware_check_' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $ipForFw), time(), 'reolink');
          }
        }
        break;
      case 'UpgradeFirmware':
        $camcnx = reolink::getReolinkConnection($EqId);
        $camcnx->SendCMD('[{"cmd":"UpgradeOnline"}]');
        break;
      case 'SyncTime':
        $camcnx = reolink::getReolinkConnection($EqId);
        $tres = $camcnx->SendCMD('[{"cmd":"GetTime"}]');
        if (!is_array($tres) || !isset($tres[0]['value']) || !is_array($tres[0]['value'])) {
          throw new Exception(__('Echec lecture GetTime', __FILE__));
        }
        $now = new DateTime('now');
        $val = $tres[0]['value'];
        $val['Time']['year'] = intval($now->format('Y'));
        $val['Time']['mon'] = intval($now->format('n'));
        $val['Time']['day'] = intval($now->format('j'));
        $val['Time']['hour'] = intval($now->format('G'));
        $val['Time']['min'] = intval($now->format('i'));
        $val['Time']['sec'] = intval($now->format('s'));
        $camresp = $camcnx->SendCMD(json_encode(array(array('cmd' => 'SetTime', 'action' => 0, 'param' => $val))));
        if (!(is_array($camresp) && intval($camresp[0]['code'] ?? -1) === 0)) {
          $apiErr = is_object($camcnx) ? $camcnx->getLastApiError() : '';
          throw new Exception(__('Echec SyncTime', __FILE__) . ($apiErr !== '' ? ' : ' . $apiErr : ''));
        }
        break;
      default:
        $camcnx = reolink::getReolinkConnection($EqId);
        if (is_object($camcnx) && is_object($eqLogic) && $eqLogic->hasBattery()) {
          $camcnx->batteryChannelMode = true;
        }
        // Speed NFO
        $cmd = reolinkCmd::byEqLogicIdAndLogicalId($EqId, "SetSpeed");
        if (is_object($cmd)) {
          $speed = $cmd->getConfiguration('speedvalue');
        } else {
          $speed = 32;
        }

        $actionAPI = $this->getConfiguration('actionapi');
        $linkedvalue = $this->getConfiguration('valueFrom');
        $revert_value = intval($this->getConfiguration('revertvalue'));
        $subtype = $this->getSubType();
        $payloadTpl = str_replace('\\', '', strval($this->getConfiguration('payload')));
        $needsSelect = (strpos($payloadTpl, '#OPTSELECTEDINT#') !== false || strpos($payloadTpl, '#OPTSELECTEDSTR#') !== false);
        $needsSlider = (strpos($payloadTpl, '#OPTSLIDER#') !== false || strpos($payloadTpl, '#OPTR_SLIDER#') !== false);
        $encSelectCmds = array('SetResolutionst1', 'SetResolutionst2', 'SetFPSst1', 'SetFPSst2', 'SetBitratest1', 'SetBitratest2', 'SetEncodingst1', 'SetEncodingst2');
        $isEncSelect = in_array($this->getLogicalId(), $encSelectCmds, true);

        $effectiveVal = null;
        if ($subtype === 'select' || $needsSelect) {
          if (array_key_exists('select', $_options)) {
            $effectiveVal = $_options['select'];
          }
          if ($isEncSelect && is_object($eqLogic) && is_object($camcnx) && strval($this->getConfiguration('listValue', '')) === '') {
            reolink::applyEncListValues($eqLogic, $camcnx, $channel);
            $this->setConfiguration('listValue', $this->getConfiguration('listValue'));
            $refreshed = reolinkCmd::byEqLogicIdAndLogicalId($EqId, $this->getLogicalId());
            if (is_object($refreshed)) {
              $this->setConfiguration('listValue', $refreshed->getConfiguration('listValue'));
            }
          }
          if ($effectiveVal === null || $effectiveVal === '') {
            throw new Exception(__('Option de sélection manquante pour ', __FILE__) . $this->getName());
          }
          $listValue = strval($this->getConfiguration('listValue', ''));
          if ($listValue !== '') {
            $allowed = array();
            foreach (explode(';', $listValue) as $pair) {
              $parts = explode('|', $pair, 2);
              if ($parts[0] !== '') {
                $allowed[] = $parts[0];
              }
            }
            if (!empty($allowed) && !in_array(strval($effectiveVal), $allowed, true)) {
              throw new Exception(__('Valeur hors liste autorisée pour ', __FILE__) . $this->getName());
            }
          }
        } elseif ($subtype === 'slider' || $needsSlider) {
          if (array_key_exists('slider', $_options)) {
            $effectiveVal = intval($_options['slider']);
          } else {
            $effectiveVal = 0;
            if (!empty($linkedvalue) && is_object($eqLogic)) {
              $infoCmd = $eqLogic->getCmd(null, $linkedvalue);
              if (is_object($infoCmd)) {
                $effectiveVal = intval($infoCmd->execCmd());
              }
            }
            $minV = $this->getConfiguration('minValue', null);
            $maxV = $this->getConfiguration('maxValue', null);
            if ($minV !== null && $effectiveVal < intval($minV)) {
              $effectiveVal = intval($minV);
            }
            if ($maxV !== null && $effectiveVal > intval($maxV)) {
              $effectiveVal = intval($maxV);
            }
          }
        }

        if ($actionAPI != NULL) {
          if ($actionAPI === 'Reboot') {
            $payload = '[{"cmd":"Reboot"}]';
          } else {
            $selectInt = ($effectiveVal !== null && ($subtype === 'select' || $needsSelect)) ? intval($effectiveVal) : 0;
            $selectStr = ($effectiveVal !== null && ($subtype === 'select' || $needsSelect)) ? strval($effectiveVal) : '';
            $sliderVal = ($effectiveVal !== null && ($subtype === 'slider' || $needsSlider)) ? intval($effectiveVal) : intval($_options['slider'] ?? 0);
            $patchJson = $payloadTpl;
            $patchJson = str_replace('#OPTSELECTEDINT#', $selectInt, $patchJson);
            $patchJson = str_replace('#OPTSELECTEDSTR#', json_encode($selectStr), $patchJson);
            $patchJson = str_replace('#OPTSLIDER#', $sliderVal, $patchJson);
            $patchJson = str_replace('#OPTR_SLIDER#', abs($revert_value - $sliderVal), $patchJson);
            $patchJson = str_replace('#CHANNEL#', $channel, $patchJson);
            $patchJson = str_replace('#SPEED#', $speed, $patchJson);
            $patch = json_decode($patchJson, true);
            $rmwApis = array('SetRecV20', 'SetEnc', 'SetIsp', 'SetOsd', 'SetAiCfg', 'SetAlarm', 'SetNtp', 'SetWhiteLed', 'SetBuzzerAlarmV20', 'SetAiAlarm');
            if (in_array($actionAPI, $rmwApis, true) && is_array($patch)) {
              if ($actionAPI === 'SetEnc') {
                foreach (array('mainStream', 'subStream') as $stream) {
                  if (!empty($patch['Enc'][$stream]['size']) && strpos($patch['Enc'][$stream]['size'], '*') !== false) {
                    list($w, $h) = explode('*', $patch['Enc'][$stream]['size'], 2);
                    $patch['Enc'][$stream]['width'] = intval($w);
                    $patch['Enc'][$stream]['height'] = intval($h);
                  }
                }
              }
              $merged = reolink::buildRmwPayload($camcnx, $actionAPI, $channel, $patch);
              if ($merged === null) {
                throw new Exception(__('Echec lecture état courant pour Read-Modify-Write', __FILE__));
              }
              if ($actionAPI === 'SetEnc') {
                $changedStream = null;
                if (!empty($patch['Enc']['mainStream']['size'])) {
                  $changedStream = 'mainStream';
                } elseif (!empty($patch['Enc']['subStream']['size'])) {
                  $changedStream = 'subStream';
                }
                if ($changedStream !== null) {
                  $merged = reolink::clampEncToRange($camcnx, $channel, $merged, $changedStream);
                }
              }
              $payload = json_encode(array(array('cmd' => $actionAPI, 'action' => 0, 'param' => $merged)));
            } elseif ($actionAPI === 'SetManualRec' && is_array($patch)) {
              if (intval($patch['Rec']['enable'] ?? 0) === 1) {
                $patch['Rec']['duration'] = 600;
              }
              if (!isset($patch['Rec']['channel'])) {
                $patch['Rec']['channel'] = $channel;
              }
              $payload = json_encode(array(array('cmd' => $actionAPI, 'action' => 0, 'param' => $patch)));
            } else {
              $payload = '[{"cmd":"' . $actionAPI . '","action":0,"param":' . $patchJson . '}]';
            }
          }

          log::add('reolink', 'debug', 'Payload avec paramètre utilisateur demandé = ' . $payload);

          try {
            $camresp = $camcnx->SendCMD($payload);
          } finally {
            if (is_object($camcnx)) {
              $camcnx->batteryChannelMode = false;
            }
          }
          $ok = is_array($camresp) && intval($camresp[0]['code'] ?? -1) === 0;
          if ($ok && array_key_exists('rspCode', $camresp[0]['value'] ?? array())) {
            $ok = intval($camresp[0]['value']['rspCode']) === 200;
          }
          if ($ok) {
            log::add('reolink', 'debug', 'OK > Action réalisé avec succès sur la caméra');

            if (!empty($linkedvalue) && $effectiveVal !== null) {
              $camcmd = reolink::byId($EqId, 'reolink');
              if (is_object($camcmd)) {
                $camcmd->checkAndUpdateCmd($linkedvalue, $effectiveVal);
                log::add('reolink', 'debug', 'Mise à jour de l\'info liée : ' . $linkedvalue . " Valeur=" . $effectiveVal);
              }
            }
            if ($actionAPI === 'SetEnc' && is_object($eqLogic) && is_object($camcnx)) {
              reolink::applyEncListValues($eqLogic, $camcnx, $channel);
            }
          } else {
            $apiErr = is_object($camcnx) ? $camcnx->getLastApiError() : '';
            $rspCode = intval($camcnx->lastResponseData[0]['error']['rspCode'] ?? 0);
            if ($rspCode === -13) {
              $ctx = ($actionAPI !== '' && $apiErr !== '')
                ? $actionAPI . ' — ' . $apiErr
                : ($apiErr !== '' ? $apiErr : $actionAPI);
              throw new Exception(
                __('Réglage refusé par le firmware de la caméra / NVR', __FILE__)
                . ($ctx !== '' ? ' (' . $ctx . ')' : '')
              );
            }
            if ($apiErr !== '') {
              throw new Exception(
                __('Échec d\'exécution de la commande', __FILE__)
                . ($actionAPI !== '' ? ' ' . $actionAPI : '')
                . ' : ' . $apiErr
              );
            }
            throw new Exception(__('Echec d\'execution de la commande (consultez le log pour plus de détails)', __FILE__));
          }
        }
    }
  }

  /*     * **********************Getteur Setteur*************************** */
}
