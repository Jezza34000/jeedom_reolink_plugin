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

    if (isset($result['message']) && $result['message'] == "motion") {
        log::add('reolink', 'debug', 'Evènement : "détection de mouvement" reçu depuis le daemon. IP='.$result['ip'].' Etat='.$result['motionstate']);
        $plugin = plugin::byId('reolink');
        $eqLogics = eqLogic::byType($plugin->getId());

        foreach ($eqLogics as $eqLogic) {
          if ($eqLogic->getConfiguration('adresseip') == $result['ip']) {
            log::add('reolink', 'debug', 'IP matché avec caméra, mise à jour des infos');
            $eqLogic->checkAndUpdateCmd('MdState', $result['motionstate']);
          }
  			}
    } else {
        log::add('reolink', 'error', 'unknown message received from daemon');
    }
} catch (Exception $e) {
    log::add('reolink', 'error', displayException($e));
}
?>
