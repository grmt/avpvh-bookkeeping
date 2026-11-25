--TEST--
Public request response: book-rate
--FILE--
<?php
$case = 'book-rate';
require __DIR__ . '/../fixtures/public-form-submit.php';
--EXPECT--
PASS: request redirects and terminates
