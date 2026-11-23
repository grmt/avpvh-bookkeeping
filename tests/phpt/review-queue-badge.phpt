--TEST--
tests/test-review-queue-badge.php
--FILE--
<?php
require __DIR__ . '/../../tests/test-review-queue-badge.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
