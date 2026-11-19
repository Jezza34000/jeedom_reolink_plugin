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

const CMD_SEND_QTY = 8;

class reolink extends eqLogic {

  const PYTHON_PATH = __DIR__ . '/../../resources/venv/bin/python3';

  /************* Static methods ************/
  private static $cnxByIp = array();

  public static function getReolinkConnection($id) {
    $camera = reolink::byId($id, 'reolink');
    $adresseIP = $camera->getConfiguration('adresseip');
    $port = $camera->getConfiguration('port');
    $username = $camera->getConfiguration('login');
    $password = $camera->getConfiguration('password');
    $cnxtype = $camera->getConfiguration('cnxtype');

    if (!empty($adresseIP) && !empty($username) && !empty($password)) {
      $cacheKey = $adresseIP . '|' . strval($port);
      if (isset(self::$cnxByIp[$cacheKey]) && is_object(self::$cnxByIp[$cacheKey])) {
        return self::$cnxByIp[$cacheKey];
      }
      $cnxinfo = array("adresseIP" => $adresseIP, "port" => $port, "username" => $username, "password" => $password, "cnxtype" => $cnxtype);
      $camcnx = new reolinkAPI($cnxinfo);
      self::$cnxByIp[$cacheKey] = $camcnx;
      return $camcnx;
    } else {
      log::add('reolink', 'warning', "Information de connexion manquantes : connexion à la caméra impossible");
      return NULL;
    }
  }

  public function getChannel() {
    return intval($this->getConfiguration('defined_channel', 0));
  }

  public function isNvrParent() {
    $defCh = $this->getConfiguration('defined_channel', '');
    if ($defCh !== '' && $defCh !== null) {
      return false;
    }
    return ($this->getConfiguration('exactType') === 'NVR'
      || intval($this->getConfiguration('channelNum', 1)) > 1);
  }

  public static function TryConnect($id) {
    $reolinkConn = reolink::getReolinkConnection($id);
    if ($reolinkConn->is_loggedin == true) {
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
    $channel = $camera->getChannel();

    // Devices Info
    $reolinkConn = reolink::getReolinkConnection($id);
    if (!is_object($reolinkConn)) {
      return false;
    }
    $deviceInfo = $reolinkConn->SendCMD('[{"cmd":"GetDevInfo"},{"cmd":"GetP2P"},{"cmd":"GetLocalLink"},{"cmd":"GetAiState","action":0,"param":{"channel":' . $channel . '}},{"cmd":"GetNetPort"}]');

    if (!$deviceInfo || !isset($deviceInfo[0]['value']['DevInfo'])) {
      return false;
    }

    foreach ($deviceInfo[0]['value']["DevInfo"] as $key => $value) {

      log::add('reolink', 'debug', 'Enregistrement : K=' . $key . ' V=' . $value);
      $camera->setConfiguration($key, $value);

      if ($key == "model") {
        // Download CAM img ICON (cosmetic; never abort GetCamNFO on CDN failure)
        $modelURL = urlencode($value);
        $iconurl = "https://cdn.reolink.com/wp-content/assets/app/model-images/$modelURL/light_off.png";
        $camera->setConfiguration("camicon", $iconurl);

        $dir = realpath(dirname(__FILE__) . '/../../desktop');

        if (!file_exists($dir . '/img')) {
          mkdir($dir . '/img', 0775, true);
          log::add('reolink', 'debug', 'Création du répertoire visuel caméra = ' . $dir . '/img');
        }

        $fileToWrite = $dir . '/img/camera' . $id . '.png';

        log::add('reolink', 'debug', 'Enregistrement du visuel de la caméra ' . $value . ' depuis serveur Reolink (' . $iconurl . ' => ' . $fileToWrite . ')');

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
        if ($httpcode == 200 && $rawdata !== false) {
          log::add('reolink', 'debug', 'HTTP code 200 OK');
          $fp = fopen($fileToWrite, 'w');
          fwrite($fp, $rawdata);
          fclose($fp);
          log::add('reolink', 'debug', 'Ecriture OK');
        } else {
          log::add('reolink', 'warning', 'Icône caméra CDN HTTP ' . $httpcode . ' (ignoré) ' . curl_error($ch));
        }
        curl_close($ch);
      }
    }

    // Alone value
    if (isset($deviceInfo[1]['value']['P2p']['uid'])) {
      $value = $deviceInfo[1]['value']["P2p"]['uid'];
      log::add('reolink', 'debug', 'Enregistrement : K=uid V=' . $value);
      $camera->setConfiguration("uid", $value);
    }

    if (isset($deviceInfo[2]['value']['LocalLink']['activeLink'])) {
      $value = $deviceInfo[2]['value']["LocalLink"]['activeLink'];
      log::add('reolink', 'debug', 'Enregistrement : K=linkconnection V=' . $value);
      $camera->setConfiguration("linkconnection", $value);
    }

    // Check if cam is AI (channel-aware)
    if (isset($deviceInfo[3]['value'])) {
      $camera->setConfiguration("supportai", "Oui");
    } else {
      $camera->setConfiguration("supportai", "Non");
    }

    // Save cam port
    if (isset($deviceInfo[4]['value']['NetPort']) && is_array($deviceInfo[4]['value']['NetPort'])) {
      foreach ($deviceInfo[4]['value']['NetPort'] as $key => $value) {
        log::add('reolink', 'debug', 'Enregistrement : K=' . $key . ' V=' . $value);

        if (strpos($key, 'Enable') !== false) {
          $value = str_replace("0", "(désactiver)", $value);
          $value = str_replace("1", "(actif)", $value);
        }

        $camera->setConfiguration($key, $value);
      }
    }


    log::add('reolink', 'debug', 'GetDeviceInfo ajout de ' . count($deviceInfo[0]['value']["DevInfo"]) . ' items');
    if (count($deviceInfo[0]['value']["DevInfo"]) > 1) {
      $camera->Save();
      return true;
    } else {
      return false;
    }
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

    // NVR E1 Outdoor: GetAbility may report ptzPatrol.permit=0 while Get/SetPtzPatrol work
    $ptzPatrolPermit = intval($deviceAbility['ptzPatrol']['permit'] ?? 0);
    $ptzPresetPermit = intval($deviceAbility['ptzPreset']['permit'] ?? 0);
    if ($ptzPatrolPermit == 0 && $ptzPresetPermit != 0) {
      $patrol = $reolinkConn->SendCMD('[{"cmd":"GetPtzPatrol","action":0,"param":{"channel":' . $channel . '}}]');
      if (is_array($patrol) && isset($patrol[0]['value']['PtzPatrol']) && is_array($patrol[0]['value']['PtzPatrol'])) {
        $deviceAbility['ptzPatrol'] = array('permit' => 1, 'ver' => intval($deviceAbility['ptzPatrol']['ver'] ?? 1));
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

    log::add('reolink', 'debug', 'GetAbility à récupérer : ' . count($deviceAbility) . ' items (canal ' . $channel . ')');
    return count($deviceAbility) > 1 ? $deviceAbility : false;
  }

  public static function updatePTZpreset($id, $data) {
    $camera = reolink::byId($id, 'reolink');
    $cmd = $camera->getCmd(null, 'SetPtzByPreset');
    $ptzlist = "";
    log::add('reolink', 'debug',  'PTZ à parser = ' . print_r($data['value']['PtzPreset'], true));
    if (is_object($cmd) && is_array($data)) {
      foreach ($data['value']['PtzPreset']  as $key => $value) {
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

  public static function applyPtzPatrolState($camera, $data) {
    if (!is_object($camera) || !is_array($data) || !isset($data['value']['PtzPatrol'])) {
      return;
    }
    $list = '';
    $running = 0;
    $activeId = null;
    foreach ($data['value']['PtzPatrol'] as $patrol) {
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
        $patrols = $item['value']['PtzPatrol'];
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
      throw new Exception(__('Échec SetPtzPatrol', __FILE__));
    }
    $refresh = $cnx->SendCMD('[{"cmd":"GetPtzPatrol","action":0,"param":{"channel":' . $channel . '}}]');
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
      throw new Exception(__('Échec PtzCtrl ' . $op, __FILE__));
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

  public static function refreshNFO($id) {
    $camcmd = reolink::byId($id, 'reolink');
    if (!is_object($camcmd) || $camcmd->isNvrParent()) {
      log::add('reolink', 'debug', 'refreshNFO ignoré : équipement NVR parent ou introuvable');
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
    foreach (reolinkCmd::byEqLogicId($id) as $cmd) {
      $payload = $cmd->getConfiguration('payload');
      if ($cmd->getType() == "info" && $payload != NULL) {
        $payload = str_replace('#CHANNEL#', $channel, $payload);
        $payload = str_replace('\\', '', $payload);
        if (isset($unsupportedMap[$payload])) {
          continue;
        }
        if (!in_array($payload, $cmdarr)) {
          $cmdarr[] = $payload;
        }
      }
    }
    if (empty($cmdarr)) {
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
            $camcmd->checkAndUpdateCmd('SetRecordStateV20', $json_data['value']['Rec']['enable']);
            if (isset($json_data['value']['Rec']['preRec'])) {
              $camcmd->checkAndUpdateCmd('SetPreRecordStateV20', $json_data['value']['Rec']['preRec']);
            }
            $camcmd->checkAndUpdateCmd('SetOverwriteStateV20', $json_data['value']['Rec']['overwrite']);
            $camcmd->checkAndUpdateCmd('SetPostRecordStateV20', $json_data['value']['Rec']['postRec']);
            break;

          case reolinkAPI::CAM_GET_HDDINFO:
            if ($json_data['value']['HddInfo'][0]['format'] == 1 && $json_data['value']['HddInfo'][0]['mount'] == 1) {
              $camcmd->checkAndUpdateCmd('driveAvailable', 1);
            } else {
              $camcmd->checkAndUpdateCmd('driveAvailable', 0);
            }
            if (is_numeric($json_data['value']['HddInfo'][0]['size']) && is_numeric($json_data['value']['HddInfo'][0]['capacity'])) {
              $percoccupancy = round(($json_data['value']['HddInfo'][0]['size'] * 100) / $json_data['value']['HddInfo'][0]['capacity'], 0, PHP_ROUND_HALF_DOWN);
              $camcmd->checkAndUpdateCmd('driveSpaceAvailable', $percoccupancy);
            }
            if ($json_data['value']['HddInfo'][0]['storageType'] == 1) {
              $camcmd->checkAndUpdateCmd('driveType', "HDD");
            } elseif ($json_data['value']['HddInfo'][0]['storageType'] == 2) {
              $camcmd->checkAndUpdateCmd('driveType', "Sdcard");
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
            $camcmd->checkAndUpdateCmd('SetFTPStateV20', $json_data['value']['Ftp']['enable']);
            break;

          case reolinkAPI::CAM_GET_PUSH:
            $camcmd->checkAndUpdateCmd('SetPushState', $json_data['value']['Push']['schedule']['enable']);
            break;

          case reolinkAPI::CAM_GET_PUSHV20:
            $camcmd->checkAndUpdateCmd('SetPushStateV20', $json_data['value']['Push']['enable']);
            break;

          case reolinkAPI::CAM_GET_PUSHCFG:
            $camcmd->checkAndUpdateCmd('SetPushCfgState', $json_data['value']['PushCfg']['pushInterval']);
            break;

          case reolinkAPI::CAM_GET_EMAIL:
            $camcmd->checkAndUpdateCmd('SetEmailState', $json_data['value']['Email']['schedule']['enable']);
            break;

          case reolinkAPI::CAM_GET_EMAILV20:
            $camcmd->checkAndUpdateCmd('SetEmailStateV20', $json_data['value']['Email']['enable']);
            break;

          case reolinkAPI::CAM_GET_ENC:
            $audio = $json_data['value']['Enc']['audio'] ?? ($json_data['value']['audio'] ?? null);
            if ($audio !== null) {
              $camcmd->checkAndUpdateCmd('SetMicrophoneState', $audio);
            }
            $camcmd->checkAndUpdateCmd('SetResolutionst1State', $json_data['value']['Enc']['mainStream']['size']);
            $camcmd->checkAndUpdateCmd('SetFPSst1State', $json_data['value']['Enc']['mainStream']['size']);
            $camcmd->checkAndUpdateCmd('SetBitratest1State', $json_data['value']['Enc']['mainStream']['bitRate']);
            $camcmd->checkAndUpdateCmd('SetResolutionst2State', $json_data['value']['Enc']['subStream']['size']);
            $camcmd->checkAndUpdateCmd('SetFPSst2State', $json_data['value']['Enc']['subStream']['size']);
            $camcmd->checkAndUpdateCmd('SetBitratest2State', $json_data['value']['Enc']['subStream']['size']);
            break;

          case reolinkAPI::CAM_GET_ISP:
            $camcmd->checkAndUpdateCmd('SetRotationState', $json_data['value']['Isp']['rotation']);
            $camcmd->checkAndUpdateCmd('SetMirroringState', $json_data['value']['Isp']['mirroring']);
            $camcmd->checkAndUpdateCmd('SetAntiFlickerState', $json_data['value']['Isp']['antiFlicker']);
            $camcmd->checkAndUpdateCmd('SetBackLightState', $json_data['value']['Isp']['backLight']);
            $camcmd->checkAndUpdateCmd('SetBlcState', $json_data['value']['Isp']['blc']);
            $camcmd->checkAndUpdateCmd('SetBlueGainState', $json_data['value']['Isp']['blueGain']); // ???
            $camcmd->checkAndUpdateCmd('SetDayNightState', $json_data['value']['Isp']['dayNight']);
            $camcmd->checkAndUpdateCmd('SetDrcState', $json_data['value']['Isp']['drc']);
            $camcmd->checkAndUpdateCmd('SetNr3dState', $json_data['value']['Isp']['nr3d']);
            $camcmd->checkAndUpdateCmd('SetRedGainState', $json_data['value']['Isp']['redGain']); // ???
            $camcmd->checkAndUpdateCmd('SetWhiteBalanceState', $json_data['value']['Isp']['whiteBalance']); // ???
            $camcmd->checkAndUpdateCmd('SetExposureState', $json_data['value']['Isp']['exposure']); // ???
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
            // Not supported for now
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
            $camcmd->checkAndUpdateCmd('SetaiTrackState', $json_data['value']['aiTrack']);
            break;

          case reolinkAPI::CAM_GET_MDALARM:
            $revert_value = reolinkCmd::byEqLogicIdAndLogicalId($id, 'SetMdDefaultSensitivityState')->getConfiguration('revertvalue', 0);
            $mdsensdef = $revert_value - $json_data['value']['MdAlarm']['newSens']['sensDef'];
            $camcmd->checkAndUpdateCmd('SetMdDefaultSensitivityState', $mdsensdef);
            break;

          case reolinkAPI::CAM_GET_AIALARM:
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
            }
            $camcmd->checkAndUpdateCmd($s1, $json_data['value']['AiAlarm']['sensitivity']);
            $camcmd->checkAndUpdateCmd($s2, $json_data['value']['AiAlarm']['stay_time']);
            log::add('reolink', 'debug', 'ai_type check : ' . $json_data['value']['AiAlarm']['ai_type']);
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
      if ($camera->isNvrParent()) {
        continue;
      }
      $autorefresh = $camera->getConfiguration('autorefresh', '*/15 * * * *');
      if ($autorefresh != '') {
        try {
          $c = new Cron\CronExpression(checkAndFixCron($autorefresh), new Cron\FieldFactory);
          if ($c->isDue()) {
            log::add('reolink', 'debug', '#### CRON refresh ' . $camera->getHumanName());
            $camera->refreshNFO($camera->getId());
          }
          #Reset detection info if daemon is down
          $deamon_info = self::deamon_info();
          if ($deamon_info['state'] != 'ok') {
            $camera->checkAndUpdateCmd('MdState', 0);
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

      $camera_contact_point = $camera->getConfiguration('adresseip');
      if (filter_var($camera_contact_point, FILTER_VALIDATE_IP)) {
        $camera_ip = $camera_contact_point;
      } else {
        $camera_ip = gethostbyname($camera_contact_point);
      }


      $port_onvif = $camera->getConfiguration('port_onvif');
      if ($port_onvif == "") {
        $port_onvif = "8000";
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
    while ($i < 20) {
      $deamon_info = self::deamon_info();
      if ($deamon_info['state'] == 'ok') {
        break;
      }
      sleep(1);
      $i++;
    }
    if ($i >= 30) {
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
      system::kill($pid);
    }
    system::kill('reolinkd.py');
    sleep(1);
  }

  public static function sendToDaemon($params) {
    $deamon_info = self::deamon_info();
    if ($deamon_info['state'] != 'ok') {
      log::add('reolink', 'error', 'Envoi des infos de webhook impossible le daemon n\'est pas démarré');
      return False;
    }
    $params['apikey'] = jeedom::getApiKey(__CLASS__);
    $payLoad = json_encode($params);
    $socket = socket_create(AF_INET, SOCK_STREAM, 0);
    socket_connect($socket, '127.0.0.1', config::byKey('socketport', __CLASS__, '44009'));
    socket_write($socket, $payLoad, strlen($payLoad));
    socket_close($socket);
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
    if (file_exists(jeedom::getTmpFolder(__CLASS__) . '/dependance')) {
      $return['state'] = 'in_progress';
    } elseif (!file_exists(self::PYTHON_PATH)) {
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
    );
    foreach ($fixes as $logicalId => $newPayload) {
      $cmd = $this->getCmd(null, $logicalId);
      if (!is_object($cmd)) {
        continue;
      }
      $cur = strval($cmd->getConfiguration('payload'));
      if ($cur === '' || strpos($cur, '#CHANNEL#') !== false || strpos($cur, 'channel') !== false) {
        continue;
      }
      $cmd->setConfiguration('payload', $newPayload);
      $cmd->save();
      log::add('reolink', 'info', 'Payload legacy mis à jour : ' . $logicalId);
    }
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
          'cmd' => is_array($item) ? ($item['cmd'] ?? null) : null,
        );
      }
    }
    $cnx->probeMode = false;
    return $results;
  }

  public static function isPermanentUnsupportedRsp($rsp) {
    return in_array(intval($rsp), array(-1, -4, -9, -12, -17, -24, -26), true);
  }

  public function loadCmdFromConf($id, $pruneUnsupported = true) {
    $camera = reolink::byId($id, 'reolink');
    if (!is_object($camera) || $camera->isNvrParent()) {
      log::add('reolink', 'info', 'CreateCMD ignoré : équipement NVR parent');
      return false;
    }

    $camera->migrateLegacyPayloads();
    $camera->setConfiguration('needs_cmd_resync', 1);
    $camera->save();

    $devAbilityReturn = reolink::GetCamAbility($id);
    if (!$devAbilityReturn) {
      log::add('reolink', 'debug', 'Erreur lors de l\'obtention des capacités hardware/software de la caméra (resync différé)');
      return false;
    }

    $cnx = reolink::getReolinkConnection($id);
    if (!is_object($cnx) || $cnx->is_loggedin == false) {
      log::add('reolink', 'debug', 'Caméra hors ligne pour loadCmdFromConf (resync différé)');
      return false;
    }

    $channel = $camera->getChannel();
    $mdProbe = $cnx->SendCMD('[{"cmd":"GetMdState","action":0,"param":{"channel":' . $channel . '}}]');
    $channelOnline = is_array($mdProbe) && isset($mdProbe[0]['value']['state']);
    if (!$channelOnline) {
      $mdRsp = intval(($cnx->lastResponseData[0]['error']['rspCode'] ?? 0));
      log::add('reolink', 'info', 'Canal ' . $channel . ' hors ligne (rsp=' . $mdRsp . '), nettoyage reporté');
      return false;
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

    $probeList = array();
    foreach ($dualFamilies as $fam) {
      $probeList[] = str_replace('#CHANNEL#', $channel, $fam['probeV20']);
      $probeList[] = str_replace('#CHANNEL#', $channel, $fam['probeV1']);
    }
    // Collect unique Get* payloads from JSON candidates with ability OK
    $payloadByLogical = array();
    foreach ($device['commands'] as $command) {
      if (($command['type'] ?? '') !== 'info') {
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
      $payloadByLogical[$command['logicalId']] = $payload;
    }
    $probeList = array_values(array_unique($probeList));
    $probeResults = reolink::probePayloads($cnx, $probeList);

    // Resolve dual families
    $allowedLogical = array();
    $deniedLogical = array();
    foreach ($dualFamilies as $abilityKey => $fam) {
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
    $createdLogical = array();

    foreach ($device['commands'] as $command) {
      $logicalId = $command['logicalId'] ?? null;
      if ($logicalId === null) {
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
          if (reolink::isPermanentUnsupportedRsp($probeResults[$payload]['rsp'])) {
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
        // Refresh payload from JSON if present
        if ($payloadTpl) {
          $cmd->setConfiguration('payload', $payloadTpl);
          $cmd->save();
        }
        $createdLogical[$logicalId] = true;
        log::add('reolink', 'debug', 'Commande déjà présente : ' . $command['name']);
        continue;
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
      $createdLogical[$logicalId] = true;
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
        // Never prune refresh / ChannelOnline / ONVIF event infos without payload
        if (in_array($lid, array('refresh', 'ChannelOnline', 'EvMotion', 'EvFaceDetect', 'EvPeopleDetect', 'EvVehicleDetect', 'EvDogCatDetect', 'EvMotionAlarm', 'EvVisitor', 'EvLastOnvifFull', 'EvLastOnvifName', 'EvLastOnvifState', 'MdState'), true)) {
          continue;
        }
        $payloadTpl = $existing->getConfiguration('payload');
        $payload = $payloadTpl ? str_replace('#CHANNEL#', $channel, str_replace('\\', '', $payloadTpl)) : null;
        $probeFailed = ($payload !== null && isset($probeResults[$payload]) && !$probeResults[$payload]['ok']
          && reolink::isPermanentUnsupportedRsp($probeResults[$payload]['rsp']));

        $shouldRemove = isset($deniedLogical[$lid]) || $probeFailed;
        // Also remove if ability/subability from JSON would deny (match by logicalId in conf)
        if (!$shouldRemove) {
          foreach ($device['commands'] as $command) {
            if (($command['logicalId'] ?? '') !== $lid) {
              continue;
            }
            if (!reolink::isAbilityOk($devAbilityReturn, $command['abilityneed'] ?? 'none', $command['subability'] ?? null)) {
              $shouldRemove = true;
            }
            break;
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

    $camera->setConfiguration('unsupported_payloads', array_keys(array_merge($unsupportedPayloads, $keptUnsupported)));
    $camera->setConfiguration('unsupported_fail_counts', array());
    $camera->setConfiguration('needs_cmd_resync', 0);
    $camera->save();
    return $cmd_order;
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
        reolink::refreshNFO($EqId);
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
      default:
        $camcnx = reolink::getReolinkConnection($EqId);
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

        if ($actionAPI != NULL) {
          $payload = str_replace('\\', '', $this->getConfiguration('payload'));
          $payload = str_replace('#OPTSELECTEDINT#', intval($_options['select']), $payload);
          $payload = str_replace('#OPTSELECTEDSTR#', '"' . $_options['select'] . '"', $payload);
          $payload = str_replace('#OPTSLIDER#', intval($_options['slider']), $payload);
          $payload = str_replace('#OPTR_SLIDER#', abs($revert_value - intval($_options['slider'])), $payload);
          $payload = str_replace('#CHANNEL#', $channel, $payload);
          $payload = str_replace('#SPEED#', $speed, $payload);
          $payload = '[{"cmd":"' . $actionAPI . '","param":' . $payload . '}]';

          log::add('reolink', 'debug', 'Payload avec paramètre utilisateur demandé = ' . $payload);

          $camresp = $camcnx->SendCMD($payload);
          // Check return and update CMD State
          if ($camresp[0]["value"]["rspCode"] == 200) {
            log::add('reolink', 'debug', 'OK > Action réalisé avec succès sur la caméra');

            if (!empty($linkedvalue)) {
              $camcmd = reolink::byId($EqId, 'reolink');
              $cmd = $camcmd->getCmd(null, $linkedvalue);
              if (is_object($cmd)) {
                if (isset($_options['select'])) {
                  $updtval = $_options['select'];
                } elseif (isset($_options['slider'])) {
                  $updtval = $_options['slider'];
                } else {
                  $updtval = 0;
                  log::add('reolink', 'error', 'Impossible de trouver la valeur à inserer');
                }
                $camcmd->checkAndUpdateCmd($linkedvalue, $updtval);
                $camcmd->save();
                log::add('reolink', 'debug', 'Mise à jour de l\'info liée : ' . $linkedvalue . " Valeur=" . $updtval);
              }
            }
          } else {
            throw new Exception(__('Echec d\'execution de la commande (consultez le log pour plus de détails)', __FILE__));
          }
        }
    }
  }

  /*     * **********************Getteur Setteur*************************** */
}
