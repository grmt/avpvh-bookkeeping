--TEST--
Public request response: congress-rate
--FILE--
<?php
$case = 'congress-rate';
require __DIR__ . '/../fixtures/public-form-submit.php';
--EXPECT--
PASS: request redirects and terminates
