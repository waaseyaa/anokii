<?php

declare(strict_types=1);

namespace Anokii\Operator\Tests;

use Anokii\Operator\Demo\OperatorDemo;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Runs the documented entry point exactly as a host would: PHP's built-in
 * server on loopback, the package router, and paths from environment
 * variables resolved against the directory the server starts in.
 */
final class OperatorDemoServerTest extends TestCase
{
    /** @var resource|null */
    private $server;

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
    }

    #[Test]
    public function theRouterServesTheExampleThroughTheRealShell(): void
    {
        $base = $this->start([
            'ANOKII_OPERATOR_DEMO_AUTOLOAD' => 'vendor/autoload.php',
            'ANOKII_OPERATOR_DEMO_FIXTURE' => 'packages/operator/examples/demo/fixture.php',
        ]);

        [$status, $headers, $body] = self::get($base . '/admin/anokii');
        self::assertSame(200, $status, $body);
        self::assertSame(OperatorDemo::CONTENT_SECURITY_POLICY, $headers['content-security-policy'] ?? null);
        self::assertStringContainsString('Demo: nothing is saved or sent', $body);
        self::assertStringContainsString('Welcome, Sample Operator', $body);

        [$status, $headers] = self::get($base . '/');
        self::assertSame(302, $status);
        self::assertSame('/admin/anokii', $headers['location'] ?? null);

        [$status, $headers] = self::get($base . '/demo-assets/theme.css');
        self::assertSame(200, $status);
        self::assertSame('text/css; charset=UTF-8', $headers['content-type'] ?? null);

        // Browsers run module scripts only when they arrive as JavaScript.
        foreach (['/anokii-demo/primitives.js', '/demo-assets/desk.js', '/demo-assets/review.js'] as $path) {
            [$status, $headers] = self::get($base . $path);
            self::assertSame(200, $status, $path);
            self::assertSame('text/javascript; charset=UTF-8', $headers['content-type'] ?? null, $path);
            self::assertSame('nosniff', $headers['x-content-type-options'] ?? null, $path);
        }

        // Files in the server's document root (here the repository) stay private.
        foreach (['/composer.json', '/vendor/autoload.php', '/packages/operator/examples/demo/fixture.php'] as $path) {
            [$status] = self::get($base . $path);
            self::assertSame(404, $status, $path);
        }
    }

    #[Test]
    public function theRouterExplainsAMissingFixtureSetting(): void
    {
        $base = $this->start(['ANOKII_OPERATOR_DEMO_AUTOLOAD' => 'vendor/autoload.php']);

        [$status, , $body] = self::get($base . '/admin/anokii');

        self::assertSame(500, $status);
        self::assertStringContainsString('Set ANOKII_OPERATOR_DEMO_FIXTURE', $body);
    }

    /** @param array<string, string> $settings */
    private function start(array $settings): string
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($probe);
        $address = (string) stream_socket_get_name($probe, false);
        fclose($probe);

        $environment = array_diff_key(getenv(), ['ANOKII_OPERATOR_DEMO_AUTOLOAD' => true, 'ANOKII_OPERATOR_DEMO_FIXTURE' => true]);
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $server = proc_open(
            [PHP_BINARY, '-S', $address, dirname(__DIR__) . '/demo/router.php'],
            [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
            $pipes,
            dirname(__DIR__, 3),
            [...$environment, ...$settings],
        );
        self::assertIsResource($server);
        $this->server = $server;

        [$host, $port] = explode(':', $address);
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $connection = @fsockopen($host, (int) $port, $errno, $error, 0.2);
            if (is_resource($connection)) {
                fclose($connection);

                return 'http://' . $address;
            }
            usleep(50_000);
        }
        self::fail("The demo server did not start on {$address}.");
    }

    /** @return array{int, array<string, string>, string} */
    private static function get(string $url): array
    {
        $body = file_get_contents($url, false, stream_context_create([
            'http' => ['ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10],
        ]));
        $status = 0;
        $headers = [];
        foreach (http_get_last_response_headers() ?? [] as $line) {
            if (preg_match('#^HTTP/\S+ (\d{3})#', $line, $match) === 1) {
                $status = (int) $match[1];
            } elseif (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }

        return [$status, $headers, (string) $body];
    }
}
