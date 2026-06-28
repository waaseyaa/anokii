<?php

declare(strict_types=1);

namespace Anokii\Workspace\Documents;

/**
 * Converts office documents (.docx) to PDF via a Gotenberg service.
 *
 * Gotenberg wraps LibreOffice behind an HTTP API, so the application image stays
 * lean (no LibreOffice baked in). Gotenberg runs as its own service and this
 * client posts the uploaded .docx to its LibreOffice route, receiving the PDF
 * bytes back. The PDF is then stored as the version's preview through the media
 * layer.
 *
 * Optional: the base URL defaults to empty, which disables conversion
 * ({@see isConfigured()} returns false and documents are stored without a
 * preview). Seeded versions ship with a pre-converted .pdf, so seeding does not
 * depend on Gotenberg's availability.
 */
final class GotenbergClient
{
    public function __construct(
        private readonly string $baseUrl = '',
        private readonly int $timeoutSeconds = 120,
    ) {}

    public function isConfigured(): bool
    {
        return trim($this->baseUrl) !== '';
    }

    /**
     * Convert the office document at $sourcePath to PDF and return the PDF bytes.
     *
     * @throws \RuntimeException on any conversion failure (caller decides whether
     *   to store the document without a preview).
     */
    public function convertToPdf(string $sourcePath, string $filename): string
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('Gotenberg is not configured (base URL is empty).');
        }
        if (!is_file($sourcePath)) {
            throw new \RuntimeException('Source file for conversion is missing.');
        }
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('The curl extension is required for document conversion.');
        }

        $url = rtrim($this->baseUrl, '/') . '/forms/libreoffice/convert';
        $handle = curl_init($url);
        if ($handle === false) {
            throw new \RuntimeException('Unable to initialise the conversion request.');
        }

        $post = [
            'files' => new \CURLFile(
                $sourcePath,
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                $filename,
            ),
        ];

        curl_setopt_array($handle, [
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_POST => true,
            \CURLOPT_POSTFIELDS => $post,
            \CURLOPT_TIMEOUT => $this->timeoutSeconds,
            \CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, \CURLINFO_HTTP_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if (!is_string($body) || $status !== 200) {
            throw new \RuntimeException(sprintf(
                'Gotenberg conversion failed (HTTP %d)%s.',
                $status,
                $error !== '' ? ': ' . $error : '',
            ));
        }

        return $body;
    }
}
