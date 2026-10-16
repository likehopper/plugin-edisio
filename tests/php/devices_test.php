<?php

/*
 * Consistency checks of the device definitions (core/config/devices) and plugin_info JSON files.
 * Runs without Jeedom: php tests/php/devices_test.php
 */

$root = dirname(__DIR__, 2);
require_once $root . '/core/php/edisio.inc.php';
$failures = 0;

function check($condition, $message) {
  global $failures;
  if (!$condition) {
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    $failures++;
  }
}

/* Same checks as test_edisio() in resources/edisiod/edisiod.py. */
function isValidFrame($frame) {
  return ctype_xdigit($frame)
    && strlen($frame) % 2 == 0
    && strlen($frame) >= 32
    && strtoupper(substr($frame, 0, 6)) == '6C7663'
    && strtoupper(substr($frame, -6)) == '640D0A';
}

foreach (array('info.json', 'packages.json') as $file) {
  check(is_array(json_decode(file_get_contents($root . '/plugin_info/' . $file), true)), 'plugin_info/' . $file . ' is valid JSON');
}

$devicesDir = $root . '/core/config/devices';
$codes = array();
foreach (glob($devicesDir . '/*', GLOB_ONLYDIR) as $dir) {
  $code = basename($dir);
  $file = $dir . '/' . $code . '.json';
  check(file_exists($file), $code . ': ' . $code . '.json exists');
  check(count(glob($dir . '/*.json')) == 1, $code . ': exactly one JSON file');
  if (!file_exists($file)) {
    continue;
  }
  $content = json_decode(file_get_contents($file), true);
  check(is_array($content), $code . ': valid JSON');
  if (!is_array($content)) {
    continue;
  }
  /* edisio::getModelList() only looks for images in the folder named after the device code. */
  check(array_keys($content) == array($code), $code . ': JSON key matches the folder name');
  $codes[] = $code;
  $device = reset($content);
  check(isset($device['name']) && $device['name'] != '', $code . ': has a name');
  $logicalIds = array();
  foreach (isset($device['commands']) ? $device['commands'] : array() as $command) {
    $name = $code . ' / ' . (isset($command['name']) ? $command['name'] : '?');
    check(isset($command['type'], $command['subtype']), $name . ': type and subtype are set');
    if (!isset($command['type'])) {
      continue;
    }
    if ($command['type'] != 'action') {
      /* Info commands without logicalId are state commands fed by updateCmdId. */
      if (isset($command['logicalId']) && $command['logicalId'] != '') {
        check(!in_array($command['logicalId'], $logicalIds), $name . ': info logicalId is unique');
        $logicalIds[] = $command['logicalId'];
      }
      continue;
    }
    check(isset($command['logicalId']), $name . ': action has a logicalId');
    if (!isset($command['logicalId'])) {
      continue;
    }
    $group = isset($command['configuration']['group']) ? $command['configuration']['group'] : '01';
    $options = array('slider' => 50, 'color' => '000000');
    foreach (edisio_build_frames($command['logicalId'], '0A1B2C3D', $group, $command['subtype'], $options) as $frame) {
      check(isValidFrame($frame), $name . ': valid frame ' . $frame);
    }
  }
}
check(count($codes) > 0, 'device definitions found');

if ($failures > 0) {
  fwrite(STDERR, $failures . ' failure(s)' . PHP_EOL);
  exit(1);
}
echo 'OK: ' . count($codes) . ' device definitions checked' . PHP_EOL;
