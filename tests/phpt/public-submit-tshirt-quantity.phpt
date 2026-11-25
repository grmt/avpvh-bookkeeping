--TEST--
Public request response: tshirt-quantity
--FILE--
<?php
$case = 'tshirt-quantity';
require __DIR__ . '/../fixtures/public-form-submit.php';
--EXPECT--
PASS: request redirects and terminates
