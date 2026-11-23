--TEST--
tests/test-mail-log.php
--FILE--
<?php
require __DIR__ . '/../../tests/test-mail-log.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
