<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Unit\Upload;

use DigitalLibrary\Upload\CoverImageProcessor;
use DigitalLibrary\Upload\UploadException;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

#[RequiresPhpExtension('gd')]
final class CoverImageProcessorTest extends TestCase
{
    private string $dir;
    /** @var list<string> */
    private array $temp = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/dla-covers-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach ($this->temp as $file) {
            @unlink($file);
        }
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testStoresReencodedJpegOutsideWebRoot(): void
    {
        $png = $this->makeImage('png', 400, 600);
        $stored = $this->processor()->store($this->upload($png));

        self::assertMatchesRegularExpression('#^[0-9a-f]{2}/[0-9a-f]{32}\.jpg$#', $stored->storageKey);
        self::assertSame('image/jpeg', $stored->mimeType);
        self::assertSame([400, 600], [$stored->width, $stored->height]);
        $path = $this->processor()->path($stored->storageKey);
        self::assertNotNull($path);
        self::assertSame('image/jpeg', (new \finfo(FILEINFO_MIME_TYPE))->file($path));
        self::assertSame(hash_file('sha256', $path), $stored->sha256);
    }

    public function testLargeImagesAreDownscaled(): void
    {
        $stored = $this->processor()->store($this->upload($this->makeImage('jpeg', 3200, 1600)));
        self::assertSame([1600, 800], [$stored->width, $stored->height]);
    }

    public function testRejectsNonImageEvenWithImageExtensionAndType(): void
    {
        $file = $this->tempFile("<?php system(\$_GET['c']); ?>");
        $this->expectUploadError('upload.invalid_type', ['name' => 'cover.jpg', 'type' => 'image/jpeg', 'tmp_name' => $file, 'error' => UPLOAD_ERR_OK, 'size' => 30]);
    }

    public function testRejectsPolyglotWithAppendedPayloadByReencoding(): void
    {
        $png = $this->makeImage('png', 50, 50);
        file_put_contents($png, '<?php echo "pwned"; ?>', FILE_APPEND);
        $stored = $this->processor()->store($this->upload($png));
        $path = (string) $this->processor()->path($stored->storageKey);
        self::assertStringNotContainsString('<?php', (string) file_get_contents($path));
    }

    public function testRejectsFilesNotFromHttpUpload(): void
    {
        $processor = new CoverImageProcessor($this->dir);
        $this->expectException(UploadException::class);
        $processor->store($this->upload($this->makeImage('png', 10, 10)));
    }

    public function testRejectsOversizedFiles(): void
    {
        $processor = new CoverImageProcessor($this->dir, maxBytes: 100, isUploadedFile: static fn (): bool => true);
        $this->expectExceptionMessage('upload.too_large');
        $processor->store($this->upload($this->makeImage('png', 200, 200)));
    }

    public function testRejectsExcessiveDimensions(): void
    {
        $processor = new CoverImageProcessor($this->dir, maxDimension: 100, isUploadedFile: static fn (): bool => true);
        $this->expectExceptionMessage('upload.invalid_dimensions');
        $processor->store($this->upload($this->makeImage('png', 101, 10)));
    }

    public function testUploadErrorCodes(): void
    {
        $this->expectUploadError('upload.missing', ['error' => UPLOAD_ERR_NO_FILE]);
    }

    public function testPathRejectsTraversal(): void
    {
        $processor = $this->processor();
        foreach (['../etc/passwd', 'ab/../../x.jpg', 'ab/cd0000000000000000000000000000.jpg', '/etc/passwd'] as $key) {
            self::assertNull($processor->path($key), $key);
        }
    }

    private function processor(): CoverImageProcessor
    {
        return new CoverImageProcessor($this->dir, isUploadedFile: static fn (): bool => true);
    }

    /**
     * @param array<string, mixed> $file
     */
    private function expectUploadError(string $key, array $file): void
    {
        try {
            $this->processor()->store($file);
            self::fail('Expected upload rejection');
        } catch (UploadException $e) {
            self::assertSame($key, $e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function upload(string $path): array
    {
        return ['name' => 'evil.php', 'type' => 'application/x-php', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => filesize($path)];
    }

    private function makeImage(string $type, int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, (int) imagecolorallocate($img, 120, 60, 40));
        $file = $this->tempFile('');
        $type === 'png' ? imagepng($img, $file) : imagejpeg($img, $file);

        return $file;
    }

    private function tempFile(string $contents): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'dla');
        file_put_contents($file, $contents);
        $this->temp[] = $file;

        return $file;
    }
}
