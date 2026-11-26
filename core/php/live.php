<?php
require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
include_file('core', 'authentification', 'php');

header('Referrer-Policy: no-referrer');
header('Cache-Control: no-cache, no-store, must-revalidate');

if (!reolink::isPluginActiveFast()) {
  http_response_code(503);
  echo 'Plugin Reolink disabled';
  exit;
}

$id = intval(init('id', 0));
$token = trim(strval(init('token', '')));
$eqLogic = reolink::byId($id, 'reolink');
if (!is_object($eqLogic) || $eqLogic->isNvrParent() || intval($eqLogic->getIsEnable()) !== 1) {
  http_response_code(404);
  echo 'Camera not found';
  exit;
}
if (!reolink::isAuthorizedForLive($eqLogic, $token)) {
  http_response_code(401);
  echo 'Unauthorized';
  exit;
}

$action = strval(init('action', ''));
if ($action === 'execCmd') {
  header('Content-Type: application/json; charset=UTF-8');
  try {
    $cmdId = intval(init('cmdId', 0));
    $cmd = cmd::byId($cmdId);
    $allowedLogical = array(
      'SetPTZmoove', 'SetZoom', 'SetFocus', 'SetPtzByPreset',
      'StartPtzPatrol', 'StopPtzPatrol', 'QuickReplyPlay',
      'SetWhitLed', 'AudioAlarmPlayTimes', 'SetAudioAlarm',
    );
    if (!is_object($cmd) || intval($cmd->getEqLogic_id()) !== $id || !in_array($cmd->getLogicalId(), $allowedLogical, true)) {
      throw new Exception(__('Commande non autorisée', __FILE__));
    }
    $opts = array();
    if (init('select', null) !== null) {
      $opts['select'] = strval(init('select'));
    }
    if (init('slider', null) !== null) {
      $opts['slider'] = intval(init('slider'));
    }
    $cmd->execCmd($opts);
    echo json_encode(array('state' => 'ok', 'result' => true));
  } catch (Exception $e) {
    echo json_encode(array('state' => 'error', 'result' => $e->getMessage()));
  }
  exit;
}

if ($action === 'SavePtzPreset') {
  header('Content-Type: application/json; charset=UTF-8');
  try {
    $presets = reolink::savePtzPreset($id, intval(init('presetId', 0)), strval(init('name', '')), 1);
    echo json_encode(array('state' => 'ok', 'result' => array('presets' => $presets)));
  } catch (Exception $e) {
    echo json_encode(array('state' => 'error', 'result' => $e->getMessage()));
  }
  exit;
}

$cnxType = strtolower(trim(strval($eqLogic->getConfiguration('cnxtype', ''))));
if ($cnxType !== 'baichuan') {
  try {
    reolink::ensureGo2rtcStreams($id);
    $eqLogic = reolink::byId($id, 'reolink');
  } catch (Exception $e) {
    http_response_code(500);
    echo htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    exit;
  }
} else {
  reolink::syncTalkRoutesToDaemon($eqLogic, true);
}

$liveMeta = $eqLogic->buildLiveControlsMeta();
$streamMetaJs = $liveMeta['streams'];
$firstStreamKey = !empty($streamMetaJs) ? array_key_first($streamMetaJs) : 'sub';
$hasTalk = $liveMeta['hasTalk'];
$ctrls = $liveMeta['controls'];
$camTitle = htmlspecialchars($eqLogic->getName(), ENT_QUOTES, 'UTF-8');
$ptzButtons = array(
  'LeftUp' => '↖', 'Up' => '↑', 'RightUp' => '↗',
  'Left' => '←', 'Right' => '→',
  'LeftDown' => '↙', 'Down' => '↓', 'RightDown' => '↘',
);
?><!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
  <title><?php echo $camTitle; ?> — Reolink Live</title>
  <link rel="stylesheet" href="../../../../3rdparty/font-awesome5/css/all.min.css">
  <style>
    * { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; background: #0d1117; color: #e6edf3; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; height: 100%; }
    #rlkWebViewRoot { display: flex; flex-direction: column; min-height: 100vh; }
    .rlkBar { display: flex; flex-wrap: wrap; align-items: center; justify-content: center; gap: 6px; padding: 8px 10px; background: #161b22; border-bottom: 1px solid #30363d; }
    .rlkStage { flex: 1 1 auto; display: flex; flex-direction: column; align-items: center; justify-content: center; background: #000; position: relative; min-height: 220px; }
    .rlkVideo, .rlkJpeg { width: 100%; max-height: calc(100vh - 135px); object-fit: contain; background: #000; }
    .btn { display: inline-flex; align-items: center; gap: 5px; padding: 6px 11px; border-radius: 6px; border: 1px solid #30363d; background: #21262d; color: #e6edf3; font-size: 13px; text-decoration: none; cursor: pointer; user-select: none; }
    .btn.active, .btn-primary { background: #1f6feb; border-color: #388bfd; color: #fff; }
    .btn-danger, .btn-danger.active { background: #da3633; border-color: #f85149; color: #fff; }
    .btn-warning { background: #9e6a03; border-color: #d29922; color: #fff; }
    .btn-info { background: #0969da; border-color: #388bfd; color: #fff; }
    select.rlkSel { padding: 6px 8px; border-radius: 6px; border: 1px solid #30363d; background: #21262d; color: #e6edf3; font-size: 13px; }
    .rlkInfo { font-size: 11px; padding: 3px 7px; border-radius: 10px; background: #30363d; color: #8b949e; }
    .rlkErr { width: 94%; margin: 6px auto; padding: 6px 10px; border-radius: 6px; background: #5a1e02; border: 1px solid #bd561d; color: #ffa657; font-size: 12px; text-align: center; }
  </style>
</head>
<body>
<div id="rlkWebViewRoot">
  <div class="rlkBar">
    <strong style="margin-right:4px;font-size:14px;"><?php echo $camTitle; ?></strong>
    <select class="rlkSel rlkQualitySel">
<?php foreach ($streamMetaJs as $sKey => $sMeta) { ?>
      <option value="<?php echo htmlspecialchars($sKey, ENT_QUOTES, 'UTF-8'); ?>"<?php echo ($sKey === $firstStreamKey) ? ' selected' : ''; ?>><?php echo htmlspecialchars($sMeta['label'], ENT_QUOTES, 'UTF-8'); ?></option>
<?php } ?>
    </select>
<?php if ($cnxType !== 'baichuan') { ?>
    <a class="btn rlkWebRtcBtn">WebRTC</a>
<?php } ?>
<?php if ($hasTalk) { ?>
    <a class="btn rlkTalkBtn"><i class="fas fa-microphone-slash"></i> <?php echo __('Parler', __FILE__); ?></a>
<?php } ?>
    <a class="btn rlkSnapBtn" title="Capture"><i class="fas fa-camera"></i></a>
    <a class="btn rlkFsBtn" title="Plein écran"><i class="fas fa-expand"></i></a>
    <span class="rlkInfo"><?php echo htmlspecialchars($firstStreamKey, ENT_QUOTES, 'UTF-8'); ?></span>
  </div>

  <div class="rlkStage">
    <video class="rlkVideo" controls autoplay muted playsinline style="<?php echo ($firstStreamKey === 'jpeg') ? 'display:none;' : ''; ?>"></video>
    <img class="rlkJpeg" alt="<?php echo $camTitle; ?>" style="<?php echo ($firstStreamKey === 'jpeg') ? '' : 'display:none;'; ?>" />
    <div class="rlkErr" style="display:none;"></div>
  </div>

  <div class="rlkBar" style="border-top:1px solid #30363d;border-bottom:none;">
<?php if (!empty($ctrls['ptz'])) {
  $ptzDirs = $ctrls['ptz']['dirs'];
  foreach ($ptzButtons as $dirKey => $arrow) {
    if (!isset($ptzDirs[$dirKey])) { continue; } ?>
    <a class="btn rlkPtzBtn" data-dir="<?php echo htmlspecialchars($dirKey, ENT_QUOTES, 'UTF-8'); ?>"><?php echo $arrow; ?></a>
<?php }
} ?>
<?php if (!empty($ctrls['zoom'])) { ?>
    <a class="btn rlkZoomBtn" data-step="-3"><i class="fas fa-search-minus"></i></a>
    <a class="btn rlkZoomBtn" data-step="3"><i class="fas fa-search-plus"></i></a>
<?php } ?>
<?php if (!empty($ctrls['presets']) && !empty($ctrls['presets']['list'])) { ?>
    <select class="rlkSel rlkPresetSel" data-auto-go="1">
      <option value="">— Preset —</option>
<?php foreach ($ctrls['presets']['list'] as $pr) { ?>
      <option value="<?php echo htmlspecialchars($pr['id'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($pr['label'], ENT_QUOTES, 'UTF-8'); ?></option>
<?php } ?>
    </select>
<?php } ?>
<?php if (!empty($ctrls['quickReplies']) && !empty($ctrls['quickReplies']['list'])) { ?>
    <select class="rlkSel rlkQuickReplySel">
<?php foreach ($ctrls['quickReplies']['list'] as $qr) { ?>
      <option value="<?php echo htmlspecialchars($qr['id'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($qr['label'], ENT_QUOTES, 'UTF-8'); ?></option>
<?php } ?>
    </select>
    <a class="btn btn-info rlkQuickReplyBtn"><i class="fas fa-comment-dots"></i> <?php echo __('Répondre', __FILE__); ?></a>
<?php } ?>
<?php if (!empty($ctrls['whiteLed'])) { ?>
    <a class="btn rlkWhiteBtn"><i class="fas fa-lightbulb"></i></a>
<?php } ?>
<?php if (!empty($ctrls['siren'])) { ?>
    <a class="btn rlkSirenBtn"><i class="fas fa-bullhorn"></i></a>
<?php } ?>
  </div>
</div>
<script src="../../desktop/js/reolink.player.js"></script>
<script>
new window.ReolinkLivePlayer({
  root: '#rlkWebViewRoot',
  eqId: <?php echo json_encode(strval($id)); ?>,
  token: <?php echo json_encode($token); ?>,
  basePath: '../../',
  streams: <?php echo json_encode($streamMetaJs); ?>,
  initialQuality: <?php echo json_encode($firstStreamKey); ?>,
  mode: 'live',
  isBattery: <?php echo $liveMeta['isBattery'] ? 'true' : 'false'; ?>,
  controls: <?php echo json_encode($ctrls); ?>
});
</script>
</body>
</html>
