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

    if (init('action') == 'webrtcOffer') {
      $id = intval(init('id', 0));
      $camOffer = reolink::byId($id, 'reolink');
      if (!reolink::isAuthorizedForLive($camOffer, init('token', ''))) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
      }
      ajax::init();
      if (!reolink::isWebrtcEnabled()) {
        throw new Exception(__('WebRTC désactivé dans la configuration du plugin Reolink', __FILE__));
      }
      $quality = reolink::sanitizeStreamKey(init('quality', 'sub'));
      if ($quality === 'jpeg') {
        $quality = 'sub';
      }
      $sdp = strval(init('sdp', ''));
      if ($sdp === '') {
        throw new Exception(__('SDP manquant', __FILE__));
      }
      reolink::ensureGo2rtcStreams($id);
      $src = 'reolink_' . $id . '_' . $quality;
      $ch = curl_init('http://127.0.0.1:' . reolink::getGo2rtcApiPort() . '/api/webrtc?src=' . rawurlencode($src));
      curl_setopt($ch, CURLOPT_POST, true);
      curl_setopt($ch, CURLOPT_POSTFIELDS, $sdp);
      curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/sdp'));
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
      curl_setopt($ch, CURLOPT_TIMEOUT, 5);
      $answer = curl_exec($ch);
      $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      curl_close($ch);
      if ($code < 200 || $code >= 300 || $answer === false || $answer === '') {
        throw new Exception(__('WebRTC go2rtc indisponible', __FILE__));
      }
      ajax::success(array('sdp' => $answer));
    }

    if (init('action') == 'SavePtzPreset') {
      if (!isConnect()) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
      }
      ajax::init();
      $presets = reolink::savePtzPreset(intval(init('id', 0)), intval(init('presetId', 0)), strval(init('name', '')), intval(init('enable', 1)));
      ajax::success(array('presets' => $presets));
    }

    if (!isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }

  /* Fonction permettant l'envoi de l'entête 'Content-Type: application/json'
    En V3 : indiquer l'argument 'true' pour contrôler le token d'accès Jeedom
    En V4 : autoriser l'exécution d'une méthode 'action' en GET en indiquant le(s) nom(s) de(s) action(s) dans un tableau en argument
  */
    ajax::init();

    if (init('action') == 'SyncCameraPlugin') {
      $id = init('id', null);
      $count = reolink::syncCameraPlugin(($id === '' || $id === null) ? null : $id);
      ajax::success(array('count' => $count));
    }

    if (init('action') == 'SyncNvrChannels') {
      $nvrEq = reolink::byId(init('id'), 'reolink');
      if (is_object($nvrEq)) {
        reolink::clearHostCooldown($nvrEq);
      }
      ajax::success(reolink::syncNvrChannels(init('id')));
    }

    if (init('action') == 'CheckFirmwareUpdates') {
      $id = init('id', null);
      $force = intval(init('force', 1)) === 1;
      $targetId = ($id === '' || $id === null) ? null : intval($id);
      ajax::success(reolink::checkAllFirmwareUpdates($targetId, $force));
    }

    $camera = reolink::byId(init('id'));
    if (!is_object($camera)) {
      throw new Exception(__('Impossible de trouver la caméra : ' . init('id'), __FILE__));
    }


    if (init('action') == 'CreateCMD') {
        reolink::clearHostCooldown($camera);
        $camera->GetCamNFO(init('id'));
        $res = $camera->loadCmdFromConf(init('id'));

        if (is_numeric($res)) {
          ajax::success();
        } elseif ($res === false) {
          throw new Exception(__('Erreur lors de la création des commandes', __FILE__));
        }
    }

    if (init('action') == 'CheckConnexion') {
        reolink::clearHostCooldown($camera);
        $res = $camera->TryConnect(init('id'));
        if ($res === true) {
          ajax::success();
        } else {
          throw new Exception(__('Impossible de se connecter à la caméra', __FILE__));
        }
    }

    if (init('action') == 'CheckDeviceInfo') {
        reolink::clearHostCooldown($camera);
        $res = $camera->GetCamNFO(init('id'));
        if ($res === true) {
          ajax::success();
        } else {
          throw new Exception(__('Impossible d\'obtenir les informations de la caméra ('.$res.')', __FILE__));
        }
    }

    if (init('action') == 'GetCAMConfig') {
      reolink::clearHostCooldown($camera);
      $res = reolink::refreshNFO(init('id'));
      ajax::success();
    }

    if (init('action') == 'GetPatrolData') {
      ajax::success(reolink::getPatrolData(init('id')));
    }

    if (init('action') == 'SavePatrol') {
      $steps = json_decode(init('steps', '[]'), true);
      reolink::savePatrol(init('id'), init('patrolId'), init('enable'), is_array($steps) ? $steps : array());
      ajax::success();
    }

    if (init('action') == 'RunPatrol') {
      reolink::runPatrol(init('id'), init('op'), init('patrolId', null));
      ajax::success();
    }

    if (init('action') == 'BaichuanDiagnostic') {
      reolink::clearHostCooldown($camera);
      ajax::success(reolink::runBaichuanDiagnostic(init('id')));
    }

    if (init('action') == 'GetLiveUrls') {
      if ($camera->isNvrParent()) {
        ajax::success(array('webviewUrl' => '', 'talkRtspUrl' => ''));
      }
      ajax::success(array(
        'webviewUrl' => $camera->getWebViewLiveUrl(true),
        'talkRtspUrl' => $camera->getLocalTalkRtspUrl(true),
      ));
    }

    if (init('action') == 'RegenerateLiveToken') {
      if ($camera->isNvrParent()) {
        throw new Exception(__('Action réservée aux équipements caméra', __FILE__));
      }
      $camera->regenerateLiveToken();
      ajax::success(array(
        'webviewUrl' => $camera->getWebViewLiveUrl(true),
      ));
    }

    throw new Exception(__('Aucune méthode correspondante à : ', __FILE__) . init('action'));
    /*     * *********Catch exeption*************** */
} catch (Exception $e) {
    ajax::error(displayException($e), $e->getCode());
} catch (\Throwable $e) {
    ajax::error($e->getMessage(), $e->getCode());
}
