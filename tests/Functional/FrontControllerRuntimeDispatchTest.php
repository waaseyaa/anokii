<?php

declare(strict_types=1);

namespace Anokii\Tests\Functional;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class FrontControllerRuntimeDispatchTest extends TestCase
{
    #[Test]
    public function classicModeDoesNotEnterTheWorkerLoopWhenTheApiExists(): void
    {
        $result = $this->runFrontController(
            <<<'PHP'
                function frankenphp_handle_request(): bool
                {
                    throw new \RuntimeException('worker-loop-entered-in-classic-mode');
                }
                PHP,
            false,
            'classic-response',
        );

        self::assertSame(0, $result['exitCode'], $result['stderr']);
        self::assertSame('classic-response', $result['stdout']);
        self::assertStringNotContainsString('worker-loop-entered-in-classic-mode', $result['stdout'] . $result['stderr']);
    }

    #[Test]
    public function explicitWorkerMarkerRequiresTheWorkerApi(): void
    {
        $result = $this->runFrontController('', true, 'must-not-be-served');

        self::assertNotSame(0, $result['exitCode']);
        self::assertSame('', $result['stdout']);
        self::assertStringContainsString(
            'FrankenPHP worker mode is enabled but the worker API is unavailable.',
            $result['stderr'],
        );
    }

    #[Test]
    public function firstWorkerLoopExceptionIsNotSwallowedAsModeDetection(): void
    {
        $result = $this->runFrontController(
            <<<'PHP'
                function frankenphp_handle_request(): bool
                {
                    throw new \RuntimeException('real-worker-loop-failure');
                }
                PHP,
            true,
            'must-not-fall-back',
        );

        self::assertNotSame(0, $result['exitCode']);
        self::assertSame('', $result['stdout']);
        self::assertStringContainsString('real-worker-loop-failure', $result['stderr']);
        self::assertStringNotContainsString('must-not-fall-back', $result['stdout'] . $result['stderr']);
    }

    #[Test]
    public function explicitWorkerMarkerRunsTheSharedHandlerOnce(): void
    {
        $result = $this->runFrontController(
            <<<'PHP'
                function frankenphp_handle_request(callable $handler): bool
                {
                    fwrite(STDERR, 'WORKER_LOOP');
                    $handler();

                    return false;
                }
                PHP,
            true,
            'worker-response',
        );

        self::assertSame(0, $result['exitCode'], $result['stderr']);
        self::assertSame('worker-response', $result['stdout']);
        self::assertSame(1, substr_count($result['stderr'], 'WORKER_LOOP'));
    }

    /**
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    private function runFrontController(string $runtimePrelude, bool $workerMode, string $responseBody): array
    {
        $root = dirname(__DIR__, 2);
        $code = <<<'PHP'
            eval('namespace Waaseyaa\\Foundation\\Kernel; final class HttpKernel { public function __construct(string $projectRoot) {} public function handle(): \\Symfony\\Component\\HttpFoundation\\Response { return new \\Symfony\\Component\\HttpFoundation\\Response((string) getenv("ANOKII_TEST_RESPONSE")); } }');
            eval($argv[2]);
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_URI'] = '/';
            $_SERVER['SCRIPT_NAME'] = '/index.php';
            $_SERVER['SCRIPT_FILENAME'] = $argv[1];
            $_SERVER['HTTP_HOST'] = 'localhost';
            $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
            require $argv[1];
            PHP;
        $environment = getenv();
        $environment = array_merge($environment, [
            'APP_ENV' => 'testing',
            'APP_DEBUG' => 'false',
            'WAASEYAA_SKIP_DOTENV' => 'true',
            'ANOKII_TEST_RESPONSE' => $responseBody,
        ]);
        if ($workerMode) {
            $environment['WAASEYAA_FRANKENPHP_WORKER'] = '1';
        } else {
            unset($environment['WAASEYAA_FRANKENPHP_WORKER']);
        }

        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', '-r', $code, $root . '/public/index.php', $runtimePrelude],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            $environment,
        );
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'exitCode' => proc_close($process),
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }
}
