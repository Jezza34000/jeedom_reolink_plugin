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

$liveMeta = $eqLogic->buildLiveControlsMeta();
if (empty($liveMeta['streams'])) {
  echo '<div class="alert alert-danger">' . __('Aucun flux disponible pour cet équipement', __FILE__) . '</div>';
  return;
}
$streamMetaJs = $liveMeta['streams'];
$firstStreamKey = array_key_first($streamMetaJs);
$hasTalk = $liveMeta['hasTalk'];
$ctrls = $liveMeta['controls'];

$ptzButtons = array(
  'LeftUp' => '↖', 'Up' => '↑', 'RightUp' => '↗',
  'Left' => '←', 'Right' => '→',
  'LeftDown' => '↙', 'Down' => '↓', 'RightDown' => '↘',
);
?>
<div class="row" id="reolinkLiveRoot">
  <div class="col-xs-12 text-center" style="margin-bottom:8px;">
    <div class="btn-group">
<?php foreach ($streamMetaJs as $sKey => $sMeta) { ?>
      <a class="btn btn-default btn-sm rlkQualityBtn<?php echo ($sKey === $firstStreamKey) ? ' active' : ''; ?>" data-quality="<?php echo htmlspecialchars($sKey, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($sMeta['label'], ENT_QUOTES, 'UTF-8'); ?></a>
<?php } ?>
    </div>
<?php if ($cnxType !== 'baichuan') { ?>
    <a class="btn btn-default btn-sm rlkWebRtcBtn" style="margin-left:6px;">{{WebRTC}}</a>
<?php } ?>
<?php if ($hasTalk) { ?>
    <a class="btn btn-default btn-sm rlkTalkBtn" style="margin-left:6px;" title="{{Audio bidirectionnel (WebRTC + Baichuan)}}"><i class="fas fa-microphone-slash"></i> {{Parler}}</a>
<?php } ?>
    <a class="btn btn-default btn-sm rlkSnapBtn" style="margin-left:6px;" title="{{Capture JPEG}}"><i class="fas fa-camera"></i> {{Capture}}</a>
    <a class="btn btn-default btn-sm rlkFsBtn" style="margin-left:4px;" title="{{Plein écran}}"><i class="fas fa-expand"></i></a>
    <span class="label label-info rlkInfo" style="margin-left:8px;"><?php echo htmlspecialchars($firstStreamKey, ENT_QUOTES, 'UTF-8'); ?></span>
  </div>

  <div class="col-xs-12 text-center">
    <video class="rlkVideo" controls autoplay muted playsinline style="width:100%;max-height:66vh;background:#000;<?php echo ($firstStreamKey === 'jpeg') ? 'display:none;' : ''; ?>"></video>
    <img class="rlkJpeg" alt="Live JPEG" style="max-width:100%;max-height:66vh;background:#000;<?php echo ($firstStreamKey === 'jpeg') ? '' : 'display:none;'; ?>" />
    <div class="alert alert-warning rlkErr" style="display:none;margin-top:8px;"></div>
  </div>

  <div class="col-xs-12 text-center" style="margin-top:8px;display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:6px;">
<?php if (!empty($ctrls['ptz'])) {
  $ptzDirs = $ctrls['ptz']['dirs'];
?>
    <div class="btn-group">
<?php foreach ($ptzButtons as $dirKey => $arrow) {
  if (!isset($ptzDirs[$dirKey])) { continue; } ?>
      <a class="btn btn-default btn-sm rlkPtzBtn" data-dir="<?php echo htmlspecialchars($dirKey, ENT_QUOTES, 'UTF-8'); ?>"><?php echo $arrow; ?></a>
<?php } ?>
    </div>
<?php } ?>

<?php if (!empty($ctrls['zoom'])) { ?>
    <div class="btn-group" title="{{Zoom optique}}">
      <a class="btn btn-default btn-sm rlkZoomBtn" data-step="-3"><i class="fas fa-search-minus"></i></a>
      <a class="btn btn-default btn-sm rlkZoomBtn" data-step="3"><i class="fas fa-search-plus"></i></a>
    </div>
<?php } ?>

<?php if (!empty($ctrls['focus'])) { ?>
    <div class="btn-group" title="{{Mise au point (Focus)}}">
      <a class="btn btn-default btn-sm rlkFocusBtn" data-step="-8">{{Focus -}}</a>
      <a class="btn btn-default btn-sm rlkFocusBtn" data-step="8">{{Focus +}}</a>
    </div>
<?php } ?>

<?php if (!empty($ctrls['presets'])) { ?>
    <div style="display:inline-flex;align-items:center;gap:3px;">
      <select class="form-control input-sm rlkPresetSel" data-auto-go="1" style="width:auto;min-width:130px;">
        <option value="">{{— Preset PTZ —}}</option>
<?php foreach ($ctrls['presets']['list'] as $pr) { ?>
        <option value="<?php echo htmlspecialchars($pr['id'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($pr['label'], ENT_QUOTES, 'UTF-8'); ?></option>
<?php } ?>
      </select>
      <a class="btn btn-default btn-sm rlkPresetGoBtn" title="{{Aller au preset}}"><i class="fas fa-crosshairs"></i></a>
      <a class="btn btn-default btn-sm rlkPresetSaveBtn" title="{{Enregistrer la position actuelle comme preset PTZ}}"><i class="fas fa-save"></i></a>
    </div>
<?php } ?>

<?php if (!empty($ctrls['patrols']) && (!empty($ctrls['patrols']['list']) || $ctrls['patrols']['stopCmdId'] !== '')) { ?>
    <div style="display:inline-flex;align-items:center;gap:3px;">
<?php if (!empty($ctrls['patrols']['list'])) { ?>
      <select class="form-control input-sm rlkPatrolSel" style="width:auto;min-width:110px;">
<?php foreach ($ctrls['patrols']['list'] as $pt) { ?>
        <option value="<?php echo htmlspecialchars($pt['id'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($pt['label'], ENT_QUOTES, 'UTF-8'); ?></option>
<?php } ?>
      </select>
      <a class="btn btn-default btn-sm rlkPatrolStartBtn" title="{{Démarrer la ronde PTZ}}"><i class="fas fa-route"></i></a>
<?php } ?>
<?php if ($ctrls['patrols']['stopCmdId'] !== '') { ?>
      <a class="btn btn-default btn-sm rlkPatrolStopBtn" title="{{Arrêter la ronde PTZ}}"><i class="fas fa-stop"></i></a>
<?php } ?>
    </div>
<?php } ?>

<?php if (!empty($ctrls['quickReplies']) && !empty($ctrls['quickReplies']['list'])) { ?>
    <div style="display:inline-flex;align-items:center;gap:3px;">
      <select class="form-control input-sm rlkQuickReplySel" style="width:auto;min-width:140px;">
<?php foreach ($ctrls['quickReplies']['list'] as $qr) { ?>
        <option value="<?php echo htmlspecialchars($qr['id'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($qr['label'], ENT_QUOTES, 'UTF-8'); ?></option>
<?php } ?>
      </select>
      <a class="btn btn-info btn-sm rlkQuickReplyBtn" title="{{Diffuser ce message vocal sur la sonnette}}"><i class="fas fa-comment-dots"></i> {{Répondre}}</a>
    </div>
<?php } ?>

<?php if (!empty($ctrls['whiteLed'])) { ?>
    <a class="btn btn-default btn-sm rlkWhiteBtn"><i class="fas fa-lightbulb"></i> <?php echo __('Projecteur', __FILE__); ?></a>
<?php } ?>
<?php if (!empty($ctrls['siren'])) { ?>
    <a class="btn btn-default btn-sm rlkSirenBtn"><i class="fas fa-bullhorn"></i> <?php echo __('Sirène', __FILE__); ?></a>
<?php } ?>
  </div>
</div>
<?php include_file('desktop', 'reolink.player', 'js', 'reolink'); ?>
<script>
(function () {
  var livePlayer = new window.ReolinkLivePlayer({
    root: '#reolinkLiveRoot',
    eqId: <?php echo json_encode(strval($id)); ?>,
    streams: <?php echo json_encode($streamMetaJs); ?>,
    initialQuality: <?php echo json_encode($firstStreamKey); ?>,
    mode: 'live',
    isBattery: <?php echo $liveMeta['isBattery'] ? 'true' : 'false'; ?>,
    controls: <?php echo json_encode($ctrls); ?>,
    i18n: {
      jpegErr: '{{Échec de capture JPEG}}',
      micHttps: '{{Le microphone nécessite un accès HTTPS (ou localhost) dans le navigateur.}}',
      micDenied: '{{Accès au microphone refusé ou aucun microphone détecté}}',
      rtcFallback: '{{WebRTC indisponible, bascule fMP4}}',
      rtcTimeout: '{{WebRTC timeout, bascule fMP4}}',
      h265Err: '{{Lecture impossible (codec High H.265 non supporté par ce navigateur ? Essayez Low ou Fluent).}}',
      mseErr: '{{Erreur de lecture du flux fMP4}}',
      presetSlotPrompt: '{{Numéro du preset PTZ à enregistrer (0..63) :}}',
      presetNamePrompt: '{{Nom du preset PTZ :}}'
    }
  });
  $('#md_modal').off('dialogbeforeclose.reolinkLive dialogclose.reolinkLive')
    .on('dialogbeforeclose.reolinkLive dialogclose.reolinkLive', function () {
      if (livePlayer) { livePlayer.destroy(); }
    });
  window.reolinkLiveStop = function () {
    if (livePlayer) { livePlayer.stopLive(); }
  };
})();
</script>
