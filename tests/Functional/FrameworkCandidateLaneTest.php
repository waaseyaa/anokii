<?php

declare(strict_types=1);

namespace Anokii\Tests\Functional;

use Anokii\CandidateCi\FrameworkCohort;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Structural guard for the governed Framework lanes added for waaseyaa/anokii#23.
 *
 * These lanes are security boundaries, so the properties asserted here are the
 * ones that would be silently lost in an edit: manual dispatch only, no write
 * permission, no secrets, SHA-pinned actions, no persisted credentials, no
 * cross-run cache, custody sealed before tests, and Anokii's own dev-main split
 * packages never repinned.
 */
final class FrameworkCandidateLaneTest extends TestCase
{
    private const CANDIDATE = '.github/workflows/framework-candidate.yml';

    private const ADOPTION = '.github/workflows/framework-release-adoption.yml';

    public static function setUpBeforeClass(): void
    {
        require_once self::repositoryRoot() . '/scripts/lib/FrameworkCohort.php';
    }

    /** @return list<array{string}> */
    public static function governedLanes(): array
    {
        return [[self::CANDIDATE], [self::ADOPTION]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('governedLanes')]
    public function testLaneIsManualDispatchOnly(string $relative): void
    {
        $workflow = self::workflow($relative);

        $triggers = self::section($workflow, 'on');

        self::assertSame(['workflow_dispatch'], array_keys($triggers));
        self::assertArrayNotHasKey('push', $triggers);
        self::assertArrayNotHasKey('pull_request', $triggers);
        self::assertArrayNotHasKey('schedule', $triggers);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('governedLanes')]
    public function testLaneHasNoWriteAuthorityAnywhere(string $relative): void
    {
        $workflow = self::workflow($relative);

        self::assertSame(['contents' => 'read'], self::section($workflow, 'permissions'));
        foreach (array_keys(self::section($workflow, 'jobs')) as $name) {
            self::assertSame(
                ['contents' => 'read'],
                self::section(self::section(self::workflow($relative), 'jobs'), $name)['permissions'] ?? null,
                $name,
            );
        }

        $source = self::source($relative);
        self::assertStringNotContainsString('contents: write', $source);
        self::assertStringNotContainsString('pull-requests: write', $source);
        self::assertStringNotContainsString('packages: write', $source);
        self::assertStringNotContainsString('id-token: write', $source);
        // git ls-remote --tags is a read; creating or pushing one is not.
        self::assertStringNotContainsString('git tag', $source);
        self::assertStringNotContainsString('git push', $source);
        self::assertDoesNotMatchRegularExpression('/push\s[^\n]*refs\/tags/', $source);
        self::assertStringNotContainsString('gh pr ', $source);
        self::assertStringNotContainsString('gh release', $source);
        self::assertStringNotContainsString('gh api', $source);
        self::assertStringNotContainsString('dep deploy', $source);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('governedLanes')]
    public function testLaneReadsNoSecretAndPersistsNoCredential(string $relative): void
    {
        $source = self::source($relative);

        self::assertStringNotContainsString('secrets.', $source);
        self::assertStringNotContainsString('github.token', $source);
        self::assertStringNotContainsString('SPLIT_GITHUB_TOKEN', $source);
        self::assertStringContainsString('persist-credentials: false', $source);
        self::assertStringContainsString('test -z "${GITHUB_TOKEN:-}"', $source);
        self::assertStringContainsString('extraheader', $source);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('governedLanes')]
    public function testLaneUsesNoCrossRunCache(string $relative): void
    {
        $source = self::source($relative);

        $matched = preg_match_all('/uses:\s*(\S+)/', $source, $matches);
        self::assertIsInt($matched);
        foreach ($matches[1] as $action) {
            self::assertStringNotContainsString('actions/cache', $action);
            self::assertStringNotContainsString('composer-install', $action);
        }
        self::assertStringContainsString('COMPOSER_CACHE_DIR=${RUNNER_TEMP}/ephemeral-composer-cache', $source);
        self::assertStringContainsString('COMPOSER_HOME=${RUNNER_TEMP}/ephemeral-composer-home', $source);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('governedLanes')]
    public function testEveryThirdPartyActionIsPinnedToACommitSha(string $relative): void
    {
        $source = self::source($relative);
        $matched = preg_match_all('/uses:\s*(\S+)(.*)$/m', $source, $matches, PREG_SET_ORDER);
        self::assertIsInt($matched);
        self::assertGreaterThan(0, $matched);

        foreach ($matches as $match) {
            [, $reference, $trailer] = $match;
            self::assertMatchesRegularExpression('/^[^@]+@[0-9a-f]{40}$/', $reference, $reference);
            self::assertMatchesRegularExpression('/#\s*v?\d+\.\d+\.\d+/', $trailer, $reference);
        }
    }

    public function testCandidateLaneOffersExactlyTheTwoTrustModes(): void
    {
        $dispatch = self::section(self::section(self::workflow(self::CANDIDATE), 'on'), 'workflow_dispatch');
        $inputs = self::section($dispatch, 'inputs');
        $trustMode = self::section($inputs, 'trust_mode');

        self::assertSame('choice', $trustMode['type']);
        self::assertSame(['merged-main', 'review-candidate'], $trustMode['options']);
        self::assertSame('merged-main', $trustMode['default']);
        self::assertTrue(self::section($inputs, 'framework_sha')['required']);
    }

    public function testCandidateLaneValidatesTheShaAndMergedMainAncestryBeforeUse(): void
    {
        $source = self::source(self::CANDIDATE);

        self::assertStringContainsString('^[0-9a-f]{40}$', $source);
        self::assertStringContainsString('merge-base --is-ancestor', $source);
        self::assertStringContainsString('refs/remotes/origin/main', $source);
        self::assertStringContainsString('https://github.com/waaseyaa/framework.git', $source);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('governedLanes')]
    public function testCustodyIsSealedAndUploadedBeforeAnyTestRuns(string $relative): void
    {
        $steps = self::steps($relative);

        $seal = null;
        $upload = null;
        $firstTest = null;
        foreach ($steps as $index => $step) {
            $run = is_string($step['run'] ?? null) ? $step['run'] : '';
            $uses = is_string($step['uses'] ?? null) ? $step['uses'] : '';
            if ($seal === null && ($step['id'] ?? null) === 'custody') {
                $seal = $index;
            }
            if ($upload === null && str_starts_with($uses, 'actions/upload-artifact@')) {
                $upload = $index;
            }
            if ($firstTest === null
                && preg_match('/composer\s+(?:--working-dir=\S+\s+)?(?:test|analyse|style)\b/', $run) === 1) {
                $firstTest = $index;
            }
        }

        self::assertNotNull($seal, 'no custody sealing step');
        self::assertNotNull($upload, 'no custody upload step');
        self::assertNotNull($firstTest, 'no acceptance step');
        self::assertLessThan($firstTest, $seal, 'custody must be sealed before tests run');
        self::assertLessThan($firstTest, $upload, 'custody must be uploaded before tests run');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('governedLanes')]
    public function testLaneRunsTheRealFrankenPhpAcceptanceGate(string $relative): void
    {
        $source = self::source($relative);
        $quality = self::source('.github/workflows/quality.yml');

        self::assertStringContainsString('scripts/acceptance-frankenphp-http.sh', $source);
        self::assertStringContainsString(
            'https://github.com/php/frankenphp/releases/download/v1.12.4/frankenphp-linux-x86_64',
            $source,
        );

        // The candidate lane must not weaken the released lane's binary pin.
        $pin = 'b39c7511483c99faf0d857cc0789b39cc23023038260b31d4b40dd4ae18795dc';
        self::assertStringContainsString($pin, $quality);
        self::assertStringContainsString($pin, $source);
        self::assertStringContainsString('sha256sum -c -', $source);
    }

    public function testOrdinaryReleasedLockQualityLaneIsUntouched(): void
    {
        $quality = self::workflow('.github/workflows/quality.yml');

        self::assertSame(['pull_request', 'push'], array_keys(self::section($quality, 'on')));
        self::assertSame(
            ['test', 'frankenphp-http', 'static', 'package-boundaries'],
            array_keys(self::section($quality, 'jobs')),
        );
        self::assertStringNotContainsString('framework-candidate', self::source('.github/workflows/quality.yml'));
        self::assertStringNotContainsString('framework-release-adoption', self::source('.github/workflows/quality.yml'));
    }

    public function testShaAndTrustModeFailClosed(): void
    {
        foreach (['', 'main', 'ABCDEF', str_repeat('A', 40), str_repeat('g', 40), str_repeat('a', 39)] as $bad) {
            $this->assertRefused(static fn() => FrameworkCohort::assertSha($bad), 'hexadecimal');
        }
        FrameworkCohort::assertSha(str_repeat('a', 40));

        foreach (['', 'trusted', 'merged main', 'REVIEW-CANDIDATE'] as $bad) {
            $this->assertRefused(static fn() => FrameworkCohort::assertTrustMode($bad), 'trust_mode');
        }
    }

    public function testCandidateVersionsCanNeverBeAdoptedAsReleased(): void
    {
        $released = FrameworkCohort::assertReleasedVersion('v0.1.0-alpha.297');
        self::assertSame(['constraint' => '^0.1.0-alpha.297', 'lock' => 'v0.1.0-alpha.297'], $released);

        foreach ([
            '0.1.0-alpha.999999+candidate.0123456789ab',
            'dev-main',
            'main',
            '0.1.x-dev',
            str_repeat('a', 40),
            '',
        ] as $bad) {
            $this->assertRefused(static fn() => FrameworkCohort::assertReleasedVersion($bad), 'framework_version');
        }
    }

    public function testCandidateResidueIsNeverReleasedCompatibility(): void
    {
        $this->assertRefused(
            static fn() => FrameworkCohort::assertNoCandidateResidue('/nonexistent', [
                'packages' => [[
                    'name' => 'waaseyaa/entity',
                    'version' => '0.1.0-alpha.999999+candidate.0123456789ab',
                ]],
            ]),
            'synthetic candidate version',
        );
    }

    public function testAnokiiDevMainIdentitiesAreNeverRepinned(): void
    {
        $original = self::anokiiLockEntries();
        $repinned = $original;
        $repinned['waaseyaa/anokii-core'] = [
            'name' => 'waaseyaa/anokii-core',
            'version' => 'v0.1.0-alpha.297',
            'dist' => ['type' => 'zip', 'url' => 'https://example.invalid/core.zip'],
        ];

        $this->assertRefused(
            static fn() => FrameworkCohort::assertAnokiiIdentitiesPreserved($original, $repinned),
            'dev-main',
        );

        $dropped = $original;
        unset($dropped['waaseyaa/anokii-identity']);
        $this->assertRefused(
            static fn() => FrameworkCohort::assertAnokiiIdentitiesPreserved($original, $dropped),
            'disappeared',
        );

        FrameworkCohort::assertAnokiiIdentitiesPreserved($original, $original);
    }

    public function testLockMovementIsClassifiedByOwner(): void
    {
        $before = self::anokiiLockEntries() + [
            'waaseyaa/entity' => ['name' => 'waaseyaa/entity', 'version' => 'v0.1.0-alpha.293'],
            'twig/twig' => ['name' => 'twig/twig', 'version' => 'v3.10.0'],
        ];
        $after = self::anokiiLockEntries() + [
            'waaseyaa/entity' => ['name' => 'waaseyaa/entity', 'version' => 'v0.1.0-alpha.297'],
            'twig/twig' => ['name' => 'twig/twig', 'version' => 'v3.11.0'],
        ];

        $classification = FrameworkCohort::classify($before, $after);

        self::assertSame(
            [['name' => 'waaseyaa/entity', 'from' => 'v0.1.0-alpha.293', 'to' => 'v0.1.0-alpha.297']],
            $classification['framework']['changed'],
        );
        self::assertSame(
            [['name' => 'twig/twig', 'from' => 'v3.10.0', 'to' => 'v3.11.0']],
            $classification['external']['changed'],
        );
        self::assertSame([], $classification['anokii']['changed']);
        self::assertSame(
            ['waaseyaa/anokii-core', 'waaseyaa/anokii-identity', 'waaseyaa/anokii-operator'],
            $classification['anokii']['unchanged'],
        );
    }

    public function testOnlyAnokiiOwnPathRepositoriesAreAccepted(): void
    {
        $canonical = self::rootComposerManifest();
        FrameworkCohort::assertAnokiiRepositoryShape($canonical);

        self::assertSame(
            ['waaseyaa/ai-agent', 'waaseyaa/deployer', 'waaseyaa/full'],
            FrameworkCohort::rootFrameworkRequirements($canonical),
        );

        $repositories = $canonical['repositories'];
        self::assertIsArray($repositories);
        $repositories[] = ['type' => 'path', 'url' => '/tmp/framework/packages/*'];
        $overlaid = $canonical;
        $overlaid['repositories'] = $repositories;
        $this->assertRefused(
            static fn() => FrameworkCohort::assertAnokiiRepositoryShape($overlaid),
            'unexpected path repository',
        );
    }

    public function testFrameworkOwnershipExcludesAnokiiSplits(): void
    {
        self::assertTrue(FrameworkCohort::isFrameworkPackage('waaseyaa/entity'));
        self::assertFalse(FrameworkCohort::isFrameworkPackage('waaseyaa/anokii-core'));
        self::assertFalse(FrameworkCohort::isFrameworkPackage('twig/twig'));
        self::assertSame('framework', FrameworkCohort::classOf('waaseyaa/entity'));
        self::assertSame('anokii', FrameworkCohort::classOf('waaseyaa/anokii-identity'));
        self::assertSame('external', FrameworkCohort::classOf('symfony/uid'));
    }

    /** @return array<string, array<string, mixed>> */
    private static function anokiiLockEntries(): array
    {
        return [
            'waaseyaa/anokii-operator' => [
                'name' => 'waaseyaa/anokii-operator',
                'version' => 'dev-main',
                'dist' => ['type' => 'path', 'url' => 'packages/operator'],
            ],
            'waaseyaa/anokii-core' => [
                'name' => 'waaseyaa/anokii-core',
                'version' => 'dev-main',
                'dist' => ['type' => 'path', 'url' => 'packages/core'],
            ],
            'waaseyaa/anokii-identity' => [
                'name' => 'waaseyaa/anokii-identity',
                'version' => 'dev-main',
                'dist' => ['type' => 'path', 'url' => 'packages/identity'],
            ],
        ];
    }

    private function assertRefused(callable $operation, string $expected): void
    {
        try {
            $operation();
        } catch (RuntimeException $error) {
            self::assertStringContainsString($expected, $error->getMessage());

            return;
        }
        self::fail('Expected a refusal mentioning ' . $expected . '.');
    }

    /** @return array<string, mixed> */
    private static function workflow(string $relative): array
    {
        return self::stringKeyed(Yaml::parseFile(self::repositoryRoot() . '/' . $relative));
    }

    /** @return array<string, mixed> */
    private static function rootComposerManifest(): array
    {
        return self::stringKeyed(json_decode(
            (string) file_get_contents(self::repositoryRoot() . '/composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        ));
    }

    /** @return array<string, mixed> */
    private static function stringKeyed(mixed $value): array
    {
        self::assertIsArray($value);

        $normalized = [];
        foreach ($value as $key => $entry) {
            $normalized[(string) $key] = $entry;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private static function section(array $document, string $key): array
    {
        return self::stringKeyed($document[$key] ?? null);
    }

    /** @return list<array<string, mixed>> */
    private static function steps(string $relative): array
    {
        $jobs = self::section(self::workflow($relative), 'jobs');
        $first = array_key_first($jobs);
        self::assertIsString($first);
        $steps = self::section($jobs, $first)['steps'] ?? null;
        self::assertIsArray($steps);

        $list = [];
        foreach ($steps as $step) {
            $list[] = self::stringKeyed($step);
        }

        return $list;
    }

    private static function source(string $relative): string
    {
        $contents = file_get_contents(self::repositoryRoot() . '/' . $relative);
        self::assertIsString($contents);

        return $contents;
    }

    private static function repositoryRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}
