--TEST--
tests/test-public-registration.php
--FILE--
<?php
require __DIR__ . '/../../tests/test-public-registration.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
