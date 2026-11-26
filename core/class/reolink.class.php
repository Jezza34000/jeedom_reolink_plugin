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
require_once dirname(__FILE__) . '/../../3rdparty/reolinkfwarchive.class.php';

const CMD_SEND_QTY = 8;

class reolink extends eqLogic {

  const PYTHON_PATH = __DIR__ . '/../../resources/venv/bin/python3';
  const BATTERY_PASSIVE_WAKE_INTERVAL = 3600;
  const BATTERY_WAKE_INTERVAL = 21600;
  const MULTI_HOST_TYPES = array('NVR', 'WIFI_NVR', 'HOMEHUB', 'HVR', 'DVR');
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
      $normType = strtolower(trim(strval($cnxtype)));
      if ($normType === 'baichuan') {
        $bcPort = reolinkBaichuan::resolvePort($camera);
        $cacheKey = 'baichuan|' . $adresseIP . '|' . strval($bcPort);
        if (isset(self::$_cnxByIp[$cacheKey]) && is_object(self::$_cnxByIp[$cacheKey])) {
          return self::$_cnxByIp[$cacheKey];
        }
        $cnxinfo = array("adresseIP" => $adresseIP, "port" => $bcPort, "username" => $username, "password" => $password, "cnxtype" => 'baichuan');
        $camcnx = new reolinkBaichuanAdapter($cnxinfo);
        self::$_cnxByIp[$cacheKey] = $camcnx;
        return $camcnx;
      }
      $cacheKey = $normType . '|' . $adresseIP . '|' . strval($port);
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
    if ($defCh === '' || $defCh === null) {
      return false;
    }
    // Single-channel standalone IPC where user entered 0 in defined_channel is not an NVR child
    $hostType = strtoupper(strval($this->getConfiguration('hostExactType', '')));
    $hostChannels = intval($this->getConfiguration('hostChannelNum', 0));
    if ($hostType !== '' && !in_array($hostType, self::MULTI_HOST_TYPES, true) && $hostChannels === 1 && intval($defCh) === 0) {
      return false;
    }
    return true;
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

  public function usesBaichuanPush() {
    $cnxType = strtolower(trim(strval($this->getConfiguration('cnxtype', ''))));
    if ($cnxType === 'baichuan') {
      return true;
    }
    $mode = strtolower(trim(strval($this->getConfiguration('event_mode', ''))));
    if ($mode === 'baichuan') {
      return true;
    }
    if ($mode === 'onvif') {
      return false;
    }
    if ($this->isChannelChild()) {
      $myHost = trim(strval($this->getConfiguration('adresseip', '')));
      if ($myHost !== '') {
        $myIp = filter_var($myHost, FILTER_VALIDATE_IP) ? $myHost : gethostbyname($myHost);
        foreach (eqLogic::byType('reolink', true) as $eq) {
          if (!$eq->isNvrParent()) {
            continue;
          }
          $pHost = trim(strval($eq->getConfiguration('adresseip', '')));
          if ($pHost === '') {
            continue;
          }
          $pIp = filter_var($pHost, FILTER_VALIDATE_IP) ? $pHost : gethostbyname($pHost);
          if (($pHost === $myHost || $pIp === $myIp)
            && strtolower(trim(strval($eq->getConfiguration('event_mode', '')))) === 'baichuan') {
            return true;
          }
        }
      }
    }
    return false;
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
    $cnxtype = strtolower(trim(strval($eqLogic->getConfiguration('cnxtype', 'https'))));
    if ($cnxtype === 'baichuan') {
      return true;
    }
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

  public static function isPluginActiveFast() {
    try {
      $p = plugin::byId('reolink');
      return is_object($p) && $p->isActive();
    } catch (Exception $e) {
      return false;
    }
  }

  public static function buildHostCacheKey($proto, $ip, $port) {
    $normProto = strtolower(trim(strval($proto)));
    if ($normProto === '') {
      $normProto = 'http';
    }
    $normIp = trim(strval($ip));
    $normPort = trim(strval($port));
    if ($normPort === '') {
      $normPort = ($normProto === 'https') ? '443' : (($normProto === 'baichuan') ? '9000' : '80');
    }
    $safe = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $normProto . '_' . $normIp . '_' . $normPort);
    return 'reolink::host_down::' . $safe;
  }

  public static function getHostCooldownState($proto, $ip, $port) {
    if (trim(strval($ip)) === '') {
      return null;
    }
    $val = cache::byKey(self::buildHostCacheKey($proto, $ip, $port))->getValue(null);
    return is_array($val) ? $val : null;
  }

  public static function isHostInCooldown($proto, $ip, $port) {
    $st = self::getHostCooldownState($proto, $ip, $port);
    if (!is_array($st)) {
      return false;
    }
    return intval($st['down_until'] ?? 0) > time();
  }

  public static function isEquipmentHostInCooldown($eqLogic) {
    if (!is_object($eqLogic)) {
      return false;
    }
    $ip = trim(strval($eqLogic->getConfiguration('adresseip', '')));
    if ($ip === '') {
      return false;
    }
    $cnxType = strtolower(trim(strval($eqLogic->getConfiguration('cnxtype', ''))));
    if ($cnxType === 'baichuan') {
      return self::isHostInCooldown('baichuan', $ip, reolinkBaichuan::resolvePort($eqLogic));
    }
    $proto = ($cnxType === 'https') ? 'https' : 'http';
    $port = trim(strval($eqLogic->getConfiguration('port', '')));
    if ($port === '' || !is_numeric($port)) {
      $port = ($proto === 'https') ? '443' : '80';
    }
    return self::isHostInCooldown($proto, $ip, $port);
  }

  public static function recordHostFailure($proto, $ip, $port, $reason = '') {
    $normIp = trim(strval($ip));
    if ($normIp === '') {
      return 0;
    }
    $wakeupUntil = intval(cache::byKey('reolink::privacy_wakeup_ip::' . $normIp)->getValue(0));
    if ($wakeupUntil > time()) {
      return 0;
    }
    $now = time();
    $key = self::buildHostCacheKey($proto, $normIp, $port);
    $prev = cache::byKey($key)->getValue(null);
    $prevArr = is_array($prev) ? $prev : array();
    $fails = intval($prevArr['fails'] ?? 0) + 1;
    if ($fails <= 1) {
      $cooldown = 30;
    } elseif ($fails === 2) {
      $cooldown = 60;
    } else {
      $cooldown = 120;
    }
    $cleanReason = trim(preg_replace('/\s+/', ' ', strip_tags(strval($reason))));
    if (strlen($cleanReason) > 140) {
      $cleanReason = substr($cleanReason, 0, 140) . '...';
    }
    $lastWarn = intval($prevArr['last_warn'] ?? 0);
    $shouldWarn = ($fails === 1 || ($now - $lastWarn) >= 300);
    if ($shouldWarn) {
      $lastWarn = $now;
      log::add(
        'reolink',
        'warning',
        'Caméra ' . $normIp . ':' . $port . ' (' . strtoupper(strval($proto)) . ') injoignable'
          . ($cleanReason !== '' ? ' [' . $cleanReason . ']' : '')
          . ' — mise en pause ' . $cooldown . 's (échec #' . $fails . ')'
      );
    } else {
      log::add(
        'reolink',
        'debug',
        'Caméra ' . $normIp . ':' . $port . ' toujours injoignable'
          . ($cleanReason !== '' ? ' [' . $cleanReason . ']' : '')
          . ' — pause ' . $cooldown . 's (échec #' . $fails . ')'
      );
    }
    cache::set($key, array(
      'fails' => $fails,
      'down_until' => $now + $cooldown,
      'last_warn' => $lastWarn,
      'reason' => $cleanReason,
    ));
    return $cooldown;
  }

  public static function recordHostSuccess($proto, $ip, $port) {
    $normIp = trim(strval($ip));
    if ($normIp === '') {
      return;
    }
    $key = self::buildHostCacheKey($proto, $normIp, $port);
    $prev = cache::byKey($key)->getValue(null);
    if (is_array($prev) && intval($prev['fails'] ?? 0) > 0) {
      $fails = intval($prev['fails']);
      cache::delete($key);
      log::add(
        'reolink',
        'info',
        'Caméra ' . $normIp . ':' . $port . ' (' . strtoupper(strval($proto)) . ') de nouveau joignable après ' . $fails . ' échec(s)'
      );
    }
  }

  public static function clearHostCooldown($eqLogic) {
    if (!is_object($eqLogic)) {
      return;
    }
    $ip = trim(strval($eqLogic->getConfiguration('adresseip', '')));
    if ($ip === '') {
      return;
    }
    $port = trim(strval($eqLogic->getConfiguration('port', '')));
    $bcPort = reolinkBaichuan::resolvePort($eqLogic);
    $candidates = array(
      array('http', $ip, ($port !== '' && is_numeric($port)) ? $port : '80'),
      array('https', $ip, ($port !== '' && is_numeric($port)) ? $port : '443'),
      array('http', $ip, '80'),
      array('https', $ip, '443'),
      array('baichuan', $ip, strval($bcPort)),
      array('baichuan', $ip, '9000'),
    );
    foreach ($candidates as $c) {
      cache::delete(self::buildHostCacheKey($c[0], $c[1], $c[2]));
    }
    self::resetConnectionCache($eqLogic);
  }

  public static function resetConnectionCache($eqLogic) {
    if (!is_object($eqLogic)) {
      return;
    }
    $ip = trim(strval($eqLogic->getConfiguration('adresseip', '')));
    if ($ip === '') {
      return;
    }
    foreach (array_keys(self::$_cnxByIp) as $k) {
      if (strpos($k, '|' . $ip . '|') !== false || strpos($k, $ip . '|') === 0) {
        unset(self::$_cnxByIp[$k]);
      }
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

  public static function getGo2rtcApiPort() {
    $p = intval(config::byKey('go2rtc_api_port', __CLASS__, 44011));
    return ($p > 0 && $p <= 65535) ? $p : 44011;
  }

  public static function getGo2rtcWebrtcPort() {
    $p = intval(config::byKey('go2rtc_webrtc_port', __CLASS__, 44012));
    return ($p > 0 && $p <= 65535) ? $p : 44012;
  }

  public static function isWebrtcEnabled() {
    return intval(config::byKey('enable_webrtc', __CLASS__, 0)) === 1;
  }

  public static function getGo2rtcWebrtcCandidates() {
    $raw = trim(strval(config::byKey('go2rtc_webrtc_candidates', __CLASS__, '')));
    if ($raw === '') {
      return array();
    }
    $out = array();
    foreach (preg_split('/[\s,;]+/', $raw) as $item) {
      $item = trim($item);
      if ($item !== '' && preg_match('/^[A-Za-z0-9._:\[\]-]{3,128}$/', $item)) {
        $out[] = $item;
      }
    }
    return array_values(array_unique($out));
  }

  public static function getTalkBridgePort() {
    $p = intval(config::byKey('talk_port', __CLASS__, 44013));
    return ($p > 0 && $p <= 65535) ? $p : 44013;
  }

  public static function sanitizeStreamKey($key) {
    $key = strtolower(trim(strval($key)));
    $allowed = array('sub', 'ext', 'main', 'autotrack_sub', 'autotrack_main', 'jpeg');
    return in_array($key, $allowed, true) ? $key : 'sub';
  }

  public function getOrCreateTalkToken() {
    $tok = trim(strval($this->getConfiguration('talk_token', '')));
    if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $tok)) {
      $tok = bin2hex(random_bytes(12));
      $this->setConfiguration('talk_token', $tok);
      if ($this->getId() > 0) {
        $this->save(true);
      }
    }
    return $tok;
  }

  public function getLocalTalkRtspUrl($withBackchannelParam = true) {
    $tok = $this->getOrCreateTalkToken();
    $url = 'rtsp://127.0.0.1:' . self::getTalkBridgePort() . '/talk/' . intval($this->getId()) . '/' . $tok;
    return $withBackchannelParam ? ($url . '#backchannel=1') : $url;
  }

  public function getOrCreateLiveToken() {
    $tok = trim(strval($this->getConfiguration('live_token', '')));
    if (!preg_match('/^[A-Za-z0-9_-]{24,80}$/', $tok)) {
      $tok = bin2hex(random_bytes(20));
      $this->setConfiguration('live_token', $tok);
      if ($this->getId() > 0) {
        $this->save(true);
      }
    }
    return $tok;
  }

  public function regenerateLiveToken() {
    $tok = bin2hex(random_bytes(20));
    $this->setConfiguration('live_token', $tok);
    if ($this->getId() > 0) {
      $this->save(true);
    }
    return $tok;
  }

  public function getWebViewLiveUrl($preferExternal = true) {
    $base = '';
    if ($preferExternal) {
      $base = rtrim(trim(strval(network::getNetworkAccess('external'))), '/');
    }
    if ($base === '' || strpos($base, 'http') !== 0) {
      $base = rtrim(trim(strval(network::getNetworkAccess('internal'))), '/');
    }
    $tok = $this->getOrCreateLiveToken();
    return $base . '/plugins/reolink/core/php/live.php?id=' . intval($this->getId()) . '&token=' . rawurlencode($tok);
  }

  public static function isAuthorizedForLive($eqLogic, $token = '') {
    if (function_exists('isConnect') && isConnect()) {
      return true;
    }
    if (!is_object($eqLogic) || $eqLogic->isNvrParent()) {
      return false;
    }
    $tok = trim(strval($token));
    if ($tok === '') {
      return false;
    }
    return hash_equals($eqLogic->getOrCreateLiveToken(), $tok);
  }

  private static function parseCmdSelectList($cmd) {
    $out = array();
    if (!is_object($cmd)) {
      return $out;
    }
    $raw = trim(strval($cmd->getConfiguration('listValue', '')));
    if ($raw === '') {
      return $out;
    }
    foreach (explode(';', $raw) as $pair) {
      $bits = explode('|', $pair, 2);
      $id = trim($bits[0] ?? '');
      $lbl = trim($bits[1] ?? $id);
      if ($id === '' || strpos($lbl, '(Cliquez sur') !== false) {
        continue;
      }
      $out[] = array('id' => $id, 'label' => $lbl);
    }
    return $out;
  }

  public function buildLiveControlsMeta() {
    $cnxType = strtolower(trim(strval($this->getConfiguration('cnxtype', ''))));
    $availableStreams = $this->getAvailableStreams();
    $streamsJs = array();
    foreach ($availableStreams as $sKey => $sMeta) {
      $streamsJs[$sKey] = array(
        'key' => $sKey,
        'label' => $sMeta['label'],
        'type' => $sMeta['type'],
      );
    }
    $hasWebrtc = self::isWebrtcEnabled() && ($cnxType !== 'baichuan');
    $hasTalk = $hasWebrtc && (intval($this->getConfiguration('hasAudioTalk', 0)) === 1);

    $cmdPtz = $this->getCmd(null, 'SetPTZmoove');
    $ptzDirs = array();
    if (is_object($cmdPtz)) {
      $rawPtz = strval($cmdPtz->getConfiguration('listValue', ''));
      if ($rawPtz === '') {
        $ptzDirs = array('Up' => true, 'Left' => true, 'Right' => true, 'Down' => true);
      } else {
        foreach (explode(';', $rawPtz) as $pair) {
          $bits = explode('|', $pair, 2);
          $dk = trim($bits[0]);
          if ($dk !== '' && $dk !== 'Stop') {
            $ptzDirs[$dk] = true;
          }
        }
      }
    }

    $cmdZoom = $this->getCmd(null, 'SetZoom');
    $cmdZoomState = $this->getCmd(null, 'SetZoomState');
    $cmdFocus = $this->getCmd(null, 'SetFocus');
    $cmdFocusState = $this->getCmd(null, 'SetFocusState');
    $cmdPreset = $this->getCmd(null, 'SetPtzByPreset');
    $cmdPatrolStart = $this->getCmd(null, 'StartPtzPatrol');
    $cmdPatrolStop = $this->getCmd(null, 'StopPtzPatrol');
    $cmdQuickReply = $this->getCmd(null, 'QuickReplyPlay');
    $cmdWhite = $this->getCmd(null, 'SetWhitLed');
    $cmdWhiteState = $this->getCmd(null, 'SetWhitLedState');
    $cmdSiren = $this->getCmd(null, 'AudioAlarmPlayTimes');
    if (!is_object($cmdSiren)) {
      $cmdSiren = $this->getCmd(null, 'SetAudioAlarm');
    }

    return array(
      'cnxType' => $cnxType,
      'hasWebrtc' => $hasWebrtc,
      'hasTalk' => $hasTalk,
      'isBattery' => $this->hasBattery(),
      'streams' => $streamsJs,
      'controls' => array(
        'ptz' => is_object($cmdPtz) ? array('cmdId' => strval($cmdPtz->getId()), 'dirs' => $ptzDirs) : null,
        'zoom' => is_object($cmdZoom) ? array(
          'cmdId' => strval($cmdZoom->getId()),
          'cur' => is_object($cmdZoomState) ? intval($cmdZoomState->execCmd()) : 0,
          'min' => intval($cmdZoom->getConfiguration('minValue', 0)),
          'max' => max(1, intval($cmdZoom->getConfiguration('maxValue', 32))),
        ) : null,
        'focus' => is_object($cmdFocus) ? array(
          'cmdId' => strval($cmdFocus->getId()),
          'cur' => is_object($cmdFocusState) ? intval($cmdFocusState->execCmd()) : 0,
          'min' => intval($cmdFocus->getConfiguration('minValue', 0)),
          'max' => max(1, intval($cmdFocus->getConfiguration('maxValue', 255))),
        ) : null,
        'presets' => is_object($cmdPreset) ? array(
          'cmdId' => strval($cmdPreset->getId()),
          'list' => self::parseCmdSelectList($cmdPreset),
          'canSave' => true,
        ) : null,
        'patrols' => (is_object($cmdPatrolStart) || is_object($cmdPatrolStop)) ? array(
          'startCmdId' => is_object($cmdPatrolStart) ? strval($cmdPatrolStart->getId()) : '',
          'stopCmdId' => is_object($cmdPatrolStop) ? strval($cmdPatrolStop->getId()) : '',
          'list' => self::parseCmdSelectList($cmdPatrolStart),
        ) : null,
        'quickReplies' => is_object($cmdQuickReply) ? array(
          'cmdId' => strval($cmdQuickReply->getId()),
          'list' => self::parseCmdSelectList($cmdQuickReply),
        ) : null,
        'whiteLed' => is_object($cmdWhite) ? array(
          'cmdId' => strval($cmdWhite->getId()),
          'state' => is_object($cmdWhiteState) ? (intval($cmdWhiteState->execCmd()) === 1) : false,
        ) : null,
        'siren' => is_object($cmdSiren) ? array('cmdId' => strval($cmdSiren->getId())) : null,
      ),
    );
  }

  public function buildFlvStreamUrl($streamSuffix) {
    $cnxType = strtolower(trim(strval($this->getConfiguration('cnxtype', ''))));
    if ($cnxType === 'baichuan') {
      return '';
    }
    $rtmpEnable = strval($this->getConfiguration('rtmpEnable', 'Actif'));
    if ($rtmpEnable === 'Désactivé' || $rtmpEnable === '0') {
      return '';
    }
    $rtmpPort = intval(preg_replace('/\D/', '', strval($this->getConfiguration('rtmpPort', '1935')))) ?: 1935;
    $useHttps = ($cnxType === 'https');
    $configuredPort = preg_replace('/\D/', '', strval($this->getConfiguration('port', '')));
    if ($configuredPort !== '') {
      $webPort = intval($configuredPort);
    } elseif ($useHttps) {
      $webPort = intval(preg_replace('/\D/', '', strval($this->getConfiguration('httpsPort', '443')))) ?: 443;
    } else {
      $webPort = intval(preg_replace('/\D/', '', strval($this->getConfiguration('httpPort', '80')))) ?: 80;
    }
    // httpx:// tells go2rtc to skip TLS certificate verification even when host is a DNS name
    $scheme = $useHttps ? 'httpx' : 'http';
    $ip = trim(strval($this->getConfiguration('adresseip', '')));
    if ($ip === '') {
      return '';
    }
    $ch = intval($this->getChannel());
    $u = rawurlencode(strval($this->getConfiguration('login', 'admin')));
    $p = rawurlencode(strval($this->getConfiguration('password', '')));
    return $scheme . '://' . $ip . ':' . $webPort
      . '/flv?port=' . $rtmpPort
      . '&app=bcs&stream=channel' . $ch . '_' . $streamSuffix . '.bcs'
      . '&user=' . $u . '&password=' . $p;
  }

  private static function appendNoBackchannel($rtspUrl) {
    $rtspUrl = trim(strval($rtspUrl));
    if ($rtspUrl === '' || strpos($rtspUrl, '#') !== false) {
      return $rtspUrl;
    }
    return $rtspUrl . '#backchannel=0';
  }

  public function getAvailableStreams() {
    if ($this->isNvrParent()) {
      return array();
    }
    $cnxType = strtolower(trim(strval($this->getConfiguration('cnxtype', ''))));
    $hasTalk = (intval($this->getConfiguration('hasAudioTalk', 0)) === 1);
    if ($cnxType === 'baichuan') {
      return array(
        'jpeg' => array(
          'key' => 'jpeg',
          'label' => __('JPEG (cmd 109)', __FILE__),
          'type' => 'jpeg',
          'url' => '',
          'talk' => $hasTalk,
        ),
      );
    }

    $streams = array();
    $subUrl = self::appendNoBackchannel($this->resolveRtspUrl($this->getConfiguration('rtspSubUrl'), true));
    if ($subUrl !== '') {
      $streams['sub'] = array(
        'key' => 'sub',
        'label' => __('Low (sub)', __FILE__),
        'type' => 'go2rtc',
        'url' => $subUrl,
        'talk' => $hasTalk,
      );
    }

    if (intval($this->getConfiguration('hasExtStream', 0)) === 1) {
      $extUrl = $this->buildFlvStreamUrl('ext');
      if ($extUrl !== '') {
        $streams['ext'] = array(
          'key' => 'ext',
          'label' => __('Fluent (ext)', __FILE__),
          'type' => 'go2rtc',
          'url' => $extUrl,
          'talk' => $hasTalk,
        );
      }
    }

    $mainUrl = self::appendNoBackchannel($this->resolveRtspUrl($this->getConfiguration('rtspMainUrl'), false));
    if ($mainUrl !== '') {
      $streams['main'] = array(
        'key' => 'main',
        'label' => __('High (main)', __FILE__),
        'type' => 'go2rtc',
        'url' => $mainUrl,
        'talk' => $hasTalk,
      );
    }

    if (intval($this->getConfiguration('hasAutoTrackStream', 0)) === 1) {
      if ($this->isChannelChild()) {
        $atSubUrl = $this->buildFlvStreamUrl('autotrack_sub');
        $chStr = str_pad(strval(intval($this->getChannel()) + 1), 2, '0', STR_PAD_LEFT);
        $atMainTpl = 'rtsp://#username#:#password#@#ip#:#port#/Preview_' . $chStr . '_autotrack';
        $atMainUrl = self::appendNoBackchannel($this->resolveRtspUrl($atMainTpl, false));
      } else {
        $atSubUrl = self::appendNoBackchannel($this->resolveRtspUrl('rtsp://#username#:#password#@#ip#:#port#/h264Preview_02_sub', true));
        $atMainUrl = self::appendNoBackchannel($this->resolveRtspUrl('rtsp://#username#:#password#@#ip#:#port#/Preview_02_main', false));
      }
      if ($atSubUrl !== '') {
        $streams['autotrack_sub'] = array(
          'key' => 'autotrack_sub',
          'label' => __('Téléobjectif Low', __FILE__),
          'type' => 'go2rtc',
          'url' => $atSubUrl,
          'talk' => $hasTalk,
        );
      }
      if ($atMainUrl !== '') {
        $streams['autotrack_main'] = array(
          'key' => 'autotrack_main',
          'label' => __('Téléobjectif High', __FILE__),
          'type' => 'go2rtc',
          'url' => $atMainUrl,
          'talk' => $hasTalk,
        );
      }
    }

    return $streams;
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
    $camera = reolink::byId($id, 'reolink');
    if (!is_object($camera)) {
      return false;
    }
    $reolinkConn = reolink::getReolinkConnection($id);
    if (!is_object($reolinkConn) || $reolinkConn->is_loggedin != true) {
      log::add('reolink', 'error', 'Connection à la caméra NOK');
      return false;
    }

    $res = $reolinkConn->SendCMD('[{"cmd":"GetDevInfo"}]');
    $data = is_array($res) ? $res : $reolinkConn->lastResponseData;
    $devInfo = null;
    if (is_array($data)) {
      foreach ($data as $item) {
        if (($item['cmd'] ?? '') === 'GetDevInfo' && isset($item['value']['DevInfo']) && is_array($item['value']['DevInfo'])) {
          $devInfo = $item['value']['DevInfo'];
          break;
        }
      }
    }
    if (!is_array($devInfo)) {
      log::add('reolink', 'error', 'Connection OK mais GetDevInfo indisponible');
      return false;
    }

    $hostType = strtoupper(strval($devInfo['exactType'] ?? ($devInfo['type'] ?? '')));
    $isMultiHost = in_array($hostType, self::MULTI_HOST_TYPES, true) || intval($devInfo['channelNum'] ?? 1) > 1;
    $definedChannel = trim(strval($camera->getConfiguration('defined_channel', '')));
    if ($isMultiHost && $definedChannel !== '' && is_numeric($definedChannel)) {
      $ch = intval($definedChannel);
      $stRes = $reolinkConn->SendCMD('[{"cmd":"GetChannelstatus","action":0,"param":{}}]');
      $stData = is_array($stRes) ? $stRes : $reolinkConn->lastResponseData;
      $chFound = false;
      $chAlive = false;
      if (is_array($stData)) {
        foreach ($stData as $stItem) {
          if (!in_array($stItem['cmd'] ?? '', array('GetChannelstatus', 'GetChannelStatus'), true)) {
            continue;
          }
          foreach (($stItem['value']['status'] ?? array()) as $entry) {
            if (is_array($entry) && intval($entry['channel'] ?? -1) === $ch) {
              $chFound = true;
              $chAlive = (intval($entry['online'] ?? 0) === 1 || intval($entry['sleep'] ?? 0) === 1);
              break 2;
            }
          }
        }
      }
      if (!$chFound || !$chAlive) {
        log::add('reolink', 'error', 'Connexion au NVR OK mais canal ' . $ch . ' hors ligne ou inexistant');
        return false;
      }
    }

    log::add('reolink', 'info', 'Connection à la caméra réussie');
    return true;
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
    if (!is_object($reolinkConn) || !$reolinkConn->is_loggedin) {
      return false;
    }

    $batch = array(
      array('cmd' => 'GetDevInfo'),
      array('cmd' => 'GetP2p'),
      array('cmd' => 'GetLocalLink'),
      array('cmd' => 'GetNetPort'),
    );
    if (!$camera->isNvrParent()) {
      $batch[] = array('cmd' => 'GetAiState', 'action' => 0, 'param' => array('channel' => $channel));
      $batch[] = array('cmd' => 'GetRtspUrl', 'action' => 0, 'param' => array('channel' => $channel));
      if (!$camera->hasBattery()) {
        $batch[] = array('cmd' => 'GetEnc', 'action' => 0, 'param' => array('channel' => $channel));
      }
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
    $camera->setConfiguration('hostHardVer', $devInfo['hardVer'] ?? '');
    // Re-evaluate channel child status now that hostExactType/hostChannelNum are known
    $isChannel = $camera->isChannelChild();

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
    // API may answer GetChannelstatus / GetP2p with either casing
    if (!isset($byCmd['GetChannelstatus']) && isset($byCmd['GetChannelStatus'])) {
      $byCmd['GetChannelstatus'] = $byCmd['GetChannelStatus'];
    }
    $p2pItem = $byCmd['GetP2p'] ?? ($byCmd['GetP2P'] ?? null);
    $hostUid = trim(strval($p2pItem['value']['P2p']['uid'] ?? ($p2pItem['value']['P2P']['uid'] ?? '')));
    if ($hostUid !== '' && !$isChannel) {
      $camera->setConfiguration('uid', $hostUid);
    } elseif (!$isChannel && trim(strval($camera->getConfiguration('uid', ''))) === '') {
      $bcUid = reolinkBaichuan::fromEqLogic($camera);
      if (is_object($bcUid)) {
        $fallbackUid = $bcUid->getUid();
        if ($fallbackUid !== null && $fallbackUid !== '') {
          $camera->setConfiguration('uid', $fallbackUid);
        }
      }
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
    if (isset($byCmd['GetEnc']['value']['Enc']) && is_array($byCmd['GetEnc']['value']['Enc'])) {
      $hasExt = (isset($byCmd['GetEnc']['value']['Enc']['extStream']) && is_array($byCmd['GetEnc']['value']['Enc']['extStream'])) ? 1 : 0;
      $camera->setConfiguration('hasExtStream', $hasExt);
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

    if (self::isGithubFirmwareCheckEnabled()) {
      try {
        self::checkEquipmentFirmwareArchive($camera, null, false);
      } catch (Exception $e) {
        log::add('reolink', 'debug', 'GetCamNFO firmware archive check ignoré : ' . $e->getMessage());
      }
    }

    $camera->save();
    return true;
  }

  public static function getSnapshot($_cameraEqLogic) {
    if (!is_object($_cameraEqLogic) || !self::isPluginActiveFast()) {
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
    if (!is_object($reolinkEq) || intval($reolinkEq->getIsEnable()) !== 1 || $reolinkEq->isNvrParent()) {
      return '';
    }
    return reolink::captureSnapshotSafe($reolinkEq, 'sub');
  }

  public static function captureSnapshotSafe($reolinkEq, $quality = 'sub', $noWake = false) {
    if (!self::isPluginActiveFast() || !is_object($reolinkEq) || intval($reolinkEq->getIsEnable()) !== 1 || $reolinkEq->isNvrParent()) {
      return '';
    }
    if ($reolinkEq->isStandalonePrivacyBlocking()) {
      return '';
    }
    $quality = ($quality === 'main') ? 'main' : 'sub';
    $snapCacheKey = 'reolink::last_snap::' . $reolinkEq->getId() . '::' . $quality;
    if ($reolinkEq->hasBattery() && ($noWake || $reolinkEq->isBatterySleeping())) {
      $cachedB64 = strval(cache::byKey($snapCacheKey)->getValue(''));
      if ($cachedB64 !== '') {
        $decoded = base64_decode($cachedB64, true);
        if ($decoded !== false && $decoded !== '') {
          return $decoded;
        }
      }
      return '';
    }
    if (self::isEquipmentHostInCooldown($reolinkEq)) {
      return '';
    }
    $cnx = reolink::getReolinkConnection($reolinkEq->getId());
    if (!is_object($cnx) || !$cnx->is_loggedin) {
      return '';
    }
    $jpeg = $cnx->SendSnap($reolinkEq->getChannel(), $quality);
    if ($jpeg === false || $jpeg === '') {
      return '';
    }
    if ($reolinkEq->hasBattery() || $quality === 'sub') {
      cache::set($snapCacheKey, base64_encode($jpeg));
    }
    return $jpeg;
  }

  public static function sendSnapshotNotification($id, $cmdObj, $_options = array()) {
    $eqLogic = reolink::byId($id, 'reolink');
    if (!is_object($eqLogic) || $eqLogic->isNvrParent()) {
      throw new Exception(__('Équipement caméra invalide pour SendSnapshot', __FILE__));
    }
    $rawTitle = trim(strval($_options['title'] ?? ''));
    $rawMessage = strval($_options['message'] ?? '');
    $targetSpec = '';
    $msgTitle = $rawTitle;
    if ($rawTitle !== '' && preg_match('/^#[^#]+#$/', $rawTitle)) {
      $targetSpec = $rawTitle;
      $msgTitle = $eqLogic->getName();
    } else {
      $targetSpec = trim(strval(is_object($cmdObj) ? $cmdObj->getConfiguration('targetMsgCmd', '') : ''));
      if ($msgTitle === '') {
        $msgTitle = $eqLogic->getName();
      }
    }
    if ($targetSpec === '') {
      throw new Exception(__('Veuillez configurer la commande de notification cible (ex: #[Maison][Telegram][Envoyer]#) sur la commande Envoyer une capture, ou la passer dans le champ Titre du scénario.', __FILE__));
    }
    $targetCmd = (strpos($targetSpec, '#') !== false)
      ? cmd::byString($targetSpec)
      : cmd::byId(intval($targetSpec));
    if (!is_object($targetCmd)) {
      throw new Exception(__('Commande de notification introuvable : ', __FILE__) . $targetSpec);
    }
    $snapQuality = is_object($cmdObj) && strval($cmdObj->getConfiguration('snapQuality', 'main')) === 'sub' ? 'sub' : 'main';
    $jpeg = self::captureSnapshotSafe($eqLogic, $snapQuality);
    if ($jpeg === '' && $snapQuality === 'main') {
      $jpeg = self::captureSnapshotSafe($eqLogic, 'sub');
    }
    if ($jpeg === '') {
      throw new Exception(__('Impossible de capturer un cliché sur la caméra ', __FILE__) . $eqLogic->getName());
    }
    $tmpDir = jeedom::getTmpFolder('reolink');
    if (!is_dir($tmpDir)) {
      @mkdir($tmpDir, 0770, true);
    }
    $tmpFile = rtrim($tmpDir, '/') . '/snap_' . intval($eqLogic->getId()) . '_' . uniqid('', true) . '.jpg';
    if (@file_put_contents($tmpFile, $jpeg) === false) {
      throw new Exception(__('Impossible d\'écrire le cliché temporaire', __FILE__));
    }
    @chmod($tmpFile, 0640);
    try {
      $targetCmd->execCmd(array(
        'title' => $msgTitle,
        'message' => $rawMessage,
        'files' => array($tmpFile),
      ));
    } finally {
      @unlink($tmpFile);
    }
  }

  public static function extractPopulatedNvrChannels($statusList) {
    $populated = array();
    if (!is_array($statusList)) {
      return $populated;
    }
    foreach ($statusList as $st) {
      if (!is_array($st) || !isset($st['channel']) || !is_numeric($st['channel'])) {
        continue;
      }
      $ch = intval($st['channel']);
      if ($ch < 0) {
        continue;
      }
      $hasCam = (intval($st['online'] ?? 0) === 1
        || intval($st['sleep'] ?? 0) === 1
        || trim(strval($st['uid'] ?? '')) !== ''
        || trim(strval($st['name'] ?? '')) !== ''
        || trim(strval($st['typeInfo'] ?? '')) !== '');
      if ($hasCam) {
        $populated[$ch] = $st;
      }
    }
    ksort($populated);
    return $populated;
  }

  public static function syncNvrChannels($nvrId) {
    $nvr = reolink::byId($nvrId, 'reolink');
    if (!is_object($nvr)) {
      throw new Exception(__('Équipement Reolink introuvable', __FILE__));
    }
    if (trim(strval($nvr->getConfiguration('defined_channel', ''))) === ''
      && trim(strval($nvr->getConfiguration('hostExactType', ''))) === '') {
      reolink::GetCamNFO($nvr->getId());
      $nvr = reolink::byId($nvrId, 'reolink');
    }
    if (!is_object($nvr) || !$nvr->isNvrParent()) {
      throw new Exception(__('Cet équipement n\'est pas un NVR ou Home Hub parent (le champ Canal NVR doit rester vide)', __FILE__));
    }

    $conn = reolink::getReolinkConnection($nvr->getId());
    if (!is_object($conn) || $conn->is_loggedin != true) {
      throw new Exception(__('Impossible de se connecter au NVR / Home Hub', __FILE__));
    }

    $res = $conn->SendCMD('[{"cmd":"GetChannelstatus","action":0,"param":{}}]');
    $data = is_array($res) ? $res : $conn->lastResponseData;
    $statusList = null;
    if (is_array($data)) {
      foreach ($data as $item) {
        if (in_array($item['cmd'] ?? '', array('GetChannelstatus', 'GetChannelStatus'), true)
          && isset($item['value']['status']) && is_array($item['value']['status'])) {
          $statusList = $item['value']['status'];
          break;
        }
      }
    }
    if (!is_array($statusList)) {
      throw new Exception(__('Impossible de lire la liste des canaux du NVR (GetChannelstatus)', __FILE__));
    }

    $populated = self::extractPopulatedNvrChannels($statusList);
    $resolveIp = function ($raw) {
      $h = trim(strval($raw));
      if ($h === '') {
        return '';
      }
      return filter_var($h, FILTER_VALIDATE_IP) ? $h : gethostbyname($h);
    };
    $nvrHost = trim(strval($nvr->getConfiguration('adresseip', '')));
    $nvrIp = $resolveIp($nvrHost);

    $existingByChannel = array();
    foreach (eqLogic::byType('reolink', false) as $eq) {
      if (intval($eq->getId()) === intval($nvr->getId())) {
        continue;
      }
      $defCh = trim(strval($eq->getConfiguration('defined_channel', '')));
      if ($defCh === '' || !is_numeric($defCh)) {
        continue;
      }
      $eqHost = trim(strval($eq->getConfiguration('adresseip', '')));
      if ($eqHost === $nvrHost || ($nvrIp !== '' && $resolveIp($eqHost) === $nvrIp)) {
        $existingByChannel[intval($defCh)] = $eq;
      }
    }

    $created = 0;
    $existing = 0;
    $createdIds = array();
    $objId = $nvr->getObject_id();
    $autoRefresh = trim(strval($nvr->getConfiguration('autorefresh', '')));
    if ($autoRefresh === '') {
      $autoRefresh = '*/15 * * * *';
    }

    foreach ($populated as $ch => $st) {
      if (isset($existingByChannel[$ch])) {
        $existing++;
        continue;
      }
      $baseName = trim(strval($st['name'] ?? ''));
      if ($baseName === '') {
        $baseName = trim(strval($nvr->getName())) . ' - Canal ' . $ch;
      }
      $candidateName = $baseName;
      $suffix = 1;
      while (is_object(eqLogic::byObjectIdEqLogicName($objId, $candidateName))) {
        $candidateName = $baseName . ' (' . ($suffix === 1 ? ('ch' . $ch) : ('ch' . $ch . '_' . $suffix)) . ')';
        $suffix++;
      }

      $child = new reolink();
      $child->setEqType_name('reolink');
      $child->setName($candidateName);
      $child->setObject_id($objId);
      $child->setCategory('security', 1);
      $child->setIsEnable(1);
      $child->setIsVisible(1);
      foreach (array('adresseip', 'port', 'port_onvif', 'login', 'password', 'cnxtype', 'event_mode') as $cfgKey) {
        $val = $nvr->getConfiguration($cfgKey, '');
        if ($val !== '' || in_array($cfgKey, array('adresseip', 'login', 'password', 'cnxtype'), true)) {
          $child->setConfiguration($cfgKey, $val);
        }
      }
      $child->setConfiguration('autorefresh', $autoRefresh);
      $child->setConfiguration('defined_channel', strval($ch));
      $child->save();

      $newId = $child->getId();
      reolink::GetCamNFO($newId);
      $childReloaded = reolink::byId($newId, 'reolink');
      if (is_object($childReloaded)) {
        $childReloaded->loadCmdFromConf($newId);
        if (intval($st['online'] ?? 0) === 1) {
          reolink::refreshNFO($newId);
        }
      }
      $created++;
      $createdIds[] = intval($newId);
    }

    if ($created > 0 && intval($nvr->getIsEnable()) === 1) {
      reolink::cron10();
    }

    return array(
      'created' => $created,
      'existing' => $existing,
      'total' => count($populated),
      'createdIds' => $createdIds,
    );
  }

  public static function formatSceneListValue($scenes) {
    $entries = array('-1|' . __('Désactiver les scènes', __FILE__));
    if (is_array($scenes)) {
      ksort($scenes, SORT_NUMERIC);
      foreach ($scenes as $id => $name) {
        $sid = intval($id);
        if ($sid < 0) {
          continue;
        }
        $clean = trim(str_replace(array(';', '|'), array(',', '/'), strval($name)));
        if ($clean === '') {
          $clean = 'Scene ' . $sid;
        }
        $entries[] = $sid . '|' . $clean;
      }
    }
    return implode(';', $entries);
  }

  public static function updateScenes($id) {
    $eqLogic = reolink::byId($id, 'reolink');
    if (!is_object($eqLogic) || !$eqLogic->isNvrParent()) {
      return false;
    }
    $bc = reolinkBaichuan::fromEqLogic($eqLogic);
    if (!is_object($bc)) {
      return false;
    }
    $data = $bc->getScenes();
    if (!is_array($data)) {
      return false;
    }
    $setCmd = $eqLogic->getCmd(null, 'SetScene');
    if (is_object($setCmd)) {
      $listVal = self::formatSceneListValue($data['scenes'] ?? array());
      if (strval($setCmd->getConfiguration('listValue', '')) !== $listVal) {
        $setCmd->setConfiguration('listValue', $listVal);
        $setCmd->save();
      }
    }
    if (isset($data['activeSceneId'])) {
      $eqLogic->checkAndUpdateCmd('SetSceneState', intval($data['activeSceneId']));
    }
    return true;
  }

  public static function setScene($id, $sceneId) {
    $eqLogic = reolink::byId($id, 'reolink');
    if (!is_object($eqLogic) || !$eqLogic->isNvrParent()) {
      throw new Exception(__('Les scènes Reolink ne sont supportées que sur un équipement Home Hub / NVR parent', __FILE__));
    }
    $bc = reolinkBaichuan::fromEqLogic($eqLogic);
    if (!is_object($bc)) {
      throw new Exception(__('Connexion Baichuan impossible (IP/identifiants manquants)', __FILE__));
    }
    $active = $bc->setScene(intval($sceneId));
    $eqLogic->checkAndUpdateCmd('SetSceneState', intval($active));
    return $active;
  }

  public static function formatQuickReplyListValue($files) {
    if (!is_array($files) || empty($files)) {
      return '';
    }
    $pairs = array();
    foreach ($files as $entry) {
      if (!is_array($entry) || !isset($entry['id'])) {
        continue;
      }
      $fid = intval($entry['id']);
      $label = trim(str_replace(array(';', '|'), array(' ', '-'), strval($entry['fileName'] ?? '')));
      if ($label === '') {
        $label = 'Message ' . ($fid + 1);
      }
      $pairs[] = $fid . '|' . $label;
    }
    return implode(';', $pairs);
  }

  public static function updateQuickReplies($id) {
    $camera = reolink::byId($id, 'reolink');
    if (!is_object($camera) || $camera->isNvrParent()) {
      return false;
    }
    if ($camera->hasBattery() && $camera->isBatterySleeping()) {
      return false;
    }
    $cnx = reolink::getReolinkConnection($id);
    if (!is_object($cnx) || !$cnx->is_loggedin) {
      return false;
    }
    $channel = intval($camera->getChannel());
    $resp = $cnx->SendCMD('[{"cmd":"GetAudioFileList","action":0,"param":{"channel":' . $channel . '}}]');
    $files = $resp[0]['value']['AudioFileList'] ?? null;
    if (!is_array($files)) {
      $bc = reolinkBaichuan::fromEqLogic($camera);
      if (is_object($bc)) {
        try {
          $files = reolinkBaichuan::parseAudioFileListXml($bc->requestXml(347, $channel));
          $bc->logout();
        } catch (Exception $e) {
          $bc->logout();
          $files = array();
        }
      }
    }
    if (!is_array($files)) {
      return false;
    }
    $listVal = self::formatQuickReplyListValue($files);
    foreach (array('QuickReplyPlay', 'SetAutoReplyFile') as $lid) {
      $cmd = $camera->getCmd(null, $lid);
      if (is_object($cmd) && strval($cmd->getConfiguration('listValue', '')) !== $listVal) {
        $cmd->setConfiguration('listValue', $listVal);
        $cmd->save();
      }
    }
    return true;
  }

  public static function playQuickReply($id, $fileId) {
    $camera = reolink::byId($id, 'reolink');
    if (!is_object($camera) || $camera->isNvrParent()) {
      throw new Exception(__('Équipement Reolink invalide', __FILE__));
    }
    $cnx = reolink::getReolinkConnection($id);
    if (!is_object($cnx) || !$cnx->is_loggedin) {
      throw new Exception(__('Caméra injoignable ou connexion impossible', __FILE__));
    }
    $channel = intval($camera->getChannel());
    $payload = json_encode(array(array(
      'cmd' => 'QuickReplyPlay',
      'action' => 0,
      'param' => array('channel' => $channel, 'id' => intval($fileId)),
    )));
    $resp = $cnx->SendCMD($payload);
    if (!(is_array($resp) && intval($resp[0]['code'] ?? -1) === 0)) {
      $bc = reolinkBaichuan::fromEqLogic($camera);
      if (is_object($bc)) {
        try {
          $bc->requestXml(349, $channel, reolinkBaichuan::buildQuickReplyPlayXml($channel, intval($fileId)));
          $bc->logout();
          return true;
        } catch (Exception $e) {
          $bc->logout();
        }
      }
      throw new Exception(__('Échec diffusion de la réponse rapide', __FILE__));
    }
    return true;
  }

  public static function setAutoReplyField($id, $field, $val) {
    $camera = reolink::byId($id, 'reolink');
    if (!is_object($camera) || $camera->isNvrParent()) {
      throw new Exception(__('Équipement Reolink invalide', __FILE__));
    }
    $cnx = reolink::getReolinkConnection($id);
    if (!is_object($cnx) || !$cnx->is_loggedin) {
      throw new Exception(__('Caméra injoignable ou connexion impossible', __FILE__));
    }
    $channel = intval($camera->getChannel());
    $curResp = $cnx->SendCMD('[{"cmd":"GetAutoReply","action":0,"param":{"channel":' . $channel . '}}]');
    $cur = $curResp[0]['value']['AutoReply'] ?? array();
    $en = intval($cur['enable'] ?? 0);
    $fid = intval($cur['fileId'] ?? 0);
    $to = max(1, min(60, intval($cur['timeout'] ?? 10)));
    if ($field === 'enable') {
      $en = intval($val) ? 1 : 0;
    } elseif ($field === 'fileId') {
      $fid = intval($val);
    } elseif ($field === 'timeout') {
      $to = max(1, min(60, intval($val)));
    }
    $setPayload = json_encode(array(array(
      'cmd' => 'SetAutoReply',
      'action' => 0,
      'param' => array(
        'AutoReply' => array(
          'channel' => $channel,
          'enable' => $en,
          'fileId' => $fid,
          'timeout' => $to,
        ),
      ),
    )));
    $resp = $cnx->SendCMD($setPayload);
    if (!(is_array($resp) && intval($resp[0]['code'] ?? -1) === 0)) {
      throw new Exception(__('Échec configuration de la réponse automatique', __FILE__));
    }
    $camera->checkAndUpdateCmd('SetAutoReplyEnableState', $en);
    $camera->checkAndUpdateCmd('SetAutoReplyFileState', $fid);
    $camera->checkAndUpdateCmd('SetAutoReplyTimeoutState', $to);
    return true;
  }

  public static function setDoorbellAudioField($id, $field, $val) {
    $camera = reolink::byId($id, 'reolink');
    if (!is_object($camera) || $camera->isNvrParent()) {
      throw new Exception(__('Équipement Reolink invalide', __FILE__));
    }
    $channel = intval($camera->getChannel());
    $bc = reolinkBaichuan::fromEqLogic($camera);
    $ok = false;
    if (is_object($bc)) {
      $ok = $bc->setDoorbellAudioCfg($channel, array($field => intval($val)));
    }
    if (!$ok && $field === 'visitorLoudspeaker') {
      $cnx = reolink::getReolinkConnection($id);
      if (is_object($cnx) && $cnx->is_loggedin) {
        $resp = $cnx->SendCMD(json_encode(array(array(
          'cmd' => 'SetAudioCfg',
          'action' => 0,
          'param' => array('AudioCfg' => array('channel' => $channel, 'visitorLoudspeaker' => intval($val) ? 1 : 0)),
        ))));
        $ok = (is_array($resp) && intval($resp[0]['code'] ?? -1) === 0);
      }
    }
    if (!$ok) {
      throw new Exception(__('Échec réglage audio sonnette (port Baichuan 9000 requis)', __FILE__));
    }
    $stateMap = array(
      'talkAndReplyVolume' => 'SetTalkVolumeState',
      'visitorVolume' => 'SetVisitorVolumeState',
      'visitorLoudspeaker' => 'SetVisitorLoudspeakerState',
    );
    if (isset($stateMap[$field])) {
      $camera->checkAndUpdateCmd($stateMap[$field], intval($val));
    }
    return true;
  }

  public static function applyDoorbellLedRange($camera, $cnx, $channel) {
    if (!is_object($camera) || !is_object($cnx)) {
      return;
    }
    $cmd = $camera->getCmd(null, 'SetDoorbellLed');
    if (!is_object($cmd)) {
      return;
    }
    $labels = array(
      'KeepOff' => __('Toujours éteinte', __FILE__),
      'Off' => __('Automatique (sonnerie)', __FILE__),
      'On' => __('Automatique et nuit', __FILE__),
      'KeepOn' => __('Toujours allumée', __FILE__),
      'Always' => __('Toujours allumée', __FILE__),
    );
    $resp = $cnx->SendCMD('[{"cmd":"GetPowerLed","action":1,"param":{"channel":' . intval($channel) . '}}]');
    $opts = $resp[0]['range']['PowerLed']['eDoorbellLightState'] ?? null;
    if (!is_array($opts) || empty($opts)) {
      $opts = array('KeepOff', 'Off', 'On', 'KeepOn');
    }
    $pairs = array();
    foreach ($opts as $optKey) {
      $k = trim(strval($optKey));
      if ($k !== '' && isset($labels[$k])) {
        $pairs[] = $k . '|' . $labels[$k];
      }
    }
    if (!empty($pairs)) {
      $listVal = implode(';', $pairs);
      if (strval($cmd->getConfiguration('listValue', '')) !== $listVal) {
        $cmd->setConfiguration('listValue', $listVal);
        $cmd->save();
      }
    }
  }

  public static function resolveChimeId($eqLogic, $bc = null) {
    $saved = intval($eqLogic->getConfiguration('chime_id', -1));
    if ($saved >= 0) {
      return $saved;
    }
    $chOrNull = $eqLogic->isNvrParent() ? null : intval($eqLogic->getChannel());
    if (!is_object($bc)) {
      $bc = reolinkBaichuan::fromEqLogic($eqLogic);
    }
    if (is_object($bc)) {
      $st = $bc->getChimeStatus($chOrNull);
      if (is_array($st) && isset($st['id'])) {
        $eqLogic->setConfiguration('chime_id', intval($st['id']));
        $eqLogic->save(true);
        return intval($st['id']);
      }
    }
    $cnx = reolink::getReolinkConnection($eqLogic->getId());
    if (is_object($cnx) && $cnx->is_loggedin) {
      $param = ($chOrNull === null) ? new stdClass() : array('channel' => $chOrNull);
      $resp = $cnx->SendCMD(json_encode(array(array('cmd' => 'GetDingDongList', 'action' => 0, 'param' => $param))));
      $paired = $resp[0]['value']['DingDongList']['pairedlist'] ?? array();
      if (is_array($paired)) {
        foreach ($paired as $item) {
          if (is_array($item) && strcasecmp(strval($item['deviceType'] ?? ''), 'BatteryDB') !== 0 && isset($item['deviceId'])) {
            $cid = intval($item['deviceId']);
            $eqLogic->setConfiguration('chime_id', $cid);
            $eqLogic->save(true);
            return $cid;
          }
        }
      }
    }
    return null;
  }

  public static function refreshDoorbellAndChimeState($camera) {
    if (!is_object($camera) || ($camera->hasBattery() && $camera->isBatterySleeping())) {
      return;
    }
    $hasDbAudio = is_object($camera->getCmd(null, 'SetTalkVolumeState'))
      || is_object($camera->getCmd(null, 'SetVisitorVolumeState'))
      || is_object($camera->getCmd(null, 'SetVisitorLoudspeakerState'));
    $hasChime = is_object($camera->getCmd(null, 'ChimeOnlineState'))
      || is_object($camera->getCmd(null, 'RingChime'));
    if (!$hasDbAudio && !$hasChime) {
      return;
    }
    $bc = reolinkBaichuan::fromEqLogic($camera);
    if ($hasDbAudio && is_object($bc) && !$camera->isNvrParent()) {
      $ac = $bc->getDoorbellAudioCfg($camera->getChannel());
      if (is_array($ac)) {
        if (isset($ac['talkAndReplyVolume'])) {
          $camera->checkAndUpdateCmd('SetTalkVolumeState', intval($ac['talkAndReplyVolume']));
        }
        if (isset($ac['visitorVolume'])) {
          $camera->checkAndUpdateCmd('SetVisitorVolumeState', intval($ac['visitorVolume']));
        }
        if (isset($ac['visitorLoudspeaker'])) {
          $camera->checkAndUpdateCmd('SetVisitorLoudspeakerState', intval($ac['visitorLoudspeaker']) ? 1 : 0);
        }
      }
    }
    if ($hasChime) {
      $chOrNull = $camera->isNvrParent() ? null : intval($camera->getChannel());
      $st = is_object($bc) ? $bc->getChimeStatus($chOrNull) : null;
      if (is_array($st) && isset($st['id'])) {
        if (intval($camera->getConfiguration('chime_id', -1)) !== intval($st['id'])) {
          $camera->setConfiguration('chime_id', intval($st['id']));
          $camera->save(true);
        }
        $camera->checkAndUpdateCmd('ChimeOnlineState', intval($st['online'] ?? 0));
        if (isset($st['volLevel'])) {
          $camera->checkAndUpdateCmd('SetChimeVolumeState', intval($st['volLevel']));
        }
        if (isset($st['ledState'])) {
          $camera->checkAndUpdateCmd('SetChimeLedState', intval($st['ledState']) ? 1 : 0);
        }
        if (isset($st['visitorTone'])) {
          $camera->checkAndUpdateCmd('SetChimeVisitorToneState', intval($st['visitorTone']));
        }
        if (isset($st['silentMinutes'])) {
          $camera->checkAndUpdateCmd('SetChimeSilentState', intval($st['silentMinutes']));
        }
      }
    }
  }

  public static function execChimeAction($id, $op, $val) {
    $eqLogic = reolink::byId($id, 'reolink');
    if (!is_object($eqLogic)) {
      throw new Exception(__('Équipement Reolink invalide', __FILE__));
    }
    $bc = reolinkBaichuan::fromEqLogic($eqLogic);
    $chimeId = self::resolveChimeId($eqLogic, $bc);
    if ($chimeId === null) {
      throw new Exception(__('Aucun carillon Reolink Chime appairé détecté', __FILE__));
    }
    $chOrNull = $eqLogic->isNvrParent() ? null : intval($eqLogic->getChannel());
    if ($op === 'ring') {
      $music = max(0, min(9, intval($val)));
      if (is_object($bc)) {
        try {
          $bc->ringChime($chOrNull, $chimeId, $music);
          return true;
        } catch (Exception $e) {
          log::add('reolink', 'debug', 'Chime ring fallback CGI: ' . $e->getMessage());
        }
      }
      $cnx = reolink::getReolinkConnection($id);
      if (!is_object($cnx) || !$cnx->is_loggedin) {
        throw new Exception(__('Équipement injoignable pour faire sonner le carillon', __FILE__));
      }
      $dd = array('id' => $chimeId, 'option' => 4, 'musicId' => $music);
      if ($chOrNull !== null) {
        $dd['channel'] = $chOrNull;
      }
      $resp = $cnx->SendCMD(json_encode(array(array('cmd' => 'DingDongOpt', 'action' => 0, 'param' => array('DingDong' => $dd)))));
      if (!(is_array($resp) && intval($resp[0]['code'] ?? -1) === 0)) {
        throw new Exception(__('Échec déclenchement du carillon', __FILE__));
      }
      return true;
    }
    if (!is_object($bc)) {
      throw new Exception(__('Connexion Baichuan requise pour piloter le carillon', __FILE__));
    }
    if ($op === 'volume') {
      $v = max(0, min(4, intval($val)));
      $bc->setChimeParam($chOrNull, $chimeId, array('volLevel' => $v));
      $eqLogic->checkAndUpdateCmd('SetChimeVolumeState', $v);
    } elseif ($op === 'led') {
      $l = intval($val) ? 1 : 0;
      $bc->setChimeParam($chOrNull, $chimeId, array('ledState' => $l));
      $eqLogic->checkAndUpdateCmd('SetChimeLedState', $l);
    } elseif ($op === 'visitorTone') {
      $m = intval($val);
      $bc->setChimeVisitorTone($chOrNull, $chimeId, $m);
      $eqLogic->checkAndUpdateCmd('SetChimeVisitorToneState', ($m < 0) ? -1 : max(0, min(9, $m)));
    } elseif ($op === 'silent') {
      $min = max(0, min(720, intval($val)));
      $bc->setChimeSilent($chOrNull, $chimeId, $min);
      $eqLogic->checkAndUpdateCmd('SetChimeSilentState', $min);
    }
    return true;
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
    $cnxType = strtolower(trim(strval($camera->getConfiguration('cnxtype', ''))));
    if (!$camera->hasBattery() || !$camera->isBatterySleeping()) {
      $needNfo = ($cnxType !== 'baichuan') && (
        $camera->getConfiguration('rtspSubUrl') == ''
        || $camera->getConfiguration('rtspMainUrl') == ''
        || (!$camera->hasBattery() && $camera->getConfiguration('hasExtStream', '') === '')
      );
      if ($needNfo) {
        reolink::GetCamNFO($id);
        $camera = reolink::byId($id, 'reolink');
      }
      if ($camera->getConfiguration('hasAudioTalk', '') === '' || $camera->getConfiguration('hasAutoTrackStream', '') === '') {
        reolink::GetCamAbility($id);
        $camera = reolink::byId($id, 'reolink');
      }
    }
    if ($cnxType === 'baichuan') {
      self::syncTalkRoutesToDaemon($camera, true);
      throw new Exception(__('Flux vidéo RTSP/Live indisponible en mode Baichuan seul (port 9000 uniquement). Utilisez le flux JPEG (cmd 109).', __FILE__));
    }

    $available = $camera->getAvailableStreams();
    $webrtcEnabled = self::isWebrtcEnabled();
    $hasTalk = (intval($camera->getConfiguration('hasAudioTalk', 0)) === 1);
    $talkUrl = '';
    if ($hasTalk) {
      self::syncTalkRoutesToDaemon($camera, true);
      if ($webrtcEnabled) {
        $talkUrl = $camera->getLocalTalkRtspUrl(true);
      }
    }

    $apiPort = self::getGo2rtcApiPort();
    $registered = array();
    foreach ($available as $key => $meta) {
      if (($meta['type'] ?? '') !== 'go2rtc' || empty($meta['url'])) {
        continue;
      }
      $name = 'reolink_' . intval($id) . '_' . $key;
      $sources = array($meta['url']);
      if ($webrtcEnabled) {
        $sources[] = 'ffmpeg:' . $name . '#audio=opus';
        if ($talkUrl !== '') {
          $sources[] = $talkUrl;
        }
      }
      $query = 'name=' . rawurlencode($name);
      foreach ($sources as $src) {
        $query .= '&src=' . rawurlencode($src);
      }
      // PUT registers multi-source streams (video + Opus WebRTC audio + Baichuan talk backchannel)
      $url = 'http://127.0.0.1:' . $apiPort . '/api/streams?' . $query;
      $ch = curl_init($url);
      curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
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
          throw new Exception(__('Service go2rtc injoignable', __FILE__) . ' (127.0.0.1:' . $apiPort . ') : ' . __('vérifiez que les dépendances sont à jour et que le démon Reolink est démarré', __FILE__));
        }
        throw new Exception(__('Échec enregistrement flux go2rtc', __FILE__) . ' (' . $name . ' HTTP ' . $code . ')');
      }
      $registered[] = $name;
    }
    if (empty($registered)) {
      throw new Exception(__('Aucune URL de flux vidéo disponible pour cette caméra', __FILE__));
    }
    return $registered;
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
    if (!is_object($reolinkConn) || !$reolinkConn->is_loggedin) {
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
      if (is_array($patrol) && !empty($patrol[0]['value']['PtzPatrol']) && is_array($patrol[0]['value']['PtzPatrol'])) {
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

    // Doorbell visitor event ability (Doorbell WiFi / PoE / Battery)
    $modelStr = strval($camera->getConfiguration('model', ''));
    $isDoorbell = (stripos($modelStr, 'Doorbell') !== false)
      || (intval($deviceAbility['doorbellVersion']['ver'] ?? 0) > 0)
      || (intval($deviceAbility['visitor']['ver'] ?? 0) > 0);
    $deviceAbility['doorbell'] = array('permit' => $isDoorbell ? 4 : 0, 'ver' => $isDoorbell ? 1 : 0);

    // Doorbell & wireless Chime (DingDong) capabilities (never wake sleeping battery cameras)
    $canProbeActive = (!$hasBat || !$camera->isBatterySleeping());
    $hasQuickReply = (intval($deviceAbility['supportAudioFileList']['ver'] ?? 0) > 0)
      || ($isDoorbell && intval($deviceAbility['supportQuickReply']['ver'] ?? 0) > 0);
    if (!$hasQuickReply && $isDoorbell && $canProbeActive) {
      $afResp = $reolinkConn->SendCMD('[{"cmd":"GetAudioFileList","action":0,"param":{"channel":' . intval($channel) . '}}]');
      $hasQuickReply = (is_array($afResp) && isset($afResp[0]['value']['AudioFileList']) && is_array($afResp[0]['value']['AudioFileList']));
    }
    $deviceAbility['supportQuickReply'] = array('permit' => $hasQuickReply ? 6 : 0, 'ver' => $hasQuickReply ? 1 : 0);

    $hasAutoReply = (intval($deviceAbility['supportAutoReply']['ver'] ?? 0) > 0);
    if (!$hasAutoReply && $isDoorbell && $canProbeActive) {
      $arResp = $reolinkConn->SendCMD('[{"cmd":"GetAutoReply","action":0,"param":{"channel":' . intval($channel) . '}}]');
      $hasAutoReply = (is_array($arResp) && isset($arResp[0]['value']['AutoReply']) && is_array($arResp[0]['value']['AutoReply']));
    }
    $deviceAbility['supportAutoReply'] = array('permit' => $hasAutoReply ? 6 : 0, 'ver' => $hasAutoReply ? 1 : 0);

    $hasDbLight = (intval($deviceAbility['doorbellLightState']['ver'] ?? 0) > 0);
    if (!$hasDbLight && $isDoorbell) {
      $plResp = $reolinkConn->SendCMD('[{"cmd":"GetPowerLed","action":1,"param":{"channel":' . intval($channel) . '}}]');
      $hasDbLight = (is_array($plResp) && !empty($plResp[0]['range']['PowerLed']['eDoorbellLightState']));
    }
    $deviceAbility['supportDoorbellLight'] = array('permit' => $hasDbLight ? 6 : 0, 'ver' => $hasDbLight ? 1 : 0);

    $hasDbAudio = false;
    if ($isDoorbell && $canProbeActive && is_object($bc)) {
      $dbAc = $bc->getDoorbellAudioCfg($channel);
      $hasDbAudio = is_array($dbAc) && (
        array_key_exists('talkAndReplyVolume', $dbAc)
        || array_key_exists('visitorVolume', $dbAc)
        || array_key_exists('visitorLoudspeaker', $dbAc)
      );
    }
    $deviceAbility['supportDoorbellAudio'] = array('permit' => $hasDbAudio ? 6 : 0, 'ver' => $hasDbAudio ? 1 : 0);

    $hasPairedChime = false;
    $chimeHint = ($isDoorbell || intval($deviceAbility['supportDingDong']['ver'] ?? 0) > 0 || intval($deviceAbility['dingdong']['ver'] ?? 0) > 0);
    if ($chimeHint && $canProbeActive) {
      if (is_object($bc)) {
        $chSt = $bc->getChimeStatus($channel);
        if (is_array($chSt) && !empty($chSt['chimes'])) {
          $hasPairedChime = true;
          $camera->setConfiguration('chime_id', intval($chSt['id']));
        }
      }
      if (!$hasPairedChime) {
        $ddResp = $reolinkConn->SendCMD('[{"cmd":"GetDingDongList","action":0,"param":{"channel":' . intval($channel) . '}}]');
        $paired = $ddResp[0]['value']['DingDongList']['pairedlist'] ?? array();
        if (is_array($paired)) {
          foreach ($paired as $ddItem) {
            if (is_array($ddItem) && strcasecmp(strval($ddItem['deviceType'] ?? ''), 'BatteryDB') !== 0 && isset($ddItem['deviceId'])) {
              $hasPairedChime = true;
              $camera->setConfiguration('chime_id', intval($ddItem['deviceId']));
              break;
            }
          }
        }
      }
    }
    $deviceAbility['supportChime'] = array('permit' => $hasPairedChime ? 6 : 0, 'ver' => $hasPairedChime ? 1 : 0);

    // Stream & two-way audio capabilities persisted for Live / go2rtc / JeedomConnect
    $hasTalk = (intval($deviceAbility['talk']['ver'] ?? 0) > 0) ? 1 : 0;
    $hasAutoTrack = (intval($deviceAbility['supportAutoTrackStream']['ver'] ?? 0) > 0) ? 1 : 0;
    $dirtyCfg = false;
    if (intval($camera->getConfiguration('hasAudioTalk', -1)) !== $hasTalk) {
      $camera->setConfiguration('hasAudioTalk', $hasTalk);
      $dirtyCfg = true;
    }
    if (intval($camera->getConfiguration('hasAutoTrackStream', -1)) !== $hasAutoTrack) {
      $camera->setConfiguration('hasAutoTrackStream', $hasAutoTrack);
      $dirtyCfg = true;
    }
    if (!$hasBat && $camera->getConfiguration('hasExtStream', '') === '') {
      $encResp = $reolinkConn->SendCMD('[{"cmd":"GetEnc","action":0,"param":{"channel":' . intval($channel) . '}}]');
      if (is_array($encResp) && isset($encResp[0]['value']['Enc']) && is_array($encResp[0]['value']['Enc'])) {
        $hasExt = (isset($encResp[0]['value']['Enc']['extStream']) && is_array($encResp[0]['value']['Enc']['extStream'])) ? 1 : 0;
        $camera->setConfiguration('hasExtStream', $hasExt);
        $dirtyCfg = true;
      }
    }
    if ($hasTalk === 1 && trim(strval($camera->getConfiguration('talk_token', ''))) === '') {
      $camera->setConfiguration('talk_token', bin2hex(random_bytes(12)));
      $dirtyCfg = true;
    }
    if ($dirtyCfg) {
      $camera->save(true);
      if ($hasTalk === 1) {
        self::syncTalkRoutesToDaemon($camera, true);
      }
    }

    // Legacy firmwares without explicit recPreRecord / ispBackLight keys: defer to GetRec / GetIsp probes
    if (!isset($deviceAbility['recPreRecord']) && isset($deviceAbility['sdCard'])) {
      $deviceAbility['recPreRecord'] = $deviceAbility['sdCard'];
    }
    if (!isset($deviceAbility['ispBackLight']) && isset($deviceAbility['isp'])) {
      $deviceAbility['ispBackLight'] = $deviceAbility['isp'];
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

  public static function savePtzPreset($id, $presetId, $name, $enable = 1) {
    $camera = reolink::byId($id, 'reolink');
    if (!is_object($camera) || $camera->isNvrParent()) {
      throw new Exception(__('Équipement caméra invalide', __FILE__));
    }
    $camcnx = reolink::getReolinkConnection($id);
    if (!is_object($camcnx) || !$camcnx->is_loggedin) {
      throw new Exception(__('Caméra injoignable ou connexion impossible', __FILE__));
    }
    $ch = $camera->getChannel();
    $pid = max(0, min(63, intval($presetId)));
    $cleanName = substr(trim(strval($name)), 0, 31);
    if ($cleanName === '') {
      $cleanName = 'Preset ' . $pid;
    }
    $en = intval($enable) ? 1 : 0;
    $payload = json_encode(array(array(
      'cmd' => 'SetPtzPreset',
      'action' => 0,
      'param' => array('PtzPreset' => array(
        'channel' => $ch,
        'enable' => $en,
        'id' => $pid,
        'name' => $cleanName,
      )),
    )));
    $resp = $camcnx->SendCMD($payload);
    if (!is_array($resp) || intval($resp[0]['code'] ?? -1) !== 0) {
      throw new Exception(__('Échec d\'enregistrement du preset PTZ', __FILE__));
    }
    $data = $camcnx->SendCMD('[{"cmd":"GetPtzPreset","action":1,"param":{"channel":' . $ch . '}}]');
    if (is_array($data) && isset($data[0])) {
      self::updatePTZpreset($id, $data[0]);
    }
    $camera = reolink::byId($id, 'reolink');
    return self::parseCmdSelectList(is_object($camera) ? $camera->getCmd(null, 'SetPtzByPreset') : null);
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
        $name = __('Ronde', __FILE__) . ' ' . ($id + 1);
      }
      $list .= $id . '|' . $name . ';';
    }
    $list = rtrim($list, ';');
    if ($list === '') {
      $list = '0|' . __('Ronde', __FILE__) . ' 1';
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
    if (!is_object($camera)) {
      throw new Exception(__('Caméra introuvable', __FILE__));
    }
    if ($camera->isNvrParent()) {
      throw new Exception(__('Les rondes PTZ ne s\'appliquent pas à un NVR parent', __FILE__));
    }
    $cnx = reolink::getReolinkConnection($id);
    if (!is_object($cnx) || !$cnx->is_loggedin) {
      throw new Exception(__('Caméra injoignable ou connexion impossible', __FILE__));
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
    if (!is_object($camera)) {
      throw new Exception(__('Caméra introuvable', __FILE__));
    }
    if ($camera->isNvrParent()) {
      throw new Exception(__('Les rondes PTZ ne s\'appliquent pas à un NVR parent', __FILE__));
    }
    $cnx = reolink::getReolinkConnection($id);
    if (!is_object($cnx) || !$cnx->is_loggedin) {
      throw new Exception(__('Caméra injoignable ou connexion impossible', __FILE__));
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
    if (!is_object($camera)) {
      throw new Exception(__('Caméra introuvable', __FILE__));
    }
    if ($camera->isNvrParent()) {
      throw new Exception(__('Les rondes PTZ ne s\'appliquent pas à un NVR parent', __FILE__));
    }
    $cnx = reolink::getReolinkConnection($id);
    if (!is_object($cnx) || !$cnx->is_loggedin) {
      throw new Exception(__('Caméra injoignable ou connexion impossible', __FILE__));
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
      $privIp = trim(strval($camcmd->getConfiguration('adresseip', '')));
      $privPort = reolinkBaichuan::resolvePort($camcmd);
      if ($privIp !== '' && !self::isHostInCooldown('baichuan', $privIp, $privPort)) {
        $bc = reolinkBaichuan::fromEqLogic($camcmd);
        if (is_object($bc)) {
          $privacy = $bc->getPrivacyMode($camcmd->getChannel());
          if ($privacy !== null) {
            self::recordHostSuccess('baichuan', $privIp, $privPort);
            $camcmd->checkAndUpdateCmd('SetPrivacyModeState', $privacy ? 1 : 0);
            if ($privacy && $camcmd->isStandaloneCamera()) {
              log::add('reolink', 'debug', 'Caméra en mode confidentialité, rafraîchissement HTTP ignoré');
              return;
            }
          } elseif (!$bc->isLoggedIn()) {
            self::recordHostFailure('baichuan', $privIp, $privPort, 'Baichuan privacy probe timeout');
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
      if (!$camcmd->isNvrParent()) {
        $camcmd->checkAndUpdateCmd('ChannelOnline', 0);
      }
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

    if ($camcmd->isNvrParent() && is_object($camcmd->getCmd(null, 'SetSceneState'))) {
      $bcScene = reolinkBaichuan::fromEqLogic($camcmd);
      if (is_object($bcScene)) {
        $curScene = $bcScene->getActiveSceneId();
        if ($curScene !== null) {
          $camcmd->checkAndUpdateCmd('SetSceneState', intval($curScene));
        }
      }
    }
    if (!$isBatteryCam || $shouldWakeBattery) {
      self::refreshDoorbellAndChimeState($camcmd);
    }

    if (empty($cmdarr)) {
      $camcnx->batteryChannelMode = false;
      return;
    }
    $cmd_block = array_chunk($cmdarr, config::byKey('cmdblock', __CLASS__, CMD_SEND_QTY));
    $anyAlive = false;
    $anyMdSeen = false;
    $channelStatusSeen = false;

    foreach ($cmd_block as $key => &$value) {
      if (!$camcnx->is_loggedin || (method_exists($camcnx, 'hasTransportFailed') && $camcnx->hasTransportFailed())) {
        log::add('reolink', 'debug', 'refreshNFO interrompu au bloc ' . ($key + 1) . '/' . count($cmd_block) . ' : hôte déconnecté');
        break;
      }
      $cmdget = "";
      foreach ($value as &$value2) {
        $cmdget .= $value2 . ",";
      }
      $cmdget = substr($cmdget, 0, -1);
      log::add('reolink', 'debug', 'Envoi ' . ($key + 1) . '/' . count($cmd_block) . ' payload multiple GetSetting = ' . $cmdget);
      $res = $camcnx->SendCMD("[$cmdget]");
      if (method_exists($camcnx, 'hasTransportFailed') && $camcnx->hasTransportFailed()) {
        break;
      }
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
      if ($channelAlive) {
        $anyAlive = true;
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
            $camcmd->checkAndUpdateCmd('SetRecordStateV20', reolink::resolveV20SwitchState($camcmd, $v));
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
            $camcmd->checkAndUpdateCmd('SetFTPStateV20', reolink::resolveV20SwitchState($camcmd, $v));
            break;

          case reolinkAPI::CAM_GET_PUSH:
            $camcmd->checkAndUpdateCmd('SetPushState', $json_data['value']['Push']['schedule']['enable']);
            break;

          case reolinkAPI::CAM_GET_PUSHV20:
            $v = $json_data['value']['Push'];
            $camcmd->checkAndUpdateCmd('SetPushStateV20', reolink::resolveV20SwitchState($camcmd, $v));
            break;

          case reolinkAPI::CAM_GET_PUSHCFG:
            $camcmd->checkAndUpdateCmd('SetPushCfgState', $json_data['value']['PushCfg']['pushInterval']);
            break;

          case reolinkAPI::CAM_GET_EMAIL:
            $camcmd->checkAndUpdateCmd('SetEmailState', $json_data['value']['Email']['schedule']['enable']);
            break;

          case reolinkAPI::CAM_GET_EMAILV20:
            $v = $json_data['value']['Email'];
            $camcmd->checkAndUpdateCmd('SetEmailStateV20', reolink::resolveV20SwitchState($camcmd, $v));
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
              $vType1 = $enc['mainStream']['vType'] ?? null;
              if ($vType1 === null || $vType1 === '') {
                $vType1 = 'h264';
              }
              $camcmd->checkAndUpdateCmd('SetEncodingst1State', $vType1);
            }
            if (isset($enc['subStream'])) {
              $camcmd->checkAndUpdateCmd('SetResolutionst2State', $enc['subStream']['size']);
              $camcmd->checkAndUpdateCmd('SetFPSst2State', $enc['subStream']['frameRate']);
              $camcmd->checkAndUpdateCmd('SetBitratest2State', $enc['subStream']['bitRate']);
              $vType2 = $enc['subStream']['vType'] ?? null;
              if ($vType2 === null || $vType2 === '') {
                $vType2 = 'h264';
              }
              $camcmd->checkAndUpdateCmd('SetEncodingst2State', $vType2);
            }
            // Auto-fill dynamic SetEnc lists when missing (existing equipments)
            $needEncLists = false;
            foreach (array('SetResolutionst1', 'SetFPSst1', 'SetBitratest1') as $encLid) {
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
            if (array_key_exists('scheduleEnable', $buzzer) || array_key_exists('enable', $buzzer)) {
              $camcmd->checkAndUpdateCmd('SetBuzzerStateV20', reolink::resolveV20SwitchState($camcmd, $buzzer));
            }
            if (array_key_exists('enable', $buzzer)) {
              $camcmd->checkAndUpdateCmd('SetNvrBuzzerState', $buzzer['enable']);
            }
            break;

          case reolinkAPI::CAM_GET_MANUALREC:
            $camcmd->checkAndUpdateCmd('SetManualRecordState', $json_data['value']['Rec']['enable'] ?? 0);
            break;

          case reolinkAPI::CAM_CHECKFIRMWARE:
            $cgiNewFw = intval($json_data['value']['newFirmware'] ?? 0);
            if (self::isGithubFirmwareCheckEnabled()) {
              try {
                $ghFw = self::checkEquipmentFirmwareArchive($camcmd, null, true);
                if (!empty($ghFw['updateAvailable'])) {
                  $cgiNewFw = 1;
                }
              } catch (Exception $e) {
                log::add('reolink', 'debug', 'CheckFirmware GitHub fallback ignoré : ' . $e->getMessage());
              }
            }
            $camcmd->checkAndUpdateCmd('NewFirmwareState', $cgiNewFw);
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
            $plData = $json_data['value']['PowerLed'] ?? array();
            if (isset($plData['state'])) {
              $camcmd->checkAndUpdateCmd('SetPowerLedState', $plData['state']);
            }
            if (isset($plData['eDoorbellLightState'])) {
              $camcmd->checkAndUpdateCmd('SetDoorbellLedState', $plData['eDoorbellLightState']);
            }
            break;

          case reolinkAPI::CAM_GET_AUTOREPLY:
            $arData = $json_data['value']['AutoReply'] ?? array();
            if (isset($arData['enable'])) {
              $camcmd->checkAndUpdateCmd('SetAutoReplyEnableState', intval($arData['enable']) ? 1 : 0);
            }
            if (isset($arData['fileId'])) {
              $camcmd->checkAndUpdateCmd('SetAutoReplyFileState', intval($arData['fileId']));
            }
            if (isset($arData['timeout'])) {
              $camcmd->checkAndUpdateCmd('SetAutoReplyTimeoutState', intval($arData['timeout']));
            }
            break;

          case reolinkAPI::CAM_GET_ABILITY:
            break;

          case reolinkAPI::CAM_GET_AUTOFOCUS:
            $camcmd->checkAndUpdateCmd('SetAutoFocusState', $json_data['value']['AutoFocus']['disable']);
            break;

          case reolinkAPI::CAM_GET_MASK:
            $camcmd->checkAndUpdateCmd('SetMaskState', self::extractMaskState($json_data['value']['Mask'] ?? null));
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
                  $channelStatusSeen = true;
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
        $anyMdSeen = true;
        $camcmd->checkAndUpdateCmd('ChannelOnline', $mdOk ? 1 : 0);
      }
    } #END foreach

    if (!$camcmd->isNvrParent() && !$anyMdSeen && !$channelStatusSeen) {
      $camcmd->checkAndUpdateCmd('ChannelOnline', $anyAlive ? 1 : 0);
    }

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
    $lockPath = jeedom::getTmpFolder('reolink') . '/cron.lock';
    $lockFp = @fopen($lockPath, 'c+');
    if ($lockFp && !@flock($lockFp, LOCK_EX | LOCK_NB)) {
      fclose($lockFp);
      log::add('reolink', 'debug', 'reolink::cron ignoré : exécution précédente encore en cours');
      return;
    }
    $startedAt = microtime(true);
    try {
      $eqLogics = eqLogic::byType('reolink', true);
      /** @var reolink */
      foreach ($eqLogics as $camera) {
        if ((microtime(true) - $startedAt) > 45.0) {
          log::add('reolink', 'warning', 'reolink::cron : budget temps de 45s atteint, report des caméras restantes au prochain cycle');
          break;
        }
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
    } finally {
      if ($lockFp) {
        @flock($lockFp, LOCK_UN);
        fclose($lockFp);
      }
    }
  }


  // Leading underscore required: Jeedom utils::o2a() / DB::buildField() persist any non-_ property into eqLogic SQL
  private static $_lastBaichuanSyncHash = null;
  private static $_lastTalkSyncHash = null;

  public static function syncTalkRoutesToDaemon($targetCamera = null, $force = false) {
    $deamon_info = self::deamon_info();
    if (($deamon_info['state'] ?? '') !== 'ok') {
      return;
    }
    $targets = is_object($targetCamera) ? array($targetCamera) : eqLogic::byType('reolink', true);
    $routes = array();
    foreach ($targets as $camera) {
      if (!is_object($camera) || $camera->isNvrParent() || intval($camera->getIsEnable()) !== 1) {
        continue;
      }
      if (is_object($targetCamera) || intval($camera->getConfiguration('hasAudioTalk', 0)) === 1) {
        $contact = trim(strval($camera->getConfiguration('adresseip', '')));
        if ($contact === '') {
          continue;
        }
        $ip = filter_var($contact, FILTER_VALIDATE_IP) ? $contact : gethostbyname($contact);
        $routes[] = array(
          'eq_id' => intval($camera->getId()),
          'ip' => $ip,
          'port' => reolinkBaichuan::resolvePort($camera),
          'channel' => intval($camera->getChannel()),
          'user' => strval($camera->getConfiguration('login', 'admin')),
          'pwd' => strval($camera->getConfiguration('password', '')),
          'token' => $camera->getOrCreateTalkToken(),
        );
      }
    }
    if (empty($routes)) {
      return;
    }
    if (!is_object($targetCamera)) {
      $hash = md5(json_encode($routes));
      if (!$force && self::$_lastTalkSyncHash === $hash) {
        return;
      }
      self::$_lastTalkSyncHash = $hash;
    }
    reolink::sendToDaemon(array(
      'action' => 'preparetalk',
      'routes' => $routes,
    ));
  }

  public static function syncBaichuanDaemonSubscriptions($force = false) {
    self::syncTalkRoutesToDaemon(null, $force);
    $deamon_info = self::deamon_info();
    if (($deamon_info['state'] ?? '') !== 'ok') {
      return;
    }
    $cameras = array();
    $seen = array();
    $eqLogics = eqLogic::byType('reolink', true);
    usort($eqLogics, function ($a, $b) {
      return intval($a->isNvrParent()) - intval($b->isNvrParent());
    });
    foreach ($eqLogics as $camera) {
      if (!$camera->usesBaichuanPush()) {
        continue;
      }
      $contact = trim(strval($camera->getConfiguration('adresseip', '')));
      if ($contact === '') {
        continue;
      }
      $ip = filter_var($contact, FILTER_VALIDATE_IP) ? $contact : gethostbyname($contact);
      $port = reolinkBaichuan::resolvePort($camera);
      $key = $ip . ':' . $port;
      if (isset($seen[$key])) {
        continue;
      }
      $seen[$key] = true;
      $cameras[] = array(
        'ip' => $ip,
        'port' => $port,
        'user' => strval($camera->getConfiguration('login', 'admin')),
        'pwd' => strval($camera->getConfiguration('password', '')),
      );
    }
    $hash = md5(json_encode($cameras));
    if (!$force && self::$_lastBaichuanSyncHash === $hash) {
      return;
    }
    self::$_lastBaichuanSyncHash = $hash;
    log::add('reolink', 'debug', 'Synchronisation démon Baichuan push (' . count($cameras) . ' caméra(s))');
    reolink::sendToDaemon(array(
      'action' => 'syncbaichuan',
      'cameras' => $cameras,
    ));
  }

  // Fonction exécutée automatiquement toutes les 10 minutes par Jeedom
  public static function cron10() {
    self::syncBaichuanDaemonSubscriptions(true);
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
      if ($camera->usesBaichuanPush() || $camera->isNvrParent()) {
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

  public static function isGithubFirmwareCheckEnabled() {
    return intval(config::byKey('check_fw_github', 'reolink', 1)) === 1;
  }

  public static function ensureGithubFirmwareCommands($camera) {
    if (!is_object($camera) || !($camera instanceof reolink) || intval($camera->getId()) <= 0) {
      return;
    }
    $isBaichuanOnly = (strtolower(trim(strval($camera->getConfiguration('cnxtype', '')))) === 'baichuan');
    $isChannel = $camera->isChannelChild();

    $newFwCmd = $camera->getCmd(null, 'NewFirmwareState');
    if (!is_object($newFwCmd)) {
      $newFwCmd = new reolinkCmd();
      $newFwCmd->setEqLogic_id($camera->getId());
      $newFwCmd->setLogicalId('NewFirmwareState');
      $newFwCmd->setName(__('Nouveau firmware (état)', __FILE__));
      $newFwCmd->setType('info');
      $newFwCmd->setSubType('binary');
      $newFwCmd->setIsHistorized(0);
      $newFwCmd->setIsVisible(($isChannel || $isBaichuanOnly) ? 0 : 1);
      $newFwCmd->setConfiguration('help', __('Indique si une mise à jour firmware officielle Reolink est disponible (0 = À jour, 1 = Mise à jour disponible).', __FILE__));
      $newFwCmd->setConfiguration('valuesHelp', __('0 = À jour, 1 = Mise à jour disponible', __FILE__));
      if (!$isChannel && !$isBaichuanOnly) {
        $newFwCmd->setConfiguration('payload', '{"cmd":"CheckFirmware"}');
      }
      $newFwCmd->save();
    }

    $latestFwCmd = $camera->getCmd(null, 'LatestFirmware');
    if (!is_object($latestFwCmd)) {
      $latestFwCmd = new reolinkCmd();
      $latestFwCmd->setEqLogic_id($camera->getId());
      $latestFwCmd->setLogicalId('LatestFirmware');
      $latestFwCmd->setName(__('Dernier firmware disponible', __FILE__));
      $latestFwCmd->setType('info');
      $latestFwCmd->setSubType('string');
      $latestFwCmd->setIsHistorized(0);
      $latestFwCmd->setIsVisible(0);
      $latestFwCmd->setConfiguration('help', __('Dernière version de firmware officielle référencée pour ce modèle et cette révision matérielle.', __FILE__));
      $latestFwCmd->save();
    }

    $checkCmd = $camera->getCmd(null, 'CheckFirmware');
    if (!is_object($checkCmd)) {
      $checkCmd = new reolinkCmd();
      $checkCmd->setEqLogic_id($camera->getId());
      $checkCmd->setLogicalId('CheckFirmware');
      $checkCmd->setName(__('Vérifier firmware', __FILE__));
      $checkCmd->setType('action');
      $checkCmd->setSubType('other');
      $checkCmd->setIsVisible(0);
      $checkCmd->setConfiguration('help', __('Vérifie la disponibilité d\'une mise à jour firmware officielle Reolink.', __FILE__));
      $checkCmd->save();
    }
  }

  public static function checkEquipmentFirmwareArchive($camera, $index = null, $saveConfig = true) {
    if (!is_object($camera) || !($camera instanceof reolink)) {
      throw new Exception(__('Équipement Reolink invalide', __FILE__));
    }
    $isEnable = intval($camera->getIsEnable()) === 1;
    $model = trim(strval($camera->getConfiguration('model', '')));
    $hardVer = trim(strval($camera->getConfiguration('hardVer', '')));
    $firmVer = trim(strval($camera->getConfiguration('firmVer', '')));

    $res = reolinkFwArchive::checkDevice($model, $hardVer, $firmVer, $index);
    $effectiveStatus = $isEnable ? $res['status'] : 'inactive';
    $effectiveUpdateAvailable = $isEnable && !empty($res['updateAvailable']);
    $checkedAt = date('Y-m-d H:i:s');
    $changelogText = !empty($res['changelog']) ? implode("\n", $res['changelog']) : '';

    $updates = array(
      'fwArchiveStatus' => $effectiveStatus,
      'fwArchiveLatestVer' => $res['latestVer'],
      'fwArchiveBuildDate' => $res['buildDate'],
      'fwArchiveDownloadUrl' => $res['downloadUrl'],
      'fwArchiveChangelog' => $changelogText,
      'fwArchiveCheckedAt' => $checkedAt,
    );

    $changed = false;
    foreach ($updates as $k => $v) {
      if (strval($camera->getConfiguration($k, '')) !== strval($v)) {
        $camera->setConfiguration($k, $v);
        $changed = true;
      }
    }
    if ($saveConfig && $changed && intval($camera->getId()) > 0) {
      $camera->save(true);
    }

    if (intval($camera->getId()) > 0) {
      if (self::isGithubFirmwareCheckEnabled()) {
        self::ensureGithubFirmwareCommands($camera);
      }
      if (is_object($camera->getCmd(null, 'LatestFirmware'))) {
        $camera->checkAndUpdateCmd('LatestFirmware', $res['latestVer']);
      }
      if (is_object($camera->getCmd(null, 'NewFirmwareState'))) {
        if (!$isEnable) {
          $camera->checkAndUpdateCmd('NewFirmwareState', 0);
        } elseif ($effectiveStatus === 'update_available') {
          $camera->checkAndUpdateCmd('NewFirmwareState', 1);
        } elseif ($effectiveStatus === 'up_to_date') {
          $camera->checkAndUpdateCmd('NewFirmwareState', 0);
        }
      }
    }

    $role = 'camera';
    if ($camera->isNvrParent()) {
      $role = 'nvr';
    } elseif ($camera->isChannelChild()) {
      $role = 'channel';
    }

    return array(
      'id' => intval($camera->getId()),
      'name' => $camera->getName(),
      'humanName' => $camera->getHumanName(),
      'isEnable' => $isEnable,
      'role' => $role,
      'model' => $model,
      'hardVer' => $hardVer,
      'installedVer' => $firmVer,
      'status' => $effectiveStatus,
      'rawStatus' => $res['status'],
      'updateAvailable' => $effectiveUpdateAvailable,
      'latestVer' => $res['latestVer'],
      'latestPakVer' => $res['latestPakVer'],
      'buildDate' => $res['buildDate'],
      'downloadUrl' => $res['downloadUrl'],
      'changelog' => $res['changelog'],
      'checkedAt' => $checkedAt,
    );
  }

  public static function checkAllFirmwareUpdates($id = null, $forceRefresh = false) {
    $index = reolinkFwArchive::fetchIndex($forceRefresh);
    $eqList = array();
    if ($id !== null && intval($id) > 0) {
      $single = reolink::byId(intval($id));
      if (!is_object($single)) {
        throw new Exception(__('Impossible de trouver l\'équipement : ', __FILE__) . intval($id));
      }
      $eqList[] = $single;
    } else {
      $allEqs = eqLogic::byType('reolink');
      foreach ($allEqs as $cand) {
        if (!is_object($cand)) {
          continue;
        }
        if (intval($cand->getIsEnable()) !== 1) {
          if (strval($cand->getConfiguration('fwArchiveStatus', '')) === 'update_available') {
            $cand->setConfiguration('fwArchiveStatus', 'inactive');
            $cand->save(true);
          }
          $inactiveFwCmd = $cand->getCmd(null, 'NewFirmwareState');
          if (is_object($inactiveFwCmd) && intval($inactiveFwCmd->execCmd()) !== 0) {
            $cand->checkAndUpdateCmd('NewFirmwareState', 0);
          }
          continue;
        }
        $eqList[] = $cand;
      }
      usort($eqList, function ($a, $b) {
        if ($a->isNvrParent() !== $b->isNvrParent()) {
          return $a->isNvrParent() ? -1 : 1;
        }
        return strcasecmp($a->getHumanName(), $b->getHumanName());
      });
    }

    $devices = array();
    $updatesCount = 0;
    foreach ($eqList as $eq) {
      if (!is_object($eq)) {
        continue;
      }
      $item = self::checkEquipmentFirmwareArchive($eq, $index, true);
      if (!empty($item['updateAvailable'])) {
        $updatesCount++;
      }
      $devices[] = $item;
    }

    return array(
      'enabled' => self::isGithubFirmwareCheckEnabled(),
      'fetchedAt' => date('Y-m-d H:i:s', intval($index['fetchedAt'] ?? time())),
      'stale' => !empty($index['stale']),
      'entryCount' => intval($index['entryCount'] ?? 0),
      'repoUrl' => reolinkFwArchive::REPO_HTML_URL,
      'updatesCount' => $updatesCount,
      'devices' => $devices,
    );
  }

  // Fonction exécutée automatiquement une fois par jour par Jeedom
  public static function cronDaily() {
    if (!self::isGithubFirmwareCheckEnabled()) {
      return;
    }
    try {
      $report = self::checkAllFirmwareUpdates(null, true);
      $updates = intval($report['updatesCount'] ?? 0);
      if ($updates > 0) {
        log::add('reolink', 'info', sprintf(__('Vérification quotidienne des firmwares Reolink : %d mise(s) à jour officielle(s) disponible(s)', __FILE__), $updates));
      } else {
        log::add('reolink', 'debug', __('Vérification quotidienne des firmwares Reolink : tous les équipements référencés sont à jour', __FILE__));
      }
    } catch (Exception $e) {
      log::add('reolink', 'warning', 'cronDaily firmware check : ' . $e->getMessage());
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

  public static function health() {
    $eqLogics = eqLogic::byType('reolink');
    $totalEq = count($eqLogics);
    $enabledEq = 0;
    $activeCams = 0;
    $onlineCams = 0;
    $fwUpdates = 0;

    foreach ($eqLogics as $eq) {
      $enabled = (intval($eq->getIsEnable()) === 1);
      if ($enabled) {
        $enabledEq++;
      }
      if ($enabled && strval($eq->getConfiguration('fwArchiveStatus', '')) === 'update_available') {
        $fwUpdates++;
      }
      if (!$enabled || $eq->isNvrParent()) {
        continue;
      }
      $activeCams++;
      $onlineCmd = $eq->getCmd(null, 'ChannelOnline');
      $onlineVal = is_object($onlineCmd) ? strval($onlineCmd->execCmd()) : '';
      if ($onlineVal === '' || intval($onlineVal) === 1) {
        $onlineCams++;
      }
    }

    $result = array(
      array(
        'test' => __('Équipements actifs', __FILE__),
        'result' => $enabledEq . ' / ' . $totalEq,
        'advice' => '',
        'state' => true,
      ),
      array(
        'test' => __('Caméras en ligne', __FILE__),
        'result' => $onlineCams . ' / ' . $activeCams,
        'advice' => ($onlineCams < $activeCams) ? __('Un ou plusieurs canaux actifs sont hors ligne (ChannelOnline = 0)', __FILE__) : '',
        'state' => ($activeCams === 0 || $onlineCams === $activeCams),
      ),
    );

    $go2rtcBin = dirname(__FILE__) . '/../../resources/bin/go2rtc';
    $deamonState = self::deamon_info()['state'] ?? 'nok';
    if (!is_file($go2rtcBin)) {
      $result[] = array(
        'test' => __('Serveur Live go2rtc', __FILE__),
        'result' => __('Non installé', __FILE__),
        'advice' => '',
        'state' => true,
      );
    } elseif ($deamonState !== 'ok') {
      $result[] = array(
        'test' => __('Serveur Live go2rtc', __FILE__),
        'result' => __('Arrêté', __FILE__),
        'advice' => '',
        'state' => true,
      );
    } else {
      $errno = 0;
      $errstr = '';
      $apiPort = self::getGo2rtcApiPort();
      $fp = @fsockopen('127.0.0.1', $apiPort, $errno, $errstr, 0.25);
      $go2rtcOk = is_resource($fp);
      if ($go2rtcOk) {
        fclose($fp);
      }
      $webrtcSuffix = self::isWebrtcEnabled() ? (', WebRTC :' . self::getGo2rtcWebrtcPort()) : ', fMP4';
      $result[] = array(
        'test' => __('Serveur Live go2rtc', __FILE__),
        'result' => $go2rtcOk ? ('OK (127.0.0.1:' . $apiPort . $webrtcSuffix . ')') : __('Indisponible', __FILE__),
        'advice' => $go2rtcOk ? '' : (__('Le démon tourne mais le port local go2rtc ne répond pas', __FILE__) . ' (127.0.0.1:' . $apiPort . ')'),
        'state' => $go2rtcOk,
      );
    }

    if (self::isGithubFirmwareCheckEnabled()) {
      $result[] = array(
        'test' => __('Mises à jour firmware', __FILE__),
        'result' => ($fwUpdates > 0) ? ($fwUpdates . ' ' . __('disponible(s)', __FILE__)) : __('À jour', __FILE__),
        'advice' => ($fwUpdates > 0) ? __('Consultez Vérifier les firmwares sur la page du plugin Reolink', __FILE__) : '',
        'state' => true,
      );
    }

    return $result;
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
    $cmd .= ' --go2rtc_api_port ' . self::getGo2rtcApiPort();
    $cmd .= ' --go2rtc_webrtc_port ' . (self::isWebrtcEnabled() ? self::getGo2rtcWebrtcPort() : 0);
    $webrtcCandidates = self::getGo2rtcWebrtcCandidates();
    if (self::isWebrtcEnabled() && !empty($webrtcCandidates)) {
      $cmd .= ' --go2rtc_webrtc_candidates ' . escapeshellarg(implode(',', $webrtcCandidates));
    }
    $cmd .= ' --talk_port ' . self::getTalkBridgePort();
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
    usleep(600000);
    self::syncBaichuanDaemonSubscriptions(true);
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
    if (intval($this->getIsEnable()) !== 1 && in_array(strval($this->getConfiguration('fwArchiveStatus', '')), array('update_available', 'up_to_date'), true)) {
      $this->setConfiguration('fwArchiveStatus', 'inactive');
    }
    // Champs OK
  }

  public function postSave() {
    if (intval($this->getIsEnable()) !== 1) {
      $inactiveFwCmd = $this->getCmd(null, 'NewFirmwareState');
      if (is_object($inactiveFwCmd) && intval($inactiveFwCmd->execCmd()) !== 0) {
        $this->checkAndUpdateCmd('NewFirmwareState', 0);
      }
    }
    self::syncBaichuanDaemonSubscriptions(false);
    $this->emptyCacheWidget();
    $this->refreshWidget();
  }

  public function postToHtml($_version, $_html) {
    if ($_html === '' || $this->isNvrParent() || intval($this->getIsEnable()) !== 1) {
      return $_html;
    }
    $mode = trim(strval($this->getConfiguration('dashboard_mode', 'commands')));
    if ($mode !== 'snapshot_live' && $mode !== 'live_auto') {
      return $_html;
    }
    if ($this->hasBattery() && $mode === 'live_auto') {
      $mode = 'snapshot_live';
    }
    $meta = $this->buildLiveControlsMeta();
    if (empty($meta['streams'])) {
      return $_html;
    }
    $refreshSec = max(5, min(3600, intval($this->getConfiguration('dashboard_snap_interval', 15)) ?: 15));
    $firstStreamKey = array_key_first($meta['streams']);
    $domUid = 'rlkDash_' . intval($this->getId()) . '_' . substr(md5(uniqid('', true)), 0, 6);
    $safeTitle = htmlspecialchars($this->getName(), ENT_QUOTES, 'UTF-8');

    $optsHtml = '';
    foreach ($meta['streams'] as $sKey => $sMeta) {
      $sel = ($sKey === $firstStreamKey) ? ' selected' : '';
      $optsHtml .= '<option value="' . htmlspecialchars($sKey, ENT_QUOTES, 'UTF-8') . '"' . $sel . '>'
        . htmlspecialchars($sMeta['label'], ENT_QUOTES, 'UTF-8') . '</option>';
    }

    $mediaHtml = '<div id="' . $domUid . '" class="reolinkDashMedia" style="position:relative;margin:4px 6px 6px 6px;background:#000;border-radius:4px;overflow:hidden;">'
      . '<div style="position:relative;width:100%;min-height:140px;display:flex;align-items:center;justify-content:center;background:#000;">'
      . '<video class="rlkVideo" autoplay muted playsinline controls style="width:100%;max-height:240px;background:#000;' . ($mode === 'snapshot_live' || $firstStreamKey === 'jpeg' ? 'display:none;' : '') . '"></video>'
      . '<img class="rlkJpeg" alt="' . $safeTitle . '" style="width:100%;max-height:240px;object-fit:contain;background:#000;' . ($mode === 'snapshot_live' || $firstStreamKey === 'jpeg' ? '' : 'display:none;') . '" />'
      . '<a href="#" class="rlkPlayOverlay" title="' . htmlspecialchars(__('Démarrer le direct', __FILE__), ENT_QUOTES, 'UTF-8') . '" style="position:absolute;inset:0;display:' . ($mode === 'snapshot_live' ? 'flex' : 'none') . ';align-items:center;justify-content:center;background:rgba(0,0,0,0.28);color:#fff;font-size:38px;text-decoration:none;"><i class="fas fa-play-circle"></i></a>'
      . '</div>'
      . '<div style="display:flex;align-items:center;justify-content:space-between;gap:4px;padding:3px 6px;background:rgba(0,0,0,0.75);font-size:11px;flex-wrap:wrap;">'
      . '<select class="rlkQualitySel form-control input-xs" style="width:auto;height:22px;padding:1px 4px;font-size:11px;">' . $optsHtml . '</select>'
      . '<div style="display:flex;align-items:center;gap:3px;">'
      . (!empty($meta['hasWebrtc']) ? '<a class="btn btn-default btn-xs rlkWebRtcBtn" title="WebRTC">RTC</a>' : '')
      . ($meta['hasTalk'] ? '<a class="btn btn-default btn-xs rlkTalkBtn" title="' . htmlspecialchars(__('Parler', __FILE__), ENT_QUOTES, 'UTF-8') . '"><i class="fas fa-microphone-slash"></i></a>' : '')
      . '<a class="btn btn-default btn-xs rlkSnapBtn" title="' . htmlspecialchars(__('Capture', __FILE__), ENT_QUOTES, 'UTF-8') . '"><i class="fas fa-camera"></i></a>'
      . '<a class="btn btn-warning btn-xs rlkStopBtn" style="display:none;" title="' . htmlspecialchars(__('Arrêter le direct', __FILE__), ENT_QUOTES, 'UTF-8') . '"><i class="fas fa-stop"></i></a>'
      . '<a class="btn btn-default btn-xs rlkOpenModalBtn" data-title="' . $safeTitle . '" title="' . htmlspecialchars(__('Ouvrir en grand avec contrôles PTZ / Audio', __FILE__), ENT_QUOTES, 'UTF-8') . '"><i class="fas fa-expand"></i></a>'
      . '</div>'
      . '</div>'
      . '<div class="rlkErr alert alert-warning" style="display:none;margin:2px;padding:2px 6px;font-size:11px;"></div>'
      . '</div>'
      . '<script>(function(){'
      . 'var cfg=' . json_encode(array(
        'root' => '#' . $domUid,
        'eqId' => strval($this->getId()),
        'streams' => $meta['streams'],
        'initialQuality' => $firstStreamKey,
        'mode' => $mode,
        'refreshSec' => $refreshSec,
        'hasWebrtc' => !empty($meta['hasWebrtc']),
        'isBattery' => $meta['isBattery'],
        'controls' => $meta['controls'],
      )) . ';'
      . 'function boot(){if(window.ReolinkLivePlayer){new window.ReolinkLivePlayer(cfg);}}'
      . 'if(window.ReolinkLivePlayer){boot();}else{'
      . 'var s=document.getElementById("reolinkPlayerJsTag");'
      . 'if(!s){s=document.createElement("script");s.id="reolinkPlayerJsTag";s.src="plugins/reolink/desktop/js/reolink.player.js";document.head.appendChild(s);}'
      . 's.addEventListener("load",boot);'
      . '}'
      . '})();</script>';

    if (strpos($_html, '<div class="cmds') !== false) {
      return preg_replace('/<div class="cmds\b/', $mediaHtml . '<div class="cmds', $_html, 1);
    }
    if (strpos($_html, '<div style="padding: 5px;">') !== false) {
      return str_replace('<div style="padding: 5px;">', $mediaHtml . '<div style="padding: 5px;">', $_html);
    }
    return $_html;
  }

  public function postRemove() {
    self::syncBaichuanDaemonSubscriptions(true);
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
      'SetIrLights' => 'Auto|Auto;On|Activer;Off|Désactiver',
      'SetAntiFlicker' => 'Other|Autre;50HZ|50Hz;60HZ|60Hz;Off|Désactiver',
      'SetExposure' => 'Auto|Auto;LowNoise|Faible bruit;Anti-Smearing|Anti-smear;Manual|Manuel',
      'SetBackLight' => 'Off|Désactiver;BackLightControl|Compensation contre-jour (BLC);DynamicRangeControl|Plage dynamique (DRC)',
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

    $legacyNames = array(
      'SetBitratest1' => array('from' => array('Bitrate Stream1'), 'to' => __('Débit Stream1', __FILE__)),
      'SetBitratest2' => array('from' => array('Bitrate Stream2'), 'to' => __('Débit Stream2', __FILE__)),
      'SetBitratest1State' => array('from' => array('Débit kbps (Stream1)', 'Bitrate kbps (Stream1)'), 'to' => __('Débit (Stream1)', __FILE__)),
      'SetBitratest2State' => array('from' => array('Débit kbps (Stream2)', 'Bitrate kbps (Stream2)'), 'to' => __('Débit (Stream2)', __FILE__)),
      'SetCodecRateState' => array('from' => array('Débit codec (Kbps)', 'Codec bitrate (Kbps)'), 'to' => __('Débit codec', __FILE__)),
      'SetNetThroughputState' => array('from' => array('Débit réseau (Kbps)', 'Network throughput (Kbps)'), 'to' => __('Débit réseau', __FILE__)),
    );
    foreach ($legacyNames as $logicalId => $spec) {
      $cmd = $this->getCmd(null, $logicalId);
      if (!is_object($cmd) || !in_array($cmd->getName(), $spec['from'], true)) {
        continue;
      }
      $collision = false;
      foreach ($this->getCmd() as $other) {
        if ($other->getId() !== $cmd->getId() && $other->getName() === $spec['to']) {
          $collision = true;
          break;
        }
      }
      if (!$collision) {
        $cmd->setName($spec['to']);
        $cmd->save();
      }
    }

    $patrolCmd = $this->getCmd(null, 'StartPtzPatrol');
    if (is_object($patrolCmd)) {
      $curPatrolList = strval($patrolCmd->getConfiguration('listValue', ''));
      if ($curPatrolList !== '') {
        $rewritten = array();
        $changedPatrol = false;
        foreach (explode(';', $curPatrolList) as $pair) {
          $bits = explode('|', $pair, 2);
          if (count($bits) === 2 && preg_match('/^(Ronde|Patrol|Ronda)\s+(\d+)$/', trim($bits[1]), $m) && intval($m[2]) === intval($bits[0])) {
            $rewritten[] = $bits[0] . '|' . __('Ronde', __FILE__) . ' ' . (intval($bits[0]) + 1);
            $changedPatrol = true;
          } else {
            $rewritten[] = $pair;
          }
        }
        if ($changedPatrol) {
          $patrolCmd->setConfiguration('listValue', implode(';', $rewritten));
          $patrolCmd->save();
        }
      }
    }
  }

  /**
   * Read the effective V20 switch state matching reolink_aio:
   * - On an NVR channel child, per-channel toggles use `scheduleEnable`.
   * - On a standalone camera (or NVR host), the master toggle is `enable`.
   */
  public static function resolveV20SwitchState($eqLogic, $node) {
    if (!is_array($node)) {
      return 0;
    }
    if (is_object($eqLogic) && $eqLogic->isChannelChild()) {
      return intval($node['scheduleEnable'] ?? ($node['enable'] ?? 0));
    }
    return intval($node['enable'] ?? ($node['scheduleEnable'] ?? 0));
  }

  /**
   * Adapt V20 switch write payloads (`SetEmailV20`, `SetFtpV20`, `SetPushV20`, `SetRecV20`, `SetBuzzerAlarmV20`)
   * for standalone cameras (`enable`) vs NVR channel children (`scheduleEnable`), matching reolink_aio.
   *
   * @return array{0: mixed, 1: bool} [$normalizedPatch, $skipRmw]
   */
  public static function normalizeStandaloneV20SwitchPatch($eqLogic, $actionAPI, $patch) {
    if (!is_object($eqLogic) || $eqLogic->isChannelChild() || !is_array($patch)) {
      return array($patch, false);
    }
    $v20RootByApi = array(
      'SetEmailV20' => 'Email',
      'SetFtpV20' => 'Ftp',
      'SetPushV20' => 'Push',
      'SetRecV20' => 'Rec',
      'SetBuzzerAlarmV20' => 'Buzzer',
    );
    if (!isset($v20RootByApi[$actionAPI])) {
      return array($patch, false);
    }
    $rootKey = $v20RootByApi[$actionAPI];
    if (!isset($patch[$rootKey]) || !is_array($patch[$rootKey])) {
      return array($patch, false);
    }
    if (array_key_exists('scheduleEnable', $patch[$rootKey])) {
      $onOff = intval($patch[$rootKey]['scheduleEnable']);
      return array(array($rootKey => array('enable' => $onOff)), true);
    }
    if (array_key_exists('enable', $patch[$rootKey]) && count($patch[$rootKey]) === 1) {
      return array($patch, true);
    }
    return array($patch, false);
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
    if (!isset($map[$actionAPI]) || !is_object($camcnx) || !$camcnx->is_loggedin || !is_array($patchArray)) {
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
   * Detect whether a GetMask response uses 3D PTZ-tracked Position Masks
   * (`supportPrivacyCoverFollow` / `enableMaskTrack` on RLC-823S2, RLC-823S1,
   * RLC-823S1W, P840, P850, W840).
   */
  public static function usesDynamicMaskTrack($mask) {
    if (!is_array($mask) || !array_key_exists('enableMaskTrack', $mask)) {
      return false;
    }
    if (!empty($mask['enableMaskTrack'])) {
      return true;
    }
    if (!empty($mask['area']) && is_array($mask['area'])) {
      foreach ($mask['area'] as $area) {
        if (is_array($area) && !empty($area['track']['valid'])) {
          return true;
        }
      }
    }
    return false;
  }

  /**
   * Extract normalized binary state (0/1) from a GetMask `value.Mask` object.
   * On 3D Position Mask cameras (`bc_ptz.cpp`), tracked masks are only active
   * when `enable == 0 && enableMaskTrack != 0` (`enable == 1` disables 3D tracking).
   */
  public static function extractMaskState($mask) {
    if (!is_array($mask)) {
      return 0;
    }
    if (self::usesDynamicMaskTrack($mask)) {
      return (empty($mask['enable']) && !empty($mask['enableMaskTrack'])) ? 1 : 0;
    }
    return !empty($mask['enable']) ? 1 : 0;
  }

  /**
   * Build the `SetMask` param payload, adapting `enable` vs `enableMaskTrack`
   * when the camera uses 3D PTZ-tracked Position Masks.
   */
  public static function buildSetMaskParam($camcnx, $channel, $patch) {
    if (!is_array($patch) || !isset($patch['Mask']) || !is_array($patch['Mask'])) {
      return $patch;
    }
    $desired = !empty($patch['Mask']['enable']) ? 1 : 0;
    $curMask = null;
    if (is_object($camcnx)) {
      $res = $camcnx->SendCMD('[{"cmd":"GetMask","action":0,"param":{"channel":' . intval($channel) . '}}]');
      if (is_array($res) && isset($res[0]['value']['Mask']) && is_array($res[0]['value']['Mask'])) {
        $curMask = $res[0]['value']['Mask'];
      }
    }
    if (self::usesDynamicMaskTrack($curMask)) {
      return array(
        'Mask' => array(
          'channel' => intval($channel),
          'enable' => 0,
          'enableMaskTrack' => $desired,
        ),
      );
    }
    $patch['Mask']['channel'] = intval($channel);
    $patch['Mask']['enable'] = $desired;
    return $patch;
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
        if (is_object($cnxTmp) && $cnxTmp->is_loggedin) {
          $ab = $cnxTmp->SendCMD('[{"cmd":"GetAbility","param":{"User":{"userName":"' . $camera->getConfiguration('login', 'admin') . '"}}}]');
          if (is_array($ab) && isset($ab[0]['value']['Ability'])) {
            $devAbilityReturn = $ab[0]['value']['Ability'];
            unset($devAbilityReturn['abilityChn']);
            if ((!isset($devAbilityReturn['upgrade']) || intval($devAbilityReturn['upgrade']['permit'] ?? 0) === 0)
              && isset($devAbilityReturn['supportRemoteUpgrade'])) {
              $devAbilityReturn['upgrade'] = $devAbilityReturn['supportRemoteUpgrade'];
            }
            $bcScene = reolinkBaichuan::fromEqLogic($camera);
            if (is_object($bcScene) && $bcScene->probeSceneSupport()) {
              $devAbilityReturn['scene'] = array('ver' => 1, 'permit' => 7);
            }
            $hostModelStr = strval($camera->getConfiguration('hostModel', $camera->getConfiguration('model', '')));
            if (is_object($bcScene) && (stripos($hostModelStr, 'Hub') !== false || intval($devAbilityReturn['supportDingDong']['ver'] ?? 0) > 0)) {
              $hostChime = $bcScene->getChimeStatus(null);
              if (is_array($hostChime) && !empty($hostChime['chimes'])) {
                $devAbilityReturn['supportChime'] = array('ver' => 1, 'permit' => 6);
                $camera->setConfiguration('chime_id', intval($hostChime['id']));
                $camera->save(true);
              }
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
    $isBaichuanOnly = (strtolower(trim(strval($camera->getConfiguration('cnxtype', '')))) === 'baichuan');
    $usesBcPush = $camera->usesBaichuanPush();
    $allowedLogical = array();
    $deniedLogical = array(
      'EvMotionAlarm' => true,
    );
    if ($usesBcPush) {
      $deniedLogical['EvLastOnvifFull'] = true;
      $deniedLogical['EvLastOnvifName'] = true;
      $deniedLogical['EvLastOnvifState'] = true;
    }
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
        // preRec only if present in GetRecV20 value and recPreRecord ability allows it
        if ($abilityKey === 'sdCard') {
          $hasPreRec = isset($probeResults[$p20]['value']['Rec']['preRec'])
            && reolink::isAbilityOk($devAbilityReturn, 'sdCard', 'recPreRecord');
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
          $hasPreRec = isset($probeResults[$p1]['value']['Rec']['preRec'])
            && reolink::isAbilityOk($devAbilityReturn, 'sdCard', 'recPreRecord');
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

    $ispProbe = null;
    if (!$camera->isNvrParent()) {
      $ispProbe = $cnx->SendCMD('[{"cmd":"GetIsp","action":1,"param":{"channel":' . intval($channel) . '}}]');
      $ispValue = $ispProbe[0]['value']['Isp'] ?? array();
      $ispRange = $ispProbe[0]['range']['Isp'] ?? array();
      $hasHdr = is_array($ispProbe) && array_key_exists('hdr', $ispValue);
      if (!$hasHdr) {
        $deniedLogical['SetHdrState'] = true;
        $deniedLogical['SetHdr'] = true;
      }
      $backLightSupported = reolink::isAbilityOk($devAbilityReturn, 'ispBackLight');
      if (is_array($ispRange) && isset($ispRange['backLight']) && is_array($ispRange['backLight']) && count($ispRange['backLight']) <= 1) {
        $backLightSupported = false;
      }
      if (!array_key_exists('backLight', $ispValue) && (!is_array($ispRange) || !isset($ispRange['backLight']))) {
        $backLightSupported = false;
      }
      if (!$backLightSupported) {
        $deniedLogical['SetBackLightState'] = true;
        $deniedLogical['SetBackLight'] = true;
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
        if ($logicalId !== 'SetPTZmoove') {
          $helpTpl = strval($command['configuration']['help'] ?? '');
          if ($helpTpl !== '' && strval($cmd->getConfiguration('help', '')) !== __($helpTpl, __FILE__)) {
            $cmd->setConfiguration('help', __($helpTpl, __FILE__));
            $dirty = true;
          }
          $valuesHelpTpl = strval($command['configuration']['valuesHelp'] ?? '');
          if ($valuesHelpTpl !== '' && strval($cmd->getConfiguration('valuesHelp', '')) !== __($valuesHelpTpl, __FILE__)) {
            $cmd->setConfiguration('valuesHelp', __($valuesHelpTpl, __FILE__));
            $dirty = true;
          }
        }
        $dynamicSelects = array(
          'SetPTZmoove', 'SetPtzByPreset', 'StartPtzPatrol', 'SetPTZpatrol',
          'SetResolutionst1', 'SetFPSst1', 'SetBitratest1', 'SetEncodingst1',
          'SetResolutionst2', 'SetFPSst2', 'SetBitratest2', 'SetEncodingst2',
          'SetScene',
          'QuickReplyPlay',
          'SetAutoReplyFile',
          'SetDoorbellLed',
        );
        $listTpl = strval($command['configuration']['listValue'] ?? '');
        if ($listTpl !== '' && !in_array($logicalId, $dynamicSelects, true)) {
          $localizedList = reolink::translateCmdListValue($listTpl);
          if (strval($cmd->getConfiguration('listValue', '')) !== $localizedList) {
            $cmd->setConfiguration('listValue', $localizedList);
            $dirty = true;
          }
        }
        $expectedUnite = strval($command['Unite'] ?? ($command['unite'] ?? ''));
        if (strval($cmd->getUnite()) !== $expectedUnite) {
          $cmd->setUnite($expectedUnite);
          $dirty = true;
        }
        $expectedGeneric = strval($command['generic_type'] ?? '');
        if ($expectedGeneric !== '' && strval($cmd->getGeneric_type()) === '') {
          $cmd->setGeneric_type($expectedGeneric);
          $dirty = true;
        }
        if ($valueFrom !== '' && strval($cmd->getValue()) === '') {
          $cmdlogic = reolinkCmd::byEqLogicIdAndLogicalId($camera->getId(), $valueFrom);
          if (is_object($cmdlogic)) {
            $cmd->setValue($cmdlogic->getId());
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
        // Never prune refresh (and supported motion/AI/ONVIF event infos on camera equipments)
        $keepAlways = array('refresh');
        if (!$camera->isNvrParent()) {
          $keepAlways = array_merge($keepAlways, array('ChannelOnline', 'EvMotion', 'MdState'));
          if (!$usesBcPush) {
            $keepAlways = array_merge($keepAlways, array('EvLastOnvifFull', 'EvLastOnvifName', 'EvLastOnvifState'));
          }
          $aiKeepMap = array(
            'EvFaceDetect' => 'supportAiFace',
            'EvPeopleDetect' => 'supportAiPeople',
            'EvVehicleDetect' => 'supportAiVehicle',
            'EvDogCatDetect' => 'supportAiDogCat',
            'EvPackageDetect' => 'supportAiPackage',
          );
          foreach ($aiKeepMap as $aiLid => $aiSubKey) {
            if (reolink::isAbilityOk($devAbilityReturn, 'alarmMd', $aiSubKey)) {
              $keepAlways[] = $aiLid;
            }
          }
          if (reolink::isAbilityOk($devAbilityReturn, 'doorbell')) {
            $keepAlways[] = 'EvVisitor';
          }
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
        $isGithubFwCmd = self::isGithubFirmwareCheckEnabled() && in_array($lid, array('NewFirmwareState', 'CheckFirmware', 'LatestFirmware'), true);
        // Also remove if ability/subability/scope from JSON would deny
        if (!$shouldRemove && !$isGithubFwCmd) {
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
          if (!$foundInConf && $camera->isNvrParent() && $lid !== 'LatestFirmware') {
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
      reolink::applyEncListValues($camera, $cnx, $channel, $pruneUnsupported);
      reolink::applyIspRanges($camera, $ispProbe, $cnx, $channel);
      reolink::applyZoomFocusRanges($camera, $cnx, $channel);
      reolink::applyPtzDirectionListValues($camera, $devAbilityReturn);
      $speedInfo = $camera->getCmd(null, 'SetSpeedState');
      if (is_object($speedInfo) && strval($speedInfo->execCmd()) === '') {
        $speedAct = $camera->getCmd(null, 'SetSpeed');
        $initSpeed = is_object($speedAct) ? intval($speedAct->getConfiguration('speedvalue', 32)) : 32;
        if ($initSpeed < 1 || $initSpeed > 64) {
          $initSpeed = 32;
        }
        $camera->checkAndUpdateCmd('SetSpeedState', $initSpeed);
      }
      if (is_object($camera->getCmd(null, 'StartPtzPatrol'))) {
        $patrolRefresh = $cnx->SendCMD('[{"cmd":"GetPtzPatrol","action":0,"param":{"channel":' . intval($channel) . '}}]');
        if (is_array($patrolRefresh) && isset($patrolRefresh[0])) {
          reolink::applyPtzPatrolState($camera, $patrolRefresh[0]);
        }
      }
      if (is_object($camera->getCmd(null, 'QuickReplyPlay'))) {
        reolink::updateQuickReplies($id);
      }
      if (is_object($camera->getCmd(null, 'SetDoorbellLed'))) {
        reolink::applyDoorbellLedRange($camera, $cnx, $channel);
      }
      reolink::refreshDoorbellAndChimeState($camera);
    } else {
      if (is_object($camera->getCmd(null, 'SetScene'))) {
        reolink::updateScenes($id);
      }
      reolink::refreshDoorbellAndChimeState($camera);
    }
    $cnx->batteryChannelMode = false;

    if (self::isGithubFirmwareCheckEnabled()) {
      self::ensureGithubFirmwareCommands($camera);
    }

    $camera->setConfiguration('unsupported_payloads', array_keys(array_merge($unsupportedPayloads, $keptUnsupported)));
    $camera->setConfiguration('unsupported_fail_counts', array());
    $camera->setConfiguration('needs_cmd_resync', 0);
    $camera->save();
    return $cmd_order;
  }

  public static function applyPtzDirectionListValues($camera, $devAbilityReturn) {
    if (!is_object($camera) || !is_array($devAbilityReturn)) {
      return;
    }
    $ptzCmd = $camera->getCmd(null, 'SetPTZmoove');
    if (!is_object($ptzCmd)) {
      return;
    }
    $ptzDirVer = intval($devAbilityReturn['ptzDirection']['ver'] ?? 0);
    $ptzTypeVer = intval($devAbilityReturn['ptzType']['ver'] ?? 0);
    $ptzCtrlVer = intval($devAbilityReturn['ptzCtrl']['ver'] ?? 0);
    $hasPtzSpeed = (intval($devAbilityReturn['supportPtzSpeed']['ver'] ?? 0) > 0)
      || ($ptzCtrlVer > 1 && (($ptzCtrlVer >> 6) & 1) === 1);

    if ($ptzDirVer === 2 || $ptzTypeVer === 7) {
      $rawList = 'Stop|Stop;Left|Gauche;Right|Droite';
      $helpKey = 'Déplacement PTZ manuel (gauche/droite/stop).';
      $valuesHelpKey = 'Stop = Stop, Left = Gauche, Right = Droite';
    } elseif (!$hasPtzSpeed && ($ptzDirVer === 1 || ($ptzCtrlVer > 1 && (($ptzCtrlVer >> 1) & 1) === 1))) {
      $rawList = 'Stop|Stop;Left|Gauche;Right|Droite;Up|Haut;Down|Bas';
      $helpKey = 'Déplacement PTZ manuel (haut/bas/gauche/droite/stop).';
      $valuesHelpKey = 'Stop = Stop, Left = Gauche, Right = Droite, Up = Haut, Down = Bas';
    } else {
      $rawList = 'Stop|Stop;Left|Gauche;Right|Droite;Up|Haut;Down|Bas;LeftUp|Haut gauche;RightUp|Haut droite;LeftDown|Bas gauche;RightDown|Bas droite';
      $helpKey = 'Déplacement PTZ manuel (haut/bas/gauche/droite/diagonales/stop).';
      $valuesHelpKey = 'Stop = Stop, Left = Gauche, Right = Droite, Up = Haut, Down = Bas, LeftUp = Haut gauche, RightUp = Haut droite, LeftDown = Bas gauche, RightDown = Bas droite';
    }

    $localizedList = reolink::translateCmdListValue($rawList);
    $localizedHelp = __($helpKey, __FILE__);
    $localizedValuesHelp = __($valuesHelpKey, __FILE__);
    $dirty = false;
    if (strval($ptzCmd->getConfiguration('listValue', '')) !== $localizedList) {
      $ptzCmd->setConfiguration('listValue', $localizedList);
      $dirty = true;
    }
    if (strval($ptzCmd->getConfiguration('help', '')) !== $localizedHelp) {
      $ptzCmd->setConfiguration('help', $localizedHelp);
      $dirty = true;
    }
    if (strval($ptzCmd->getConfiguration('valuesHelp', '')) !== $localizedValuesHelp) {
      $ptzCmd->setConfiguration('valuesHelp', $localizedValuesHelp);
      $dirty = true;
    }
    if ($dirty) {
      $ptzCmd->save();
    }
  }

  public static function applyEncListValues($camera, $cnx, $channel, $pruneSingleCodec = false) {
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
    $codecTargets = array(
      'SetEncodingst1' => $vt1,
      'SetEncodingst2' => $vt2,
    );
    foreach ($codecTargets as $codecLid => $codecSet) {
      $codecCmd = $camera->getCmd(null, $codecLid);
      if (!is_object($codecCmd)) {
        continue;
      }
      if (count($codecSet) <= 1) {
        if ($pruneSingleCodec && !reolink::isCmdUsedExternally($codecCmd)) {
          log::add('reolink', 'info', 'Sélecteur codec fixe supprimé : ' . $codecCmd->getName() . ' (' . $codecLid . ')');
          $codecCmd->remove();
          continue;
        }
        $fallbackCodec = !empty($codecSet) ? $toList($codecSet) : 'h264|h264';
        if (strval($codecCmd->getConfiguration('listValue', '')) !== $fallbackCodec) {
          $codecCmd->setConfiguration('listValue', $fallbackCodec);
          $codecCmd->save();
        }
      }
    }
    $map = array(
      'SetResolutionst1' => $toList($sizes1),
      'SetFPSst1' => $toList($fps1),
      'SetBitratest1' => $toList($br1),
      'SetEncodingst1' => (count($vt1) > 1) ? $toList($vt1) : '',
      'SetResolutionst2' => $toList($sizes2),
      'SetFPSst2' => $toList($fps2),
      'SetBitratest2' => $toList($br2),
      'SetEncodingst2' => (count($vt2) > 1) ? $toList($vt2) : '',
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

  public static function runBaichuanDiagnostic($id) {
    $camera = reolink::byId($id, 'reolink');
    if (!is_object($camera)) {
      throw new Exception(__('Caméra introuvable', __FILE__));
    }
    $bc = reolinkBaichuan::fromEqLogic($camera);
    if (!is_object($bc)) {
      throw new Exception(__('Renseignez et sauvegardez l\'adresse IP, le login et le mot de passe avant de lancer le diagnostic Baichuan', __FILE__));
    }
    $report = $bc->runDiagnosticReport($camera->getChannel(), $camera->isChannelChild());
    if (!empty($report['error'])) {
      throw new Exception(__('Échec diagnostic Baichuan', __FILE__) . ' : ' . $report['error']);
    }
    return $report;
  }

  public static function applyIspRanges($camera, $ispProbe = null, $cnx = null, $channel = 0) {
    if (!is_object($camera)) {
      return;
    }
    if (!is_array($ispProbe) || !isset($ispProbe[0]['range']['Isp'])) {
      if (!is_object($cnx)) {
        return;
      }
      $ispProbe = $cnx->SendCMD('[{"cmd":"GetIsp","action":1,"param":{"channel":' . intval($channel) . '}}]');
    }
    if (!is_array($ispProbe) || !isset($ispProbe[0]['range']['Isp']) || !is_array($ispProbe[0]['range']['Isp'])) {
      return;
    }
    $ispRange = $ispProbe[0]['range']['Isp'];
    $defaultLabels = array(
      'Other' => __('Autre', __FILE__),
      '50HZ' => __('50Hz', __FILE__),
      '60HZ' => __('60Hz', __FILE__),
      'Off' => __('Désactiver', __FILE__),
      'Auto' => __('Auto', __FILE__),
      'LowNoise' => __('Faible bruit', __FILE__),
      'Anti-Smearing' => __('Anti-smear', __FILE__),
      'Manual' => __('Manuel', __FILE__),
      'BackLightControl' => __('Compensation contre-jour (BLC)', __FILE__),
      'DynamicRangeControl' => __('Plage dynamique (DRC)', __FILE__),
      'Color' => __('Couleur', __FILE__),
      'Black&White' => __('Noir&Blanc', __FILE__),
    );
    $fields = array(
      'SetAntiFlicker' => 'antiFlicker',
      'SetExposure' => 'exposure',
      'SetBackLight' => 'backLight',
      'SetDayNight' => 'dayNight',
    );
    foreach ($fields as $lid => $rangeKey) {
      if (!isset($ispRange[$rangeKey]) || !is_array($ispRange[$rangeKey]) || empty($ispRange[$rangeKey])) {
        continue;
      }
      $cmd = $camera->getCmd(null, $lid);
      if (!is_object($cmd)) {
        continue;
      }
      $existingLabels = array();
      foreach (explode(';', strval($cmd->getConfiguration('listValue', ''))) as $pair) {
        $bits = explode('|', $pair, 2);
        if (count($bits) === 2 && $bits[0] !== '') {
          $existingLabels[$bits[0]] = $bits[1];
        }
      }
      $out = array();
      foreach ($ispRange[$rangeKey] as $allowedVal) {
        $valStr = strval($allowedVal);
        if ($valStr === '') {
          continue;
        }
        $label = $existingLabels[$valStr] ?? ($defaultLabels[$valStr] ?? $valStr);
        $out[] = $valStr . '|' . $label;
      }
      if (empty($out)) {
        continue;
      }
      $newList = implode(';', $out);
      if (strval($cmd->getConfiguration('listValue', '')) !== $newList) {
        $cmd->setConfiguration('listValue', $newList);
        $cmd->save();
      }
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
        if (is_object($eqLogic)) {
          reolink::clearHostCooldown($eqLogic);
        }
        reolink::refreshNFO($EqId, true);
        break;
      case 'GetPtzPreset':
        $camcnx = reolink::getReolinkConnection($EqId);
        if (!is_object($camcnx) || !$camcnx->is_loggedin) {
          throw new Exception(__('Caméra injoignable ou connexion impossible', __FILE__));
        }
        $data = $camcnx->SendCMD('[{"cmd":"GetPtzPreset","action":1,"param":{"channel":' . $channel . '}}]');
        if (is_array($data) && isset($data[0])) {
          reolink::updatePTZpreset($EqId, $data[0]);
        }
        break;
      case 'GetPtzPatrol':
        $camcnx = reolink::getReolinkConnection($EqId);
        if (!is_object($camcnx) || !$camcnx->is_loggedin) {
          throw new Exception(__('Caméra injoignable ou connexion impossible', __FILE__));
        }
        $data = $camcnx->SendCMD('[{"cmd":"GetPtzPatrol","action":0,"param":{"channel":' . $channel . '}}]');
        if (is_array($data) && isset($data[0])) {
          reolink::updatePTZpatrol($EqId, $data[0]);
        }
        break;
      case 'GetScenes':
        reolink::updateScenes($EqId);
        break;
      case 'SetScene':
        reolink::setScene($EqId, intval($_options['select'] ?? -1));
        break;
      case 'GetQuickReplies':
        reolink::updateQuickReplies($EqId);
        break;
      case 'QuickReplyPlay':
        reolink::playQuickReply($EqId, intval($_options['select'] ?? 0));
        break;
      case 'SetAutoReplyEnable':
        reolink::setAutoReplyField($EqId, 'enable', intval($_options['select'] ?? 0));
        break;
      case 'SetAutoReplyFile':
        reolink::setAutoReplyField($EqId, 'fileId', intval($_options['select'] ?? 0));
        break;
      case 'SetAutoReplyTimeout':
        reolink::setAutoReplyField($EqId, 'timeout', intval($_options['slider'] ?? 10));
        break;
      case 'SetTalkVolume':
        reolink::setDoorbellAudioField($EqId, 'talkAndReplyVolume', max(0, min(100, intval($_options['slider'] ?? 80))));
        break;
      case 'SetVisitorVolume':
        reolink::setDoorbellAudioField($EqId, 'visitorVolume', max(0, min(100, intval($_options['slider'] ?? 80))));
        break;
      case 'SetVisitorLoudspeaker':
        reolink::setDoorbellAudioField($EqId, 'visitorLoudspeaker', intval($_options['select'] ?? 1) ? 1 : 0);
        break;
      case 'RingChime':
        reolink::execChimeAction($EqId, 'ring', intval($_options['select'] ?? 0));
        break;
      case 'SetChimeVolume':
        reolink::execChimeAction($EqId, 'volume', max(0, min(4, intval($_options['slider'] ?? 3))));
        break;
      case 'SetChimeLed':
        reolink::execChimeAction($EqId, 'led', intval($_options['select'] ?? 1));
        break;
      case 'SetChimeVisitorTone':
        reolink::execChimeAction($EqId, 'visitorTone', intval($_options['select'] ?? 0));
        break;
      case 'SetChimeSilent':
        reolink::execChimeAction($EqId, 'silent', intval($_options['select'] ?? 0));
        break;
      case 'SendSnapshot':
        reolink::sendSnapshotNotification($EqId, $this, $_options);
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
        $val = max(1, min(64, intval($_options['slider'] ?? 32)));
        $this->setConfiguration('speedvalue', $val);
        $this->save();
        if (is_object($eqLogic)) {
          $eqLogic->checkAndUpdateCmd('SetSpeedState', $val);
        }
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
        if (!is_object($eqLogic) || intval($eqLogic->getIsEnable()) !== 1) {
          if (is_object($eqLogic) && reolink::isGithubFirmwareCheckEnabled()) {
            try {
              reolink::checkEquipmentFirmwareArchive($eqLogic, null, true);
            } catch (Exception $e) {
              log::add('reolink', 'debug', 'CheckFirmware inactif ignoré : ' . $e->getMessage());
            }
          }
          break;
        }
        $newFwVal = null;
        $isBaichuanOnly = is_object($eqLogic) && (strtolower(trim(strval($eqLogic->getConfiguration('cnxtype', '')))) === 'baichuan');
        if (is_object($eqLogic) && !$isBaichuanOnly && !$eqLogic->isChannelChild()) {
          $camcnx = reolink::getReolinkConnection($EqId);
          if (is_object($camcnx) && $camcnx->is_loggedin) {
            $res = $camcnx->SendCMD('[{"cmd":"CheckFirmware"}]');
            if (is_array($res) && isset($res[0]['value']['newFirmware'])) {
              $newFwVal = intval($res[0]['value']['newFirmware']);
            }
          }
        }
        if (is_object($eqLogic) && reolink::isGithubFirmwareCheckEnabled()) {
          try {
            $ghRes = reolink::checkEquipmentFirmwareArchive($eqLogic, null, true);
            if (!empty($ghRes['updateAvailable'])) {
              $newFwVal = 1;
            } elseif ($newFwVal === null && ($ghRes['status'] ?? '') === 'up_to_date') {
              $newFwVal = 0;
            }
          } catch (Exception $e) {
            log::add('reolink', 'warning', 'CheckFirmware GitHub : ' . $e->getMessage());
          }
        }
        if ($newFwVal !== null && is_object($eqLogic)) {
          $eqLogic->checkAndUpdateCmd('NewFirmwareState', $newFwVal);
          $ipForFw = strval($eqLogic->getConfiguration('adresseip'));
          if ($ipForFw !== '') {
            config::save('last_firmware_check_' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $ipForFw), time(), 'reolink');
          }
        }
        break;
      case 'UpgradeFirmware':
        $camcnx = reolink::getReolinkConnection($EqId);
        if (!is_object($camcnx) || !$camcnx->is_loggedin) {
          throw new Exception(__('Caméra injoignable ou connexion impossible', __FILE__));
        }
        $camcnx->SendCMD('[{"cmd":"UpgradeOnline"}]');
        break;
      case 'SyncTime':
        $camcnx = reolink::getReolinkConnection($EqId);
        if (!is_object($camcnx) || !$camcnx->is_loggedin) {
          throw new Exception(__('Caméra injoignable ou connexion impossible', __FILE__));
        }
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
        $actionAPI = $this->getConfiguration('actionapi');
        if ($actionAPI === 'Reboot' && is_object($eqLogic)) {
          reolink::clearHostCooldown($eqLogic);
        }
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
          if (!is_object($camcnx) || !$camcnx->is_loggedin) {
            if (is_object($camcnx)) {
              $camcnx->batteryChannelMode = false;
            }
            throw new Exception(__('Caméra injoignable ou connexion impossible', __FILE__) . ' (' . $actionAPI . ')');
          }
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
            list($patch, $skipRmw) = reolink::normalizeStandaloneV20SwitchPatch($eqLogic, $actionAPI, $patch);
            if ($skipRmw && is_array($patch)) {
              $patchJson = json_encode($patch);
            }
            $rmwApis = array('SetRecV20', 'SetEnc', 'SetIsp', 'SetOsd', 'SetAiCfg', 'SetAlarm', 'SetNtp', 'SetWhiteLed', 'SetBuzzerAlarmV20', 'SetAiAlarm');
            if (!$skipRmw && in_array($actionAPI, $rmwApis, true) && is_array($patch)) {
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
            } elseif ($actionAPI === 'SetMask' && is_array($patch)) {
              $maskParam = reolink::buildSetMaskParam($camcnx, $channel, $patch);
              $payload = json_encode(array(array('cmd' => $actionAPI, 'action' => 0, 'param' => $maskParam)));
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
