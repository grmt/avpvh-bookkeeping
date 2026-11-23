--TEST--
tests/test-duplicate-candidates.php
--FILE--
<?php
require __DIR__ . '/../../tests/test-duplicate-candidates.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
