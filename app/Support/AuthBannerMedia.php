<?php

namespace App\Support;

class AuthBannerMedia
{
    /**
     * Supported image extensions for the auth header banner slideshow.
     *
     * @var list<string>
     */
    private const AllowedExtensions = ['webp', 'jpg', 'jpeg', 'png', 'avif'];

    /**
     * Scans `public/images/auth` for all valid banner images, placing the preferred basename first when present.
     *
     * @return list<array{url: string, filename: string}>
     */
    public static function slides(?string $preferredBasename = null, ?string $directory = null): array
    {
        $targetDirectory = $directory ?? public_path('images/auth');

        if (! is_dir($targetDirectory)) {
            return [];
        }

        $entries = scandir($targetDirectory);
        if ($entries === false) {
            return [];
        }

        $matchedFiles = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $fullPath = $targetDirectory.DIRECTORY_SEPARATOR.$entry;
            if (! is_file($fullPath)) {
                continue;
            }

            $extension = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            if (! in_array($extension, self::AllowedExtensions, true)) {
                continue;
            }

            $matchedFiles[] = $entry;
        }

        if ($matchedFiles === []) {
            return [];
        }

        sort($matchedFiles, SORT_NATURAL | SORT_FLAG_CASE);

        if (filled($preferredBasename)) {
            $preferredLower = strtolower((string) $preferredBasename);
            $preferredIndex = null;

            foreach ($matchedFiles as $index => $filename) {
                if (strtolower($filename) === $preferredLower) {
                    $preferredIndex = $index;

                    break;
                }
            }

            if ($preferredIndex !== null && $preferredIndex > 0) {
                $preferredFile = $matchedFiles[$preferredIndex];
                unset($matchedFiles[$preferredIndex]);
                array_unshift($matchedFiles, $preferredFile);
            }
        }

        $slides = [];

        foreach ($matchedFiles as $filename) {
            $fullPath = $targetDirectory.DIRECTORY_SEPARATOR.$filename;
            $mtime = @filemtime($fullPath) ?: 1;

            $slides[] = [
                'url' => asset('images/auth/'.$filename).'?v='.$mtime,
                'filename' => $filename,
            ];
        }

        return $slides;
    }
}
