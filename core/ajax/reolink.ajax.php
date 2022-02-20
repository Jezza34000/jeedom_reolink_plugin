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

try {
    require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');

    if (!isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }

  /* Fonction permettant l'envoi de l'entête 'Content-Type: application/json'
    En V3 : indiquer l'argument 'true' pour contrôler le token d'accès Jeedom
    En V4 : autoriser l'exécution d'une méthode 'action' en GET en indiquant le(s) nom(s) de(s) action(s) dans un tableau en argument
  */
    ajax::init();

    if (init('action') == 'CreateCMD') {
        $camera = reolink::byId(init('id'));
        if (!is_object($camera)) {
          throw new Exception(__('Impossible de trouver la caméra : ' . init('id'), __FILE__));
        }
        $camera->GetCamNFO(init('id'));
        $res = $camera->loadCmdFromConf(init('id'));

        if (is_numeric($res)) {
          ajax::success();
        } elseif ($res === false) {
          throw new Exception(__('Erreur lors de la création des commandes', __FILE__));
        }
    }

    if (init('action') == 'CheckConnexion') {
        $camera = reolink::byId(init('id'));
        if (!is_object($camera)) {
          throw new Exception(__('Impossible de trouver la caméra : ' . init('id'), __FILE__));
        }
        $res = $camera->TryConnect(init('id'));
        if ($res === true) {
          ajax::success();
        } else {
          throw new Exception(__('Impossible de se connecter à la caméra', __FILE__));
        }
    }

    if (init('action') == 'CheckDeviceInfo') {
        $camera = reolink::byId(init('id'));
        if (!is_object($camera)) {
          throw new Exception(__('Impossible de trouver la caméra : ' . init('id'), __FILE__));
        }
        $res = $camera->GetCamNFO(init('id'));
        if ($res === true) {
          ajax::success();
        } else {
          throw new Exception(__('Impossible de se connecter à la caméra ('.$res.')', __FILE__));
        }
    }

    if (init('action') == 'SetCAMConfig') {
      $camera = reolink::byId(init('id'));
      if (!is_object($camera)) {
        throw new Exception(__('Impossible de trouver la caméra : ' . init('id'), __FILE__));
      }
      $cfgGroup = init('group');
      if ($cfgGroup == 'EMAIL'){
        $res = reolink::setEmail(init('id'));
      }
      if ($cfgGroup == 'FTP'){
        $res = reolink::setFTP(init('id'));
      }

      if ($res == 200) {
        ajax::success();
      } else {
        throw new Exception(__('Echec du paramétrage de la caméra (consultez le log pour plus de détails)', __FILE__));
      }
    }

    if (init('action') == 'GetCAMConfig') {
      $camera = reolink::byId(init('id'));
      if (!is_object($camera)) {
        throw new Exception(__('Impossible de trouver la caméra : ' . init('id'), __FILE__));
      }
      $res = reolink::refreshNFO(init('id'));
      ajax::success();
    }

    throw new Exception(__('Aucune méthode correspondante à : ', __FILE__) . init('action'));
    /*     * *********Catch exeption*************** */
} catch (Exception $e) {
    ajax::error(displayException($e), $e->getCode());
}
