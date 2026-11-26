<?php
require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
include_file('core', 'authentification', 'php');

if (!reolink::isPluginActiveFast()) {
  http_response_code(503);
  exit;
}
$id = intval(init('id', 0));
$quality = (init('quality', 'sub') === 'main') ? 'main' : 'sub';
$noWake = (intval(init('no_wake', 0)) === 1);
$eq = reolink::byId($id, 'reolink');
if (!is_object($eq) || $eq->isNvrParent()) {
  http_response_code(404);
  exit;
}
if (!reolink::isAuthorizedForLive($eq, init('token', ''))) {
  http_response_code(401);
  exit;
}
if (intval($eq->getIsEnable()) !== 1) {
  http_response_code(503);
  exit;
}
if ($eq->isStandalonePrivacyBlocking()) {
  http_response_code(204);
  exit;
}
$jpeg = reolink::captureSnapshotSafe($eq, $quality, $noWake);
if ($jpeg === '') {
  http_response_code(($eq->hasBattery() && ($noWake || $eq->isBatterySleeping())) ? 204 : 502);
  exit;
}
header('Content-Type: image/jpeg');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Content-Length: ' . strlen($jpeg));
echo $jpeg;
