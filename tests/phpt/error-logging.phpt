--TEST--
tests/test-error-logging.php
--FILE--
<?php
require __DIR__ . '/../../tests/test-error-logging.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
