--TEST--
Bank costs module handles direct access without bootstrapping WordPress
--FILE--
<?php
require __DIR__ . '/../../includes/class-bank-costs.php';
echo 'Unexpected module execution';
--EXPECT--
