--TEST--
tests/test-company-export-access.php
--FILE--
<?php
require __DIR__ . '/../../tests/test-company-export-access.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
