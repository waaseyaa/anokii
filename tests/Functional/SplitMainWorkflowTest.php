<?php

declare(strict_types=1);

namespace Anokii\Tests\Functional;

use PHPUnit\Framework\TestCase;

final class SplitMainWorkflowTest extends TestCase
{
    public function testResolverAllowsOnlyCanonicalPackageNames(): void
    {
        [$exit, $stdout] = $this->runResolver('core,identity,core');

        self::assertSame(0, $exit, $stdout);
        self::assertSame([
            'include' => [
                ['local' => 'packages/core', 'remote' => 'anokii-core'],
                ['local' => 'packages/identity', 'remote' => 'anokii-identity'],
            ],
        ], json_decode($stdout, true, flags: JSON_THROW_ON_ERROR));

        [$unknownExit, , $stderr] = $this->runResolver('../anokii');
        self::assertNotSame(0, $unknownExit);
        self::assertStringContainsString('not allowlisted', $stderr);
    }

    public function testWorkflowIsExactGreenMainAndDevelopmentBranchOnly(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/split-main.yml');
        self::assertNotFalse($workflow);
        self::assertStringContainsString('requested}" != "${current_main}', $workflow);
        self::assertStringContainsString('--workflow quality.yml --commit', $workflow);
        self::assertStringContainsString('--force-with-lease=', $workflow);
        self::assertStringContainsString('${split_sha}:refs/heads/main', $workflow);
        self::assertStringContainsString('release:false', $workflow);
    }

    public function testWorkflowHasNoReleaseAuthority(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/split-main.yml');
        self::assertNotFalse($workflow);
        self::assertStringNotContainsString('refs/tags/', $workflow);
        self::assertStringNotContainsString('PACKAGIST_', $workflow);
        self::assertStringNotContainsString('create-release', $workflow);
    }

    /** @return array{int, string, string} */
    private function runResolver(string $selection): array
    {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/scripts/resolve-split-main-targets.php', $selection],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
