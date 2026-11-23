--TEST--
tests/test-company-bootstrap.php
--FILE--
<?php
require __DIR__ . '/../../tests/test-company-bootstrap.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
