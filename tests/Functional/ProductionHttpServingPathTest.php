<?php

declare(strict_types=1);

namespace Anokii\Tests\Functional;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Kernel\ConsoleKernel;
use Waaseyaa\Foundation\Kernel\HttpKernel;
use Waaseyaa\Foundation\Kernel\Preflight\LiveEntitySchemaFingerprint;

#[CoversNothing]
final class ProductionHttpServingPathTest extends TestCase
{
    #[Test]
    #[RunInSeparateProcess]
    public function productionHttpLoginUsesTheSameAcceptedFingerprintAcrossRepeatedBoots(): void
    {
        $root = dirname(__DIR__, 2);
        $database = sys_get_temp_dir() . '/anokii-prod-http-' . bin2hex(random_bytes(8)) . '.sqlite';
        $artifact = $root . '/.waaseyaa/field-access-preflight.json';
        $previousArtifact = is_file($artifact) ? (string) file_get_contents($artifact) : null;

        putenv('APP_ENV=local');
        putenv('WAASEYAA_DB=' . $database);
        putenv('ANOKII_COMMUNITY_ID=production-http-test');
        putenv('ANOKII_PRIVACY_SECRET=' . str_repeat('p', 32));
        putenv('WAASEYAA_JWT_SECRET=' . str_repeat('j', 32));
        putenv('WAASEYAA_APP_SECRET=base64:' . base64_encode(str_repeat('a', 32)));
        putenv('AUTH_TOKEN_SECRET=' . bin2hex(random_bytes(32)));
        putenv('WAASEYAA_SKIP_DOTENV=true');

        try {
            $this->runCli($root, ['db:init', '--sync-schema']);
            putenv('APP_ENV=production');
            $this->runCli($root, ['field-access:preflight', '--format=json', '--write-artifact']);
            $written = (string) file_get_contents($artifact);
            $decoded = json_decode($written, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            self::assertTrue($decoded['ready'] ?? false);
            $expectedFingerprint = $decoded['schema_fingerprint'] ?? null;
            self::assertIsString($expectedFingerprint);

            $statuses = [];
            $fingerprints = [];
            for ($i = 0; $i < 20; ++$i) {
                $this->primeLoginRequest($root);
                $response = new HttpKernel($root)->handle();
                $statuses[] = $response->getStatusCode();
                $kernel = new ConsoleKernel($root);
                $kernel->bootForFieldAccessPreflight();
                $connection = $kernel->getDatabase();
                self::assertInstanceOf(DBALDatabase::class, $connection);
                $fingerprints[] = LiveEntitySchemaFingerprint::compute(
                    $connection,
                    array_keys($kernel->getEntityTypeManager()->getDefinitions()),
                );
            }

            $counts = array_count_values($statuses);
            self::assertSame([200 => 20], $counts, json_encode($counts, JSON_THROW_ON_ERROR));
            self::assertSame(array_fill(0, 20, $expectedFingerprint), $fingerprints);
            self::assertSame($written, (string) file_get_contents($artifact));
        } finally {
            if ($previousArtifact === null) {
                if (is_file($artifact)) {
                    unlink($artifact);
                }
            } else {
                file_put_contents($artifact, $previousArtifact);
            }
            foreach ([$database, $database . '-shm', $database . '-wal'] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    #[Test]
    #[RunInSeparateProcess]
    public function productionHttpLoginFailsClosedWithoutAuthTokenSecret(): void
    {
        $root = dirname(__DIR__, 2);
        $database = sys_get_temp_dir() . '/anokii-prod-http-nosecret-' . bin2hex(random_bytes(8)) . '.sqlite';

        putenv('APP_ENV=local');
        putenv('WAASEYAA_DB=' . $database);
        putenv('ANOKII_COMMUNITY_ID=production-http-test');
        putenv('ANOKII_PRIVACY_SECRET=' . str_repeat('p', 32));
        putenv('WAASEYAA_JWT_SECRET=' . str_repeat('j', 32));
        putenv('WAASEYAA_APP_SECRET=base64:' . base64_encode(str_repeat('a', 32)));
        putenv('AUTH_TOKEN_SECRET');
        putenv('WAASEYAA_SKIP_DOTENV=true');

        try {
            $this->runCli($root, ['db:init', '--sync-schema']);
            putenv('APP_ENV=production');
            $this->primeLoginRequest($root);
            $response = new HttpKernel($root)->handle();

            self::assertSame(500, $response->getStatusCode(), (string) $response->getContent());
        } finally {
            foreach ([$database, $database . '-shm', $database . '-wal'] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    /** @param list<string> $arguments */
    private function runCli(string $root, array $arguments): void
    {
        $previous = $_SERVER['argv'] ?? null;
        $_SERVER['argv'] = ['waaseyaa', ...$arguments];
        try {
            $code = new ConsoleKernel($root)->handle();
        } finally {
            if ($previous === null) {
                unset($_SERVER['argv']);
            } else {
                $_SERVER['argv'] = $previous;
            }
        }
        self::assertSame(0, $code, implode(' ', $arguments) . ' failed');
    }

    private function primeLoginRequest(string $root): void
    {
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
            'HTTP_USER_AGENT' => 'Anokii production serving-path test',
            'argv' => $_SERVER['argv'] ?? ['phpunit'],
        ];
    }
}
