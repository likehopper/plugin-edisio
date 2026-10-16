<?php

require_once __DIR__ . '/../../core/php/edisio.inc.php';

function assertBan($condition, $message) {
  if (!$condition) {
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
  }
  echo 'PASS: ' . $message . PHP_EOL;
}

assertBan(edisio_is_banned('1356AD87', '1356AD87 DB54AF'), 'id in a space separated list');
assertBan(edisio_is_banned('DB54AF', "1356AD87\nDB54AF"), 'id on its own line (textarea)');
assertBan(edisio_is_banned('DB54AF', '1356AD87,  DB54AF ;'), 'commas, semicolons and extra spaces');
assertBan(edisio_is_banned('1356AD87', '1356ad87'), 'case is ignored');
assertBan(!edisio_is_banned('1356AD8', '1356AD87'), 'no partial match');
assertBan(!edisio_is_banned('1356AD87', ''), 'empty list bans nothing');
assertBan(!edisio_is_banned('', ' '), 'empty id is not banned');
