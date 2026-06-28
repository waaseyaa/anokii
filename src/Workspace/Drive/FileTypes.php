<?php

declare(strict_types=1);

namespace Anokii\Workspace\Drive;

/**
 * Maps a file's MIME type / name to the coarse "kind" code the Drive UI uses
 * for its colour-coded icon (pdf|doc|xls|img|gen), and formats byte sizes for
 * display.
 */
final class FileTypes
{
    /**
     * Resolve the UI icon bucket from MIME type, falling back to the extension.
     */
    public static function kind(string $mimeType, string $filename): string
    {
        $mime = strtolower(trim($mimeType));

        if (str_starts_with($mime, 'image/')) {
            return 'img';
        }
        if ($mime === 'application/pdf') {
            return 'pdf';
        }
        if (
            $mime === 'application/msword'
            || $mime === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        ) {
            return 'doc';
        }
        if (
            $mime === 'application/vnd.ms-excel'
            || $mime === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            || $mime === 'text/csv'
        ) {
            return 'xls';
        }

        // Fall back to the extension for octet-stream and friends.
        return match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'pdf' => 'pdf',
            'doc', 'docx', 'rtf', 'odt' => 'doc',
            'xls', 'xlsx', 'csv', 'ods' => 'xls',
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'heic', 'bmp', 'tif', 'tiff' => 'img',
            default => 'gen',
        };
    }

    /**
     * Human-readable byte size, e.g. "2.1 MB", "48 KB".
     */
    public static function humanSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = $bytes / 1024;
        $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return ($value >= 10 ? (string) round($value) : number_format($value, 1)) . ' ' . $units[$unit];
    }
}
