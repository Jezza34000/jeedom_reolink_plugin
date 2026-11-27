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

require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';

// Fonction exécutée automatiquement après l'installation du plugin
  function reolink_install() {
    if (config::byKey('check_fw_github', 'reolink', '') === '') {
      config::save('check_fw_github', '1', 'reolink');
    }
    if (config::byKey('enable_webrtc', 'reolink', '') === '') {
      config::save('enable_webrtc', '0', 'reolink');
    }
  }

// Fonction exécutée automatiquement après la mise à jour du plugin
  function reolink_update() {
    if (config::byKey('check_fw_github', 'reolink', '') === '') {
      config::save('check_fw_github', '1', 'reolink');
    }
    if (config::byKey('enable_webrtc', 'reolink', '') === '') {
      config::save('enable_webrtc', '0', 'reolink');
    }
    $plugin = plugin::byId('reolink');
    $eqLogics = eqLogic::byType($plugin->getId());
    $cleanedTokens = array();
    foreach ($eqLogics as $eqLogic) {
      if (!is_object($eqLogic)) {
        continue;
      }
      $tagToken = str_replace('.', '', trim(strval($eqLogic->getConfiguration('adresseip', ''))));
      if ($tagToken !== '' && !isset($cleanedTokens[$tagToken])) {
        $cleanedTokens[$tagToken] = true;
        try {
          config::remove('token' . $tagToken, 'reolink');
          config::remove('tokenEXP' . $tagToken, 'reolink');
        } catch (Exception $e) {
          // ignore
        }
      }
      if ($eqLogic->isNvrParent()) {
        continue;
      }
      try {
        $eqLogic->migrateLegacyPayloads();
        $eqLogic->setConfiguration('needs_cmd_resync', 1);
        $eqLogic->setConfiguration('resync_retry_count', 0);
        $eqLogic->save();
        if (intval($eqLogic->getIsEnable()) !== 1) {
          continue;
        }
        $id = $eqLogic->getId();
        if (reolink::GetCamNFO($id)) {
          $eqLogic->loadCmdFromConf($id, true);
        }
      } catch (Exception $e) {
        log::add('reolink', 'warning', 'reolink_update : ' . $eqLogic->getHumanName() . ' — ' . $e->getMessage());
      } catch (Throwable $e) {
        log::add('reolink', 'warning', 'reolink_update : ' . $eqLogic->getHumanName() . ' — ' . $e->getMessage());
      }
    }
  }

// Fonction exécutée automatiquement après la suppression du plugin
  function reolink_remove() {

  }

?>
