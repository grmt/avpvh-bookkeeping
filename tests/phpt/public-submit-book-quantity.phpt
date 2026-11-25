--TEST--
Public request response: book-quantity
--FILE--
<?php
$case = 'book-quantity';
require __DIR__ . '/../fixtures/public-form-submit.php';
--EXPECT--
PASS: request redirects and terminates
