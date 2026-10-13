<?php

declare(strict_types=1);

namespace DigitalLibrary\Backup;

/**
 * Minimal POSIX ustar writer (gzip-compressed): regular files only, no
 * directories, links or extended headers, fixed mode 0600, mtime 0 and
 * root ownership, so archives contain nothing but named byte streams.
 */
final class TarWriter
{
    /** @var resource */
    private $gz;

    public function __construct(string $path)
    {
        $gz = gzopen($path, 'wb6');
        if ($gz === false) {
            throw new BackupException('Cannot open archive for writing: ' . basename($path));
        }
        $this->gz = $gz;
    }

    /**
     * @return string SHA-256 of the bytes actually written
     */
    public function addFile(string $name, string $sourcePath): string
    {
        TarPath::assertSafe($name);
        $size = filesize($sourcePath);
        $in = fopen($sourcePath, 'rb');
        if ($size === false || $in === false) {
            throw new BackupException('Cannot read ' . $name);
        }
        $this->header($name, $size);
        $written = 0;
        $hash = hash_init('sha256');
        while (!feof($in)) {
            $chunk = fread($in, 1 << 20);
            if ($chunk === false) {
                throw new BackupException('Read error on ' . $name);
            }
            $this->write($chunk);
            hash_update($hash, $chunk);
            $written += strlen($chunk);
        }
        fclose($in);
        if ($written !== $size) {
            throw new BackupException($name . ' changed while it was being archived');
        }
        $this->pad($size);

        return hash_final($hash);
    }

    public function addString(string $name, string $contents): void
    {
        TarPath::assertSafe($name);
        $this->header($name, strlen($contents));
        $this->write($contents);
        $this->pad(strlen($contents));
    }

    public function close(): void
    {
        $this->write(str_repeat("\0", 1024));
        if (!gzclose($this->gz)) {
            throw new BackupException('Cannot finish archive');
        }
    }

    private function header(string $name, int $size): void
    {
        if (strlen($name) > 100) {
            throw new BackupException('Archive member name too long: ' . $name);
        }
        $header = str_pad($name, 100, "\0")
            . str_pad('0000600', 7, '0', STR_PAD_LEFT) . "\0"
            . '0000000' . "\0" . '0000000' . "\0"
            . str_pad(decoct($size), 11, '0', STR_PAD_LEFT) . "\0"
            . '00000000000' . "\0"
            . '        '
            . '0'
            . str_repeat("\0", 100)
            . "ustar\0" . '00'
            . str_pad('root', 32, "\0") . str_pad('root', 32, "\0")
            . str_repeat("\0", 8) . str_repeat("\0", 8)
            . str_repeat("\0", 155);
        $header = str_pad($header, 512, "\0");
        $checksum = array_sum(array_map('ord', str_split($header)));
        $header = substr_replace($header, str_pad(decoct($checksum), 6, '0', STR_PAD_LEFT) . "\0 ", 148, 8);
        $this->write($header);
    }

    private function pad(int $size): void
    {
        $rest = $size % 512;
        if ($rest !== 0) {
            $this->write(str_repeat("\0", 512 - $rest));
        }
    }

    private function write(string $bytes): void
    {
        if ($bytes !== '' && gzwrite($this->gz, $bytes) !== strlen($bytes)) {
            throw new BackupException('Write error (disk full?)');
        }
    }
}
