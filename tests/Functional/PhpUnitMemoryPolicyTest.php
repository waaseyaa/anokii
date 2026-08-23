<?php

declare(strict_types=1);

namespace Anokii\Tests\Functional;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Pins the repository-owned test-process memory policy from issue #19. */
final class PhpUnitMemoryPolicyTest extends TestCase
{
    private const string MEMORY_LIMIT = '1G';

    #[Test]
    public function canonical_phpunit_configuration_declares_the_exact_finite_ceiling(): void
    {
        $configuration = dirname(__DIR__, 2) . '/phpunit.xml.dist';
        $xml = simplexml_load_file($configuration);

        self::assertNotFalse($xml, 'phpunit.xml.dist must remain valid XML.');

        $configured = [];
        foreach ($xml->php->ini ?? [] as $ini) {
            if ((string) $ini['name'] === 'memory_limit') {
                $configured[] = (string) $ini['value'];
            }
        }

        self::assertSame(
            [self::MEMORY_LIMIT],
            $configured,
            'The canonical PHPUnit configuration must own one finite 1G memory ceiling.',
        );
    }

    #[Test]
    public function effective_phpunit_process_uses_the_repository_ceiling(): void
    {
        self::assertSame(
            self::MEMORY_LIMIT,
            ini_get('memory_limit'),
            'PHPUnit must override the invoking PHP CLI memory_limit.',
        );
    }

    #[Test]
    public function composer_and_hosted_quality_share_the_canonical_phpunit_entrypoint(): void
    {
        $root = dirname(__DIR__, 2);
        $composer = json_decode(
            (string) file_get_contents($root . '/composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $quality = (string) file_get_contents($root . '/.github/workflows/quality.yml');

        self::assertIsArray($composer);
        $scripts = $composer['scripts'] ?? null;
        self::assertIsArray($scripts);
        self::assertSame('phpunit', $scripts['test'] ?? null);
        self::assertStringContainsString('- run: composer test', $quality);
        self::assertStringNotContainsString(
            'memory_limit',
            $quality,
            'Hosted tests must consume phpunit.xml.dist instead of carrying a second ceiling.',
        );
    }
}
