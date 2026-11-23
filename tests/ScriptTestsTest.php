<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Every tests/test-*.php runs under PHPUnit through its own tests/phpt/*.phpt. */
final class ScriptTestsTest extends TestCase {
    public function test_every_script_has_a_phpt(): void {
        $root = dirname(__DIR__);
        $missing = [];
        foreach (glob($root . '/tests/test-*.php') as $script) {
            $phpt = $root . '/tests/phpt/' . substr(basename($script, '.php'), strlen('test-')) . '.phpt';
            if (!is_file($phpt)) {
                $missing[] = basename($script);
            }
        }
        $this->assertSame([], $missing, 'Run bin/make-script-tests to add the missing .phpt files.');
    }
}
