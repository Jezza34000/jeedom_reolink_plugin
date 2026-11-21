<?php
require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
include_file('core', 'authentification', 'php');

if (!isConnect()) {
  http_response_code(401);
  echo 'Unauthorized';
  exit;
}

$id = intval(init('id', 0));
$quality = (init('quality', 'sub') === 'main') ? 'main' : 'sub';
$camera = reolink::byId($id, 'reolink');
if (!is_object($camera) || $camera->isNvrParent()) {
  http_response_code(404);
  echo 'Camera not found';
  exit;
}
if ($camera->isStandalonePrivacyBlocking()) {
  http_response_code(409);
  echo 'Mode confidentialité actif';
  exit;
}

try {
  reolink::ensureGo2rtcStreams($id);
} catch (Exception $e) {
  http_response_code(500);
  echo $e->getMessage();
  exit;
}

$src = 'reolink_' . $id . '_' . $quality;
$url = 'http://127.0.0.1:1984/api/stream.mp4?src=' . rawurlencode($src);

// false = PHP s'arrête quand le navigateur annule (changement qualité / fermeture modale)
ignore_user_abort(false);
@set_time_limit(0);
while (ob_get_level() > 0) {
  ob_end_clean();
}
header('Content-Type: video/mp4');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Connection: close');
header('X-Accel-Buffering: no');
if (function_exists('apache_setenv')) {
  @apache_setenv('no-gzip', '1');
}
@ini_set('zlib.output_compression', '0');
@ini_set('implicit_flush', '1');

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
curl_setopt($ch, CURLOPT_TIMEOUT, 0);
curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($curl, $data) {
  if (connection_aborted() || connection_status() !== CONNECTION_NORMAL) {
    return 0;
  }
  echo $data;
  if (ob_get_level() === 0) {
    flush();
  }
  return strlen($data);
});
curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);
if ($code && $code >= 400 && !connection_aborted()) {
  log::add('reolink', 'debug', 'stream.php go2rtc HTTP ' . $code . ' ' . $err);
}
