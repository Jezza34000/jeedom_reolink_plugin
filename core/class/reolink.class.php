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
require_once dirname(__FILE__).'/../../3rdparty/reolinkapi.class.php';

class reolink extends eqLogic {

    /************* Static methods ************/
    public static function getReolinkConnection($id) {
      $camera = reolink::byId($id, 'reolink');
      $adresseIP = $camera->getConfiguration('adresseip');
      $port = $camera->getConfiguration('port');
      $username = $camera->getConfiguration('login');
      $password = $camera->getConfiguration('password');
      $cnxtype = $camera->getConfiguration('cnxtype');

      if (!empty($adresseIP) && !empty($username) && !empty($password))
      {
        $cnxinfo = array("adresseIP" => $adresseIP, "port" => $port, "username" => $username, "password" => $password, "cnxtype" => $cnxtype);
        $camcnx = new reolinkAPI($cnxinfo);
        return $camcnx;
      } else {
        log::add('reolink', 'warning', "Information de connexion manquantes : connexion à la caméra impossible");
        return false;
      }
  	}

    public static function TryConnect($id) {
      $reolinkConn = reolink::getReolinkConnection($id);
      if ($reolinkConn->$is_loggedin == true) {
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

      // Devices Info
      $reolinkConn = reolink::getReolinkConnection($id);
      $deviceInfo = $reolinkConn->SendCMD('[{"cmd":"GetDevInfo"},{"cmd":"GetP2P"},{"cmd":"GetLocalLink"},{"cmd":"GetAiState"}]');

      if (!$deviceInfo) {
        return false;
      }

      foreach ($deviceInfo[0]['value']["DevInfo"] as $key => $value) {

        log::add('reolink', 'debug', 'Enregistrement : K='.$key. ' V='.$value);
        $camera->setConfiguration($key, $value);

        if ($key == "model") {
          // Download CAM img ICON
          $modelURL = urlencode($value);
          $iconurl = "https://cdn.reolink.com/wp-content/assets/app/model-images/$modelURL/light_off.png";
          $camera->setConfiguration("camicon", $iconurl);

          $dir = realpath(dirname(__FILE__) . '/../../desktop');

          if (!file_exists($dir.'/img')) {
              mkdir($dir.'/img', 0775, true);
              log::add('reolink', 'debug', 'Création du répertoire visuel caméra = '.$dir.'/img');
          }

          $fileToWrite = $dir.'/img/camera'.$id.'.png';

          log::add('reolink', 'debug', 'Enregistrement du visuel de la caméra '.$value.' depuis serveur Reolink ('.$iconurl. ' => '.$fileToWrite.')');

          $ch = curl_init ($iconurl);
          curl_setopt($ch, CURLOPT_HEADER, false);
          curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
          curl_setopt($ch, CURLOPT_BINARYTRANSFER,true);
          curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
          curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
          curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
          $rawdata=curl_exec($ch);

          $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
          $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
          $header = substr($response, 0, $header_size);

          if ($httpcode == 200) {
            log::add('reolink', 'debug', 'HTTP code 200 OK');
          } else {
            log::add('reolink', 'error', 'HTTP code '.$httpcode.' NOK '.curl_error($ch). ' Entête : '.$header);
            return false;
          }
          curl_close ($ch);
          $fp = fopen($fileToWrite,'w');
          fwrite($fp, $rawdata);
          fclose($fp);
          log::add('reolink', 'debug', 'Ecriture OK');
        }
      }

      // Alone value
      $value = $deviceInfo[1]['value']["P2p"]['uid'];
      log::add('reolink', 'debug', 'Enregistrement : K=uid V='.$value);
      $camera->setConfiguration("uid", $value);

      $value = $deviceInfo[2]['value']["LocalLink"]['activeLink'];
      log::add('reolink', 'debug', 'Enregistrement : K=linkconnection V='.$value);
      $camera->setConfiguration("linkconnection", $value);

      // Check if cam is AI
      if (isset($deviceInfo[3]['value'])) {
        // Cam is AI
        $camera->setConfiguration("supportai", "Oui");
      } else {
        // Cam is not AI
        $camera->setConfiguration("supportai", "Non");
      }

      log::add('reolink', 'debug', 'GetDeviceInfo ajout de '.count($deviceInfo[0]['value']["DevInfo"]). ' items');
      if (count($deviceInfo[0]['value']["DevInfo"]) > 1) {
        $camera->Save();
        return true;
      } else {
        return false;
      }
    }

    public static function GetCamAbility($id) {
      log::add('reolink', 'debug', 'Interrogation de la caméra sur ses capacités hardware/software...');
      $reolinkConn = reolink::getReolinkConnection($id);
      $camera = reolink::byId($id, 'reolink');

      $username = $camera->getConfiguration('login');
      if (empty($username)) {
        $username = "admin";
      }

      // Devices Ability
      if (is_object($reolinkConn)) {
        $deviceAbility = $reolinkConn->SendCMD('[{"cmd":"GetAbility","param":{"User":{"userName":"'.$username.'"}}}]');

      log::add('reolink', 'debug', print_r($deviceAbility ,true));

        $ab1 = $deviceAbility[0]["value"]["Ability"];
        unset($ab1["abilityChn"]);
        $ab2 = $deviceAbility[0]["value"]["Ability"]["abilityChn"][0];
        $deviceAbility = array_merge($ab1, $ab2);


        log::add('reolink', 'debug', 'GetAbility à récupérer : '.count($deviceAbility). ' items');

        if (count($deviceAbility) > 1) {
          return $deviceAbility;
        } else {
          return false;
        }
      } else {
        return false;
      }

    }

    public static function updatePTZpreset($id, $data) {
      $camera=reolink::byId($id, 'reolink');
      $cmd = $camera->getCmd(null, 'SetPtzByPreset');
      $ptzlist = "";
      log::add('reolink', 'debug',  'PTZ à parser = '. print_r($data['value']['PtzPreset'], true));
      if (is_object($cmd) && is_array($data)) {
        foreach ($data['value']['PtzPreset']  as $key => $value) {
          if ($value['enable'] == 1) {
              log::add('reolink', 'debug',  'Ajout du PTZ preset = '.$value['id'].'|'.$value['name']);
              $ptzlist .=  $value['id'].'|'.$value['name'].";";
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

    public static function refreshNFO($id) {
        $camcmd = reolink::byId($id, 'reolink');
        $camcnx = reolink::getReolinkConnection($id);
        $cmdget = NULL;

        $channel = $camcmd->getConfiguration('defined_channel');
        if ($channel == NULL) {
          $channel = 0;
        }

        // Prepare request with INFO needed
        foreach (reolinkCmd::byEqLogicId($id) as $cmd) {
          $payload = $cmd->getConfiguration('payload');
        	  if ($cmd->getType() == "info" && $payload != NULL) {
                $payload = str_replace('#CHANNEL#', $channel, $payload);
                $payload = str_replace('\\', '', $payload);

                if (!in_array($payload, $cmdarr))
                {
                    $cmdarr[] = $payload;
                }
        	  }
        }
        //Nbr de commandes par bloc
        $cmdsblock = 8;

        $cmdarrlength = count($cmdarr);
        $cmdarrmod = $cmdarrlength % $cmdsblock;
        $passnbr = ($cmdarrlength - ($cmdarrmod))/$cmdsblock;

        if ($cmdarrmod != 0) {
           $passnbr += 1;
        }

        log::add('reolink', 'debug', 'Nbr commandes dans un bloc : '. $cmdsblock .' / Nbr commandes : ' . $cmdarrlength . ' / Commandes restantes : ' . $cmdarrmod . ' / Nbr des passes :' . $passnbr);
        for ($pass = 1; $pass <= $passnbr; $pass++) {

        $cmdget = "";
        $beginrange = ($pass-1)*$cmdsblock;
        if ($pass != $passnbr) {
        	$endrange = ($cmdsblock*$pass)-1;
        } else {
        	if ($cmdarrmod != 0) {
        		$endrange = ($cmdsblock*$pass)+($cmdarrmod-$cmdsblock)-1;
        	} else {
        		$endrange = ($cmdsblock*$pass)-1;
        	}
        }

        for ($cmdarrnbr = $beginrange; $cmdarrnbr <= $endrange; $cmdarrnbr++) {
                $cmdget .= $cmdarr[$cmdarrnbr].",";
        }
        $cmdget = substr($cmdget, 0, -1);

        log::add('reolink', 'debug', 'Payload multiple GetSetting (Pass '. $pass .') = ' . $cmdget);

        $res = $camcnx->SendCMD("[$cmdget]");

        foreach ($res as &$json_data) {
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
      	      $camcmd->checkAndUpdateCmd('SetPreRecordStateV20', $json_data['value']['Rec']['preRec']);
      	      $camcmd->checkAndUpdateCmd('SetOverwriteStateV20', $json_data['value']['Rec']['overwrite']);
      	      $camcmd->checkAndUpdateCmd('SetPostRecordStateV20', $json_data['value']['Rec']['postRec']);
      	      break;

            case reolinkAPI::CAM_GET_MDSTATE:
              // Updated by daemon
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
              $camcmd->checkAndUpdateCmd('SetMicrophoneState', $json_data['value']['audio']);
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
              $camcmd->checkAndUpdateCmd('SetWhitLedLuxState', $json_data['value']['WhiteLed']['bright']);
              break;

            case reolinkAPI::CAM_GET_PTZPRESET:
              break;

            case reolinkAPI::CAM_PTZCHECK:
              break;

            case reolinkAPI::CAM_GET_PTZCHECKSTATE:
              switch ((int) $json_data['value']['PtzCheckState']) {
                  case 0:
                    $camcmd->checkAndUpdateCmd('SetPtzCheckState','REQUISE');
                    break;
                  case 1:
                    $camcmd->checkAndUpdateCmd('SetPtzCheckState','EN COURS');
                    break;
                  case 2:
                    $camcmd->checkAndUpdateCmd('SetPtzCheckState','TERMINEE');
                    break;
                  }
                  log::add('reolink', 'debug', 'Statut Calibration : '. $json_data['value']['PtzCheckState']);
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

            case reolinkAPI::CAM_GET_POWERLED:
              $camcmd->checkAndUpdateCmd('SetPowerLedState', $json_data['value']['PowerLed']['state']);
              break;

            case reolinkAPI::CAM_GET_ABILITY:
              $ab1 = $json_data['value']['Ability'];
              unset($ab1['abilityChn']);
              $ab2 = $json_data['value']['Ability']['abilityChn']['0'];
              $camcnx->ability_settings = array_merge($ab1, $ab2);
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

            case reolinkAPI::CAM_PERFORMANCE:
              $camcmd->checkAndUpdateCmd('SetCpuUsedState', $json_data['value']['Performance']['cpuUsed']);
              $camcmd->checkAndUpdateCmd('SetNetThroughputState', $json_data['value']['Performance']['netThroughput']);
              $camcmd->checkAndUpdateCmd('SetCodecRateState', $json_data['value']['Performance']['codecRate']);
              break;

            default:
              log::add('reolink', 'error', 'JSON map résultat à echouer avec le retour : '. print_r($json_data, true));
              $res = false;
        }
      }
     }
    }

  /*
   * Permet de définir les possibilités de personnalisation du widget (en cas d'utilisation de la fonction 'toHtml' par exemple)
   * Tableau multidimensionnel - exemple: array('custom' => true, 'custom::layout' => false)
	public static $_widgetPossibility = array();
   */

    /*     * ***********************Methode static*************************** */

      public static function cron() {
        $eqLogics = ($_eqlogic_id !== null) ? array(eqLogic::byId($_eqlogic_id)) : eqLogic::byType('reolink', true);
        foreach ($eqLogics as $camera) {
          $autorefresh = $camera->getConfiguration('autorefresh','*/15 * * * *');
          if ($autorefresh != '') {
            try {
              $c = new Cron\CronExpression(checkAndFixCron($autorefresh), new Cron\FieldFactory);
              if ($c->isDue()) {
                log::add('reolink', 'debug', '#### CRON refresh '.$camera->getHumanName());
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


    /*
     * Fonction exécutée automatiquement toutes les 5 minutes par Jeedom
      public static function cron5() {
      }
     */


     // Fonction exécutée automatiquement toutes les 10 minutes par Jeedom
      public static function cron10() {
        // Refresh motion detection subscription
        $eqLogics = ($_eqlogic_id !== null) ? array(eqLogic::byId($_eqlogic_id)) : eqLogic::byType('reolink', true);
        foreach ($eqLogics as $camera) {

          $port_onvif = $camera->getConfiguration('port_onvif');
          if ($port_onvif == "") { $port_onvif = "8000"; }
          // Sending info to Daemon
          $params['action'] = 'sethook';
          $params['cam_ip'] = $camera->getConfiguration('adresseip');
          $params['cam_onvif_port'] = $port_onvif;
          $params['cam_user'] = $camera->getConfiguration('login');
          $params['cam_pwd'] = $camera->getConfiguration('password');

          log::add('reolink', 'debug', 'CRON mise à jour souscription ONVIF events Cam='.$camera->getConfiguration('adresseip'));
          reolink::sendToDaemon($params);
        }
      }


    /*
     * Fonction exécutée automatiquement toutes les 15 minutes par Jeedom
      public static function cron15() {
      }
     */

    /*
     * Fonction exécutée automatiquement toutes les 30 minutes par Jeedom
      public static function cron30() {
      }
     */

    /*
     * Fonction exécutée automatiquement toutes les heures par Jeedom
      public static function cronHourly() {
      }
     */

    /*
     * Fonction exécutée automatiquement tous les jours par Jeedom
      public static function cronDaily() {
      }
     */

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

         $list_camera = eqLogic::byType('reolink') ;


         /*
         $adresseIP = $camera->getConfiguration('adresseip');
         $username = $camera->getConfiguration('login');
         $password = $camera->getConfiguration('password');
        */
         if (count($list_camera) == 0) {
             $return['launchable'] = 'nok';
             $return['launchable_message'] = __('Aucune caméra n\'est configuré', __FILE__);
         } /*elseif ($password == '') {
             $return['launchable'] = 'nok';
             $return['launchable_message'] = __('Le mot de passe de la caméra n\'est pas configuré', __FILE__);
         } elseif ($adresseIP == '') {
             $return['launchable'] = 'nok';
             $return['launchable_message'] = __('L\'IP de la caméra n\'est pas configurée', __FILE__);
         }*/
         return $return;
      }

      public static function deamon_start() {
        self::deamon_stop();
        $deamon_info = self::deamon_info();
        if ($deamon_info['launchable'] != 'ok') {
            throw new Exception(__('Veuillez vérifier la configuration', __FILE__));
        }

        $path = realpath(dirname(__FILE__) . '/../../resources/demond');
        $cmd = 'python3 ' . $path . '/reolinkd.py';
        $cmd .= ' --loglevel ' . log::convertLogLevel(log::getLogLevel(__CLASS__));
        $cmd .= ' --socketport ' . config::byKey('socketport', __CLASS__, '44009');
        $cmd .= ' --callback ' . network::getNetworkAccess('internal', 'proto:127.0.0.1:port:comp') . '/plugins/reolink/core/php/jeeReolink.php';
        $cmd .= ' --apikey ' . jeedom::getApiKey(__CLASS__);
        $cmd .= ' --pid ' . jeedom::getTmpFolder(__CLASS__) . '/deamon.pid';
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
          return array('script' => dirname(__FILE__) . '/../../resources/install_#stype#.sh ' . jeedom::getTmpFolder(__CLASS__) . '/dependency', 'log' => log::getPathToLog(__CLASS__ . '_update'));
      }

      public static function dependancy_info() {
          $return = array();
          $return['log'] = log::getPathToLog(__CLASS__ . '_update');
          $return['progress_file'] = jeedom::getTmpFolder(__CLASS__) . '/dependency';
          if (file_exists(jeedom::getTmpFolder(__CLASS__) . '/dependency')) {
              $return['state'] = 'in_progress';
          } else {
              if (exec(system::getCmdSudo() . system::get('cmd_check') . '-Ec "python3\-requests"') < 1) {
                  $return['state'] = 'nok';
              } elseif (exec(system::getCmdSudo() . 'pip3 list | grep -Ewc "aiohttp|asyncio|uvicorn|fastapi"') < 4) {
                  $return['state'] = 'nok';
              } else {
                  $return['state'] = 'ok';
              }
          }
          return $return;
      }

    /*     * *********************Méthodes d'instance************************* */

 // Fonction exécutée automatiquement avant la création de l'équipement
    public function preInsert() {

    }

 // Fonction exécutée automatiquement après la création de l'équipement
    public function postInsert() {


    }

 // Fonction exécutée automatiquement avant la mise à jour de l'équipement
    public function preUpdate() {
      if ($this->getConfiguration('adresseip') == NULL) {
        throw new Exception(__('L\'adresse IP est obligatoire', __FILE__));
      }
      if (!filter_var($this->getConfiguration('adresseip'), FILTER_VALIDATE_IP)) {
        throw new Exception("Adresse IP de la caméra invalide " . $this->ip);
      }
      if ($this->getConfiguration('login') == NULL) {
        throw new Exception(__('Le champ login est obligatoire', __FILE__));
      }
      if ($this->getConfiguration('password') == NULL) {
        throw new Exception(__('Le mot de passe ne peut pas être vide', __FILE__));
      }
      // Champs OK
    }

 // Fonction exécutée automatiquement après la mise à jour de l'équipement
    public function postUpdate() {

    }

 // Fonction exécutée automatiquement avant la sauvegarde (création ou mise à jour) de l'équipement
    public function preSave() {

    }

 // Fonction exécutée automatiquement après la sauvegarde (création ou mise à jour) de l'équipement
    public function postSave() {

    }

 // Fonction exécutée automatiquement avant la suppression de l'équipement
    public function preRemove() {

    }

 // Fonction exécutée automatiquement après la suppression de l'équipement
    public function postRemove() {

    }


    public function loadCmdFromConf($id) {
      $devAbilityReturn = reolink::GetCamAbility($id);
      $camera = reolink::byId($id, 'reolink');
      if ($camera->GetConfiguration("supportai") == "Non") {
        $camisIA = 0;
      } else {
        $camisIA = 1;
      }

      if (!$devAbilityReturn) {
        log::add('reolink', 'debug', 'Erreur lors de l\'obtention des capacités hardware/software de la caméra');
        return false;
      }

      log::add('reolink', 'debug', 'Chargement des commandes depuis le fichiers de config : '.dirname(__FILE__) . '/../config/reolinkapicmd.json');
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
      log::add('reolink', 'info', 'Nombre de commandes dans le fichier de configuration : '.count($device['commands']));
      $cmd_order = 0;

      foreach ($device['commands'] as $command) {
          // Check cam ability
          $cmd = null;
          foreach ($this->getCmd() as $liste_cmd) {
            if ((isset($command['logicalId']) && $liste_cmd->getLogicalId() == $command['logicalId'])
            || (isset($command['name']) && $liste_cmd->getName() == $command['name'])) {
              $cmd = $liste_cmd;
              break;
            }
          }

          if ($cmd == null || !is_object($cmd))
          {
            // Check cam ability
            $ability = false;
            $abilityfound = false;
            // Global Ability
            foreach ($devAbilityReturn as $abilityName => $abilityParam) {

                if ($command['abilityneed'] == "none") {
                  $ability = true;
                  break;
                }

                if ($command['abilityneed'] == $abilityName) {
                  $abilityfound = true;
                  if ($abilityParam['permit'] != 0) {
                      if (isset($command['iastate'])) {
                        if ($command['iastate'] == $camisIA) {
                          // Function available for this model ADD
                          log::add('reolink', 'info', '=> (IA) Capacité hardware/software OK pour : '.$command['name']);
                          $ability = true;
                        } else {
                          // Function IA NOT available for this model DO NOT ADD
                          log::add('reolink', 'debug', '=> (IA) Ignorer, capacité hardware/software NOK pour : '.$command['name']);
                          break;
                        }
                      } else {
                        // Function available for this model ADD
                        log::add('reolink', 'info', '=> Capacité hardware/software OK pour : '.$command['name']);
                        $ability = true;
                      }
                    break;
                  } else {
                    // Function NOT available for this model DO NOT ADD
                    log::add('reolink', 'debug', '=> Ignorer, capacité hardware/software NOK pour : '.$command['name']);
                    break;
                  }
                  break;
                }
              }

            if (!$abilityfound && !$ability) {
              log::add('reolink', 'error', 'Aucun match de capacité '.$command['abilityneed'].' pour la CMD : '.$command['name']);
            }

            if ($ability) {
              log::add('reolink', 'info', '-> Ajout de : '.$command['name']);
              $cmd = new reolinkCmd();
              $cmd->setOrder($cmd_order);
              $cmd->setEqLogic_id($this->getId());
              utils::a2o($cmd, $command);
              $cmd->save();
              if ($cmd->getConfiguration('valueFrom') != "") {
                $valueLink = $cmd->getConfiguration('valueFrom');
                $camera = reolink::byId($id, 'reolink');
                $cmdlogic = reolinkCmd::byEqLogicIdAndLogicalId($camera->getId(), $valueLink);
                if (is_object($cmdlogic)) {
                  $cmd->setValue($cmdlogic->getId());
                  $cmd->save();
                  log::add('reolink', 'debug', '--> Valeur lier depuis : '.$valueLink." (".$cmdlogic->getId().")");
                } else {
                  log::add('reolink', 'warning', 'X--> Liaison impossible objet introuvable : '.$valueLink);
                }
              }
              $cmd_order++;
            }
          } else {
            log::add('reolink', 'debug', 'Commande déjà présente : '.$command['name']);
          }
      }
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
      log::add('reolink', 'debug', 'Action demandé : '.$this->getLogicalId());
      $EqId = $this->getEqLogic_id();

      $channel = $this->getConfiguration('defined_channel');
      if ($channel == NULL) {
        $channel = 0;
      }

       switch ($this->getLogicalId()) {
          case 'refresh':
              reolink::refreshNFO($EqId);
              break;
          case 'GetPtzPreset':
              $camcnx = reolink::getReolinkConnection($EqId);
              $data = $camcnx->SendCMD('[{"cmd":"GetPtzPreset","action":1,"param":{"channel":'.$channel.'}}]');
              reolink::updatePTZpreset($EqId, $data[0]);
              break;
          case 'SetSpeed':
              $this->setConfiguration('speedvalue', $_options['slider']);
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

            if ($actionAPI != NULL) {
              $payload = str_replace('\\', '', $this->getConfiguration('payload'));
              $payload = str_replace('#OPTSELECTEDINT#', intval($_options['select']), $payload);
              $payload = str_replace('#OPTSELECTEDSTR#', '"'.$_options['select'].'"', $payload);
              $payload = str_replace('#OPTSLIDER#', intval($_options['slider']), $payload);
              $payload = str_replace('#CHANNEL#', $channel, $payload);
              $payload = str_replace('#SPEED#', $speed, $payload);
              $payload = '[{"cmd":"'.$actionAPI.'","param":'.$payload.'}]';

              log::add('reolink', 'debug', 'Payload avec paramètre utilisateur demandé = '.$payload);

              $camresp = $camcnx->SendCMD($payload);
              // Check return and update CMD State
              if ($camresp[0]["value"]["rspCode"] == 200) {
                log::add('reolink', 'debug', 'OK > Action réalisé avec succès sur la caméra');

                if (!empty($linkedvalue)) {
                  $camcmd = reolink::byId($EqId, 'reolink');
                  $cmd = $camcmd->getCmd(null, $linkedvalue);
                  if (is_object($cmd) ) {
                    if (isset($_options['select'])) {
                      $updtval = $_options['select'];
                    } elseif (isset($_options['slider'])) {
                      $updtval = $_options['select'];
                    } else {
                      $updtval = 0;
                      log::add('reolink', 'error', 'Impossible de trouver la valeur à inserer');
                    }
                    $camcmd->checkAndUpdateCmd($linkedvalue, $updtval);
                    $camcmd->save();
                    log::add('reolink', 'debug', 'Mise à jour de l\'info liée : '.$linkedvalue. " Valeur=".$updtval);
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
