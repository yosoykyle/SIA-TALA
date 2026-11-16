<?php

declare(strict_types=1);

namespace App\Support;

use Symfony\Component\HttpFoundation\Response;

final class PrivateDocumentResponse
{
    /**
     * @var list<string>
     */
    public const InlineAllowedMimeTypes = [
        'application/pdf',
        'image/jpeg',
        'image/png',
    ];

    /**
     * Build a private inline or download HTTP response after verifying buffer MIME safety.
     *
     * @param  (callable(string): void)|null  $beforeRespond
     */
    public static function make(
        string $contents,
        string $filenameStem,
        ?string $storedMimeType,
        bool $inline,
        ?callable $beforeRespond = null,
    ): Response {
        $mime = self::resolveMimeType($contents, $storedMimeType, $inline);

        abort_if(
            $inline && ! in_array($mime, self::InlineAllowedMimeTypes, true),
            415,
            'This file can be downloaded for review.',
        );

        if ($beforeRespond !== null) {
            $beforeRespond($mime);
        }

        $extension = self::extensionForMime($mime);
        $disposition = $inline ? 'inline' : 'attachment';

        return response($contents, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => sprintf('%s; filename="%s.%s"', $disposition, $filenameStem, $extension),
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public static function resolveMimeType(string $contents, ?string $storedMimeType, bool $inspectBuffer = true): string
    {
        if ($inspectBuffer) {
            $detected = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents);

            if (is_string($detected) && $detected !== '') {
                return $detected;
            }
        }

        $fallback = trim((string) $storedMimeType);

        return $fallback !== '' ? $fallback : 'application/octet-stream';
    }

    public static function extensionForMime(string $mime): string
    {
        return match ($mime) {
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            default => 'bin',
        };
    }
}
