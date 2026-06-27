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

    private const array MIME_BY_EXTENSION = [
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    private readonly UploadHandler $handler;
    private readonly LocalFileRepository $repository;

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
        $mimeType = $this->detectMime($sourcePath, $originalName);

        $errors = $this->handler->validate([
            'error' => UPLOAD_ERR_OK,
            'size' => $size,
            'tmp_name' => '',
            'type' => $mimeType,
        ]);
        if ($errors !== []) {
            throw new \InvalidArgumentException(implode(' ', $errors));
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
        $this->repository->save($file);

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

    private function detectMime(string $path, string $originalName): string
    {
        if (is_file($path) && extension_loaded('fileinfo')) {
            $detected = new \finfo(FILEINFO_MIME_TYPE)->file($path);
            if (is_string($detected) && $detected !== '') {
                return $detected;
            }
        }

        return self::MIME_BY_EXTENSION[strtolower(pathinfo($originalName, PATHINFO_EXTENSION))]
            ?? 'application/octet-stream';
    }
}
