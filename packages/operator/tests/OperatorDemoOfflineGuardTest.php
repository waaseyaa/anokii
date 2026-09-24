<?php

declare(strict_types=1);

namespace Anokii\Operator\Tests;

use Anokii\Operator\Demo\OperatorDemo;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Keeps the operator demo offline and out of production routing. The CSP in
 * OperatorDemo blocks connections at runtime; this guard fails earlier, on the
 * source of every file a demo page loads.
 */
final class OperatorDemoOfflineGuardTest extends TestCase
{
    private const array NETWORK_APIS = [
        'fetch(', 'XMLHttpRequest', 'WebSocket', 'EventSource', 'sendBeacon',
        'RTCPeerConnection', 'importScripts', 'serviceWorker', 'window.open(',
    ];

    /** SVG's namespace name is an identifier, not a request. */
    private const string SVG_NAMESPACE = 'http://www.w3.org/2000/svg';

    #[Test]
    public function demoSourcesNeverReachTheNetwork(): void
    {
        $package = dirname(__DIR__);
        $scanned = 0;
        foreach (['demo', 'templates', 'examples/demo'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($package . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (!$file instanceof \SplFileInfo || !in_array($file->getExtension(), ['twig', 'php', 'css', 'js', 'svg', 'html'], true)) {
                    continue;
                }
                self::assertLocalOnly((string) file_get_contents($file->getPathname()), $dir . '/' . $file->getFilename());
                ++$scanned;
            }
        }
        self::assertGreaterThanOrEqual(8, $scanned);
    }

    #[Test]
    public function everyRenderedExamplePageLinksOnlyToItself(): void
    {
        $demo = OperatorDemo::fromFixtureFile(dirname(__DIR__) . '/examples/demo/fixture.php');

        foreach (['/admin/anokii', '/admin/anokii/updates', '/admin/anokii/records'] as $path) {
            $html = (string) $demo->handle(Request::create($path))->getContent();
            self::assertLocalOnly($html, $path);
            preg_match_all('/\b(?:src|href|action)="([^"]*)"/', $html, $matches);
            self::assertNotEmpty($matches[1], $path);
            foreach ($matches[1] as $reference) {
                self::assertMatchesRegularExpression('~^(?:/(?!/)|#)~', $reference, "{$path} references {$reference}");
            }
        }
    }

    #[Test]
    public function theDemoIsNotReachableFromProductionRouting(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);
        $extra = $composer['extra'] ?? [];
        self::assertIsArray($extra);
        self::assertArrayNotHasKey('waaseyaa', $extra, 'The operator package must not register providers or routes.');

        $root = dirname(__DIR__, 3);
        foreach (['src', 'public', 'config', 'templates'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                    continue;
                }
                $source = (string) file_get_contents($file->getPathname());
                self::assertStringNotContainsString('OperatorDemo', $source, $file->getPathname());
                self::assertStringNotContainsString('demo/router.php', $source, $file->getPathname());
            }
        }

        // Outside PHP's built-in server the router answers nothing at all.
        $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/demo/router.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), (string) $stderr);
        self::assertSame('', $stdout);
    }

    private static function assertLocalOnly(string $source, string $label): void
    {
        foreach (self::NETWORK_APIS as $api) {
            self::assertStringNotContainsString($api, $source, "{$label} uses {$api}");
        }
        self::assertDoesNotMatchRegularExpression(
            '~(?:https?:)?//[a-z0-9-]+\.[a-z0-9.-]+~i',
            str_replace(self::SVG_NAMESPACE, '', $source),
            "{$label} references an external URL",
        );
    }
}
