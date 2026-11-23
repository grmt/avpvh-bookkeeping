--TEST--
tests/test-expenses.php
--FILE--
<?php
require __DIR__ . '/../../tests/test-expenses.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
