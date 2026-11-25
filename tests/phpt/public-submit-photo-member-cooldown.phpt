--TEST--
Public request response: photo-member-cooldown
--FILE--
<?php
$case = 'photo-member-cooldown';
require __DIR__ . '/../fixtures/public-form-submit.php';
--EXPECT--
PASS: request redirects and terminates
