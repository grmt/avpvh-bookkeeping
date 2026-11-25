--TEST--
Public request response: photo-unavailable
--FILE--
<?php
$case = 'photo-unavailable';
require __DIR__ . '/../fixtures/public-form-submit.php';
--EXPECT--
PASS: request redirects and terminates
