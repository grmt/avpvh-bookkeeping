--TEST--
tests/test-mail-history-import.php
--FILE--
<?php
require __DIR__ . '/../../tests/test-mail-history-import.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
