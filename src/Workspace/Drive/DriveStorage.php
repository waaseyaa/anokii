<?php

declare(strict_types=1);

namespace Anokii\Workspace\Drive;

use Waaseyaa\Media\File;
use Waaseyaa\Media\LocalFileRepository;
use Waaseyaa\Media\UploadHandler;

/**
 * Stores Drive file bytes on the storage volume through the Waaseyaa media
 * layer, and records the native File metadata sidecar.
 *
 * We reuse the framework's media primitives rather than rolling our own
 * uploader: UploadHandler for MIME/size validation and safe filename
 * generation, and LocalFileRepository for the on-disk metadata sidecar. Bytes
 * land under "<files_dir>/drive/", addressed by the public:// URI the media
 * layer uses elsewhere. files_dir points at the instance's storage volume
 * (WAASEYAA_FILES_DIR), so Drive content stays on the instance's own
 * infrastructure.
 *
 * The allowed MIME types and the maximum byte size are constructor parameters
 * so an instance can widen or narrow what Drive accepts. The defaults cover the
 * common image types at a 10MB ceiling.
 */
final class DriveStorage
{
    private const string SUBDIR = 'drive';

    /** A neutral default: the common web image types. */
    public const array DEFAULT_ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/svg+xml',
    ];

    /** A neutral default ceiling of 10MB. */
    public const int DEFAULT_MAX_BYTES = 10 * 1024 * 1024;

    /** Extension to MIME fallback when the fileinfo extension is unavailable. */
    private const array MIME_BY_EXTENSION = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
        'pdf' => 'application/pdf', 'txt' => 'text/plain', 'csv' => 'text/csv',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    private readonly UploadHandler $handler;
    private readonly LocalFileRepository $repository;

    /**
     * @param list<string> $allowedMimeTypes
     */
    public function __construct(
        private readonly string $filesDir,
        array $allowedMimeTypes = self::DEFAULT_ALLOWED_MIME_TYPES,
        int $maxBytes = self::DEFAULT_MAX_BYTES,
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

        // Pass an empty tmp_name so UploadHandler validates against the MIME we
        // already resolved, rather than re-running finfo (which may be absent in
        // some PHP builds); size + error are still enforced.
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
            throw new \RuntimeException('Unable to create Drive storage directory: ' . $targetDir);
        }

        $dest = $targetDir . '/' . $safeName;
        if (!copy($sourcePath, $dest)) {
            throw new \RuntimeException('Failed to store Drive file.');
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
     * Absolute on-disk path for a stored public:// URI, or null if missing.
     */
    public function pathForUri(string $uri): ?string
    {
        $path = $this->resolvePath($uri);

        return ($path !== null && is_file($path)) ? $path : null;
    }

    /**
     * Remove the stored bytes and the metadata sidecar for a URI (best effort).
     */
    public function delete(string $uri): void
    {
        $path = $this->resolvePath($uri);
        if ($path !== null && is_file($path)) {
            @unlink($path);
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

    /**
     * Resolve the MIME type from file content when the fileinfo extension is
     * available (most accurate), otherwise fall back to the original filename's
     * extension, otherwise a generic binary type.
     */
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
