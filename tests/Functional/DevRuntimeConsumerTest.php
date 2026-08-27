<?php

declare(strict_types=1);

namespace Anokii\Tests\Functional;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Tooling\DevRuntimeConsumer;

#[CoversNothing]
final class DevRuntimeConsumerTest extends TestCase
{
    private const string FRAMEWORK_COMMIT = '78711436577d339d9f118976364626e302967752';

    #[Test]
    public function source_record_is_exact_and_local_consumer_files_are_verified(): void
    {
        $root = dirname(__DIR__, 2);
        require_once $root . '/tools/lib/DevRuntimeConsumer.php';

        $record = DevRuntimeConsumer::loadSourceRecord(
            $root . '/tools/dev-runtime-source.json',
            $root,
        );

        self::assertSame('FW-DEV-RUNTIME-01', $record['change_record']);
        self::assertSame('waaseyaa/framework', $record['repository']);
        self::assertSame(self::FRAMEWORK_COMMIT, $record['commit']);
        self::assertSame(
            'https://raw.githubusercontent.com/waaseyaa/framework/' . self::FRAMEWORK_COMMIT . '/bin/dev-runtime',
            DevRuntimeConsumer::sourceUrl($record['repository'], $record['commit'], 'bin/dev-runtime'),
        );
        self::assertArrayNotHasKey('tools', $record);
        self::assertArrayNotHasKey('versions', $record);
        self::assertArrayNotHasKey('artifacts', $record);
    }

    #[Test]
    public function delegation_binds_commands_to_this_checkout(): void
    {
        $root = dirname(__DIR__, 2);
        require_once $root . '/tools/lib/DevRuntimeConsumer.php';

        self::assertSame(
            [
                PHP_BINARY,
                '/cache/bin/dev-runtime',
                'exec',
                '--repository-root=' . $root,
                '--',
                'composer',
                'test',
            ],
            DevRuntimeConsumer::delegationCommand(
                '/cache',
                $root,
                ['exec', '--', 'composer', 'test'],
            ),
        );
    }
}
