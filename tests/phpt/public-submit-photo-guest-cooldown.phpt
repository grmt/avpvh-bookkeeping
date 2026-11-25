--TEST--
Public request response: photo-guest-cooldown
--FILE--
<?php
$case = 'photo-guest-cooldown';
require __DIR__ . '/../fixtures/public-form-submit.php';
--EXPECT--
PASS: request redirects and terminates
