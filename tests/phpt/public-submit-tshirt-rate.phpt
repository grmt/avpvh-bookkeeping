--TEST--
Public request response: tshirt-rate
--FILE--
<?php
$case = 'tshirt-rate';
require __DIR__ . '/../fixtures/public-form-submit.php';
--EXPECT--
PASS: request redirects and terminates
