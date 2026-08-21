<?php

declare(strict_types=1);

namespace Anokii\Tests\Functional;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Anokii's front controller must keep the Framework classic-FrankenPHP fallback.
 * `frankenphp_handle_request()` exists under php-server/FPM and throws
 * "called while not in worker mode"; an unconditional worker loop 500s every request.
 */
#[CoversNothing]
final class FrontControllerRuntimeDispatchTest extends TestCase
{
    #[Test]
    public function publicIndexFallsBackWhenFrankenphpIsNotInWorkerMode(): void
    {
        $source = str_replace(["\r\n", "\r"], "\n", (string) file_get_contents(dirname(__DIR__, 2) . '/public/index.php'));

        self::assertStringContainsString("function_exists('frankenphp_handle_request')", $source);
        self::assertStringContainsString('catch (\\Throwable $e)', $source);
        self::assertStringContainsString('if ($handled > 0)', $source);
        self::assertStringContainsString('$handle();', $source);
    }
}
