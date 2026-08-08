<?php

declare(strict_types=1);

namespace Anokii\Tests\Workspace;

use Anokii\Workspace\Documents\DocumentStorage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DocumentStorageTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/anokii-documents-' . bin2hex(random_bytes(8));
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
    public function stores_a_content_sniffed_pdf(): void
    {
        $source = $this->source('source.bin', "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF");

        $file = $this->storage()->store($source, 'brief.pdf', 7);

        self::assertSame('application/pdf', $file->mimeType);
        self::assertNotNull($this->storage()->pathForUri($file->uri));
    }

    #[Test]
    public function rejects_a_pdf_renamed_as_a_word_document(): void
    {
        $source = $this->source('source.pdf', "%PDF-1.4\n%%EOF");

        $this->expectException(\InvalidArgumentException::class);

        $this->storage()->store($source, 'spoofed.docx', 7);
    }

    #[Test]
    public function rejects_arbitrary_zip_content_renamed_as_docx(): void
    {
        $source = $this->zip('fake.docx', ['payload.txt' => 'not OOXML']);

        $this->expectException(\InvalidArgumentException::class);

        $this->storage()->store($source, 'fake.docx', 7);
    }

    #[Test]
    public function accepts_a_docx_container_with_required_ooxml_members(): void
    {
        $source = $this->zip('real.docx', [
            '[Content_Types].xml' => '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
            'word/document.xml' => '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"/>',
        ]);

        $file = $this->storage()->store($source, 'real.docx', 7);

        self::assertSame('application/vnd.openxmlformats-officedocument.wordprocessingml.document', $file->mimeType);
    }

    #[Test]
    public function accepts_a_real_docx_from_an_extensionless_upload_temp_path(): void
    {
        $source = $this->zip('phpA1B2C3', [
            '[Content_Types].xml' => '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
            'word/document.xml' => '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"/>',
        ]);

        $file = $this->storage()->store($source, 'minutes.docx', 7);

        self::assertSame('application/vnd.openxmlformats-officedocument.wordprocessingml.document', $file->mimeType);
    }

    private function storage(): DocumentStorage
    {
        return new DocumentStorage($this->directory, [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ], 1024 * 1024);
    }

    private function source(string $name, string $bytes): string
    {
        $path = $this->directory . '/' . $name;
        self::assertNotFalse(file_put_contents($path, $bytes));

        return $path;
    }

    /** @param array<string, string> $members */
    private function zip(string $name, array $members): string
    {
        $tarPath = $this->directory . '/fixture-' . bin2hex(random_bytes(4)) . '.tar';
        $tar = new \PharData($tarPath);
        foreach ($members as $path => $bytes) {
            $tar->addFromString($path, $bytes);
        }
        $zip = $tar->convertToData(\Phar::ZIP);
        $target = $this->directory . '/' . $name;
        self::assertTrue(rename($zip->getPath(), $target));
        unset($zip, $tar);
        @unlink($tarPath);

        return $target;
    }
}
