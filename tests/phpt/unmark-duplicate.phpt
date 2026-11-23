--TEST--
tests/test-unmark-duplicate.php
--FILE--
<?php
require __DIR__ . '/../../tests/test-unmark-duplicate.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
