--TEST--
tests/test-audit.php
--FILE--
<?php
require __DIR__ . '/../../tests/test-audit.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
