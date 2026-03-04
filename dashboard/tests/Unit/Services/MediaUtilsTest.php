<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Services\MediaUtilsService;

class MediaUtilsTest extends TestCase
{
    protected MediaUtilsService $mediaUtils;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mediaUtils = new MediaUtilsService();
    }

    public function test_generates_unique_filenames(): void
    {
        $filename1 = $this->mediaUtils->generateFilename('photo.jpg');
        $filename2 = $this->mediaUtils->generateFilename('photo.jpg');

        $this->assertNotEquals($filename1, $filename2);
        $this->assertStringEndsWith('.jpg', $filename1);
        $this->assertStringEndsWith('.jpg', $filename2);
    }

    public function test_generates_filename_with_prefix(): void
    {
        $filename = $this->mediaUtils->generateFilename('video.mp4', 'instagram');

        $this->assertStringStartsWith('instagram_', $filename);
        $this->assertStringEndsWith('.mp4', $filename);
    }

    public function test_preserves_original_extension(): void
    {
        $this->assertStringEndsWith('.png', $this->mediaUtils->generateFilename('image.png'));
        $this->assertStringEndsWith('.gif', $this->mediaUtils->generateFilename('animation.gif'));
        $this->assertStringEndsWith('.webp', $this->mediaUtils->generateFilename('modern.webp'));
    }

    public function test_is_vertical(): void
    {
        $this->assertTrue($this->mediaUtils->isVertical(1080, 1920));
        $this->assertFalse($this->mediaUtils->isVertical(1920, 1080));
        $this->assertFalse($this->mediaUtils->isVertical(1080, 1080));
    }

    public function test_is_horizontal(): void
    {
        $this->assertTrue($this->mediaUtils->isHorizontal(1920, 1080));
        $this->assertFalse($this->mediaUtils->isHorizontal(1080, 1920));
        $this->assertFalse($this->mediaUtils->isHorizontal(1080, 1080));
    }

    public function test_is_square(): void
    {
        $this->assertTrue($this->mediaUtils->isSquare(1080, 1080));
        $this->assertTrue($this->mediaUtils->isSquare(1000, 1040)); // Within 5% tolerance
        $this->assertFalse($this->mediaUtils->isSquare(1000, 1200));
    }

    public function test_aspect_ratio_labels(): void
    {
        $this->assertEquals('1:1', $this->mediaUtils->aspectRatioLabel(1080, 1080));
        $this->assertEquals('16:9', $this->mediaUtils->aspectRatioLabel(1920, 1080));
        $this->assertEquals('9:16', $this->mediaUtils->aspectRatioLabel(1080, 1920));
        $this->assertEquals('4:5', $this->mediaUtils->aspectRatioLabel(1080, 1350));
    }

    public function test_get_orientation(): void
    {
        $this->assertEquals('vertical', $this->mediaUtils->getOrientation(1080, 1920));
        $this->assertEquals('horizontal', $this->mediaUtils->getOrientation(1920, 1080));
        $this->assertEquals('square', $this->mediaUtils->getOrientation(1080, 1080));
    }

    public function test_suggest_platforms_for_vertical(): void
    {
        $suggestions = $this->mediaUtils->suggestPlatforms(1080, 1920);

        $platforms = array_column($suggestions, 'platform');
        $this->assertContains('tiktok', $platforms);
        $this->assertContains('instagram_reels', $platforms);
        $this->assertContains('youtube_shorts', $platforms);
    }

    public function test_suggest_platforms_for_square(): void
    {
        $suggestions = $this->mediaUtils->suggestPlatforms(1080, 1080);

        $platforms = array_column($suggestions, 'platform');
        $this->assertContains('instagram_feed', $platforms);
        $this->assertContains('threads', $platforms);
    }

    public function test_suggest_platforms_for_horizontal(): void
    {
        $suggestions = $this->mediaUtils->suggestPlatforms(1920, 1080);

        $platforms = array_column($suggestions, 'platform');
        $this->assertContains('youtube', $platforms);
        $this->assertContains('facebook', $platforms);
        $this->assertContains('linkedin', $platforms);
    }

    public function test_format_file_size(): void
    {
        $this->assertEquals('500 B', $this->mediaUtils->formatFileSize(500));
        $this->assertEquals('1 KB', $this->mediaUtils->formatFileSize(1024));
        $this->assertEquals('1.5 MB', $this->mediaUtils->formatFileSize(1572864));
        $this->assertEquals('2.5 GB', $this->mediaUtils->formatFileSize(2684354560));
    }

    public function test_inbox_path_returns_string(): void
    {
        $path = $this->mediaUtils->inboxPath();
        
        $this->assertIsString($path);
        $this->assertStringContainsString('media', $path);
        $this->assertStringContainsString('inbox', $path);
    }

    public function test_processed_path_returns_string(): void
    {
        $path = $this->mediaUtils->processedPath();
        
        $this->assertIsString($path);
        $this->assertStringContainsString('processed', $path);
    }
}
