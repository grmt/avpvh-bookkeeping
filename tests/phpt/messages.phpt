--TEST--
tests/test-messages.php
--FILE--
<?php
require __DIR__ . '/../../tests/test-messages.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
