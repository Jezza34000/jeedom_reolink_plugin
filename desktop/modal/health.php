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

if (!isConnect('admin')) {
  throw new Exception('{{401 - Accès non autorisé}}');
}

$plugin = plugin::byId('reolink');
$eqLogics = eqLogic::byType($plugin->getId());
$fwCheckEnabled = reolink::isGithubFirmwareCheckEnabled();

usort($eqLogics, function ($a, $b) {
  if ($a->isNvrParent() !== $b->isNvrParent()) {
    return $a->isNvrParent() ? -1 : 1;
  }
  return strcasecmp($a->getHumanName(), $b->getHumanName());
});

$nvrChannelStats = array();
foreach ($eqLogics as $eq) {
  if (!$eq->isChannelChild() || intval($eq->getIsEnable()) !== 1) {
    continue;
  }
  $host = trim(strval($eq->getConfiguration('adresseip', '')));
  if ($host === '') {
    continue;
  }
  if (!isset($nvrChannelStats[$host])) {
    $nvrChannelStats[$host] = array('online' => 0, 'total' => 0);
  }
  $nvrChannelStats[$host]['total']++;
  $onCmd = $eq->getCmd(null, 'ChannelOnline');
  if (!is_object($onCmd) || strval($onCmd->execCmd()) === '' || intval($onCmd->execCmd()) === 1) {
    $nvrChannelStats[$host]['online']++;
  }
}
?>

<table class="table table-condensed tablesorter" id="table_healthreolink">
  <thead>
    <tr>
      <th>{{Image}}</th>
      <th>{{Équipement}}</th>
      <th>{{ID}}</th>
      <th>{{Rôle}}</th>
      <th>{{Modèle}}</th>
      <th>{{Accès}}</th>
      <th>{{Événements}}</th>
      <th>{{Statut}}</th>
      <th>{{Batterie}}</th>
      <th>{{Firmware}}</th>
      <th>{{Dernière communication}}</th>
    </tr>
  </thead>
  <tbody>
    <?php
    foreach ($eqLogics as $eqLogic) {
      $id = intval($eqLogic->getId());
      $enabled = (intval($eqLogic->getIsEnable()) === 1);
      $opacity = $enabled ? '' : 'style="opacity:0.55;"';
      $img = '<img src="' . htmlspecialchars($eqLogic->getImage(), ENT_QUOTES, 'UTF-8') . '" height="48" style="max-width:64px;object-fit:contain;" />';
      $eqUrl = 'index.php?v=d&p=reolink&m=reolink&id=' . $id;

      $isNvr = $eqLogic->isNvrParent();
      $isCh = $eqLogic->isChannelChild();
      if ($isNvr) {
        $hostType = strtoupper(trim(strval($eqLogic->getConfiguration('hostExactType', 'NVR'))));
        $roleHtml = '<span class="label label-primary">' . htmlspecialchars($hostType !== '' ? $hostType : 'NVR', ENT_QUOTES, 'UTF-8') . '</span>';
      } elseif ($isCh) {
        $roleHtml = '<span class="label label-info">{{Canal}} ' . intval($eqLogic->getChannel()) . '</span>';
      } else {
        $roleHtml = '<span class="label label-default">{{Autonome}}</span>';
      }

      $model = trim(strval($eqLogic->getConfiguration('model', '-')));
      $hardVer = trim(strval($eqLogic->getConfiguration('hardVer', '')));
      $modelHtml = htmlspecialchars($model !== '' ? $model : '-', ENT_QUOTES, 'UTF-8');
      if ($hardVer !== '') {
        $modelHtml .= '<br><small class="text-muted">' . htmlspecialchars($hardVer, ENT_QUOTES, 'UTF-8') . '</small>';
      }

      $host = trim(strval($eqLogic->getConfiguration('adresseip', '')));
      $port = trim(strval($eqLogic->getConfiguration('port', '')));
      $cnx = strtoupper(trim(strval($eqLogic->getConfiguration('cnxtype', 'http'))));
      $endpoint = $host . ($port !== '' ? (':' . $port) : '');
      $accessHtml = '<code>' . htmlspecialchars($endpoint !== '' ? $endpoint : '-', ENT_QUOTES, 'UTF-8') . '</code> <span class="label label-default" style="font-size:0.85em;">' . htmlspecialchars($cnx, ENT_QUOTES, 'UTF-8') . '</span>';

      if ($eqLogic->usesBaichuanPush()) {
        $evHtml = '<span class="label label-info">Baichuan TCP</span>';
      } else {
        $evHtml = '<span class="label label-default">ONVIF</span>';
      }

      if (!$enabled) {
        $statusHtml = '<span class="label label-default">{{Inactif}}</span>';
      } elseif ($isNvr) {
        $st = $nvrChannelStats[$host] ?? null;
        if (is_array($st) && $st['total'] > 0) {
          $cls = ($st['online'] === $st['total']) ? 'label-success' : 'label-warning';
          $statusHtml = '<span class="label ' . $cls . '">' . intval($st['online']) . '/' . intval($st['total']) . ' {{en ligne}}</span>';
        } else {
          $statusHtml = '<span class="label label-success">{{Actif}}</span>';
        }
        $sceneCmd = $eqLogic->getCmd(null, 'SetSceneState');
        if (is_object($sceneCmd)) {
          $scVal = strval($sceneCmd->execCmd());
          if ($scVal !== '' && intval($scVal) >= 0) {
            $statusHtml .= ' <span class="label label-primary">{{Scène}} #' . intval($scVal) . '</span>';
          }
        }
      } else {
        $onCmd = $eqLogic->getCmd(null, 'ChannelOnline');
        $rawOn = is_object($onCmd) ? strval($onCmd->execCmd()) : '';
        if ($rawOn === '') {
          $statusHtml = '<span class="label label-default">N/A</span>';
        } elseif (intval($rawOn) === 1) {
          $slCmd = $eqLogic->getCmd(null, 'BatterySleepState');
          if ($eqLogic->hasBattery() && is_object($slCmd) && intval($slCmd->execCmd()) === 1) {
            $statusHtml = '<span class="label label-info">{{En veille}}</span>';
          } else {
            $statusHtml = '<span class="label label-success">{{En ligne}}</span>';
          }
        } else {
          $statusHtml = '<span class="label label-danger">{{Hors ligne}}</span>';
        }
      }

      if ($eqLogic->hasBattery()) {
        $batCmd = $eqLogic->getCmd(null, 'BatteryPercent');
        $batRaw = is_object($batCmd) ? strval($batCmd->execCmd()) : '';
        if ($batRaw !== '' && is_numeric($batRaw)) {
          $pct = intval($batRaw);
          $bCls = ($pct <= 15) ? 'label-danger' : (($pct <= 30) ? 'label-warning' : 'label-success');
          $batHtml = '<span class="label ' . $bCls . '">' . $pct . '%</span>';
        } else {
          $batHtml = '<span class="label label-default">N/A</span>';
        }
      } else {
        $batHtml = '-';
      }

      $firmVer = trim(strval($eqLogic->getConfiguration('firmVer', '')));
      $fwHtml = ($firmVer !== '') ? ('<code>' . htmlspecialchars($firmVer, ENT_QUOTES, 'UTF-8') . '</code>') : '-';
      if ($fwCheckEnabled && $enabled) {
        $fwStatus = trim(strval($eqLogic->getConfiguration('fwArchiveStatus', '')));
        $fwLatest = trim(strval($eqLogic->getConfiguration('fwArchiveLatestVer', '')));
        if ($fwStatus === 'update_available' && $fwLatest !== '') {
          $fwHtml .= ' <span class="label label-warning" title="' . htmlspecialchars($fwLatest, ENT_QUOTES, 'UTF-8') . '">{{MAJ}} ' . htmlspecialchars($fwLatest, ENT_QUOTES, 'UTF-8') . '</span>';
        } elseif ($fwStatus === 'up_to_date') {
          $fwHtml .= ' <span class="label label-success">{{À jour}}</span>';
        }
      }

      $lastComm = strval($eqLogic->getStatus('lastCommunication', ''));

      echo '<tr ' . $opacity . '>';
      echo '<td>' . $img . '</td>';
      echo '<td><a href="' . htmlspecialchars($eqUrl, ENT_QUOTES, 'UTF-8') . '" style="text-decoration:none;">' . $eqLogic->getHumanName(true) . '</a></td>';
      echo '<td><span class="label label-info" style="font-size:0.9em;">' . $id . '</span></td>';
      echo '<td>' . $roleHtml . '</td>';
      echo '<td>' . $modelHtml . '</td>';
      echo '<td>' . $accessHtml . '</td>';
      echo '<td>' . $evHtml . '</td>';
      echo '<td>' . $statusHtml . '</td>';
      echo '<td>' . $batHtml . '</td>';
      echo '<td>' . $fwHtml . '</td>';
      echo '<td><span class="label label-info" style="font-size:0.9em;">' . htmlspecialchars($lastComm !== '' ? $lastComm : '-', ENT_QUOTES, 'UTF-8') . '</span></td>';
      echo '</tr>';
    }
    ?>
  </tbody>
</table>
