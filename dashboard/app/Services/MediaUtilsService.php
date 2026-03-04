<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Media Utilities — file management, metadata extraction, and storage helpers.
 *
 *
 * Handles:
 *   - File path generation and organization
 *   - Image/video metadata extraction
 *   - Aspect ratio detection for platform compatibility
 *   - File download and storage
 */
class MediaUtilsService
{
    /**
     * Storage disk for media files.
     */
    protected string $disk = 'local';

    /**
     * Base path for media storage.
     */
    protected string $basePath = 'media';

    /**
     * Get path for inbox (newly uploaded) media.
     */
    public function inboxPath(): string
    {
        $path = $this->basePath . '/inbox';
        Storage::disk($this->disk)->makeDirectory($path);
        return $path;
    }

    /**
     * Get path for processed media.
     */
    public function processedPath(): string
    {
        $path = $this->basePath . '/processed';
        Storage::disk($this->disk)->makeDirectory($path);
        return $path;
    }

    /**
     * Get path for Snapchat-ready media.
     */
    public function snapchatReadyPath(): string
    {
        $path = $this->basePath . '/snapchat_ready';
        Storage::disk($this->disk)->makeDirectory($path);
        return $path;
    }

    /**
     * Get path for collages.
     */
    public function collagesPath(): string
    {
        $path = $this->basePath . '/collages';
        Storage::disk($this->disk)->makeDirectory($path);
        return $path;
    }

    /**
     * Get path for video compilations.
     */
    public function compilationsPath(): string
    {
        $path = $this->basePath . '/compilations';
        Storage::disk($this->disk)->makeDirectory($path);
        return $path;
    }

    /**
     * Get path for resumes.
     */
    public function resumesPath(): string
    {
        $path = $this->basePath . '/resumes';
        Storage::disk($this->disk)->makeDirectory($path);
        return $path;
    }

    /**
     * Generate a unique filename preserving the original extension.
     */
    public function generateFilename(string $originalName, string $prefix = ''): string
    {
        $ext = pathinfo($originalName, PATHINFO_EXTENSION) ?: 'jpg';
        $timestamp = now()->format('Ymd_His');
        $uid = Str::random(8);
        $prefixStr = $prefix ? "{$prefix}_" : '';

        return "{$prefixStr}{$timestamp}_{$uid}.{$ext}";
    }

    /**
     * Extract metadata from an image file.
     */
    public function getImageMetadata(string $filePath): array
    {
        if (!file_exists($filePath)) {
            return [];
        }

        try {
            $imageInfo = getimagesize($filePath);
            if ($imageInfo === false) {
                return [
                    'file_size_bytes' => filesize($filePath),
                    'error' => 'Could not read image info',
                ];
            }

            $width = $imageInfo[0];
            $height = $imageInfo[1];
            $mimeType = $imageInfo['mime'] ?? 'image/unknown';

            return [
                'width'           => $width,
                'height'          => $height,
                'file_size_bytes' => filesize($filePath),
                'mime_type'       => $mimeType,
                'aspect_ratio'    => $this->aspectRatioLabel($width, $height),
                'orientation'     => $this->getOrientation($width, $height),
            ];
        } catch (\Exception $e) {
            return [
                'file_size_bytes' => filesize($filePath),
                'error'           => $e->getMessage(),
            ];
        }
    }

    /**
     * Extract metadata from a video file using ffprobe.
     */
    public function getVideoMetadata(string $filePath): array
    {
        if (!file_exists($filePath)) {
            return [];
        }

        $metadata = [
            'file_size_bytes' => filesize($filePath),
        ];

        try {
            $command = sprintf(
                'ffprobe -v quiet -print_format json -show_format -show_streams %s 2>&1',
                escapeshellarg($filePath)
            );

            $output = shell_exec($command);
            $probe = json_decode($output, true);

            if (!$probe) {
                return $metadata;
            }

            // Find video stream
            $videoStream = null;
            foreach ($probe['streams'] ?? [] as $stream) {
                if (($stream['codec_type'] ?? '') === 'video') {
                    $videoStream = $stream;
                    break;
                }
            }

            $format = $probe['format'] ?? [];

            if ($videoStream) {
                $metadata['width'] = (int) ($videoStream['width'] ?? 0);
                $metadata['height'] = (int) ($videoStream['height'] ?? 0);
                $metadata['codec'] = $videoStream['codec_name'] ?? null;
                $metadata['aspect_ratio'] = $this->aspectRatioLabel(
                    $metadata['width'],
                    $metadata['height']
                );
                $metadata['orientation'] = $this->getOrientation(
                    $metadata['width'],
                    $metadata['height']
                );
            }

            if ($format) {
                $metadata['duration_seconds'] = (float) ($format['duration'] ?? 0);
                $metadata['bitrate'] = (int) ($format['bit_rate'] ?? 0);
                $metadata['format_name'] = $format['format_name'] ?? null;
            }

            return $metadata;
        } catch (\Exception $e) {
            $metadata['error'] = $e->getMessage();
            return $metadata;
        }
    }

    /**
     * Check if dimensions are vertical (portrait).
     */
    public function isVertical(int $width, int $height): bool
    {
        return $height > $width;
    }

    /**
     * Check if dimensions are horizontal (landscape).
     */
    public function isHorizontal(int $width, int $height): bool
    {
        return $width > $height;
    }

    /**
     * Check if dimensions are square (with 5% tolerance).
     */
    public function isSquare(int $width, int $height): bool
    {
        $diff = abs($width - $height);
        $max = max($width, $height);
        return $diff < ($max * 0.05);
    }

    /**
     * Get orientation string.
     */
    public function getOrientation(int $width, int $height): string
    {
        if ($this->isSquare($width, $height)) {
            return 'square';
        }
        return $this->isVertical($width, $height) ? 'vertical' : 'horizontal';
    }

    /**
     * Return a human-readable aspect ratio label.
     */
    public function aspectRatioLabel(int $width, int $height): string
    {
        if ($this->isSquare($width, $height)) {
            return '1:1';
        }

        $ratio = $height > 0 ? $width / $height : 1;

        // Common aspect ratios
        if ($ratio >= 0.5 && $ratio <= 0.6) {
            return '9:16';
        }
        if ($ratio >= 1.7 && $ratio <= 1.8) {
            return '16:9';
        }
        if ($ratio >= 0.74 && $ratio <= 0.76) {
            return '3:4';
        }
        if ($ratio >= 1.3 && $ratio <= 1.35) {
            return '4:3';
        }
        if ($ratio >= 0.79 && $ratio <= 0.81) {
            return '4:5';
        }

        return sprintf('%.2f:1', $ratio);
    }

    /**
     * Get optimal platform for a given aspect ratio.
     */
    public function suggestPlatforms(int $width, int $height): array
    {
        $ratio = $this->aspectRatioLabel($width, $height);
        $orientation = $this->getOrientation($width, $height);

        $suggestions = [];

        // Vertical content
        if ($orientation === 'vertical') {
            $suggestions[] = ['platform' => 'tiktok', 'fit' => 'optimal'];
            $suggestions[] = ['platform' => 'instagram_reels', 'fit' => 'optimal'];
            $suggestions[] = ['platform' => 'youtube_shorts', 'fit' => 'optimal'];
            $suggestions[] = ['platform' => 'snapchat', 'fit' => 'optimal'];
        }

        // Square content
        if ($orientation === 'square') {
            $suggestions[] = ['platform' => 'instagram_feed', 'fit' => 'optimal'];
            $suggestions[] = ['platform' => 'facebook', 'fit' => 'good'];
            $suggestions[] = ['platform' => 'threads', 'fit' => 'optimal'];
        }

        // Horizontal content
        if ($orientation === 'horizontal') {
            $suggestions[] = ['platform' => 'youtube', 'fit' => 'optimal'];
            $suggestions[] = ['platform' => 'facebook', 'fit' => 'optimal'];
            $suggestions[] = ['platform' => 'linkedin', 'fit' => 'optimal'];
            $suggestions[] = ['platform' => 'twitter', 'fit' => 'good'];
        }

        // 4:5 is ideal for Instagram feed
        if ($ratio === '4:5') {
            array_unshift($suggestions, ['platform' => 'instagram_feed', 'fit' => 'ideal']);
        }

        return $suggestions;
    }

    /**
     * Download a file from URL and save to storage.
     */
    public function downloadFromUrl(string $url, string $destPath): ?string
    {
        try {
            $contents = file_get_contents($url);
            if ($contents === false) {
                return null;
            }

            Storage::disk($this->disk)->put($destPath, $contents);
            return Storage::disk($this->disk)->path($destPath);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Move file from inbox to processed.
     */
    public function moveToProcessed(string $filename): ?string
    {
        $source = $this->inboxPath() . '/' . $filename;
        $dest = $this->processedPath() . '/' . $filename;

        if (Storage::disk($this->disk)->exists($source)) {
            Storage::disk($this->disk)->move($source, $dest);
            return Storage::disk($this->disk)->path($dest);
        }

        return null;
    }

    /**
     * Get full filesystem path.
     */
    public function getFullPath(string $relativePath): string
    {
        return Storage::disk($this->disk)->path($relativePath);
    }

    /**
     * Check if file exists.
     */
    public function fileExists(string $path): bool
    {
        return Storage::disk($this->disk)->exists($path);
    }

    /**
     * Delete a file.
     */
    public function deleteFile(string $path): bool
    {
        return Storage::disk($this->disk)->delete($path);
    }

    /**
     * Get file size in human-readable format.
     */
    public function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
