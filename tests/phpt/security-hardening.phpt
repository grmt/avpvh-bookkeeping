--TEST--
tests/test-security-hardening.php
--FILE--
<?php
require __DIR__ . '/../../tests/test-security-hardening.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
