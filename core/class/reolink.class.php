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
      $reolink = NULL;

      $adresseIP = $camera->getConfiguration('adresseip');
      $port = $camera->getConfiguration('port');
      $token = $camera->getConfiguration('token');
      $tokenexp = $camera->getConfiguration('tokenexp');
      $username = $camera->getConfiguration('login');
      $password = $camera->getConfiguration('password');

      if (reolink::reolinkTokenValidity($token, $tokenexp) == false) {
        $cnxinfo = array("adresseIP" => $adresseIP, "port" => $port, "username" => $username, "password" => $password);
        $reolink = new reolinkAPI($cnxinfo);
        // TOKEN NOK (get new one)
        if (!empty($adresseIP) && !empty($username) && !empty($password)){
          $LogCredential = $reolink->login();
          $date_utc = new DateTime("now", new DateTimeZone("UTC"));
          $tokenexp = intval($LogCredential['leaseTime']) + ($date_utc->getTimestamp());
          $camera->SetConfiguration('token', $LogCredential['name']);
          $camera->SetConfiguration('tokenexp', $tokenexp);
          $camera->Save();
        } else {
          log::add('reolink', 'warning', "Information de connexion manquantes : connexion à la caméra impossible");
          return false;
        }
      } else {
        $cnxinfo = array("adresseIP" => $adresseIP, "port" => $port, "token" => $token);
        $reolink = new reolinkAPI($cnxinfo);
      }
  		return $reolink;
  	}

    public static function reolinkTokenValidity($token, $tsexpiration) {
      $date_utc = new DateTime("now", new DateTimeZone("UTC"));
      $tsnow = $date_utc->getTimestamp();

      if (($tsexpiration -15) < $tsnow || $token == NULL) {
        log::add('reolink', 'warning', 'API Token expiré renouvellement requis.');
        return false;
      } else {
        log::add('reolink', 'debug', 'API Token OK');
        return true;
      }
    }

    public function TryConnect($id) {
      $reolinkConn = reolink::getReolinkConnection($id);
      if (is_object($reolinkConn)) {
        log::add('reolink', 'warning', 'Connection à la caméra OK');
        return true;
      } else {
        log::add('reolink', 'warning', 'Connection à la caméra NOK');
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
          // Get CAM img ICON
          $modelURL = urlencode($value);
          $camera->setConfiguration("camicon", "https://cdn.reolink.com/wp-content/assets/app/model-images/$modelURL/light_off.png");
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
        $ptzlist = substr($ptzlist, 0, -1);
        $cmd->setConfiguration('listValue', $ptzlist);
        $cmd->save();
      }

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

    /*
     * Fonction exécutée automatiquement toutes les minutes par Jeedom
      public static function cron() {
      }
     */

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
      if ($this->getConfiguration('login') == NULL) {
        throw new Exception(__('Le champ login est obligatoire', __FILE__));
      }
      if ($this->getConfiguration('password') == NULL) {
        throw new Exception(__('Le mot de passe ne peut pas être vide', __FILE__));
      }
      if ($this->getConfiguration('adresseip') == NULL) {
        throw new Exception(__('L\'adresse IP est obligatoire', __FILE__));
      }
      if (!filter_var($this->getConfiguration('adresseip'), FILTER_VALIDATE_IP)) {
        throw new Exception("Adresse IP de la caméra invalide " . $this->ip);
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
      $cmd->setEqLogic_id($this->getId());
      $cmd->save();
      //=======================================
      // On/Off SDCARD Record
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
      $cmd->setEqLogic_id($this->getId());
      $cmd->save();
      //=======================================
      // On/Off Mail
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
      $cmd->setEqLogic_id($this->getId());
      $cmd->save();
      //=======================================
      // On/Off FTP
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
      $cmd->setEqLogic_id($this->getId());
      $cmd->save();
      //=======================================
      // IR Light On/Off/Auto
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
      $cmd->setEqLogic_id($this->getId());
      $cmd->save();
      //=======================================
      // On/Off Sound Alarm
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
      $cmd->setEqLogic_id($this->getId());
      $cmd->save();
      //=======================================
      // Power LED On/Off
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
      $cmd->setEqLogic_id($this->getId());
      $cmd->save();
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
      $cmd->setEqLogic_id($this->getId());
      $cmd->save();
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
      $cmd->setEqLogic_id($this->getId());
      $cmd->save();
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
      $cmd->setEqLogic_id($this->getId());
      $cmd->save();
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
          case 'SetPush':
              $param = array("Push" =>
                            array("schedule" =>
                                  array("enable" => intval($_options['select']),
                                  "table" => "111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111",)
                                  )

                          );
              $cam->SendCMD('SetPush', $param);
              break;
          case 'SetRecord':
              $param = array("Rec" =>
                          array("schedule" =>
                                  array("enable" => intval($_options['select']),
                                  "table" => "111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111",)
                                  )
                          );
              $cam->SendCMD('SetRec', $param);
              break;
          case 'SetEmail':
              $param = array("Email" =>
                              array("schedule" =>
                                    array("enable" => intval($_options['select']),
                                    "table" => "111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111",)
                                    )

                            );
              $cam->SendCMD('SetEmail', $param);
              break;
          case 'SetFTP':
              $param = array("Ftp" =>
                              array("schedule" =>
                                    array("enable" => intval($_options['select']),
                                    "table" => "111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111",)
                                    )

                            );
              $cam->SendCMD('SetFtp', $param);
              break;
          case 'SetAudioAlarm':
              $param = array("Audio" =>
                              array("schedule" =>
                                    array("enable" => intval($_options['select']),
                                    "table" => "111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111",)
                                    )

                            );
              $cam->SendCMD('SetAudioAlarm', $param);
              break;
          case 'GetPtzPreset':
              $param = array("channel" => 0);
              $data = $cam->SendCMD('GetPtzPreset', $param);
              $cam->SaveFile("ptzpreset", $data);
              reolink::updatePTZpreset($EqId, $data);
              break;
          case 'SetPtzByPreset':
              $param =
              $param = array("channel" => 0,
                             "op" => "ToPos",
                             "id" => intval($_options['select']),
                             "speed" => 32
                            );
              $data = $cam->SendCMD('PtzCtrl', $param);
              break;
          case 'SetZoom':
              $param = array("ZoomFocus" =>
                            array("channel" => 0,
                                   "pos" => intval($_options['slider']),
                                   "op" => "ZoomPos")
                            );
              $data = $cam->SendCMD('StartZoomFocus', $param);
              break;
          case 'SetIrLights':
              $param = array("channel" => 0,
                              "state" => $_options['select']);
              $data = $cam->SendCMD('SetIrLights', $param);
              break;
          case 'SetPowerLed':
              $param =
              $param = array("PowerLed" =>
                              array("channel" => 0,
                                    "state" => $_options['select'])
                            );
              $data = $cam->SendCMD('SetPowerLed', $param);
              break;
          case 'Reboot':
              $param = array();
              $data = $cam->SendCMD('Reboot', $param);
              break;
          case 'CheckFirmware':
              $param = array();
              $data = $cam->SendCMD('CheckFirmware', $param);
              break;
          case 'UpgradeOnline':
              $param = array();
              $data = $cam->SendCMD('UpgradeOnline', $param);
              break;
        }

     }

    /*     * **********************Getteur Setteur*************************** */
}
