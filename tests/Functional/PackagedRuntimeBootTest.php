<?php

declare(strict_types=1);

namespace Anokii\Tests\Functional;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Kernel\HttpKernel;

final class PackagedRuntimeBootTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testPackagedApplicationBootsAndRegistersWorkspaceRoutes(): void
    {
        $root = dirname(__DIR__, 2);
        $database = sys_get_temp_dir() . '/anokii-runtime-' . bin2hex(random_bytes(8)) . '.sqlite';

        putenv('APP_ENV=local');
        putenv('WAASEYAA_DB=' . $database);
        putenv('ANOKII_PRIVACY_SECRET=0123456789abcdef0123456789abcdef');
        putenv('WAASEYAA_JWT_SECRET=abcdef0123456789abcdef0123456789');

        $_GET = [];
        $_POST = [];
        $_COOKIE = [];
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/admin/anokii/login',
            'SCRIPT_NAME' => '/index.php',
            'SCRIPT_FILENAME' => $root . '/public/index.php',
            'HTTP_HOST' => 'localhost',
            'SERVER_NAME' => 'localhost',
            'SERVER_PORT' => '80',
            'REMOTE_ADDR' => '127.0.0.1',
            'REQUEST_TIME_FLOAT' => microtime(true),
            'HTTP_ACCEPT' => 'text/html,application/xhtml+xml',
            'HTTP_USER_AGENT' => 'Anokii packaged-runtime test',
        ];

        try {
            $response = new HttpKernel($root)->handle();
            $content = (string) $response->getContent();

            self::assertSame(200, $response->getStatusCode(), $content);
            self::assertStringContainsString('Sign in', $content);
            self::assertStringContainsString('/admin/anokii/login', $content);
        } finally {
            foreach ([$database, $database . '-shm', $database . '-wal'] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }
}
