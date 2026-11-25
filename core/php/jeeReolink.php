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
    if (!reolink::isPluginActiveFast()) {
        die();
    }
    $result = json_decode(file_get_contents("php://input"), true);
    if (!is_array($result)) {
        die();
    }

    if (isset($result['message']) && $result['message'] === 'baichuan_events') {
        $plugin = plugin::byId('reolink');
        $eqLogics = eqLogic::byType($plugin->getId(), true);
        $events = is_array($result['events'] ?? null) ? $result['events'] : array();

        foreach ($eqLogics as $eqLogic) {
            if (!$eqLogic->usesBaichuanPush()) {
              continue;
            }
            $camera_contact_point = $eqLogic->getConfiguration('adresseip');
            $camera_ip = filter_var($camera_contact_point, FILTER_VALIDATE_IP)
              ? $camera_contact_point
              : gethostbyname($camera_contact_point);
            if ($camera_ip != ($result['ip'] ?? '')) {
              continue;
            }
            $defCh = $eqLogic->getConfiguration('defined_channel', '');
            $isNvr = $eqLogic->isNvrParent();
            $multiChannel = ($defCh !== '' || $isNvr || $eqLogic->isChannelChild());
            if ($isNvr && $defCh === '') {
              foreach ($events as $ev) {
                if (($ev['event'] ?? '') === 'EvScene') {
                  $eqLogic->checkAndUpdateCmd('SetSceneState', intval($ev['state'] ?? -1));
                }
              }
              continue;
            }
            $channel = ($defCh !== '') ? intval($defCh) : max(0, intval($eqLogic->getConfiguration('channelNum', 1)) - 1);

            $matchedAny = false;
            $hasExplicitOnline = false;
            $wokeBattery = false;
            foreach ($events as $ev) {
              if (($ev['event'] ?? '') === 'EvScene') {
                continue;
              }
              $evCh = array_key_exists('channel', $ev) ? intval($ev['channel']) : null;
              if ($evCh !== null && $multiChannel && $channel !== $evCh) {
                continue;
              }
              $matchedAny = true;
              $evName = strval($ev['event'] ?? '');
              $evState = intval($ev['state'] ?? 0);
              if ($evName === 'motion') {
                $eqLogic->checkAndUpdateCmd('MdState', $evState);
                $eqLogic->checkAndUpdateCmd('EvMotion', $evState);
                if ($evState === 1) {
                  $wokeBattery = true;
                }
              } elseif ($evName === 'EvPrivacy') {
                $eqLogic->checkAndUpdateCmd('SetPrivacyModeState', $evState);
              } elseif ($evName === 'EvChannelOnline') {
                $hasExplicitOnline = true;
                $eqLogic->checkAndUpdateCmd('ChannelOnline', $evState);
              } elseif ($evName === 'EvBatterySleep') {
                if ($eqLogic->hasBattery()) {
                  $eqLogic->checkAndUpdateCmd('BatterySleepState', $evState);
                }
              } elseif (strpos($evName, 'Ev') !== false) {
                $eqLogic->checkAndUpdateCmd($evName, $evState);
                if ($evState === 1) {
                  $wokeBattery = true;
                }
              }
            }
            if ($wokeBattery && $eqLogic->hasBattery()) {
              $eqLogic->checkAndUpdateCmd('BatterySleepState', 0);
              $lastWake = intval(cache::byKey('reolink::battery_last_wake::' . $eqLogic->getId())->getValue(0));
              if ((time() - $lastWake) > reolink::BATTERY_PASSIVE_WAKE_INTERVAL) {
                reolink::refreshNFO($eqLogic->getId(), true);
              }
            }
            if ($matchedAny && $multiChannel && !$hasExplicitOnline) {
              $eqLogic->checkAndUpdateCmd('ChannelOnline', 1);
            }
        }
    } elseif (isset($result['message']) && (($result['message'] == "motion") || (strpos($result['message'], 'Ev') !== false))) {
        $plugin = plugin::byId('reolink');
        $eqLogics = eqLogic::byType($plugin->getId(), true);
        $cnxByIp = array();
        $eventChannel = array_key_exists('channel', $result) ? intval($result['channel']) : null;

        foreach ($eqLogics as $eqLogic) {
            $isBaichuanPush = (($result['source'] ?? '') === 'baichuan');
            if (!$isBaichuanPush && $eqLogic->usesBaichuanPush()) {
              continue;
            }
            $camera_contact_point = $eqLogic->getConfiguration('adresseip');
            $camera_AI = $eqLogic->getConfiguration('supportai');

            if (filter_var($camera_contact_point, FILTER_VALIDATE_IP)) {
              $camera_ip = $camera_contact_point;
            } else {
              $camera_ip = gethostbyname($camera_contact_point);
            }

            if ($camera_ip != $result['ip']) {
              continue;
            }

            $defCh = $eqLogic->getConfiguration('defined_channel', '');
            $isNvr = $eqLogic->isNvrParent();
            $multiChannel = ($defCh !== '' || $isNvr || $eqLogic->isChannelChild());
            if ($isNvr && $defCh === '') {
              continue;
            }
            $channel = ($defCh !== '') ? intval($defCh) : max(0, intval($eqLogic->getConfiguration('channelNum', 1)) - 1);
            if ($eventChannel !== null && $multiChannel && $channel !== $eventChannel) {
              continue;
            }

            $isBaichuanPush = (($result['source'] ?? '') === 'baichuan');
            if (!$isBaichuanPush) {
              $eqLogic->checkAndUpdateCmd('EvLastOnvifName', $result['message']);
              $eqLogic->checkAndUpdateCmd('EvLastOnvifState', $result['motionstate']);
              $eqLogic->checkAndUpdateCmd('EvLastOnvifFull', $result['message'] . '-' . $result['motionstate']);
            }

            if ($result['message'] == "motion") {
              $eqLogic->checkAndUpdateCmd('MdState', $result['motionstate']);
              $eqLogic->checkAndUpdateCmd('EvMotion', $result['motionstate']);
            } elseif ($result['message'] == "EvPrivacy") {
              $eqLogic->checkAndUpdateCmd('SetPrivacyModeState', intval($result['motionstate']));
            } elseif (strpos($result['message'], 'Ev') !== false) {
              $eqLogic->checkAndUpdateCmd($result['message'], $result['motionstate']);
            }

            if ($eqLogic->hasBattery()) {
              $eqLogic->checkAndUpdateCmd('BatterySleepState', 0);
              if (intval($result['motionstate'] ?? 0) === 1) {
                $lastWake = intval(cache::byKey('reolink::battery_last_wake::' . $eqLogic->getId())->getValue(0));
                if ((time() - $lastWake) > reolink::BATTERY_PASSIVE_WAKE_INTERVAL) {
                  reolink::refreshNFO($eqLogic->getId(), true);
                }
              }
            }

            $needAiPoll = (!$isBaichuanPush && $camera_AI == "Oui" && $result['message'] == "motion");
            $needMdPoll = (!$isBaichuanPush && $multiChannel && $eventChannel === null && $result['message'] == "motion");
            if (!$needAiPoll && !$needMdPoll) {
              if ($multiChannel) {
                $eqLogic->checkAndUpdateCmd('ChannelOnline', 1);
              }
              continue;
            }
            if ($eqLogic->isStandalonePrivacyBlocking() || reolink::isEquipmentHostInCooldown($eqLogic)) {
              log::add('reolink', 'debug', 'jeeReolink : poll HTTP ignoré (confidentialité ou disjoncteur actif) pour ' . $eqLogic->getHumanName());
              continue;
            }

            if (!isset($cnxByIp[$camera_ip])) {
              $cnxByIp[$camera_ip] = reolink::getReolinkConnection($eqLogic->getId());
            }
            $camcnx = $cnxByIp[$camera_ip];
            $parts = array();
            if ($needMdPoll) {
              $parts[] = '{"cmd":"GetMdState","action":0,"param":{"channel":' . $channel . '}}';
            }
            if ($needAiPoll) {
              $parts[] = '{"cmd":"GetAiState","action":0,"param":{"channel":' . $channel . '}}';
            }
            $res = (is_object($camcnx) && $camcnx->is_loggedin) ? $camcnx->SendCMD('[' . implode(',', $parts) . ']') : false;
            $mdOk = false;
            if (is_array($res)) {
              foreach ($res as $item) {
                if (($item['cmd'] ?? '') === 'GetMdState' && isset($item['value']['state'])) {
                  $mdOk = true;
                  $md = intval($item['value']['state']);
                  $eqLogic->checkAndUpdateCmd('MdState', $md);
                  $eqLogic->checkAndUpdateCmd('EvMotion', $md);
                } elseif (($item['cmd'] ?? '') === 'GetAiState' && isset($item['value'])) {
                  reolink::applyAiStateCmds($eqLogic, $item['value']);
                }
              }
            }
            if ($multiChannel) {
              $eqLogic->checkAndUpdateCmd('ChannelOnline', ($needMdPoll ? ($mdOk ? 1 : 0) : 1));
            }
        }
    } elseif (isset($result['message']) && $result['message'] == "subscription") {
        $subIp = trim(strval($result['ip'] ?? ''));
        $cacheKey = 'reolink::sub_fail::' . $subIp;
        if (intval($result['state'] ?? 0) === 0) {
          $fails = intval(cache::byKey($cacheKey)->getValue(0)) + 1;
          cache::set($cacheKey, $fails);
          // Absorb single transient failure (e.g. weekly AutoMaint reboot) before alerting Message Center
          if ($fails >= 2) {
            $message = 'Notification de détection de mouvement indisponible sur la caméra : ' . $subIp . ' ( Détails : ' . ($result['details'] ?? '') . ')';
            message::add('Plugin Reolink', $message, '', 'reolink_sub_' . $subIp);
          } else {
            log::add('reolink', 'warning', 'Souscription événements indisponible (1er échec, nouvel essai au prochain cron10) sur ' . $subIp . ' : ' . ($result['details'] ?? ''));
          }
        } elseif ($subIp !== '' && intval(cache::byKey($cacheKey)->getValue(0)) > 0) {
          cache::set($cacheKey, 0);
        }
    } else {
        log::add('reolink', 'error', 'unknown message received from daemon');
    }
} catch (Exception $e) {
    log::add('reolink', 'error', displayException($e));
}
