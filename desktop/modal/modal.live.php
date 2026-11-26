<?php
if (!isConnect()) {
  throw new Exception(__('401 - Accès non autorisé', __FILE__));
}
$id = intval(init('id'));
$eqLogic = reolink::byId($id, 'reolink');
if (!is_object($eqLogic) || $eqLogic->isNvrParent()) {
  throw new Exception(__('Équipement Reolink invalide', __FILE__));
}
$cnxType = strtolower(trim(strval($eqLogic->getConfiguration('cnxtype', ''))));
if ($cnxType !== 'baichuan') {
  try {
    reolink::ensureGo2rtcStreams($id);
    $eqLogic = reolink::byId($id, 'reolink');
  } catch (Exception $e) {
    echo '<div class="alert alert-danger">' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
    return;
  }
} else {
  reolink::syncTalkRoutesToDaemon($eqLogic, true);
}

$availableStreams = $eqLogic->getAvailableStreams();
if (empty($availableStreams)) {
  echo '<div class="alert alert-danger">' . __('Aucun flux disponible pour cet équipement', __FILE__) . '</div>';
  return;
}
$streamMetaJs = array();
foreach ($availableStreams as $sKey => $sMeta) {
  $streamMetaJs[$sKey] = array(
    'key' => $sKey,
    'label' => $sMeta['label'],
    'type' => $sMeta['type'],
  );
}
$firstStreamKey = array_key_first($streamMetaJs);
$hasTalk = (intval($eqLogic->getConfiguration('hasAudioTalk', 0)) === 1) && ($cnxType !== 'baichuan');

$cmdPtz = $eqLogic->getCmd(null, 'SetPTZmoove');
$cmdWhite = $eqLogic->getCmd(null, 'SetWhitLed');
$cmdSiren = $eqLogic->getCmd(null, 'AudioAlarmPlayTimes');
if (!is_object($cmdSiren)) {
  $cmdSiren = $eqLogic->getCmd(null, 'SetAudioAlarm');
}
$hasPtz = is_object($cmdPtz);
$ptzDirs = array();
if ($hasPtz) {
  $rawPtzList = strval($cmdPtz->getConfiguration('listValue', ''));
  if ($rawPtzList === '') {
    $ptzDirs = array('Up' => true, 'Left' => true, 'Right' => true, 'Down' => true);
  } else {
    foreach (explode(';', $rawPtzList) as $pair) {
      $bits = explode('|', $pair, 2);
      $dirKey = trim($bits[0]);
      if ($dirKey !== '') {
        $ptzDirs[$dirKey] = true;
      }
    }
  }
}
$hasWhite = is_object($cmdWhite);
$hasSiren = is_object($cmdSiren);
$whiteState = is_object($eqLogic->getCmd(null, 'SetWhitLedState')) ? intval($eqLogic->getCmd(null, 'SetWhitLedState')->execCmd()) : 0;
?>
<div class="row" id="reolinkLiveRoot">
  <div class="col-xs-12 text-center" style="margin-bottom:8px;">
    <div class="btn-group" id="divLiveStreamBtns">
<?php foreach ($streamMetaJs as $sKey => $sMeta) { ?>
      <a class="btn btn-default btn-sm btLiveQuality<?php echo ($sKey === $firstStreamKey) ? ' active' : ''; ?>" data-quality="<?php echo htmlspecialchars($sKey, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($sMeta['label'], ENT_QUOTES, 'UTF-8'); ?></a>
<?php } ?>
    </div>
<?php if ($cnxType !== 'baichuan') { ?>
    <a class="btn btn-default btn-sm" id="btLiveWebRtc" style="margin-left:6px;">{{WebRTC}}</a>
<?php } ?>
<?php if ($hasTalk) { ?>
    <a class="btn btn-default btn-sm" id="btLiveTalk" style="margin-left:6px;" title="{{Audio bidirectionnel (WebRTC + Baichuan)}}"><i class="fas fa-microphone-slash"></i> {{Parler}}</a>
<?php } ?>
    <span id="spanLiveInfo" class="label label-info" style="margin-left:8px;"><?php echo htmlspecialchars($firstStreamKey, ENT_QUOTES, 'UTF-8'); ?></span>
  </div>
  <div class="col-xs-12 text-center">
    <video id="reolinkLivePlayer" controls autoplay muted playsinline style="width:100%;max-height:70vh;background:#000;<?php echo ($firstStreamKey === 'jpeg') ? 'display:none;' : ''; ?>"></video>
    <img id="reolinkLiveJpeg" alt="Live JPEG" style="max-width:100%;max-height:70vh;background:#000;<?php echo ($firstStreamKey === 'jpeg') ? '' : 'display:none;'; ?>" />
    <div id="divLiveError" class="alert alert-warning" style="display:none;margin-top:8px;"></div>
  </div>
  <div class="col-xs-12 text-center" style="margin-top:8px;">
<?php if ($hasPtz) {
  $ptzButtons = array(
    'LeftUp' => '↖',
    'Up' => '↑',
    'RightUp' => '↗',
    'Left' => '←',
    'Right' => '→',
    'LeftDown' => '↙',
    'Down' => '↓',
    'RightDown' => '↘',
  );
?>
    <div class="btn-group" style="margin:2px;">
<?php foreach ($ptzButtons as $dirKey => $arrow) {
  if (!isset($ptzDirs[$dirKey])) {
    continue;
  } ?>
      <a class="btn btn-default btn-sm btLivePtz" data-dir="<?php echo $dirKey; ?>"><?php echo $arrow; ?></a>
<?php } ?>
    </div>
<?php } ?>
<?php if ($hasWhite) { ?>
    <a class="btn btn-default btn-sm" id="btLiveWhite"><?php echo __('Projecteur', __FILE__); ?></a>
<?php } ?>
<?php if ($hasSiren) { ?>
    <a class="btn btn-default btn-sm" id="btLiveSiren"><?php echo __('Sirène', __FILE__); ?></a>
<?php } ?>
    <a class="btn btn-default btn-sm" id="btLiveSnap"><?php echo __('Capture', __FILE__); ?></a>
  </div>
</div>
<script>
(function () {
  var player = document.getElementById('reolinkLivePlayer');
  var jpegImg = document.getElementById('reolinkLiveJpeg');
  var errBox = document.getElementById('divLiveError');
  var eqId = <?php echo json_encode(strval($id)); ?>;
  var streams = <?php echo json_encode($streamMetaJs); ?>;
  var quality = <?php echo json_encode($firstStreamKey); ?>;
  var pc = null;
  var micStream = null;
  var talkActive = false;
  var rtcTimer = null;
  var jpegTimer = null;
  var playGen = 0;
  var playTimer = null;
  var streamBase = 'plugins/reolink/core/php/stream.php?id=' + encodeURIComponent(eqId) + '&quality=';
  var snapBase = 'plugins/reolink/core/php/snap.php?id=' + encodeURIComponent(eqId);

  function showErr(msg) {
    if (!msg) { errBox.style.display = 'none'; errBox.textContent = ''; return; }
    errBox.textContent = msg;
    errBox.style.display = 'block';
  }

  function updateTalkBtn() {
    var $btn = $('#btLiveTalk');
    if (!$btn.length) { return; }
    $btn.toggleClass('btn-danger active', talkActive).toggleClass('btn-default', !talkActive);
    $btn.find('i').attr('class', talkActive ? 'fas fa-microphone' : 'fas fa-microphone-slash');
  }

  function stopMic() {
    if (micStream) {
      micStream.getTracks().forEach(function (t) { try { t.stop(); } catch (e) {} });
      micStream = null;
    }
    talkActive = false;
    updateTalkBtn();
  }

  function stopJpeg() {
    if (jpegTimer) { clearTimeout(jpegTimer); jpegTimer = null; }
    if (jpegImg) { jpegImg.removeAttribute('src'); }
  }

  function stopRtc() {
    if (rtcTimer) { clearTimeout(rtcTimer); rtcTimer = null; }
    if (pc) { try { pc.close(); } catch (e) {} pc = null; }
    if (player) { player.srcObject = null; }
  }

  function stopMse() {
    if (!player) { return; }
    try { player.pause(); } catch (e) {}
    try {
      player.removeAttribute('src');
      player.src = '';
      player.load();
    } catch (e) {}
  }

  function stopLive() {
    playGen++;
    if (playTimer) { clearTimeout(playTimer); playTimer = null; }
    stopMic();
    stopJpeg();
    stopRtc();
    stopMse();
    showErr('');
  }

  function playJpeg() {
    stopLive();
    if (player) { player.style.display = 'none'; }
    if (jpegImg) { jpegImg.style.display = ''; }
    var gen = playGen;
    $('#spanLiveInfo').text('jpeg / cmd 109');
    function tick() {
      if (gen !== playGen || !jpegImg || !document.getElementById('reolinkLiveJpeg')) { return; }
      jpegImg.onload = function () {
        if (gen === playGen) { jpegTimer = setTimeout(tick, 900); }
      };
      jpegImg.onerror = function () {
        if (gen === playGen) {
          showErr('{{Échec de capture JPEG (cmd 109)}}');
          jpegTimer = setTimeout(tick, 2500);
        }
      };
      jpegImg.src = snapBase + '&quality=sub&_=' + Date.now();
    }
    tick();
  }

  function playMse(q) {
    if (streams[q] && streams[q].type === 'jpeg') {
      playJpeg();
      return;
    }
    stopMic();
    stopJpeg();
    stopRtc();
    stopMse();
    showErr('');
    if (jpegImg) { jpegImg.style.display = 'none'; }
    if (player) { player.style.display = ''; }
    var gen = ++playGen;
    playTimer = setTimeout(function () {
      if (gen !== playGen || !player || !document.getElementById('reolinkLivePlayer')) { return; }
      player.src = streamBase + encodeURIComponent(q) + '&_=' + Date.now();
      player.play().catch(function () {});
      $('#spanLiveInfo').text(q + ' / fMP4');
    }, 80);
  }

  function playWebRtc(q, withMic) {
    if (streams[q] && streams[q].type === 'jpeg') {
      playJpeg();
      return;
    }
    if (!window.RTCPeerConnection) {
      playMse(q);
      return;
    }
    stopLive();
    if (jpegImg) { jpegImg.style.display = 'none'; }
    if (player) { player.style.display = ''; }
    var gen = playGen;

    var micPromise = Promise.resolve(null);
    if (withMic) {
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        showErr('{{Le microphone nécessite un accès HTTPS (ou localhost) dans le navigateur.}}');
        withMic = false;
      } else {
        micPromise = navigator.mediaDevices.getUserMedia({
          audio: {echoCancellation: true, noiseSuppression: true, autoGainControl: true}
        });
      }
    }

    micPromise.then(function (mStream) {
      if (gen !== playGen) {
        if (mStream) { mStream.getTracks().forEach(function (t) { t.stop(); }); }
        return;
      }
      if (mStream) {
        micStream = mStream;
        talkActive = true;
        player.muted = false;
      }
      updateTalkBtn();
      $('#spanLiveInfo').text(q + (talkActive ? ' / WebRTC + Micro…' : ' / WebRTC…'));

      var gotTrack = false;
      pc = new RTCPeerConnection({iceServers: []});
      // 3 separate transceivers: sendonly mic negotiates PCMA/8000 directly with baichuan_talk.py
      if (micStream && micStream.getAudioTracks().length > 0) {
        pc.addTransceiver(micStream.getAudioTracks()[0], {direction: 'sendonly'});
      }
      pc.addTransceiver('video', {direction: 'recvonly'});
      pc.addTransceiver('audio', {direction: 'recvonly'});

      var inboundStream = new MediaStream();
      pc.ontrack = function (ev) {
        if (gen !== playGen) { return; }
        gotTrack = true;
        if (rtcTimer) { clearTimeout(rtcTimer); rtcTimer = null; }
        if (ev.track) {
          inboundStream.addTrack(ev.track);
          player.srcObject = inboundStream;
        } else if (ev.streams && ev.streams[0]) {
          player.srcObject = ev.streams[0];
        }
        player.play().catch(function () {});
        $('#spanLiveInfo').text(q + (talkActive ? ' / WebRTC + Micro' : ' / WebRTC'));
        showErr('');
      };
      pc.oniceconnectionstatechange = function () {
        var st = pc ? pc.iceConnectionState : '';
        if (!gotTrack && (st === 'failed' || st === 'disconnected' || st === 'closed')) {
          if (gen === playGen) {
            playMse(q);
            showErr('{{WebRTC indisponible, bascule fMP4}}');
          }
        }
      };
      rtcTimer = setTimeout(function () {
        if (!gotTrack && gen === playGen) {
          playMse(q);
          showErr('{{WebRTC timeout, bascule fMP4}}');
        }
      }, 4500);

      return pc.createOffer().then(function (offer) {
        return pc.setLocalDescription(offer);
      }).then(function () {
        return new Promise(function (resolve) {
          if (pc.iceGatheringState === 'complete') { resolve(); return; }
          var t = setTimeout(resolve, 800);
          pc.onicegatheringstatechange = function () {
            if (pc.iceGatheringState === 'complete') { clearTimeout(t); resolve(); }
          };
        });
      }).then(function () {
        if (gen !== playGen) { return; }
        return $.ajax({
          type: 'POST',
          url: 'plugins/reolink/core/ajax/reolink.ajax.php',
          data: {action: 'webrtcOffer', id: eqId, quality: q, sdp: pc.localDescription.sdp},
          dataType: 'json'
        });
      }).then(function (res) {
        if (!pc || gen !== playGen) { return; }
        if (!res || res.state !== 'ok' || !res.result || !res.result.sdp) { throw new Error('no sdp'); }
        return pc.setRemoteDescription({type: 'answer', sdp: res.result.sdp});
      });
    }).catch(function (err) {
      if (gen === playGen) {
        var isPerm = err && (err.name === 'NotAllowedError' || err.name === 'NotFoundError');
        playMse(q);
        showErr(isPerm
          ? '{{Accès au microphone refusé ou aucun microphone détecté}}'
          : '{{WebRTC indisponible, bascule fMP4}}');
      }
    });
  }

  function setQuality(q) {
    quality = q;
    $('.btLiveQuality').each(function () {
      $(this).toggleClass('active', $(this).data('quality') === q);
    });
    if (talkActive) {
      playWebRtc(q, true);
    } else {
      playMse(q);
    }
  }

  if (player) {
    player.onerror = function () {
      if (!player || !player.getAttribute('src')) { return; }
      showErr((quality === 'main' || quality === 'autotrack_main')
        ? '{{Lecture impossible (codec High H.265 non supporté par ce navigateur ? Essayez Low ou Fluent).}}'
        : '{{Erreur de lecture du flux fMP4}}');
    };
  }

  var ptzId = <?php echo json_encode($hasPtz ? strval($cmdPtz->getId()) : ''); ?>;
  var whiteId = <?php echo json_encode($hasWhite ? strval($cmdWhite->getId()) : ''); ?>;
  var sirenId = <?php echo json_encode($hasSiren ? strval($cmdSiren->getId()) : ''); ?>;

  function execSelect(cmdId, val) {
    if (!cmdId) { return; }
    jeedom.cmd.execute({id: cmdId, value: {select: val}});
  }

  $('.btLiveQuality').off('click').on('click', function () {
    setQuality(String($(this).data('quality')));
  });
  $('#btLiveWebRtc').off('click').on('click', function () {
    playWebRtc(quality, false);
  });
  $('#btLiveTalk').off('click').on('click', function () {
    if (talkActive) {
      playWebRtc(quality, false);
    } else {
      playWebRtc(quality, true);
    }
  });

  var ptzStepMs = 400;
  var ptzBusy = false;
  var ptzTimer = null;
  $('.btLivePtz').off('click').on('click', function () {
    if (!ptzId || ptzBusy) { return; }
    ptzBusy = true;
    var dir = $(this).data('dir');
    execSelect(ptzId, dir);
    if (ptzTimer) { clearTimeout(ptzTimer); }
    ptzTimer = setTimeout(function () {
      execSelect(ptzId, 'Stop');
      ptzBusy = false;
      ptzTimer = null;
    }, ptzStepMs);
  });
  var whiteOn = <?php echo $whiteState ? 'true' : 'false'; ?>;
  $('#btLiveWhite').off('click').on('click', function () {
    whiteOn = !whiteOn;
    execSelect(whiteId, whiteOn ? 1 : 0);
  });
  $('#btLiveSiren').off('click').on('click', function () {
    execSelect(sirenId, 1);
  });
  $('#btLiveSnap').off('click').on('click', function () {
    var snapQ = (quality === 'main' || quality === 'autotrack_main') ? 'main' : 'sub';
    window.open(snapBase + '&quality=' + snapQ, '_blank');
  });

  $('#md_modal').off('dialogbeforeclose.reolinkLive dialogclose.reolinkLive')
    .on('dialogbeforeclose.reolinkLive dialogclose.reolinkLive', function () {
      stopLive();
    });
  $(window).off('beforeunload.reolinkLive').on('beforeunload.reolinkLive', function () {
    stopLive();
  });

  window.reolinkLiveStop = stopLive;
  setQuality(quality);
})();
</script>
