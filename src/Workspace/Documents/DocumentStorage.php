<?php

declare(strict_types=1);

namespace Anokii\Workspace\Documents;

use Waaseyaa\Media\File;
use Waaseyaa\Media\LocalFileRepository;
use Waaseyaa\Media\UploadHandler;

/**
 * Stores Documents file bytes on the storage volume through the Waaseyaa media
 * layer. Each document version has a source file (.docx) and a preview file
 * (.pdf); both are stored here under "<files_dir>/documents/" and addressed by
 * the public:// URI the media layer uses. Only those URIs are kept in the
 * entity; bytes never touch the database.
 *
 * The files_dir is the instance's configured storage directory
 * (WAASEYAA_FILES_DIR), so documents stay on the instance's own infrastructure,
 * sovereign at rest.
 */
final class DocumentStorage
{
    private const string SUBDIR = 'documents';

    private readonly UploadHandler $handler;
    private readonly LocalFileRepository $repository;

    /** @var list<string> */
    private readonly array $allowedMimeTypes;

    private readonly int $maxBytes;

    /**
     * @param list<string> $allowedMimeTypes
     */
    public function __construct(
        private readonly string $filesDir,
        array $allowedMimeTypes,
        int $maxBytes,
    ) {
        $this->handler = new UploadHandler($filesDir, $allowedMimeTypes, $maxBytes);
        $this->repository = new LocalFileRepository($filesDir);
        $this->allowedMimeTypes = $allowedMimeTypes;
        $this->maxBytes = $maxBytes;
    }

    /**
     * Validate and store bytes copied from a source path (an uploaded temp file
     * or a local file when seeding). Returns the saved media File value object.
     *
     * @throws \InvalidArgumentException when validation fails (size or MIME)
     * @throws \RuntimeException when the bytes cannot be written
     */
    public function store(string $sourcePath, string $originalName, ?int $ownerId): File
    {
        $size = is_file($sourcePath) ? (int) filesize($sourcePath) : 0;
        if (!is_file($sourcePath)) {
            throw new \InvalidArgumentException('File type could not be verified.');
        }
        if ($size > $this->maxBytes) {
            $maxMb = round($this->maxBytes / 1_048_576);
            throw new \InvalidArgumentException("File must be under {$maxMb}MB.");
        }

        $detected = $this->handler->detectMimeType($sourcePath);
        if ($detected === null) {
            throw new \InvalidArgumentException('File type could not be verified.');
        }
        $mimeType = $this->normalizedDocumentMime($sourcePath, $originalName, $detected);
        if ($mimeType === null || !UploadHandler::mimeTypeMatches($mimeType, $this->allowedMimeTypes)) {
            throw new \InvalidArgumentException('File type not allowed.');
        }

        $safeName = $this->handler->generateSafeFilename($originalName);
        $targetDir = $this->filesDir . '/' . self::SUBDIR;
        if (!is_dir($targetDir) && !mkdir($targetDir, 0o755, true) && !is_dir($targetDir)) {
            throw new \RuntimeException('Unable to create Documents storage directory: ' . $targetDir);
        }

        $dest = $targetDir . '/' . $safeName;
        if (!copy($sourcePath, $dest)) {
            throw new \RuntimeException('Failed to store document file.');
        }

        $file = new File(
            uri: 'public://' . self::SUBDIR . '/' . $safeName,
            filename: $originalName,
            mimeType: $mimeType,
            size: (int) filesize($dest),
            ownerId: $ownerId,
            createdTime: time(),
        );
        try {
            $this->repository->save($file);
        } catch (\Throwable $exception) {
            @unlink($dest);

            throw $exception;
        }

        return $file;
    }

    /**
     * Store raw bytes (e.g. a PDF returned by Gotenberg) under the given name.
     */
    public function storeBytes(string $bytes, string $originalName, string $mimeType, ?int $ownerId): File
    {
        $tmp = tempnam(sys_get_temp_dir(), 'doc_');
        if ($tmp === false) {
            throw new \RuntimeException('Unable to create a temporary file for document bytes.');
        }
        try {
            if (file_put_contents($tmp, $bytes) === false) {
                throw new \RuntimeException('Unable to buffer document bytes.');
            }

            return $this->store($tmp, $originalName, $ownerId);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Absolute on-disk path for a stored public:// URI, or null if missing.
     */
    public function pathForUri(string $uri): ?string
    {
        $path = $this->resolvePath($uri);

        return ($path !== null && is_file($path)) ? $path : null;
    }

    public function delete(string $uri): void
    {
        $path = $this->resolvePath($uri);
        if ($path !== null && is_file($path) && !unlink($path)) {
            throw new \RuntimeException('Failed to remove document bytes.');
        }
        $this->repository->delete($uri);
    }

    private function resolvePath(string $uri): ?string
    {
        $prefix = 'public://';
        if (!str_starts_with($uri, $prefix)) {
            return null;
        }
        $relative = ltrim(substr($uri, strlen($prefix)), '/');
        if ($relative === '' || str_contains($relative, '..')) {
            return null;
        }

        return $this->filesDir . '/' . $relative;
    }

    private function normalizedDocumentMime(string $path, string $originalName, string $detected): ?string
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($extension === 'pdf' && $detected === 'application/pdf') {
            return 'application/pdf';
        }
        if ($extension === 'docx'
            && in_array($detected, ['application/zip', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'], true)
            && $this->isWordprocessingOoxml($path)
        ) {
            return 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
        }
        if ($extension === 'doc' && $this->hasOleCompoundFileSignature($path)) {
            return 'application/msword';
        }

        return null;
    }

    private function isWordprocessingOoxml(string $path): bool
    {
        $archive = new \ZipArchive();
        if ($archive->open($path, \ZipArchive::RDONLY) !== true) {
            return false;
        }
        try {
            return $archive->locateName('[Content_Types].xml') !== false
                && $archive->locateName('word/document.xml') !== false;
        } finally {
            $archive->close();
        }
    }

    private function hasOleCompoundFileSignature(string $path): bool
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        try {
            return fread($handle, 8) === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";
        } finally {
            fclose($handle);
        }
    }
}
