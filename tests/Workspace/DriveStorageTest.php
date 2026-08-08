<?php

declare(strict_types=1);

namespace Anokii\Tests\Workspace;

use Anokii\Workspace\Drive\DriveStorage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DriveStorageTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/anokii-drive-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0o700, true));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->directory);
    }

    #[Test]
    public function stores_a_content_sniffed_png_and_records_the_detected_type(): void
    {
        $bytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        );
        self::assertIsString($bytes);
        $source = $this->source('pixel.bin', $bytes);

        $file = new DriveStorage($this->directory)->store($source, 'photo.png', 7);

        self::assertSame('image/png', $file->mimeType);
        self::assertNotNull(new DriveStorage($this->directory)->pathForUri($file->uri));
    }

    #[Test]
    public function rejects_an_extension_spoof_instead_of_falling_back_to_the_filename(): void
    {
        $source = $this->source('payload.txt', 'not an image');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('File type not allowed.');

        new DriveStorage($this->directory)->store($source, 'payload.png', 7);
    }

    #[Test]
    public function the_default_policy_rejects_active_svg_content(): void
    {
        $source = $this->source('active.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        $this->expectException(\InvalidArgumentException::class);

        new DriveStorage($this->directory)->store($source, 'active.svg', 7);
    }

    private function source(string $name, string $bytes): string
    {
        $path = $this->directory . '/' . $name;
        self::assertNotFalse(file_put_contents($path, $bytes));

        return $path;
    }
}
