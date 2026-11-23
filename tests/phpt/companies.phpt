--TEST--
tests/test-companies.php
--FILE--
<?php
require __DIR__ . '/../../tests/test-companies.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
