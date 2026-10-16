<?php

require_once __DIR__ . '/../../core/php/edisio.inc.php';

function assertFrames($expected, $actual, $message) {
  if ($expected !== $actual) {
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL . '  expected ' . json_encode($expected) . PHP_EOL . '  got      ' . json_encode($actual) . PHP_EOL);
    exit(1);
  }
  echo 'PASS: ' . $message . PHP_EOL;
}

$id = '0A1B2C3D';

assertFrames(array('6C76630A1B2C3D01011E010003640D0A'), edisio_build_frames('6C7663#ID##GROUP#011E010003640D0A', $id, 1, 'other'), 'id and one-digit group are substituted');
assertFrames(array('6C76630A1B2C3D12011E010003640D0A'), edisio_build_frames('6C7663#ID##GROUP#011E010003640D0A', $id, '12', 'other'), 'two-digit group is kept');
assertFrames(array('6C76630A1B2C3D01011E010003640D0A'), edisio_build_frames('6C7663#ID##GROUP#011E010003640D0A', $id, '', 'other'), 'empty group defaults to 01');
assertFrames(array('6C76630A1B2C3D09011F000010640D0A'), edisio_build_frames('6C7663#ID#09011F000010640D0A', $id, '01', 'other'), 'template without group');
assertFrames(
  array('6C76630A1B2C3D02021E010001640D0A', '6C76630A1B2C3D02021E010009640D0A'),
  edisio_build_frames('6C7663#ID##GROUP#021E010001640D0A&&6C7663#ID##GROUP#021E010009640D0A$', $id, 2, 'other'),
  'frames are split on && and the trailing $ is removed'
);

$slider = '6C7663#ID##GROUP#051E010004#slider#640D0A';
assertFrames(array('6C76630A1B2C3D01051E01000432640D0A'), edisio_build_frames($slider, $id, 1, 'slider', array('slider' => 50)), 'slider value is sent in hex');
assertFrames(array('6C76630A1B2C3D01051E01000405640D0A'), edisio_build_frames($slider, $id, 1, 'slider', array('slider' => '5')), 'slider value is padded to two digits');
assertFrames(array('6C76630A1B2C3D01051E01000464640D0A'), edisio_build_frames($slider, $id, 1, 'slider', array('slider' => 100)), 'slider 100 is 64');
assertFrames(array('6C76630A1B2C3D01051E010002640D0A'), edisio_build_frames($slider, $id, 1, 'slider', array('slider' => 0)), 'slider 0 sends the off command (02) instead of level 00');
