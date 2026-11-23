--TEST--
tests/test-flyer.php
--FILE--
<?php
require __DIR__ . '/../../tests/test-flyer.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
