--TEST--
Public request response: photo-ip
--FILE--
<?php
$case = 'photo-ip';
require __DIR__ . '/../fixtures/public-form-submit.php';
--EXPECT--
PASS: request redirects and terminates
