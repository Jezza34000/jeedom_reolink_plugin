<?php
if (!isConnect()) {
  throw new Exception(__('401 - Accès non autorisé', __FILE__));
}
$id = init('id');
$eqLogic = reolink::byId($id, 'reolink');
if (!is_object($eqLogic) || $eqLogic->isNvrParent()) {
  throw new Exception(__('Équipement Reolink invalide', __FILE__));
}
try {
  reolink::ensureGo2rtcStreams($id);
} catch (Exception $e) {
  echo '<div class="alert alert-danger">' . $e->getMessage() . '</div>';
  return;
}
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
    <div class="btn-group">
      <a class="btn btn-default btn-sm active" id="btLiveQualitySub">{{Low (sub)}}</a>
      <a class="btn btn-default btn-sm" id="btLiveQualityMain">{{High (main)}}</a>
    </div>
    <a class="btn btn-default btn-sm" id="btLiveWebRtc" style="margin-left:6px;">{{WebRTC}}</a>
    <span id="spanLiveInfo" class="label label-info" style="margin-left:8px;">sub / fMP4</span>
  </div>
  <div class="col-xs-12 text-center">
    <video id="reolinkLivePlayer" controls autoplay muted playsinline style="width:100%;max-height:70vh;background:#000;"></video>
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
  var errBox = document.getElementById('divLiveError');
  var eqId = <?php echo json_encode(strval($id)); ?>;
  var quality = 'sub';
  var pc = null;
  var rtcTimer = null;
  var playGen = 0;
  var playTimer = null;
  var streamBase = 'plugins/reolink/core/php/stream.php?id=' + encodeURIComponent(eqId) + '&quality=';

  function showErr(msg) {
    if (!msg) { errBox.style.display = 'none'; errBox.textContent = ''; return; }
    errBox.textContent = msg;
    errBox.style.display = 'block';
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
    stopRtc();
    stopMse();
    showErr('');
  }

  function playMse(q) {
    stopRtc();
    stopMse();
    showErr('');
    var gen = ++playGen;
    // laisser le navigateur annuler l'ancien GET stream.php avant d'en ouvrir un nouveau
    playTimer = setTimeout(function () {
      if (gen !== playGen || !player || !document.getElementById('reolinkLivePlayer')) { return; }
      player.src = streamBase + q + '&_=' + Date.now();
      player.play().catch(function () {});
      $('#spanLiveInfo').text(q + ' / fMP4');
    }, 80);
  }

  function playWebRtc(q) {
    if (!window.RTCPeerConnection) {
      playMse(q);
      return;
    }
    stopLive();
    showErr('');
    $('#spanLiveInfo').text(q + ' / WebRTC…');
    var gen = playGen;
    var gotTrack = false;
    pc = new RTCPeerConnection({iceServers: []});
    pc.addTransceiver('video', {direction: 'recvonly'});
    pc.addTransceiver('audio', {direction: 'recvonly'});
    pc.ontrack = function (ev) {
      if (gen !== playGen) { return; }
      gotTrack = true;
      if (rtcTimer) { clearTimeout(rtcTimer); rtcTimer = null; }
      player.srcObject = ev.streams[0];
      player.play().catch(function () {});
      $('#spanLiveInfo').text(q + ' / WebRTC');
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
    }, 3000);
    pc.createOffer().then(function (offer) {
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
      if (gotTrack || !pc || gen !== playGen) { return; }
      if (!res || !res.state || !res.result || !res.result.sdp) { throw new Error('no sdp'); }
      return pc.setRemoteDescription({type: 'answer', sdp: res.result.sdp});
    }).catch(function () {
      if (!gotTrack && gen === playGen) {
        playMse(q);
        showErr('{{WebRTC indisponible, bascule fMP4}}');
      }
    });
  }

  function setQuality(q) {
    quality = q;
    $('#btLiveQualitySub').toggleClass('active', q === 'sub');
    $('#btLiveQualityMain').toggleClass('active', q === 'main');
    playMse(q);
  }

  player.onerror = function () {
    if (!player || !player.getAttribute('src')) { return; }
    showErr(quality === 'main'
      ? '{{Lecture impossible (codec High H.265 non supporté par ce navigateur ? Essayez Low).}}'
      : '{{Erreur de lecture du flux fMP4}}');
  };

  var ptzId = <?php echo json_encode($hasPtz ? strval($cmdPtz->getId()) : ''); ?>;
  var whiteId = <?php echo json_encode($hasWhite ? strval($cmdWhite->getId()) : ''); ?>;
  var sirenId = <?php echo json_encode($hasSiren ? strval($cmdSiren->getId()) : ''); ?>;

  function execSelect(cmdId, val) {
    if (!cmdId) { return; }
    jeedom.cmd.execute({id: cmdId, value: {select: val}});
  }

  $('#btLiveQualitySub').off('click').on('click', function () { setQuality('sub'); });
  $('#btLiveQualityMain').off('click').on('click', function () { setQuality('main'); });
  $('#btLiveWebRtc').off('click').on('click', function () { playWebRtc(quality); });
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
    window.open('plugins/reolink/core/php/snap.php?id=' + encodeURIComponent(eqId) + '&quality=' + quality, '_blank');
  });

  // Couper le flux dès que la modale se ferme / est remplacée (sinon stream.php bloque PHP)
  $('#md_modal').off('dialogbeforeclose.reolinkLive dialogclose.reolinkLive')
    .on('dialogbeforeclose.reolinkLive dialogclose.reolinkLive', function () {
      stopLive();
    });
  $(window).off('beforeunload.reolinkLive').on('beforeunload.reolinkLive', function () {
    stopLive();
  });

  window.reolinkLiveStop = stopLive;
  setQuality('sub');
})();
</script>
