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
final class ProductionSchemaRosterTest extends TestCase
{
    #[Test]
    #[RunInSeparateProcess]
    public function cliAndHttpKernelsShareThePinnedEntityAndProviderRoster(): void
    {
        $root = dirname(__DIR__, 2);
        $database = sys_get_temp_dir() . '/anokii-roster-' . bin2hex(random_bytes(8)) . '.sqlite';

        putenv('APP_ENV=local');
        putenv('WAASEYAA_DB=' . $database);
        putenv('ANOKII_COMMUNITY_ID=roster-test');
        putenv('ANOKII_PRIVACY_SECRET=' . str_repeat('p', 32));
        putenv('WAASEYAA_JWT_SECRET=' . str_repeat('j', 32));
        putenv('WAASEYAA_APP_SECRET=base64:' . base64_encode(str_repeat('a', 32)));
        putenv('WAASEYAA_SKIP_DOTENV=true');

        $_SERVER['argv'] = ['waaseyaa', 'install:init'];
        try {
            self::assertSame(0, new ConsoleKernel($root)->handle());

            $cli = new ConsoleKernel($root);
            $cli->bootForFieldAccessPreflight();
            $cliTypes = array_keys($cli->getEntityTypeManager()->getDefinitions());
            sort($cliTypes);

            $this->primeLoginRequest($root);
            $http = new HttpKernel($root);
            $http->handle();
            $httpTypes = array_keys($http->getEntityTypeManager()->getDefinitions());
            sort($httpTypes);
            $httpProviders = array_map(static fn(object $provider): string => $provider::class, $http->getProviders());
            sort($httpProviders);

            $expectedTypes = $this->lines('production-entity-types.txt');
            $expectedProviders = $this->lines('production-providers.txt');

            self::assertSame($expectedTypes, $cliTypes);
            self::assertSame($expectedTypes, $httpTypes);
            self::assertSame($expectedProviders, $httpProviders);
            $cliDatabase = $cli->getDatabase();
            $httpDatabase = $http->getDatabase();
            self::assertInstanceOf(DBALDatabase::class, $cliDatabase);
            self::assertInstanceOf(DBALDatabase::class, $httpDatabase);
            self::assertSame(
                LiveEntitySchemaFingerprint::compute($cliDatabase, $cliTypes),
                LiveEntitySchemaFingerprint::compute($httpDatabase, $httpTypes),
            );
        } finally {
            foreach ([$database, $database . '-shm', $database . '-wal'] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    /** @return list<string> */
    private function lines(string $fixture): array
    {
        $path = dirname(__DIR__) . '/fixtures/' . $fixture;
        $lines = preg_split('/\R/', trim((string) file_get_contents($path))) ?: [];

        return array_values(array_filter($lines, static fn(string $line): bool => $line !== ''));
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
            'HTTP_ACCEPT' => 'text/html',
            'HTTP_USER_AGENT' => 'Anokii roster test',
            'argv' => ['phpunit'],
        ];
    }
}
