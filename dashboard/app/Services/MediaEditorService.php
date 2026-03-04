<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

/**
 * Media Editor Service — local image/video processing.
 *
 * Features:
 * - Image resizing and cropping
 * - Platform-specific optimization
 * - Watermark and text overlay
 * - Video editing via FFmpeg
 * - Collage creation
 *
 * Note: Requires Intervention/Image package for image processing
 * and FFmpeg for video processing.
 * Install via: composer require intervention/image-laravel
 */
class MediaEditorService
{
    // Platform specifications for media optimization
    private const PLATFORM_SPECS = [
        'instagram' => [
            'feed'    => ['width' => 1080, 'height' => 1350, 'ratio' => '4:5'],
            'story'   => ['width' => 1080, 'height' => 1920, 'ratio' => '9:16'],
            'reel'    => ['width' => 1080, 'height' => 1920, 'ratio' => '9:16'],
            'square'  => ['width' => 1080, 'height' => 1080, 'ratio' => '1:1'],
        ],
        'facebook' => [
            'feed'    => ['width' => 1200, 'height' => 630, 'ratio' => '1.91:1'],
            'story'   => ['width' => 1080, 'height' => 1920, 'ratio' => '9:16'],
        ],
        'youtube' => [
            'thumbnail' => ['width' => 1280, 'height' => 720, 'ratio' => '16:9'],
            'short'     => ['width' => 1080, 'height' => 1920, 'ratio' => '9:16'],
        ],
        'linkedin' => [
            'feed'    => ['width' => 1200, 'height' => 627, 'ratio' => '1.91:1'],
            'square'  => ['width' => 1080, 'height' => 1080, 'ratio' => '1:1'],
        ],
        'tiktok' => [
            'video'   => ['width' => 1080, 'height' => 1920, 'ratio' => '9:16'],
        ],
        'twitter' => [
            'feed'    => ['width' => 1200, 'height' => 675, 'ratio' => '16:9'],
        ],
        'pinterest' => [
            'pin'     => ['width' => 1000, 'height' => 1500, 'ratio' => '2:3'],
        ],
        'snapchat' => [
            'story'   => ['width' => 1080, 'height' => 1920, 'ratio' => '9:16'],
        ],
    ];

    // Platform filter presets
    private const FILTER_PRESETS = [
        'instagram'  => ['brightness' => 1.05, 'contrast' => 1.1, 'saturation' => 1.15],
        'tiktok'     => ['brightness' => 1.1, 'contrast' => 1.15, 'saturation' => 1.2],
        'linkedin'   => ['brightness' => 1.0, 'contrast' => 1.05, 'saturation' => 0.95],
        'facebook'   => ['brightness' => 1.02, 'contrast' => 1.05, 'saturation' => 1.05],
        'youtube'    => ['brightness' => 1.05, 'contrast' => 1.1, 'saturation' => 1.1],
    ];

    // ═══════════════════════════════════════════════════════════════════════
    // IMAGE EDITING
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Get platform specifications for a format.
     */
    public function getPlatformSpec(string $platform, string $format = 'feed'): array
    {
        return self::PLATFORM_SPECS[$platform][$format]
            ?? self::PLATFORM_SPECS['instagram']['feed'];
    }

    /**
     * Resize image for a specific platform.
     */
    public function resizeForPlatform(
        string $sourcePath,
        string $platform,
        string $format = 'feed',
        ?string $outputPath = null
    ): array {
        if (!$this->hasImageLibrary()) {
            return ['success' => false, 'error' => 'Image library not available'];
        }

        try {
            $spec = $this->getPlatformSpec($platform, $format);
            $outputPath = $outputPath ?? $this->generateOutputPath($sourcePath, "_{$platform}_{$format}");

            $image = $this->readImage($sourcePath);

            // Smart crop to target dimensions
            $image->cover($spec['width'], $spec['height']);

            $image->save($outputPath);

            return [
                'success' => true,
                'path'    => $outputPath,
                'width'   => $spec['width'],
                'height'  => $spec['height'],
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Add watermark to image.
     */
    public function addWatermark(
        string $sourcePath,
        string $text,
        float $opacity = 0.3,
        ?string $outputPath = null
    ): array {
        if (!$this->hasImageLibrary()) {
            return ['success' => false, 'error' => 'Image library not available'];
        }

        try {
            $outputPath = $outputPath ?? $this->generateOutputPath($sourcePath, '_watermarked');

            $image = $this->readImage($sourcePath);

            // Add text watermark in bottom-right corner
            $image->text($text, $image->width() - 20, $image->height() - 20, function ($font) use ($opacity) {
                $font->filename(public_path('fonts/arial.ttf'));
                $font->size(24);
                $font->color([255, 255, 255, $opacity]);
                $font->align('right');
                $font->valign('bottom');
            });

            $image->save($outputPath);

            return ['success' => true, 'path' => $outputPath];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Add text overlay to image.
     */
    public function addTextOverlay(
        string $sourcePath,
        string $text,
        string $position = 'bottom',
        int $fontSize = 0,
        ?string $outputPath = null
    ): array {
        if (!$this->hasImageLibrary()) {
            return ['success' => false, 'error' => 'Image library not available'];
        }

        try {
            $outputPath = $outputPath ?? $this->generateOutputPath($sourcePath, '_text');

            $image = $this->readImage($sourcePath);
            $width = $image->width();
            $height = $image->height();
            $fontSize = $fontSize ?: max(24, (int)($width / 20));

            $y = match($position) {
                'top'    => $fontSize + 20,
                'center' => $height / 2,
                default  => $height - $fontSize - 20,
            };

            $image->text($text, $width / 2, $y, function ($font) use ($fontSize) {
                $font->filename(public_path('fonts/arial.ttf'));
                $font->size($fontSize);
                $font->color('#ffffff');
                $font->align('center');
                $font->valign('middle');
                $font->stroke('#000000', 2);
            });

            $image->save($outputPath);

            return ['success' => true, 'path' => $outputPath];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Apply platform-specific filter.
     */
    public function applyPlatformFilter(
        string $sourcePath,
        string $platform,
        ?string $outputPath = null
    ): array {
        if (!$this->hasImageLibrary()) {
            return ['success' => false, 'error' => 'Image library not available'];
        }

        try {
            $preset = self::FILTER_PRESETS[$platform] ?? self::FILTER_PRESETS['instagram'];
            $outputPath = $outputPath ?? $this->generateOutputPath($sourcePath, "_{$platform}_filtered");

            $image = $this->readImage($sourcePath);

            // Apply adjustments
            $image->brightness((int)(($preset['brightness'] - 1) * 100));
            $image->contrast((int)(($preset['contrast'] - 1) * 100));

            $image->save($outputPath);

            return [
                'success' => true,
                'path'    => $outputPath,
                'filter'  => $preset,
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Create a collage from multiple images.
     */
    public function createCollage(
        array $imagePaths,
        string $layout = 'grid',
        int $targetWidth = 1080,
        int $targetHeight = 1080
    ): array {
        if (!$this->hasImageLibrary()) {
            return ['success' => false, 'error' => 'Image library not available'];
        }

        if (count($imagePaths) < 2) {
            return ['success' => false, 'error' => 'At least 2 images required for collage'];
        }

        try {
            $outputPath = storage_path('app/public/collages/' . uniqid('collage_') . '.jpg');

            // Create blank canvas
            $canvas = $this->createCanvas($targetWidth, $targetHeight)->fill('#ffffff');

            $count = count($imagePaths);

            if ($layout === 'grid') {
                $cols = (int)ceil(sqrt($count));
                $rows = (int)ceil($count / $cols);
                $cellWidth = (int)($targetWidth / $cols);
                $cellHeight = (int)($targetHeight / $rows);

                foreach ($imagePaths as $i => $path) {
                    $row = (int)floor($i / $cols);
                    $col = $i % $cols;
                    $x = $col * $cellWidth;
                    $y = $row * $cellHeight;

                    $img = $this->readImage($path)->cover($cellWidth, $cellHeight);
                    $canvas->place($img, 'top-left', $x, $y);
                }
            } elseif ($layout === 'before_after' && $count >= 2) {
                $halfWidth = (int)($targetWidth / 2);
                $before = $this->readImage($imagePaths[0])->cover($halfWidth, $targetHeight);
                $after = $this->readImage($imagePaths[1])->cover($halfWidth, $targetHeight);

                $canvas->place($before, 'top-left', 0, 0);
                $canvas->place($after, 'top-left', $halfWidth, 0);
            }

            $canvas->save($outputPath);

            return [
                'success' => true,
                'path'    => $outputPath,
                'layout'  => $layout,
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // VIDEO EDITING (FFmpeg)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Trim video to specified duration.
     */
    public function trimVideo(
        string $sourcePath,
        float $startSeconds,
        float $endSeconds,
        ?string $outputPath = null
    ): array {
        if (!$this->hasFFmpeg()) {
            return ['success' => false, 'error' => 'FFmpeg not available'];
        }

        $outputPath = $outputPath ?? $this->generateOutputPath($sourcePath, '_trimmed', '.mp4');
        $duration = $endSeconds - $startSeconds;

        $cmd = sprintf(
            'ffmpeg -i %s -ss %f -t %f -c copy %s -y 2>&1',
            escapeshellarg($sourcePath),
            $startSeconds,
            $duration,
            escapeshellarg($outputPath)
        );

        exec($cmd, $output, $returnCode);

        return $returnCode === 0
            ? ['success' => true, 'path' => $outputPath]
            : ['success' => false, 'error' => implode("\n", $output)];
    }

    /**
     * Resize video for platform.
     */
    public function resizeVideo(
        string $sourcePath,
        string $platform,
        string $format = 'video',
        ?string $outputPath = null
    ): array {
        if (!$this->hasFFmpeg()) {
            return ['success' => false, 'error' => 'FFmpeg not available'];
        }

        $spec = $this->getPlatformSpec($platform, $format);
        $outputPath = $outputPath ?? $this->generateOutputPath($sourcePath, "_{$platform}", '.mp4');

        $cmd = sprintf(
            'ffmpeg -i %s -vf "scale=%d:%d:force_original_aspect_ratio=decrease,pad=%d:%d:(ow-iw)/2:(oh-ih)/2" -c:a copy %s -y 2>&1',
            escapeshellarg($sourcePath),
            $spec['width'],
            $spec['height'],
            $spec['width'],
            $spec['height'],
            escapeshellarg($outputPath)
        );

        exec($cmd, $output, $returnCode);

        return $returnCode === 0
            ? ['success' => true, 'path' => $outputPath]
            : ['success' => false, 'error' => implode("\n", $output)];
    }

    /**
     * Add background music to video.
     */
    public function addMusicToVideo(
        string $videoPath,
        string $audioPath,
        float $musicVolume = 0.3,
        ?string $outputPath = null
    ): array {
        if (!$this->hasFFmpeg()) {
            return ['success' => false, 'error' => 'FFmpeg not available'];
        }

        $outputPath = $outputPath ?? $this->generateOutputPath($videoPath, '_music', '.mp4');

        $cmd = sprintf(
            'ffmpeg -i %s -i %s -filter_complex "[1:a]volume=%f[music];[0:a][music]amix=inputs=2:duration=first" -c:v copy %s -y 2>&1',
            escapeshellarg($videoPath),
            escapeshellarg($audioPath),
            $musicVolume,
            escapeshellarg($outputPath)
        );

        exec($cmd, $output, $returnCode);

        return $returnCode === 0
            ? ['success' => true, 'path' => $outputPath]
            : ['success' => false, 'error' => implode("\n", $output)];
    }

    /**
     * Concatenate multiple videos.
     */
    public function concatenateVideos(array $videoPaths, ?string $outputPath = null): array
    {
        if (!$this->hasFFmpeg()) {
            return ['success' => false, 'error' => 'FFmpeg not available'];
        }

        if (count($videoPaths) < 2) {
            return ['success' => false, 'error' => 'At least 2 videos required'];
        }

        $outputPath = $outputPath ?? storage_path('app/public/compilations/' . uniqid('compilation_') . '.mp4');

        // Create concat file
        $concatFile = storage_path('app/temp_concat.txt');
        $concatContent = '';
        foreach ($videoPaths as $path) {
            $concatContent .= "file '" . str_replace("'", "'\\''", $path) . "'\n";
        }
        file_put_contents($concatFile, $concatContent);

        $cmd = sprintf(
            'ffmpeg -f concat -safe 0 -i %s -c copy %s -y 2>&1',
            escapeshellarg($concatFile),
            escapeshellarg($outputPath)
        );

        exec($cmd, $output, $returnCode);
        @unlink($concatFile);

        return $returnCode === 0
            ? ['success' => true, 'path' => $outputPath]
            : ['success' => false, 'error' => implode("\n", $output)];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Check if Intervention Image library is available.
     */
    private function hasImageLibrary(): bool
    {
        return class_exists('Intervention\Image\Laravel\Facades\Image');
    }

    /**
     * Get the Image facade class name.
     */
    // ═══════════════════════════════════════════════════════════════════════
    // WORKFLOW INTEGRATION METHODS
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Edit media for a specific platform (unified interface for WorkflowService).
     *
     * @param string $filePath Source file path
     * @param string $platform Target platform
     * @param string $mediaType 'photo' or 'video'
     * @param int $width Original width
     * @param int $height Original height
     * @param int $qualityScore Quality score from analysis (1-10)
     * @param array $improvementTips Tips from vision analysis
     * @return string Path to edited file
     */
    public function editForPlatform(
        string $filePath,
        string $platform,
        string $mediaType,
        int $width,
        int $height,
        int $qualityScore = 7,
        array $improvementTips = []
    ): string {
        if ($mediaType === 'video') {
            $result = $this->resizeVideo($filePath, $platform);
        } else {
            $result = $this->resizeForPlatform($filePath, $platform);

            // Apply platform filter if quality is good
            if (($result['success'] ?? false) && $qualityScore >= 6) {
                $filteredResult = $this->applyPlatformFilter($result['path'], $platform);
                if ($filteredResult['success'] ?? false) {
                    return $filteredResult['path'];
                }
            }
        }

        return ($result['success'] ?? false) ? $result['path'] : $filePath;
    }

    /**
     * Mix background music into a video (alias for WorkflowService).
     *
     * @param string $videoPath Path to video
     * @param string $musicPath Path to music file
     * @param float $volume Music volume (0.0-1.0)
     * @return string Path to mixed video
     */
    public function mixBackgroundMusic(
        string $videoPath,
        string $musicPath,
        float $volume = 0.3
    ): string {
        $result = $this->addMusicToVideo($videoPath, $musicPath, $volume);
        return ($result['success'] ?? false) ? $result['path'] : $videoPath;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // PRIVATE HELPERS
    // ═══════════════════════════════════════════════════════════════════════

    private function imageClass(): string
    {
        return 'Intervention\Image\Laravel\Facades\Image';
    }

    /**
     * Read an image file.
     * @return mixed Intervention Image instance
     */
    private function readImage(string $path)
    {
        $class = $this->imageClass();
        return $class::read($path);
    }

    /**
     * Create a new image canvas.
     * @return mixed Intervention Image instance
     */
    private function createCanvas(int $width, int $height)
    {
        $class = $this->imageClass();
        return $class::create($width, $height);
    }

    private function hasFFmpeg(): bool
    {
        exec('ffmpeg -version 2>&1', $output, $returnCode);
        return $returnCode === 0;
    }

    private function generateOutputPath(string $sourcePath, string $suffix, ?string $extension = null): string
    {
        $info = pathinfo($sourcePath);
        $ext = $extension ?? '.' . ($info['extension'] ?? 'jpg');
        return storage_path('app/public/processed/' . $info['filename'] . $suffix . $ext);
    }

    /**
     * Auto-enhance an image with brightness, contrast, and sharpness adjustments.
     */
    public function autoEnhance(string $imagePath): array
    {
        if (!$this->hasImageLibrary()) {
            return [
                'success' => false,
                'error'   => 'Image processing library not available',
            ];
        }

        try {
            $image = $this->readImage($imagePath);
            $outputPath = $this->generateOutputPath($imagePath, '_enhanced');

            // Apply enhancements
            $image->brightness(5);
            $image->contrast(5);
            $image->sharpen(10);

            // Save enhanced image
            $image->save($outputPath);

            return [
                'success'     => true,
                'output_path' => $outputPath,
                'message'     => 'Image enhanced successfully',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error'   => 'Enhancement failed: ' . $e->getMessage(),
            ];
        }
    }
}
