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
      $deviceInfo = $reolinkConn->SendCMD('GetDevInfo', array());
      if (!$deviceInfo) {
        return false;
      }

      foreach ($deviceInfo  as $key => $value) {
        $camera->setConfiguration($key, $value);

        if ($key == "model") {
          // Download CAM img ICON
          $modelURL = urlencode($value);
          $iconurl = "https://cdn.reolink.com/wp-content/assets/app/model-images/$modelURL/light_off.png";
          $camera->setConfiguration("camicon", $iconurl);

          $file = realpath(dirname(__FILE__) . '/../../desktop/img').'/camera.png';
          log::add('reolink', 'debug', 'Enregistrement du visuel de la caméra '.$value.' depuis serveur Reolink ('.$iconurl. ' => '.$file.')');

          /*if (file_put_contents($file, file_get_contents($iconurl)))
          {
              log::add('reolink', 'debug', 'Enregistrement OK');
          } else {
              log::add('reolink', 'debug', 'Enregistrement NOK');
          }*/

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
          $fp = fopen($file,'w');
          fwrite($fp, $rawdata);
          fclose($fp);
          log::add('reolink', 'debug', 'Ecriture OK');
        }
      }
      log::add('reolink', 'debug', 'GetDeviceInfo ajout de '.count($deviceInfo). ' items');
      // Devices Ability
      $deviceAbility = $reolinkConn->SendCMD('GetAbility', array("User" => array("userName" => "admin")));
      if (!$deviceAbility) {
        return false;
      }

      foreach ($deviceAbility  as $key => $value) {
        $camera->setConfiguration($key, $value);
      }
      log::add('reolink', 'debug', 'GetAbility ajout de '.count($deviceAbility). ' items');

      if (count($deviceInfo) > 1 && count($deviceAbility) > 1) {
        $camera->Save();
        return true;
      } else {
        return false;
      }
    }

    public static function updatePTZpreset($id, $data) {
      $camera=reolink::byId($id, 'reolink');
      $cmd = $camera->getCmd(null, 'SetPtzByPreset');
      $ptzlist = NULL;
      if (is_object($cmd) && is_array($data)) {
        foreach ($data  as $key => $value) {
          if ($value['enable'] == 1) {
              log::add('reolink', 'debug',  'Ajout du PTZ preset = '.$value['id'].'|'.$value['name']);
              $ptzlist .=  $value['id'].'|'.$value['name'].";";
          }
        }
        log::add('reolink', 'debug',  'fin boucle');
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


      $res = $camcnx->SendCMD(reolinkAPI::CAM_GET_PUSH, array());
      $camcmd->checkAndUpdateCmd('SetPushState', $res['schedule']['enable']);

      $res = $camcnx->SendCMD(reolinkAPI::CAM_GET_FTP, array());
      $camcmd->checkAndUpdateCmd('SetFTPState', $res['schedule']['enable']);

      $res = $camcnx->SendCMD(reolinkAPI::CAM_GET_EMAIL, array());
      $camcmd->checkAndUpdateCmd('SetEmailState', $res['schedule']['enable']);

      $res = $camcnx->SendCMD(reolinkAPI::CAM_GET_ENC, array("channel" => 0));
      $camcmd->checkAndUpdateCmd('SetMicrophoneState', $res['audio']);

      $res = $camcnx->SendCMD(reolinkAPI::CAM_GET_REC, array("channel" => 0));
      $camcmd->checkAndUpdateCmd('SetRecordState', $res['schedule']['enable']);

      $res = $camcnx->SendCMD(reolinkAPI::CAM_GET_AUDIOALARM, array());
      $camcmd->checkAndUpdateCmd('SetAudioAlarmState', $res['schedule']['enable']);

      $res = $camcnx->SendCMD(reolinkAPI::CAM_GET_IRLIGHTS, array());
      $camcmd->checkAndUpdateCmd('SetIrLightsState', $res['state']);

      $res = $camcnx->SendCMD(reolinkAPI::CAM_GET_POWERLED, array());
      ($res['state'] == 0) ? $value = "Off" : $value = "On";
      $camcmd->checkAndUpdateCmd('SetPowerLedState', $value);

      $res = $camcnx->SendCMD(reolinkAPI::CAM_GET_AUTOFOCUS, array("channel" => 0));
      $camcmd->checkAndUpdateCmd('SetAutoFocusState', $res['disable']);

      $res = $camcnx->SendCMD(reolinkAPI::CAM_GET_AUTOMAINT, array("channel" => 0));
      $camcmd->checkAndUpdateCmd('SetAutoMaintState', $res['enable']);
    }

    /*public function CheckConnection() {
      if ($this->reolinkTokenValidity()) {
        // TOKEN OK
        $reolink_connection = new reolinkAPI($adresseIP, $token, NULL, NULL, $port);
      } else {
        // TOKEN NOK
        $this->setConfiguration("token", $loginresult[0]);
        $this->setConfiguration("token_expiration", $loginresult[1]);
        $this->save();

      }
      // TODO: Error connection
      return true;
    }

*/




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

    /*
     * Fonction exécutée automatiquement toutes les 10 minutes par Jeedom
      public static function cron10() {
      }
     */

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
      //reolink::GetCamNFO($this->getId());
      $order = 0;
      //=======================================
      // Refresh
      //=======================================
      $cmd = $this->getCmd(null, 'refresh');
      if (!is_object($cmd)) {
        $cmd = new reolinkCmd();
        $cmd->setLogicalId('refresh');
        $cmd->setIsVisible(1);
        $cmd->setName(__('Rafraichir', __FILE__));
      }
      $cmd->setType('action');
      $cmd->setSubType('other');
      $cmd->setOrder($order);
      $cmd->setEqLogic_id($this->getId());
      $cmd->save();
      $order++;
      //=======================================
      // System REBOOT
      //=======================================
      $cmd = $this->getCmd(null, 'Reboot');
      if (!is_object($cmd)) {
        $cmd = new reolinkCmd();
        $cmd->setLogicalId('Reboot');
        $cmd->setIsVisible(1);
        $cmd->setName(__('Redémarrer', __FILE__));
      }
      $cmd->setType('action');
      $cmd->setSubType('other');
      $cmd->setOrder($order);
      $cmd->setEqLogic_id($this->getId());
      $cmd->save();
      $order++;
      //=======================================
      // Push Notification (etat)
      //=======================================
      $cmd = $this->getCmd(null, 'SetPushState');
      if (!is_object($cmd)) {
        $cmd = new reolinkCmd();
        $cmd->setLogicalId('SetPushState');
        $cmd->setIsVisible(0);
        $cmd->setName(__('Notification push (état)', __FILE__));
      }
      $cmd->setType('info');
      $cmd->setSubType('binary');
      $cmd->setOrder($order);
      $cmd->setEqLogic_id($this->getId());
      $cmd->save();
      $order++;
      //=======================================
      // On/Off Push Notification
      //=======================================
      $cmd = $this->getCmd(null, 'SetPush');
      if (!is_object($cmd)) {
        $cmd = new reolinkCmd();
        $cmd->setLogicalId('SetPush');
        $cmd->setIsVisible(1);
        $cmd->setName(__('Notification push', __FILE__));
      }
      $cmd->setType('action');
      $cmd->setSubType('select');
      $cmd->setConfiguration('listValue', '1|Activer;0|Désactiver');
      $linkcmd = $this->getCmd(null, 'SetPushState');
      $cmd->setValue($linkcmd->getId());
      $cmd->setOrder($order);
      $cmd->setEqLogic_id($this->getId());
      $cmd->save();
      $order++;
      //=======================================
      // SDCARD Record (etat)
      //=======================================
      $cmd = $this->getCmd(null, 'SetRecordState');
      if (!is_object($cmd)) {
        $cmd = new reolinkCmd();
        $cmd->setLogicalId('SetRecordState');
        $cmd->setIsVisible(0);
        $cmd->setName(__('Enregistrement SDCARD (état)', __FILE__));
      }
      $cmd->setType('info');
      $cmd->setSubType('binary');
      $cmd->setOrder($order);
      $cmd->setEqLogic_id($this->getId());
      $cmd->save();
      $order++;
      //=======================================
      // Set On/Off SDCARD Record
      //=======================================
      $cmd = $this->getCmd(null, 'SetRecord');
      if (!is_object($cmd)) {
        $cmd = new reolinkCmd();
        $cmd->setLogicalId('SetRecord');
        $cmd->setIsVisible(1);
        $cmd->setName(__('Enregistrement SDCARD', __FILE__));
      }
      $cmd->setType('action');
      $cmd->setSubType('select');
      $cmd->setConfiguration('listValue', '1|Activer;0|Désactiver');
      $linkcmd = $this->getCmd(null, 'SetRecordState');
      $cmd->setValue($linkcmd->getId());
      $cmd->setOrder($order);
      $cmd->setEqLogic_id($this->getId());
      $cmd->save();
      $order++;
      //=======================================
      // Send Mail (etat)
      //=======================================
      $cmd = $this->getCmd(null, 'SetEmailState');
      if (!is_object($cmd)) {
        $cmd = new reolinkCmd();
        $cmd->setLogicalId('SetEmailState');
        $cmd->setIsVisible(0);
        $cmd->setName(__('Envoi email (état)', __FILE__));
      }
      $cmd->setType('info');
      $cmd->setSubType('binary');
      $cmd->setOrder($order);
      $cmd->setEqLogic_id($this->getId());
      $cmd->save();
      $order++;
      //=======================================
      // Set On/Off Send Mail
      //=======================================
      $cmd = $this->getCmd(null, 'SetEmail');
      if (!is_object($cmd)) {
        $cmd = new reolinkCmd();
        $cmd->setLogicalId('SetEmail');
        $cmd->setIsVisible(1);
        $cmd->setName(__('Envoi email', __FILE__));
      }
      $cmd->setType('action');
      $cmd->setSubType('select');
      $cmd->setConfiguration('listValue', '1|Activer;0|Désactiver');
      $linkcmd = $this->getCmd(null, 'SetEmailState');
      $cmd->setValue($linkcmd->getId());
      $cmd->setOrder($order);
      $cmd->setEqLogic_id($this->getId());
      $cmd->save();
      $order++;
      //=======================================
      // Send FTP (etat)
      //=======================================
      $cmd = $this->getCmd(null, 'SetFTPState');
      if (!is_object($cmd)) {
        $cmd = new reolinkCmd();
        $cmd->setLogicalId('SetFTPState');
        $cmd->setIsVisible(0);
        $cmd->setName(__('Envoi FTP (état)', __FILE__));
      }
      $cmd->setType('info');
      $cmd->setSubType('binary');
      $cmd->setOrder($order);
      $cmd->setEqLogic_id($this->getId());
      $cmd->save();
      $order++;
      //=======================================
      // Set On/Off Send FTP
      //=======================================
      $cmd = $this->getCmd(null, 'SetFTP');
      if (!is_object($cmd)) {
        $cmd = new reolinkCmd();
        $cmd->setLogicalId('SetFTP');
        $cmd->setIsVisible(1);
        $cmd->setName(__('Envoi FTP', __FILE__));
      }
      $cmd->setType('action');
      $cmd->setSubType('select');
      $cmd->setConfiguration('listValue', '1|Activer;0|Désactiver');
      $linkcmd = $this->getCmd(null, 'SetFTPState');
      $cmd->setValue($linkcmd->getId());
      $cmd->setOrder($order);
      $cmd->setEqLogic_id($this->getId());
      $cmd->save();
      $order++;
      //=======================================
      // IR Light (etat)
      //=======================================
      $cmd = $this->getCmd(null, 'SetIrLightsState');
      if (!is_object($cmd)) {
        $cmd = new reolinkCmd();
        $cmd->setLogicalId('SetIrLightsState');
        $cmd->setIsVisible(0);
        $cmd->setName(__('Led infra rouge (état)', __FILE__));
      }
      $cmd->setType('info');
      $cmd->setSubType('string');
      $cmd->setOrder($order);
      $cmd->setEqLogic_id($this->getId());
      $cmd->save();
      $order++;
      //=======================================
      // Set IR Light On/Off/Auto
      //=======================================
      $cmd = $this->getCmd(null, 'SetIrLights');
      if (!is_object($cmd)) {
        $cmd = new reolinkCmd();
        $cmd->setLogicalId('SetIrLights');
        $cmd->setIsVisible(1);
        $cmd->setName(__('Led infra rouge', __FILE__));
      }
      $cmd->setType('action');
      $cmd->setSubType('select');
      $cmd->setConfiguration('listValue', 'Auto|Auto;Off|Désactivé;On|Toujours activé');
      $linkcmd = $this->getCmd(null, 'SetIrLightsState');
      $cmd->setValue($linkcmd->getId());
      $cmd->setOrder($order);
      $cmd->setEqLogic_id($this->getId());
      $cmd->save();
      $order++;
      //=======================================
      // Sound Alarm (etat)
      //=======================================
      $cmd = $this->getCmd(null, 'SetAudioAlarmState');
      if (!is_object($cmd)) {
        $cmd = new reolinkCmd();
        $cmd->setLogicalId('SetAudioAlarmState');
        $cmd->setIsVisible(0);
        $cmd->setName(__('Alarme Audio (état)', __FILE__));
      }
      $cmd->setType('info');
      $cmd->setSubType('binary');
      $cmd->setOrder($order);
      $cmd->setEqLogic_id($this->getId());
      $cmd->save();
      $order++;
      //=======================================
      // Set On/Off Sound Alarm
      //=======================================
      $cmd = $this->getCmd(null, 'SetAudioAlarm');
      if (!is_object($cmd)) {
        $cmd = new reolinkCmd();
        $cmd->setLogicalId('SetAudioAlarm');
        $cmd->setIsVisible(1);
        $cmd->setName(__('Alarme Audio', __FILE__));
      }
      $cmd->setType('action');
      $cmd->setSubType('select');
      $cmd->setConfiguration('listValue', '1|Activer;0|Désactiver');
      $linkcmd = $this->getCmd(null, 'SetAudioAlarmState');
      $cmd->setValue($linkcmd->getId());
      $cmd->setOrder($order);
      $cmd->setEqLogic_id($this->getId());
      $cmd->save();
      $order++;
      //=======================================
      // Power LED (etat)
      //=======================================
      $cmd = $this->getCmd(null, 'SetPowerLedState');
      if (!is_object($cmd)) {
        $cmd = new reolinkCmd();
        $cmd->setLogicalId('SetPowerLedState');
        $cmd->setIsVisible(0);
        $cmd->setName(__('Power LED (état)', __FILE__));
      }
      $cmd->setType('info');
      $cmd->setSubType('binary');
      $cmd->setOrder($order);
      $cmd->setEqLogic_id($this->getId());
      $cmd->save();
      $order++;
      //=======================================
      // Set Power LED On/Off
      //=======================================
      $cmd = $this->getCmd(null, 'SetPowerLed');
      if (!is_object($cmd)) {
        $cmd = new reolinkCmd();
        $cmd->setLogicalId('SetPowerLed');
        $cmd->setIsVisible(1);
        $cmd->setName(__('Power LED', __FILE__));
      }
      $cmd->setType('action');
      $cmd->setSubType('select');
      $cmd->setConfiguration('listValue', 'On|Activer;Off|Désactiver');
      $linkcmd = $this->getCmd(null, 'SetPowerLedState');
      $cmd->setValue($linkcmd->getId());
      $cmd->setOrder($order);
      $cmd->setEqLogic_id($this->getId());
      $cmd->save();
      $order++;
      //=======================================
      // Microphone (etat)
      //=======================================
      $cmd = $this->getCmd(null, 'SetMicrophoneState');
      if (!is_object($cmd)) {
        $cmd = new reolinkCmd();
        $cmd->setLogicalId('SetMicrophoneState');
        $cmd->setIsVisible(0);
        $cmd->setName(__('Microphone (état)', __FILE__));
      }
      $cmd->setType('info');
      $cmd->setSubType('binary');
      $cmd->setOrder($order);
      $cmd->setEqLogic_id($this->getId());
      $cmd->save();
      $order++;
      //=======================================
      // Set Microphone On/Off
      //=======================================
      $cmd = $this->getCmd(null, 'SetMicrophone');
      if (!is_object($cmd)) {
        $cmd = new reolinkCmd();
        $cmd->setLogicalId('SetMicrophone');
        $cmd->setIsVisible(1);
        $cmd->setName(__('Microphone', __FILE__));
      }
      $cmd->setType('action');
      $cmd->setSubType('select');
      $cmd->setConfiguration('listValue', '1|Activer;0|Désactiver');
      $linkcmd = $this->getCmd(null, 'SetMicrophoneState');
      $cmd->setValue($linkcmd->getId());
      $cmd->setOrder($order);
      $cmd->setEqLogic_id($this->getId());
      $cmd->save();
      $order++;
      //=======================================
      // Get PTZ Preset
      //=======================================
      $cmd = $this->getCmd(null, 'GetPtzPreset');
      if (!is_object($cmd)) {
        $cmd = new reolinkCmd();
        $cmd->setLogicalId('GetPtzPreset');
        $cmd->setIsVisible(1);
        $cmd->setName(__('Récupérer les presets PTZ', __FILE__));
      }
      $cmd->setType('action');
      $cmd->setSubType('other');
      $cmd->setOrder($order);
      $cmd->setEqLogic_id($this->getId());
      $cmd->save();
      $order++;
      //=======================================
      // Set PTZ Control (by preset)
      //=======================================
      $cmd = $this->getCmd(null, 'SetPtzByPreset');
      if (!is_object($cmd)) {
        $cmd = new reolinkCmd();
        $cmd->setLogicalId('SetPtzByPreset');
        $cmd->setIsVisible(1);
        $cmd->setName(__('Utiliser un preset PTZ', __FILE__));
      }
      $cmd->setType('action');
      $cmd->setSubType('select');
      $cmd->setOrder($order);
      $cmd->setEqLogic_id($this->getId());
      $cmd->save();
      $order++;
      //=======================================
      // Set PTZ ZOOM
      //=======================================
      $cmd = $this->getCmd(null, 'SetZoom');
      if (!is_object($cmd)) {
        $cmd = new reolinkCmd();
        $cmd->setLogicalId('SetZoom');
        $cmd->setIsVisible(1);
        $cmd->setName(__('Zoom', __FILE__));
      }
      $cmd->setType('action');
      $cmd->setSubType('slider');
      $cmd->setConfiguration('option', 'slider');
      $cmd->setConfiguration('minValue', 0);
      $cmd->setConfiguration('maxValue', 249);
      $cmd->setOrder($order);
      $cmd->setEqLogic_id($this->getId());
      $cmd->save();
      $order++;
      //=======================================
      // PTZ Control
      //=======================================
      $cmd = $this->getCmd(null, 'SetPTZmoove');
      if (!is_object($cmd)) {
        $cmd = new reolinkCmd();
        $cmd->setLogicalId('SetPTZmoove');
        $cmd->setIsVisible(1);
        $cmd->setName(__('Mouvement PTZ', __FILE__));
      }
      $cmd->setType('action');
      $cmd->setSubType('select');
      $cmd->setConfiguration('listValue', 'Auto|Auto;Stop|Stop;Left|Gauche;Right|Droite;Up|Haut;Down|Bas;LeftUp|Haut-Gauche;LeftDown|Bas-Gauche;RightUp|Haut-Droit;RightDown|Bas-Droite');
      $cmd->setOrder($order);
      $cmd->setEqLogic_id($this->getId());
      $cmd->save();
      $order++;
      //=======================================
      // Set PTZ Speed
      //=======================================
      $cmd = $this->getCmd(null, 'PtzSpeed');
      if (!is_object($cmd)) {
        $cmd = new reolinkCmd();
        $cmd->setLogicalId('PtzSpeed');
        $cmd->setIsVisible(1);
        $cmd->setName(__('Vitesse PTZ', __FILE__));
      }
      $cmd->setType('action');
      $cmd->setSubType('slider');
      $cmd->setConfiguration('option', 'slider');
      $cmd->setConfiguration('minValue', 1);
      $cmd->setConfiguration('maxValue', 64);
      $cmd->setValue(32);
      $cmd->setOrder($order);
      $cmd->setEqLogic_id($this->getId());
      $cmd->save();
      $order++;
      //=======================================
      // PTZ Patrol
      //=======================================
      $cmd = $this->getCmd(null, 'SetPTZpatrol');
      if (!is_object($cmd)) {
        $cmd = new reolinkCmd();
        $cmd->setLogicalId('SetPTZpatrol');
        $cmd->setIsVisible(1);
        $cmd->setName(__('PTZ Patrol', __FILE__));
      }
      $cmd->setType('action');
      $cmd->setSubType('select');
      $cmd->setConfiguration('listValue', 'StartPatrol|Démarrer;StopPatrol|Arrêter');
      $cmd->setOrder($order);
      $cmd->setEqLogic_id($this->getId());
      $cmd->save();
      $order++;
      //=======================================
      // AutoFocus (etat)
      //=======================================
      $cmd = $this->getCmd(null, 'SetAutoFocusState');
      if (!is_object($cmd)) {
        $cmd = new reolinkCmd();
        $cmd->setLogicalId('SetAutoFocusState');
        $cmd->setIsVisible(0);
        $cmd->setName(__('Autofocus (état)', __FILE__));
      }
      $cmd->setType('info');
      $cmd->setSubType('binary');
      $cmd->setOrder($order);
      $cmd->setEqLogic_id($this->getId());
      $cmd->save();
      $order++;
      //=======================================
      // Set AutoFocus
      //=======================================
      $cmd = $this->getCmd(null, 'SetAutoFocus');
      if (!is_object($cmd)) {
        $cmd = new reolinkCmd();
        $cmd->setLogicalId('SetAutoFocus');
        $cmd->setIsVisible(0);
        $cmd->setName(__('Autofocus', __FILE__));
      }
      $cmd->setType('action');
      $cmd->setSubType('select');
      $cmd->setConfiguration('listValue', '0|Activer;1|Désactiver');
      $linkcmd = $this->getCmd(null, 'SetAutoFocusState');
      $cmd->setValue($linkcmd->getId());
      $cmd->setOrder($order);
      $cmd->setEqLogic_id($this->getId());
      $cmd->save();
      $order++;
      //=======================================
      // AutoReboot (etat)
      //=======================================
      $cmd = $this->getCmd(null, 'SetAutoMaintState');
      if (!is_object($cmd)) {
        $cmd = new reolinkCmd();
        $cmd->setLogicalId('SetAutoMaintState');
        $cmd->setIsVisible(0);
        $cmd->setName(__('Auto reboot (état)', __FILE__));
      }
      $cmd->setType('info');
      $cmd->setSubType('string');
      $cmd->setOrder($order);
      $cmd->setEqLogic_id($this->getId());
      $cmd->save();
      $order++;
      //=======================================
      // AutoReboot On/Off
      //=======================================
      $cmd = $this->getCmd(null, 'SetAutoMaint');
      if (!is_object($cmd)) {
        $cmd = new reolinkCmd();
        $cmd->setLogicalId('SetAutoMaint');
        $cmd->setIsVisible(0);
        $cmd->setName(__('Auto reboot', __FILE__));
      }
      $cmd->setType('action');
      $cmd->setSubType('select');
      $cmd->setConfiguration('listValue', '0|Désactivé;1|Activé');
      $linkcmd = $this->getCmd(null, 'SetAutoMaintState');
      $cmd->setValue($linkcmd->getId());
      $cmd->setOrder($order);
      $cmd->setEqLogic_id($this->getId());
      $cmd->save();
      $order++;
    }

 // Fonction exécutée automatiquement avant la suppression de l'équipement
    public function preRemove() {

    }

 // Fonction exécutée automatiquement après la suppression de l'équipement
    public function postRemove() {

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
      $cam = reolink::getReolinkConnection($EqId);

       switch ($this->getLogicalId()) {
          case 'refresh':
              reolink::refreshNFO($EqId);
              break;
          case 'SetPush':
              $param = array("Push" =>
                            array("schedule" =>
                                  array("enable" => intval($_options['select']))
                                  )
                          );
              $cam->SendCMD(reolinkAPI::CAM_SET_PUSH, $param);
              break;
          case 'SetRecord':
              $param = array("Rec" =>
                          array("schedule" =>
                                  array("enable" => intval($_options['select']))
                                )
                          );
              $cam->SendCMD(reolinkAPI::CAM_SET_REC, $param);
              break;
          case 'SetEmail':
              $param = array("Email" =>
                              array("schedule" =>
                                    array("enable" => intval($_options['select']))
                                  )
                            );
              $cam->SendCMD(reolinkAPI::CAM_SET_EMAIL, $param);
              break;
          case 'SetFTP':
              $param = array("Ftp" =>
                              array("schedule" =>
                                    array("enable" => intval($_options['select']))
                                  )

                            );
              $cam->SendCMD(reolinkAPI::CAM_SET_FTP, $param);
              break;
          case 'SetAudioAlarm':
              $param = array("Audio" =>
                              array("schedule" =>
                                    array("enable" => intval($_options['select']))
                                  )

                            );
              $cam->SendCMD(reolinkAPI::CAM_SET_AUDIOALARM, $param);
              break;
          case 'GetPtzPreset':
              $param = array("channel" => 0);
              $data = $cam->SendCMD(reolinkAPI::CAM_GET_PTZPRESET, $param);
              reolink::updatePTZpreset($EqId, $data);
              break;
          case 'SetPtzByPreset':
              $param = array("channel" => 0,
                             "op" => "ToPos",
                             "id" => intval($_options['select']),
                             "speed" => 32
                            );
              $data = $cam->SendCMD(reolinkAPI::CAM_PTZCTRL, $param);
              break;
          case 'SetZoom':
              $param = array("ZoomFocus" =>
                            array("channel" => 0,
                                   "pos" => intval($_options['slider']),
                                   "op" => "ZoomPos")
                            );
              $data = $cam->SendCMD(reolinkAPI::CAM_STARTZOOMFOCUS, $param);
              break;
          case 'SetPTZmoove':
              $param = array("channel" => 0,
                             "op" => $_options['select'],
                             "speed" => 32
                            );
              $data = $cam->SendCMD(reolinkAPI::CAM_PTZCTRL, $param);
              break;
          case 'SetPTZpatrol':
              $param = array("channel" => 0,
                             "op" => $_options['select'],
                             "speed" => 32
                            );
              $data = $cam->SendCMD(reolinkAPI::CAM_PTZCTRL, $param);
              break;
          case 'SetFocus':
              $param = array("ZoomFocus" =>
                            array("channel" => 0,
                                   "pos" => intval($_options['slider']),
                                   "op" => "FocusPos")
                            );
              $data = $cam->SendCMD(reolinkAPI::CAM_STARTZOOMFOCUS, $param);
              break;
          case 'SetIrLights':
              $param = array("IrLights" =>
                              array("channel" => 0,
                                    "state" => $_options['select'])
                            );
              $data = $cam->SendCMD(reolinkAPI::CAM_SET_IRLIGHTS, $param);
              break;
          case 'SetPowerLed':
              $param = array("PowerLed" =>
                              array("channel" => 0,
                                    "state" => $_options['select'])
                            );
              $data = $cam->SendCMD(reolinkAPI::CAM_SET_POWERLED, $param);
              break;
          case 'SetAutoFocus':
              $param = array("AutoFocus" =>
                              array("channel" => 0,
                                    "disable" => intval($_options['select']))
                            );
              $data = $cam->SendCMD(reolinkAPI::CAM_SET_AUTOFOCUS, $param);
              break;
          case 'SetMicrophone':
              $param = array("Enc" =>
                              array("audio" => intval($_options['select']))
                            );
              $data = $cam->SendCMD(reolinkAPI::CAM_SET_ENC, $param);
              break;
          case 'Reboot':
              $param = array();
              $data = $cam->SendCMD(reolinkAPI::CAM_REBOOT, $param);
              break;
          case 'CheckFirmware':
              $param = array();
              $data = $cam->SendCMD(reolinkAPI::CAM_CHECKFIRMWARE, $param);
              break;
          case 'UpgradeOnline':
              $param = array();
              $data = $cam->SendCMD(reolinkAPI::CAM_UPGRADEONLINE, $param);
              break;
          case 'SetAutoMaint':
              $param = array("AutoMaint" =>
                              array("enable" => intval($_options['select']))
                            );
              $data = $cam->SendCMD(reolinkAPI::CAM_SET_AUTOMAINT, $param);
              break;
        }

     }

    /*     * **********************Getteur Setteur*************************** */
}
