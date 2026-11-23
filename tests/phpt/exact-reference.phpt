--TEST--
tests/test-exact-reference.php
--FILE--
<?php
require __DIR__ . '/../../tests/test-exact-reference.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
