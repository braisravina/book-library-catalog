<?php

declare(strict_types=1);

namespace DigitalLibrary\Upload;

/**
 * Validates and stores uploaded cover images.
 *
 * Defences: upload-origin check, size limit, MIME sniffed from content (the
 * client-supplied type and filename are ignored), dimension and pixel-count
 * limits (decompression bombs), and a full GD re-encode that strips metadata
 * and any embedded payload. Files are written outside the web root under a
 * random name and are only ever served through an authorised controller.
 */
final class CoverImageProcessor
{
    private const ALLOWED = ['image/jpeg', 'image/png', 'image/webp'];

    /** @var \Closure(string): bool */
    private \Closure $isUploadedFile;

    public function __construct(
        private readonly string $storageDirectory,
        private readonly int $maxBytes = 5 * 1024 * 1024,
        private readonly int $maxDimension = 6000,
        private readonly int $maxPixels = 24_000_000,
        private readonly int $outputMaxDimension = 1600,
        ?\Closure $isUploadedFile = null,
        private readonly int $thumbnailMaxDimension = 480,
    ) {
        $this->isUploadedFile = $isUploadedFile ?? static fn (string $path): bool => is_uploaded_file($path);
    }

    /**
     * @param array<string, mixed> $file One entry from $_FILES.
     */
    public function store(array $file): StoredImage
    {
        if (!extension_loaded('gd')) {
            throw new UploadException('upload.unavailable');
        }
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            throw new UploadException('upload.missing');
        }
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new UploadException('upload.too_large');
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new UploadException('upload.failed');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !($this->isUploadedFile)($tmp)) {
            throw new UploadException('upload.failed');
        }

        return $this->process($tmp);
    }

    /**
     * Stores an image the server downloaded itself (metadata cover import).
     * Exactly the same validation and re-encoding as uploads, minus the
     * HTTP-upload origin check, which cannot apply to a server-side file.
     */
    public function storeDownloaded(string $path): StoredImage
    {
        if (!extension_loaded('gd')) {
            throw new UploadException('upload.unavailable');
        }
        if (!is_file($path)) {
            throw new UploadException('upload.failed');
        }

        return $this->process($path);
    }

    private function process(string $tmp): StoredImage
    {
        $size = filesize($tmp);
        if ($size === false || $size === 0 || $size > $this->maxBytes) {
            throw new UploadException('upload.too_large');
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!in_array($mime, self::ALLOWED, true)) {
            throw new UploadException('upload.invalid_type');
        }
        $info = @getimagesize($tmp);
        if ($info === false || $info['mime'] !== $mime) {
            throw new UploadException('upload.invalid_type');
        }
        [$width, $height] = $info;
        if ($width < 1 || $height < 1 || $width > $this->maxDimension || $height > $this->maxDimension || $width * $height > $this->maxPixels) {
            throw new UploadException('upload.invalid_dimensions');
        }

        $source = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($tmp),
            'image/png' => @imagecreatefrompng($tmp),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmp) : false,
        };
        if ($source === false) {
            throw new UploadException('upload.invalid_type');
        }

        $key = bin2hex(random_bytes(16));
        $relative = substr($key, 0, 2) . '/' . $key . '.jpg';
        $target = rtrim($this->storageDirectory, '/') . '/' . $relative;
        $dir = dirname($target);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new UploadException('upload.failed');
        }

        [$outW, $outH] = $this->writeResized($source, $width, $height, $this->outputMaxDimension, $target);
        $this->writeResized($source, $width, $height, $this->thumbnailMaxDimension, self::thumbnailPath($target));

        return new StoredImage(
            $relative,
            'image/jpeg',
            $outW,
            $outH,
            (int) filesize($target),
            (string) hash_file('sha256', $target),
        );
    }

    /**
     * Resolves a stored key to its absolute path, rejecting anything that is
     * not exactly the format store() produces.
     */
    public function path(string $storageKey, bool $thumbnail = false): ?string
    {
        $path = $this->unverifiedPath($storageKey);
        if ($path === null) {
            return null;
        }
        if ($thumbnail) {
            $thumb = self::thumbnailPath($path);
            // Fall back to the full image if the thumbnail is missing.
            $path = is_file($thumb) ? $thumb : $path;
        }

        return is_file($path) ? $path : null;
    }

    /**
     * Removes the image and its thumbnail. Unknown or malformed keys are ignored.
     */
    public function delete(string $storageKey): void
    {
        $path = $this->unverifiedPath($storageKey);
        if ($path === null) {
            return;
        }
        foreach ([$path, self::thumbnailPath($path)] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    private function unverifiedPath(string $storageKey): ?string
    {
        if (!preg_match('#^([0-9a-f]{2})/\1[0-9a-f]{30}\.jpg$#', $storageKey)) {
            return null;
        }

        return rtrim($this->storageDirectory, '/') . '/' . $storageKey;
    }

    private static function thumbnailPath(string $path): string
    {
        return substr($path, 0, -4) . '.thumb.jpg';
    }

    /**
     * Re-encodes onto a fresh white canvas (flattening transparency and
     * dropping all metadata) and writes a JPEG.
     *
     * @return array{int, int} output width and height
     */
    private function writeResized(\GdImage $source, int $width, int $height, int $maxDimension, string $target): array
    {
        $scale = min(1.0, $maxDimension / max($width, $height));
        $outW = max(1, (int) round($width * $scale));
        $outH = max(1, (int) round($height * $scale));
        $canvas = imagecreatetruecolor($outW, $outH);
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $outW, $outH, $width, $height);
        if (!imagejpeg($canvas, $target, 85)) {
            throw new UploadException('upload.failed');
        }
        @chmod($target, 0640);

        return [$outW, $outH];
    }
}
