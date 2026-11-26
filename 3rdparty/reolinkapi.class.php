<?php

class reolinkAPI {
  private $ip;
  private $port;
  private $token;
  private $tokenexp;
  private $tagtoken;
  private $sessionKey;
  private $user;
  private $password;
  private $cnxtype;
  private $authmethod;
  private $skipRetry = false;
  private $loginInProgress = false;
  private $privacyWakeRetry = false;
  private $transportFailed = false;
  private $loginAttempted = false;
  private $loginBusy = false;

  public $is_loggedin;
  public $lastResponseData = null;
  public $probeMode = false;
  public $batteryChannelMode = false;
  public $bypassCooldown = false;

  public function hasTransportFailed() {
    return $this->transportFailed;
  }

  public function isLoginBusy() {
    return $this->loginBusy;
  }

  const CAM_GET_ABILITY = 'GetAbility';
  const CAM_GET_DEVINFO = 'GetDevInfo';
  const CAM_GET_DEVNAME = 'GetDevName';
  const CAM_SET_DEVNAME = 'SetDevName';
  const CAM_GET_TIME = 'GetTime';
  const CAM_SET_TIME = 'SetTime';
  const CAM_GET_AUTOMAINT = 'GetAutoMaint';
  const CAM_SET_AUTOMAINT = 'SetAutoMaint';
  const CAM_GET_HDDINFO = 'GetHddInfo';
  const CAM_FORMAT = 'Format';
  const CAM_UPGRADE = 'Upgrade';
  const CAM_RESTORE = 'Restore';
  const CAM_REBOOT = 'Reboot';
  const CAM_GET_PERFORMANCE = 'GetPerformance';
  const CAM_UPGRADEPREPARE = 'UpgradePrepare';
  const CAM_GET_AUTOUPGRADE = 'GetAutoUpgrade';
  const CAM_SET_AUTOUPGRADE = 'SetAutoUpgrade';
  const CAM_CHECKFIRMWARE = 'CheckFirmware';
  const CAM_UPGRADEONLINE = 'UpgradeOnline';
  const CAM_UPGRADESTATUS = 'UpgradeStatus';
  const CAM_GET_CHANNELSTATUS = 'Getchannelstatus';
  const CAM_LOGIN = 'Login';
  const CAM_LOGOUT = 'Logout';
  const CAM_GET_USER = 'GetUser';
  const CAM_ADDUSER = 'AddUser';
  const CAM_DELUSER = 'DelUser';
  const CAM_MODIFYUSER = 'ModifyUser';
  const CAM_GET_ONLINE = 'GetOnline';
  const CAM_DISCONNECT = 'Disconnect';
  const CAM_GET_LOCALLINK = 'GetLocalLink';
  const CAM_SET_LOCALLINK = 'SetLocalLink';
  const CAM_GET_DDNS = 'GetDdns';
  const CAM_SET_DDNS = 'SetDdns';
  const CAM_GET_EMAIL = 'GetEmail';
  const CAM_SET_EMAIL = 'SetEmail';
  const CAM_GET_EMAILV20 = 'GetEmailV20';
  const CAM_SET_EMAILV20 = 'SetEmailV20';
  const CAM_TESTEMAIL = 'TestEmail';
  const CAM_GET_FTP = 'GetFtp';
  const CAM_SET_FTP = 'SetFtp';
  const CAM_GET_FTPV20 = 'GetFtpV20';
  const CAM_SET_FTPV20 = 'SetFtpV20';
  const CAM_TESTFTP = 'TestFtp';
  const CAM_GET_NTP = 'GetNtp';
  const CAM_SET_NTP = 'SetNtp';
  const CAM_GET_NETPORT = 'GetNetPort';
  const CAM_SET_NETPORT = 'SetNetPort';
  const CAM_GET_UPNP = 'GetUpnp';
  const CAM_SET_UPNP = 'SetUpnp';
  const CAM_GET_WIFI = 'GetWifi';
  const CAM_SET_WIFI = 'SetWifi';
  const CAM_TESTWIFI = 'TestWifi';
  const CAM_SCANWIFI = 'ScanWifi';
  const CAM_GET_WIFISIGNAL = 'GetWifiSignal';
  const CAM_GET_PUSH = 'GetPush';
  const CAM_SET_PUSH = 'SetPush';
  const CAM_GET_PUSHV20 = 'GetPushV20';
  const CAM_SET_PUSHV20 = 'SetPushV20';
  const CAM_GET_PUSHCFG = 'GetPushCfg';
  const CAM_SET_PUSHCFG = 'SetPushCfg';
  const CAM_GET_P2P = 'GetP2p';
  const CAM_SET_P2P = 'SetP2p';
  const CAM_GET_CERTIFICATEINFO = 'GetCertificateInfo';
  const CAM_CERTIFICATECLEAR = 'CertificateClear';
  const CAM_GET_RTSPURL = 'GetRtspUrl';
  const CAM_GET_NORM = 'GetNorm';
  const CAM_SET_NORM = 'SetNorm';
  const CAM_GET_IMAGE = 'GetImage';
  const CAM_SET_IMAGE = 'SetImage';
  const CAM_GET_OSD = 'GetOsd';
  const CAM_SET_OSD = 'SetOsd';
  const CAM_GET_ISP = 'GetIsp';
  const CAM_SET_ISP = 'SetIsp';
  const CAM_GET_MASK = 'GetMask';
  const CAM_SET_MASK = 'SetMask';
  const CAM_PREVIEW = 'Preview';
  const CAM_GET_CROP = 'GetCrop';
  const CAM_SET_CROP = 'SetCrop';
  const CAM_GET_ENC = 'GetEnc';
  const CAM_SET_ENC = 'SetEnc';
  const CAM_GET_REC = 'GetRec';
  const CAM_SET_REC = 'SetRec';
  const CAM_GET_RECV20 = 'GetRecV20';
  const CAM_SET_RECV20 = 'SetRecV20';
  const CAM_SEARCH = 'Search';
  const CAM_DOWNLOAD = 'Download';
  const CAM_SNAP = 'Snap';
  const CAM_PLAYBACK = 'Playback';
  const CAM_NVRDOWNLOAD = 'NvrDownload';
  const CAM_GET_PTZPRESET = 'GetPtzPreset';
  const CAM_SET_PTZPRESET = 'SetPtzPreset';
  const CAM_GET_PTZPATROL = 'GetPtzPatrol';
  const CAM_SET_PTZPATROL = 'SetPtzPatrol';
  const CAM_PTZCTRL = 'PtzCtrl';
  const CAM_GET_PTZSERIAL = 'GetPtzSerial';
  const CAM_SET_PTZSERIAL = 'SetPtzSerial';
  const CAM_GET_PTZTATTERN = 'GetPtzTattern';
  const CAM_SET_PTZTATTERN = 'SetPtzTattern';
  const CAM_GET_AUTOFOCUS = 'GetAutoFocus';
  const CAM_SET_AUTOFOCUS = 'SetAutoFocus';
  const CAM_GET_ZOOMFOCUS = 'GetZoomFocus';
  const CAM_STARTZOOMFOCUS = 'StartZoomFocus';
  const CAM_GET_PTZGUARD = 'GetPtzGuard';
  const CAM_SET_PTZGUARD = 'SetPtzGuard';
  const CAM_GET_PTZCHECKSTATE = 'GetPtzCheckState';
  const CAM_PTZCHECK = 'PtzCheck';
  const CAM_GET_ALARM = 'GetAlarm';
  const CAM_SET_ALARM = 'SetAlarm';
  const CAM_GET_MDALARM = 'GetMdAlarm';
  const CAM_SET_MDALARM = 'SetMdAlarm';
  const CAM_GET_MDSTATE = 'GetMdState';
  const CAM_GET_AUDIOALARM = 'GetAudioAlarm';
  const CAM_SET_AUDIOALARM = 'SetAudioAlarm';
  const CAM_GET_AUDIOALARMV20 = 'GetAudioAlarmV20';
  const CAM_SET_AUDIOALARMV20 = 'SetAudioAlarmV20';
  const CAM_GET_BUZZERALARMV20 = 'GetBuzzerAlarmV20';
  const CAM_SET_BUZZERALARMV20 = 'SetBuzzerAlarmV20';
  const CAM_GET_MANUALREC = 'GetManualRec';
  const CAM_SET_MANUALREC = 'SetManualRec';
  const CAM_GET_BUZZER = 'GetBuzzer';
  const CAM_SET_BUZZER = 'SetBuzzer';
  const CAM_AUDIOALARMPLAY = 'AudioAlarmPlay';
  const CAM_GET_AUDIOCFG = 'GetAudioCfg';
  const CAM_SET_AUDIOCFG = 'SetAudioCfg';
  const CAM_GET_AUDIOFILELIST = 'GetAudioFileList';
  const CAM_QUICKREPLYPLAY = 'QuickReplyPlay';
  const CAM_GET_AUTOREPLY = 'GetAutoReply';
  const CAM_SET_AUTOREPLY = 'SetAutoReply';
  const CAM_GET_DINGDONGLIST = 'GetDingDongList';
  const CAM_DINGDONGOPT = 'DingDongOpt';
  const CAM_GET_IRLIGHTS = 'GetIrLights';
  const CAM_SET_IRLIGHTS = 'SetIrLights';
  const CAM_GET_POWERLED = 'GetPowerLed';
  const CAM_SET_POWERLED = 'SetPowerLed';
  const CAM_GET_WHITELED = 'GetWhiteLed';
  const CAM_SET_WHITELED = 'SetWhiteLed';
  const CAM_GET_AIALARM = 'GetAiAlarm';
  const CAM_SET_AIALARM = 'SetAiAlarm';
  const CAM_SET_ALARMAREA = 'SetAlarmArea';
  const CAM_GET_AICFG = 'GetAiCfg';
  const CAM_SET_AICFG = 'SetAiCfg';
  const CAM_GET_AISTATE = 'GetAiState';
  const CAM_GET_BATTERYINFO = 'GetBatteryInfo';
  const CAM_GET_PIRINFO = 'GetPirInfo';
  const CAM_GET_SYSCFG = 'GetSysCfg';
  const CAM_SET_SYSCFG = 'SetSysCfg';


  public function __construct(array $cnxinfo) {
    log::add('reolink', 'debug', str_repeat("~", 60));
    $this->is_loggedin = false;
    $this->ip = trim(strval($cnxinfo['adresseIP'] ?? ''));
    $this->bypassCooldown = !empty($cnxinfo['bypassCooldown']);

    $rawCnxType = strtolower(trim(strval($cnxinfo['cnxtype'] ?? '')));
    $this->cnxtype = ($rawCnxType === '') ? 'http' : $rawCnxType;

    $rawPort = $cnxinfo['port'] ?? '';
    if ($rawPort == "" || !is_numeric($rawPort)) {
      if ($this->cnxtype == "http") {
        $this->port = 80;
      } else {
        $this->port = 443;
      }
    } else {
      $this->port = trim(strval($rawPort));
    }

    $this->user = trim(strval($cnxinfo['username'] ?? ''));
    $this->password = trim(strval($cnxinfo['password'] ?? ''));
    $this->tagtoken = str_replace(".", "", $this->ip);
    $this->sessionKey = substr(hash('sha256', $this->cnxtype . '|' . $this->ip . '|' . $this->port . '|' . $this->user . '|' . $this->password), 0, 24);
    $this->authmethod = intval(config::byKey("authmethod", 'reolink', 0));

    if ($this->authmethod == 0) {
      $this->login();
    } else {
      if (!$this->bypassCooldown && class_exists('reolink') && reolink::isHostInCooldown($this->cnxtype, $this->ip, $this->port)) {
        $this->is_loggedin = false;
        $this->transportFailed = true;
      } else {
        $this->is_loggedin = true;
      }
    }
  }

  public function __destruct() {
    log::add('reolink', 'debug', str_repeat("=", 60));
  }

  private static function summarizeHttpFailure($httpcode, $curlErr, $bodySnippet) {
    $parts = array('HTTP ' . intval($httpcode));
    $cleanErr = trim(preg_replace('/\s+/', ' ', strval($curlErr)));
    if ($cleanErr !== '') {
      $parts[] = $cleanErr;
    }
    $cleanBody = trim(preg_replace('/\s+/', ' ', strip_tags(strval($bodySnippet))));
    if ($cleanBody !== '' && stripos($cleanErr, $cleanBody) === false) {
      if (strlen($cleanBody) > 90) {
        $cleanBody = substr($cleanBody, 0, 90) . '...';
      }
      $parts[] = $cleanBody;
    }
    return implode(' — ', $parts);
  }

  private function request($cmd, $payload) {
    if (!$this->bypassCooldown && class_exists('reolink') && reolink::isHostInCooldown($this->cnxtype, $this->ip, $this->port)) {
      $this->skipRetry = true;
      $this->transportFailed = true;
      log::add('reolink', 'debug', 'Requête ignorée (disjoncteur actif) vers ' . $this->ip . ':' . $this->port);
      return false;
    }

    $ch = curl_init();
    $url = "$this->cnxtype://$this->ip:$this->port/cgi-bin/api.cgi?$cmd";
    // Debug REMOVE PWD
    $urldebug = preg_replace('/password=(.*?)$/', 'password=******', $url);
    // Debug REMOVE PWD
    $payloadLOG = preg_replace('/password":"(.*?)"}}}/', 'password":"******"}}}', $payload);
    log::add('reolink', 'debug', 'URL de requête => ' . $urldebug);
    log::add('reolink', 'debug', 'Payload => ' . $payloadLOG);
    $reqTimeout = $this->probeMode ? 8 : 6;
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_TIMEOUT, $reqTimeout);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
    curl_setopt(
      $ch,
      CURLOPT_HTTPHEADER,
      array(
        'Content-Type: application/json',
        'Content-Length: ' . strlen($payload)
      )
    );
    $response = curl_exec($ch);
    $httpcode = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($httpcode === 200) {
      $this->transportFailed = false;
      if (class_exists('reolink')) {
        reolink::recordHostSuccess($this->cnxtype, $this->ip, $this->port);
      }
      log::add('reolink', 'debug', 'HTTP code 200 OK');
    } else {
      $wakeupUntil = intval(cache::byKey('reolink::privacy_wakeup_ip::' . $this->ip)->getValue(0));
      $privacyWake = ($wakeupUntil > time());
      $summary = self::summarizeHttpFailure($httpcode, $curlErr, is_string($response) ? substr($response, 0, 256) : '');
      if ($privacyWake && $httpcode === 0) {
        log::add('reolink', 'debug', $summary . ' (réveil confidentialité)');
        $this->privacyWakeRetry = true;
        return false;
      }

      // Do not retry synchronously on transport failure (HTTP 0) or camera gateway/HTTP errors (502/503/504/4xx)
      $prevTok = strval($this->token ?? '');
      $this->transportFailed = true;
      $this->skipRetry = true;
      $this->is_loggedin = false;
      unset($this->token);
      unset($this->tokenexp);

      if (($httpcode === 0 || $httpcode >= 500) && class_exists('reolink')) {
        if ($prevTok !== '') {
          $this->invalidateCachedToken($prevTok);
        }
        reolink::recordHostFailure($this->cnxtype, $this->ip, $this->port, $summary);
      } else {
        log::add('reolink', 'warning', 'Requête caméra ' . $this->ip . ':' . $this->port . ' en échec (' . $summary . ')');
      }
      return false;
    }

    //log::add('reolink', 'debug', 'Payload => '.print_r($payload, true));
    $debugResp = preg_replace('/\s+/', '', print_r($response, true));
    log::add('reolink', 'debug', 'Réponse caméra >> ' . $debugResp);
    return $response;
  }

  private function loginLockPath() {
    return jeedom::getTmpFolder('reolink') . '/login_' . $this->sessionKey . '.lock';
  }

  private function sessionCachePath() {
    return jeedom::getTmpFolder('reolink') . '/session_' . $this->sessionKey . '.json';
  }

  private function readSessionFile() {
    $path = $this->sessionCachePath();
    if (!is_file($path)) {
      return null;
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
      return null;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
  }

  private function loadCachedToken($minRemainingSec = 120, $excludeToken = '') {
    $entry = $this->readSessionFile();
    if (!is_array($entry) || !empty($entry['forceRotate'])) {
      return false;
    }
    $tok = trim(strval($entry['token'] ?? ''));
    $exp = intval($entry['expiresAt'] ?? 0);
    if ($tok === '' || ($excludeToken !== '' && $tok === $excludeToken) || $exp <= (time() + $minRemainingSec)) {
      return false;
    }
    $this->token = $tok;
    $this->tokenexp = $exp;
    $this->is_loggedin = true;
    $this->transportFailed = false;
    $this->loginBusy = false;
    return true;
  }

  private function saveCachedToken($token, $expiresAt) {
    $dir = jeedom::getTmpFolder('reolink');
    if (!is_dir($dir)) {
      @mkdir($dir, 0770, true);
    }
    $path = $this->sessionCachePath();
    $tmp = $path . '.' . getmypid() . '.' . substr(md5(uniqid('', true)), 0, 6) . '.tmp';
    $payload = json_encode(array(
      'token' => strval($token),
      'expiresAt' => intval($expiresAt),
      'ip' => $this->ip,
    ));
    if (@file_put_contents($tmp, $payload) !== false) {
      @chmod($tmp, 0600);
      @rename($tmp, $path);
    } else {
      @unlink($tmp);
    }
  }

  public function invalidateCachedToken($expectedToken = '') {
    $path = $this->sessionCachePath();
    if (!is_file($path)) {
      return;
    }
    if ($expectedToken !== '') {
      $entry = $this->readSessionFile();
      if (is_array($entry) && strval($entry['token'] ?? '') !== $expectedToken) {
        return;
      }
    }
    @unlink($path);
  }

  private function logoutTokenQuiet($tok) {
    $tok = trim(strval($tok));
    if ($tok === '' || $this->ip === '') {
      return;
    }
    $url = $this->cnxtype . '://' . $this->ip . ':' . $this->port . '/cgi-bin/api.cgi?cmd=Logout&token=' . urlencode($tok);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, '[{"cmd":"Logout","param":{}}]');
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 1200);
    curl_setopt($ch, CURLOPT_TIMEOUT_MS, 1500);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    @curl_exec($ch);
    curl_close($ch);
  }

  public static function invalidateHostSessions($ip = '') {
    $dir = jeedom::getTmpFolder('reolink');
    if (!is_dir($dir)) {
      return;
    }
    $normIp = trim(strval($ip));
    foreach (glob($dir . '/session_*.json') ?: array() as $file) {
      $raw = @file_get_contents($file);
      $data = is_string($raw) ? json_decode($raw, true) : null;
      if ($normIp !== '' && (!is_array($data) || strval($data['ip'] ?? '') !== $normIp)) {
        continue;
      }
      if (is_array($data) && !empty($data['token']) && intval($data['expiresAt'] ?? 0) > time()) {
        $data['forceRotate'] = 1;
        $tmp = $file . '.' . getmypid() . '.rot.tmp';
        if (@file_put_contents($tmp, json_encode($data)) !== false) {
          @chmod($tmp, 0600);
          @rename($tmp, $file);
          continue;
        }
        @unlink($tmp);
      }
      @unlink($file);
    }
  }

  private function login($forceFresh = false, $staleToken = '') {
    if ($this->authmethod != 0) {
      $this->is_loggedin = true;
      return true;
    }
    if ($this->loginInProgress) {
      return false;
    }
    $this->loginAttempted = true;
    $this->loginBusy = false;

    if (!$this->bypassCooldown && class_exists('reolink') && reolink::isHostInCooldown($this->cnxtype, $this->ip, $this->port)) {
      $this->is_loggedin = false;
      $this->transportFailed = true;
      log::add('reolink', 'debug', 'Login ignoré (disjoncteur actif) pour ' . $this->ip . ':' . $this->port);
      return false;
    }

    if (!$forceFresh && $this->loadCachedToken(120)) {
      return true;
    }

    $lockDir = jeedom::getTmpFolder('reolink');
    if (!is_dir($lockDir)) {
      @mkdir($lockDir, 0770, true);
    }
    $lockPath = $this->loginLockPath();
    $lockFp = @fopen($lockPath, 'c+');
    $locked = false;
    if ($lockFp) {
      // Bounded non-blocking lock (max 3.0s = 15 x 200ms) to prevent PHP/SQL worker pile-up
      for ($attempt = 0; $attempt < 15; $attempt++) {
        if (@flock($lockFp, LOCK_EX | LOCK_NB)) {
          $locked = true;
          break;
        }
        usleep(200000);
        if (!$this->bypassCooldown && class_exists('reolink') && reolink::isHostInCooldown($this->cnxtype, $this->ip, $this->port)) {
          fclose($lockFp);
          $this->is_loggedin = false;
          $this->transportFailed = true;
          log::add('reolink', 'debug', 'Login annulé pendant attente verrou (disjoncteur activé) pour ' . $this->ip . ':' . $this->port);
          return false;
        }
      }
      if (!$locked) {
        fclose($lockFp);
        $this->is_loggedin = false;
        $this->loginBusy = true;
        log::add('reolink', 'debug', 'Login reporté (verrou occupé > 3s) pour ' . $this->ip . ':' . $this->port);
        return false;
      }
    }

    $this->loginInProgress = true;
    try {
      if (!$this->bypassCooldown && class_exists('reolink') && reolink::isHostInCooldown($this->cnxtype, $this->ip, $this->port)) {
        $this->is_loggedin = false;
        $this->transportFailed = true;
        log::add('reolink', 'debug', 'Login ignoré après obtention du verrou (disjoncteur actif) pour ' . $this->ip . ':' . $this->port);
        return false;
      }

      // Double-check after acquiring lock: reuse if another worker already minted a fresh token
      if ($this->loadCachedToken(120, $staleToken)) {
        return true;
      }

      // Cleanly Logout any previous still-unexpired token before minting its replacement (prevents NVR max-session -5)
      $prevEntry = $this->readSessionFile();
      $prevTok = is_array($prevEntry) ? trim(strval($prevEntry['token'] ?? '')) : '';
      $prevExp = is_array($prevEntry) ? intval($prevEntry['expiresAt'] ?? 0) : 0;
      @unlink($this->sessionCachePath());
      if ($prevTok !== '' && ($staleToken === '' || $prevTok !== $staleToken) && $prevExp > time()) {
        log::add('reolink', 'debug', 'Rotation token CGI : fermeture préalable de la session précédente');
        $this->logoutTokenQuiet($prevTok);
      }

      log::add('reolink', 'debug', 'Camera login...');
      $this->is_loggedin = false;
      unset($this->token);
      unset($this->tokenexp);

      $loginParameters = '[{"cmd":"Login","param":{"User":{"userName":"' . $this->user . '","password":"' . $this->password . '"}}}]';
      $response = $this->SendCMD($loginParameters, "Login");

      if (!is_array($response) || empty($response[0]['value']['Token']['name'])) {
        $this->is_loggedin = false;
        if ($this->transportFailed) {
          log::add('reolink', 'debug', 'Echec login : hôte injoignable (' . $this->ip . ':' . $this->port . ')');
        } else {
          log::add('reolink', 'error', 'Echec > Impossible de se logguer sur la caméra (' . $this->ip . ':' . $this->port . ')');
        }
        return false;
      }

      $this->token = $response[0]['value']['Token']['name'];
      $leaseSec = max(60, intval($response[0]['value']['Token']['leaseTime'] ?? 3600));
      $this->tokenexp = time() + $leaseSec;
      $this->is_loggedin = true;
      $this->transportFailed = false;
      $this->saveCachedToken($this->token, $this->tokenexp);
      log::add('reolink', 'debug', 'Token session OK (leaseUntil=' . $this->tokenexp . ')');
      return true;
    } finally {
      $this->loginInProgress = false;
      if ($lockFp && $locked) {
        @flock($lockFp, LOCK_UN);
        fclose($lockFp);
      }
    }
  }

  public function logout() {
    $tok = strval($this->token ?? '');
    if ($tok !== '') {
      $this->invalidateCachedToken($tok);
    }
    if ($this->authmethod != 0 || $tok === '' || $this->transportFailed) {
      $this->is_loggedin = false;
      unset($this->token);
      unset($this->tokenexp);
      return true;
    }

    log::add('reolink', 'debug', 'Camera logout...');
    $payload = '[{"cmd":"Logout","param":{}}]';
    $this->request('cmd=Logout&token=' . urlencode($tok), $payload);
    $this->is_loggedin = false;
    unset($this->token);
    unset($this->tokenexp);
    return true;
  }

  public function SendSnap($channel = 0, $snapType = 'sub') {
    if (!$this->bypassCooldown && class_exists('reolink') && reolink::isHostInCooldown($this->cnxtype, $this->ip, $this->port)) {
      return false;
    }
    if ($this->authmethod == 0 && !$this->is_loggedin) {
      // Avoid a second login attempt if constructor already tried and failed
      if ($this->loginAttempted || $this->transportFailed || $this->loginBusy) {
        return false;
      }
      if (!$this->login()) {
        return false;
      }
    }
    $snapType = ($snapType === 'main') ? 'main' : 'sub';
    for ($snapAttempt = 0; $snapAttempt < 2; $snapAttempt++) {
      if ($this->authmethod == 0) {
        $auth = 'token=' . urlencode(strval($this->token ?? ''));
      } else {
        $auth = 'user=' . urlencode($this->user) . '&password=' . urlencode($this->password);
      }
      $url = $this->cnxtype . '://' . $this->ip . ':' . $this->port
        . '/cgi-bin/api.cgi?cmd=Snap&channel=' . intval($channel)
        . '&snapType=' . $snapType . '&' . $auth;
      $ch = curl_init($url);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
      curl_setopt($ch, CURLOPT_TIMEOUT, 6);
      curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
      curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
      $data = curl_exec($ch);
      $code = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
      $ctype = strval(curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
      $snapErr = curl_error($ch);
      curl_close($ch);
      if ($code === 0 || $code >= 500) {
        $staleTok = strval($this->token ?? '');
        $this->transportFailed = true;
        $this->is_loggedin = false;
        unset($this->token);
        unset($this->tokenexp);
        if ($staleTok !== '') {
          $this->invalidateCachedToken($staleTok);
        }
        if (class_exists('reolink')) {
          reolink::recordHostFailure(
            $this->cnxtype,
            $this->ip,
            $this->port,
            self::summarizeHttpFailure($code, $snapErr, is_string($data) ? substr($data, 0, 200) : '')
          );
        }
        return false;
      }
      if ($code === 200 && $data !== false && $data !== '' && stripos($ctype, 'image/') !== false) {
        if (class_exists('reolink')) {
          reolink::recordHostSuccess($this->cnxtype, $this->ip, $this->port);
        }
        return $data;
      }
      if ($snapAttempt === 0 && $this->authmethod == 0 && $code === 200) {
        $staleTok = strval($this->token ?? '');
        $this->invalidateCachedToken($staleTok);
        $this->is_loggedin = false;
        unset($this->token);
        unset($this->tokenexp);
        if ($this->login(true, $staleTok)) {
          continue;
        }
      }
      log::add('reolink', 'debug', 'SendSnap failed HTTP=' . $code . ' ctype=' . $ctype . ' len=' . strlen(strval($data)));
      return false;
    }
    return false;
  }

  public function SendCMD($POSTparameters, $URLrequest = NULL) {
    $isAuthCmd = (strpos($POSTparameters, 'cmd":"Login') !== false
      || strpos($POSTparameters, 'cmd":"Logout') !== false);
    if (!$this->is_loggedin && !$isAuthCmd) {
      log::add('reolink', 'error', 'Envoi de la commande impossible non loggué sur la caméra');
      return false;
    }

    // Send Request

    if (!is_json($POSTparameters)) {
      log::add('reolink', 'error', 'Format du payload POST n\'est pas un JSON valide, envoi commande échoué');
      return false;
    }

    if (isset($URLrequest)) {
      log::add('reolink', 'debug', 'Ajout URL request = ' . $URLrequest);
      $URLrequest = "cmd=" . $URLrequest . "&";
    }

    $this->skipRetry = false;
    $this->privacyWakeRetry = false;
    $this->lastResponseData = null;
    $sentItems = json_decode($POSTparameters, true);
    if (!is_array($sentItems)) {
      $sentItems = null;
    }
    $maxRetry = 0;
    do {
      // Rebuild auth each attempt (token may be renewed after rspCode -6)
      if ($this->authmethod == 0) {
        $authURL = 'token=' . ($this->token ?? '');
      } else {
        $authURL = 'user=' . $this->user . '&password=' . $this->password;
      }
      $response = $this->request($URLrequest . $authURL, $POSTparameters);
      $checkedresponse = $this->checkResponse($response, $URLrequest, $sentItems);

      if ($checkedresponse !== false) {
        break;
      }
      if (!empty($this->skipRetry) || $this->probeMode) {
        break;
      }
      $maxRetry++;
      // reolink_aio: wait ~2s between retries while camera wakes from privacy
      if (!empty($this->privacyWakeRetry)) {
        usleep(2000000);
        $this->privacyWakeRetry = false;
      }
      log::add('reolink', 'debug', 'Echec de la requête nouvelle tentative...');
    } while ($maxRetry < 3);

    // Battery camera waking from sleep (reolink_aio: retry -12/-13/-17 once after 1.0s)
    if ($this->batteryChannelMode && is_array($sentItems)) {
      $resData = is_array($checkedresponse) ? $checkedresponse : $this->lastResponseData;
      if (is_array($resData)) {
        $retryCmds = array();
        $retryIdxs = array();
        foreach ($resData as $idx => $item) {
          $rsp = intval($item['error']['rspCode'] ?? 0);
          if (in_array($rsp, array(-12, -13, -17), true) && isset($sentItems[$idx])) {
            $retryCmds[] = $sentItems[$idx];
            $retryIdxs[] = $idx;
          }
        }
        if (!empty($retryCmds)) {
          log::add('reolink', 'debug', 'Caméra batterie en réveil : nouvelle tentative après 1s (' . count($retryCmds) . ' cmd)');
          usleep(1000000);
          $prevMode = $this->batteryChannelMode;
          $this->batteryChannelMode = false;
          $retryResp = $this->SendCMD(json_encode($retryCmds));
          $retryData = is_array($retryResp) ? $retryResp : $this->lastResponseData;
          $this->batteryChannelMode = $prevMode;
          if (is_array($retryData)) {
            foreach ($retryIdxs as $pos => $origIdx) {
              if (isset($retryData[$pos])) {
                $resData[$origIdx] = $retryData[$pos];
              }
            }
            $this->lastResponseData = $resData;
            $anyOk = false;
            foreach ($resData as $it) {
              if (!isset($it['error']['rspCode'])) {
                $anyOk = true;
                break;
              }
            }
            if ($anyOk) {
              $checkedresponse = $resData;
            }
          }
        }
      }
    }

    if (strpos($POSTparameters, '"Reboot"') !== false && $checkedresponse !== false) {
      $this->invalidateCachedToken(strval($this->token ?? ''));
    }

    return $checkedresponse;
  }

  /*
    Check Response validity
    */
  private function checkResponse($response, $command, $sentItems = null) {
    if ($response === false) {
      $this->skipRetry = $this->skipRetry || $this->transportFailed || $this->probeMode;
      $this->lastResponseData = null;
      return false;
    }

    $data = json_decode($response, true);
    $this->skipRetry = false;
    $this->lastResponseData = (is_array($data) && isset($data[0])) ? $data : null;
    $noRetryCodes = array(-1, -4, -5, -9, -12, -13, -17, -23, -24, -26, -99);

    // HTTP 200 with empty or invalid JSON body
    if (!$data || !isset($data[0])) {
      $this->skipRetry = true;
      if (!$this->probeMode) {
        log::add('reolink', 'warning', 'Requête vers la caméra en erreur : réponse JSON vide ou invalide');
      }
      return false;
    }

    $hasOk = false;
    $allNoRetry = true;
    foreach ($data as $idx => $item) {
      if (!isset($item['error']['rspCode'])) {
        $hasOk = true;
        $allNoRetry = false;
        continue;
      }
      $rsp = intval($item['error']['rspCode']);
      if ($rsp == -6 || $rsp == -21) {
        $staleTok = strval($this->token ?? '');
        $this->invalidateCachedToken($staleTok);
        $this->is_loggedin = false;
        unset($this->token);
        unset($this->tokenexp);
        if ($this->login(true, $staleTok)) {
          $this->skipRetry = false;
        } else {
          $this->skipRetry = true;
        }
        return false;
      }
      if (!in_array($rsp, $noRetryCodes, true)) {
        $allNoRetry = false;
      }
      $label = $this->formatApiError($item);
      $cmdName = $item['cmd'] ?? '';
      if ($cmdName === '' || $cmdName === 'Unknown') {
        $cmdName = (is_array($sentItems) && isset($sentItems[$idx]['cmd'])) ? $sentItems[$idx]['cmd'] : $command;
      }
      log::add('reolink', 'debug', $cmdName . ' ignoré : ' . $label);
    }
    if (!$hasOk) {
      $rsp = intval($data[0]['error']['rspCode'] ?? 0);
      $label = $this->formatApiError($data[0]);
      if ($this->probeMode) {
        log::add('reolink', 'debug', 'Probe lot refusé : ' . $label);
      } else {
        $level = in_array($rsp, $noRetryCodes, true) ? 'warning' : 'error';
        log::add('reolink', $level, 'Requête vers la caméra en erreur. Réponse de la caméra : ' . $label);
      }
      $this->skipRetry = $allNoRetry || $this->probeMode;
      return false;
    }

    return $data;
  }

  public function formatApiError($item) {
    $rsp = intval($item['error']['rspCode'] ?? 0);
    $detail = trim(strval($item['error']['detail'] ?? ''));
    $errorNFO = $this->GetErrorNFO($rsp);
    if (!is_array($errorNFO)) {
      $errorNFO = array('unknown', 'Unknown error');
    }
    $codeSuffix = ($rsp !== 0) ? ', rspCode ' . $rsp : '';
    if ($detail !== '') {
      return $detail . ' (' . $errorNFO[1] . $codeSuffix . ')';
    }
    return $errorNFO[0] . '/' . $errorNFO[1] . ($rsp !== 0 ? ' (rspCode ' . $rsp . ')' : '');
  }

  public function getLastApiError() {
    if (!is_array($this->lastResponseData)) {
      return '';
    }
    foreach ($this->lastResponseData as $item) {
      if (isset($item['error']['rspCode'])) {
        return $this->formatApiError($item);
      }
      if (isset($item['value']['rspCode']) && intval($item['value']['rspCode']) !== 200) {
        return 'rspCode ' . intval($item['value']['rspCode']);
      }
    }
    return '';
  }

  private function GetErrorNFO($errorCode) {
    switch ($errorCode) {
      case -1:
        return array('not exist', 'Missing parameters');
      case -2:
        return array('out of mem', 'Used up memory');
      case -3:
        return array('check err', 'Check error');
      case -4:
        return array('param error', 'Parameters error');
      case -5:
        return array('max session', 'Reached the max session number.');
      case -6:
        return array('please login first', 'Login required');
      case -7:
        return array('login failed', 'Login error');
      case -8:
        return array('timeout', 'Operation timeout');
      case -9:
        return array('not support', 'Not supported');
      case -10:
        return array('protocol', 'Protocol error');
      case -11:
        return array('fcgi read failed', 'Failed to read operation');
      case -12:
        return array('get config failed', 'Failed to get configuration.');
      case -13:
        return array('set config failed', 'Failed to set configuration.');
      case -14:
        return array('malloc failed', 'Failed to apply for memory');
      case -15:
        return array('create socket failed', 'Failed to created socket');
      case -16:
        return array('send failed', 'Failed to send data');
      case -17:
        return array('rcv failed', 'Failed to receiver data');
      case -18:
        return array('open file failed', 'Failed to open file');
      case -19:
        return array('read file failed', 'Failed to read file');
      case -20:
        return array('write file failed', 'Failed to write file');
      case -21:
        return array('error token', 'Token error');
      case -22:
        return array('The length of the string exceeds the limit', 'The length of the string exceeds the limitmation');
      case -23:
        return array('missing param', 'Missing parameters');
      case -24:
        return array('error command', 'Command error');
      case -25:
        return array('internal error', 'Internal error');
      case -26:
        return array('ability error', 'Ability error');
      case -27:
        return array('invalid user', 'Invalid user');
      case -28:
        return array('user already exist', 'User already exist');
      case -29:
        return array('maximum number of users', 'Reached the maximum number of users');
      case -30:
        return array('same version', 'The version is identical to the current one.');
      case -31:
        return array('busy', 'Ensure only one user can upgrade');
      case -32:
        return array('ip conflict', 'Modify IP conflicted with used IP');
      case -34:
        return array('need bing email', 'Cloud login need bind email first');
      case -35:
        return array('unbind', 'Cloud login unbind camera');
      case -36:
        return array('network timeout', 'Cloud login get login information out of time');
      case -37:
        return array('password err', 'Cloud login password error');
      case -38:
        return array('uid err', 'Cloud bind camera uid error');
      case -39:
        return array('user not exist', 'Cloud login user doesn’t exist');
      case -40:
        return array('unbind failed', 'Cloud unbind camera failed');
      case -41:
        return array('cloud not support', 'The device doesn’t support cloud');
      case -42:
        return array('login cloud server failed', 'Cloud login server failed');
      case -43:
        return array('bind failed', 'Cloud bind camera failed');
      case -44:
        return array('cloud unknown err', 'Cloud unknown error');
      case -45:
        return array('need verify code', 'Cloud bind camera need verify code');
      case -48:
        return array('Fetching a picture failed', 'Snap a picture failed');
      case -49:
        return array('Channel invalid', 'Channel is invalid');
      case -99:
        return array('device offline', 'Device offline');
      case -100:
        return array('test failed', 'Test Email/Ftp/Wifi failed');
      case -101:
        return array('check firmware failed', 'Upgrade checking firmware failed');
      case -102:
        return array('download online failed', 'Upgrade download online failed');
      case -103:
        return array('get upgrade status failed', 'Upgrade get upgrade status failed');
      case -105:
        return array('Frequent logins, please try again later!', 'Frequent logins');
      case -451:
        return array('ftp login failed', 'ftp test login failed');
      case -452:
        return array('ftp create dir failed', 'Creat ftp dir failed');
      case -453:
        return array('ftp upload failed', 'Upload ftp file failed');
      case -454:
        return array('ftp connect failed', 'Cannot connect ftp server');
      case -480:
        return array('email undefined failed', 'Some undifined errors');
      case -481:
        return array('email connect failed', 'Cannot connect email server');
      case -482:
        return array('email auth failed', 'Auth user failed');
      case -483:
        return array('email network err', 'Email network err');
      case -484:
        return array('email server err', 'Something wrong with email server ');
      case -485:
        return array('email memory err', 'Something wrong with memory');
    }
  }
}
