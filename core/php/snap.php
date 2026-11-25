<?php
require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';
include_file('core', 'authentification', 'php');

if (!isConnect()) {
  http_response_code(401);
  exit;
}

if (!reolink::isPluginActiveFast()) {
  http_response_code(503);
  exit;
}
$id = intval(init('id', 0));
$quality = (init('quality', 'sub') === 'main') ? 'main' : 'sub';
$eq = reolink::byId($id, 'reolink');
if (!is_object($eq) || $eq->isNvrParent()) {
  http_response_code(404);
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
$jpeg = reolink::captureSnapshotSafe($eq, $quality);
if ($jpeg === '') {
  http_response_code(($eq->hasBattery() && $eq->isBatterySleeping()) ? 204 : 502);
  exit;
}
header('Content-Type: image/jpeg');
header('Content-Length: ' . strlen($jpeg));
echo $jpeg;
