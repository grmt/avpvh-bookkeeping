--TEST--
Public form helpers handle direct access without bootstrapping WordPress
--FILE--
<?php
require __DIR__ . '/../../includes/functions-public-forms.php';
echo 'Unexpected module execution';
--EXPECT--
