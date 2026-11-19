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

  }

// Fonction exécutée automatiquement après la mise à jour du plugin
  function reolink_update() {
    $plugin = plugin::byId('reolink');
    $eqLogics = eqLogic::byType($plugin->getId());
    foreach ($eqLogics as $eqLogic) {
      if (!is_object($eqLogic) || $eqLogic->isNvrParent()) {
        continue;
      }
      try {
        $eqLogic->migrateLegacyPayloads();
        $eqLogic->setConfiguration('needs_cmd_resync', 1);
        $eqLogic->save();
        if (intval($eqLogic->getIsEnable()) !== 1) {
          continue;
        }
        $id = $eqLogic->getId();
        reolink::GetCamNFO($id);
        $eqLogic->loadCmdFromConf($id, true);
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
