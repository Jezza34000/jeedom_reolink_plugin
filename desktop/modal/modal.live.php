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
$hasWhite = is_object($cmdWhite);
$hasSiren = is_object($cmdSiren);
$whiteState = is_object($eqLogic->getCmd(null, 'SetWhitLedState')) ? intval($eqLogic->getCmd(null, 'SetWhitLedState')->execCmd()) : 0;
?>
<div class="row">
  <div class="col-xs-12 text-center" style="margin-bottom:8px;">
    <div class="btn-group">
      <a class="btn btn-default btn-sm active" id="btLiveQualitySub">{{Low (sub)}}</a>
      <a class="btn btn-default btn-sm" id="btLiveQualityMain">{{High (main)}}</a>
    </div>
    <span id="spanLiveInfo" class="label label-info" style="margin-left:8px;">sub / MSE</span>
  </div>
  <div class="col-xs-12 text-center">
    <video id="reolinkLivePlayer" controls autoplay muted playsinline style="width:100%;max-height:70vh;background:#000;"></video>
  </div>
  <div class="col-xs-12 text-center" style="margin-top:8px;">
<?php if ($hasPtz) { ?>
    <div class="btn-group" style="margin:2px;">
      <a class="btn btn-default btn-sm btLivePtz" data-dir="Up">↑</a>
      <a class="btn btn-default btn-sm btLivePtz" data-dir="Left">←</a>
      <a class="btn btn-default btn-sm btLivePtz" data-dir="Right">→</a>
      <a class="btn btn-default btn-sm btLivePtz" data-dir="Down">↓</a>
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
  var eqId = <?php echo json_encode(strval($id)); ?>;
  var quality = 'sub';
  var pc = null;
  var streamBase = 'plugins/reolink/core/php/stream.php?id=' + encodeURIComponent(eqId) + '&quality=';

  function stopRtc() {
    if (pc) { try { pc.close(); } catch (e) {} pc = null; }
    player.srcObject = null;
  }

  function playMse(q) {
    stopRtc();
    player.src = streamBase + q + '&_=' + Date.now();
    player.play().catch(function () {});
    $('#spanLiveInfo').text(q + ' / fMP4');
  }

  function playWebRtc(q) {
    stopRtc();
    player.removeAttribute('src');
    pc = new RTCPeerConnection({iceServers: []});
    pc.addTransceiver('video', {direction: 'recvonly'});
    pc.addTransceiver('audio', {direction: 'recvonly'});
    pc.ontrack = function (ev) {
      player.srcObject = ev.streams[0];
      player.play().catch(function () {});
      $('#spanLiveInfo').text(q + ' / WebRTC');
    };
    var done = false;
    var fallback = setTimeout(function () { if (!done) { done = true; playMse(q); } }, 2500);
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
      return $.ajax({
        type: 'POST',
        url: 'plugins/reolink/core/ajax/reolink.ajax.php',
        data: {action: 'webrtcOffer', id: eqId, quality: q, sdp: pc.localDescription.sdp},
        dataType: 'json'
      });
    }).then(function (res) {
      if (done) { return; }
      if (!res || !res.state || !res.result || !res.result.sdp) { throw new Error('no sdp'); }
      return pc.setRemoteDescription({type: 'answer', sdp: res.result.sdp});
    }).then(function () {
      if (!done) { done = true; clearTimeout(fallback); }
    }).catch(function () {
      if (!done) { done = true; clearTimeout(fallback); playMse(q); }
    });
  }

  function setQuality(q) {
    quality = q;
    $('#btLiveQualitySub').toggleClass('active', q === 'sub');
    $('#btLiveQualityMain').toggleClass('active', q === 'main');
    if (window.RTCPeerConnection) { playWebRtc(q); } else { playMse(q); }
  }

  $('#btLiveQualitySub').off('click').on('click', function () { setQuality('sub'); });
  $('#btLiveQualityMain').off('click').on('click', function () { setQuality('main'); });
  $('.btLivePtz').off('click').on('click', function () {
    jeedom.cmd.execute({id: <?php echo json_encode($hasPtz ? strval($cmdPtz->getId()) : ''); ?>, value: $(this).data('dir')});
  });
  var whiteOn = <?php echo $whiteState ? 'true' : 'false'; ?>;
  $('#btLiveWhite').off('click').on('click', function () {
    whiteOn = !whiteOn;
    jeedom.cmd.execute({id: <?php echo json_encode($hasWhite ? strval($cmdWhite->getId()) : ''); ?>, value: whiteOn ? 1 : 0});
  });
  $('#btLiveSiren').off('click').on('click', function () {
    jeedom.cmd.execute({id: <?php echo json_encode($hasSiren ? strval($cmdSiren->getId()) : ''); ?>, value: 1});
  });
  $('#btLiveSnap').off('click').on('click', function () {
    window.open('plugins/reolink/core/php/snap.php?id=' + encodeURIComponent(eqId) + '&quality=' + quality, '_blank');
  });
  setQuality('sub');
})();
</script>
