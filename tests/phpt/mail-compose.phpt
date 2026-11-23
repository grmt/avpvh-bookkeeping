--TEST--
tests/test-mail-compose.php
--FILE--
<?php
require __DIR__ . '/../../tests/test-mail-compose.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
