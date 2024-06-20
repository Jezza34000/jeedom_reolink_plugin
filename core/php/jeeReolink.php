<?php

try {
    require_once dirname(__FILE__) . "/../../../../core/php/core.inc.php";

    if (!jeedom::apiAccess(init('apikey'), 'reolink')) {
        echo __('Vous n\'etes pas autorisé à effectuer cette action', __FILE__);
        die();
    }
    if (init('test') != '') {
        echo 'OK';
        die();
    }
    $result = json_decode(file_get_contents("php://input"), true);
    if (!is_array($result)) {
        die();
    }

# BEGIN  modified by t0urista to handle ONVIF events
    if (isset($result['message']) && (($result['message']=="motion") || (strpos($result['message'], 'Ev') !== false) )) {
# END  modified by t0urista to handle ONVIF events

        $plugin = plugin::byId('reolink');
        $eqLogics = eqLogic::byType($plugin->getId());

        foreach ($eqLogics as $eqLogic) {
            $camera_contact_point = $eqLogic->getConfiguration('adresseip');
            $camera_AI = $eqLogic->getConfiguration('supportai');

            if (filter_var($camera_contact_point, FILTER_VALIDATE_IP)) {
              $camera_ip = $camera_contact_point;
            } else {
              $camera_ip = gethostbyname($camera_contact_point);
            }

            if ($camera_ip == $result['ip']) {
              if ($result['message'] == "motion") {
                log::add('reolink', 'debug',  'Cam IP='.$result['ip']. ' Onvif event reçu depuis le daemon. name= MDstate, état='.$result['motionstate']);
                $eqLogic->checkAndUpdateCmd('MdState', $result['motionstate']);
              } 
              
# BEGIN  added by t0urista to handle ONVIF events

# catch any ONVIF events in the 3 genreic ONVIF commands, can cover unknown ONVIF events
              $eqLogic->checkAndUpdateCmd('EvLastOnvifName', $result['message']); 
              $eqLogic->checkAndUpdateCmd('EvLastOnvifState', $result['motionstate']); 
              $eqLogic->checkAndUpdateCmd('EvLastOnvifFull', $result['message'] . '-' . $result['motionstate']); 

# catch all pre-defined ONVIF  events with their dedicated commands, does only cover knwon ONVIF events

              if (strpos($result['message'], 'Ev') !== false) {
                 log::add('reolink', 'debug', 'Cam IP='.$result['ip']. ' Onvif event reçu depuis le daemon. name= ' . $result['message'] . ', etat='.$result['motionstate']);
                 $eqLogic->checkAndUpdateCmd($result['message'], $result['motionstate']); 
              }
# END  added by t0urista to handle ONVIF events

              #log::add('reolink', 'debug', 'IP : ' . $camera_contact_point . ' / IsCamAI : ' . $camera_AI . ' / EqId : ' . $EqId . ' / Channel : ' . $channel);
              if ($camera_AI == "Oui") {
                  $camcnx = reolink::getReolinkConnection($eqLogic->getId());
                  $channel = $eqLogic->getConfiguration('channelNum') - 1;
                  $res = $camcnx->SendCMD('[{"cmd":"GetAiState","action":0,"param":{"channel":'.$channel.'}}]');
                  if (isset($res[0]['value'])) {
                    $eqLogic->checkAndUpdateCmd('EvPeopleDetect', $res[0]['value']['people']['alarm_state']);
                    $eqLogic->checkAndUpdateCmd('EvVehicleDetect', $res[0]['value']['vehicle']['alarm_state']);
                  }
                  log::add('reolink', 'debug', 'Cam AI : Evènements Motion | Personne : ' . $res[0]['value']['people']['alarm_state'] . ' / Vehicule : ' . $res[0]['value']['vehicle']['alarm_state']);
              }
            }
          }
    } elseif (isset($result['message']) && $result['message'] == "subscription") {
        if ($result['state'] == 0) {
          $title = 'Plugin Reolink';
          $message = 'Notification de détection de mouvement indisponible sur la caméra : ' . $result['ip'] . ' ( Détails : ' . $result['details'] . ')';
          message::add($title, $message);
        }
    } else {
        log::add('reolink', 'error', 'unknown message received from daemon');
    }
} catch (Exception $e) {
    log::add('reolink', 'error', displayException($e));
}
?>
