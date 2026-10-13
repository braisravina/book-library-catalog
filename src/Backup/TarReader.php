<?php

declare(strict_types=1);

namespace DigitalLibrary\Backup;

/**
 * Strict reader for backup archives (gzip-compressed ustar).
 *
 * Only regular-file members with safe names (TarPath) are accepted.
 * Symlinks, hard links, directories, devices, FIFOs, pax/GNU extended
 * headers, duplicate names, bad header checksums, truncated data and
 * oversized members or archives are rejected. Nothing is ever written to
 * disk by the reader itself: member data is passed to a callback.
 */
final class TarReader
{
    /** @var resource */
    private $gz;

    public function __construct(
        string $path,
        private readonly int $maxMembers = 500_000,
        private readonly int $maxTotalBytes = 64 * 1024 * 1024 * 1024,
    ) {
        if (!is_file($path) || is_link($path)) {
            throw new BackupException('Not a regular file: ' . basename($path));
        }
        $magic = (string) file_get_contents($path, false, null, 0, 2);
        if ($magic !== "\x1f\x8b") {
            throw new BackupException('Not a gzip-compressed archive: ' . basename($path));
        }
        $gz = @gzopen($path, 'rb');
        if ($gz === false) {
            throw new BackupException('Cannot open archive: ' . basename($path));
        }
        $this->gz = $gz;
    }

    /**
     * Calls $onMember(name, size, readChunk) for every member, in order.
     * readChunk() returns the next chunk of the member's data, or null at
     * its end; unread data is skipped.
     *
     * @param callable(string, int, \Closure(): ?string): void $onMember
     *
     * @return list<string> member names, in archive order
     */
    public function each(callable $onMember): array
    {
        $names = [];
        $total = 0;
        try {
            while (true) {
                $header = $this->readExactly(512, 'header');
                if ($header === str_repeat("\0", 512)) {
                    $next = $this->readUpTo(512);
                    if ($next !== str_repeat("\0", 512)) {
                        throw new BackupException('Malformed end of archive');
                    }
                    $trailing = $this->readUpTo(1);
                    if ($trailing !== '' && ($trailing !== "\0" || !$this->restIsZero())) {
                        throw new BackupException('Unexpected data after end of archive');
                    }
                    break;
                }
                [$name, $size] = $this->parseHeader($header);
                if (isset($names[$name])) {
                    throw new BackupException('Duplicate archive member: ' . $name);
                }
                $names[$name] = true;
                if (count($names) > $this->maxMembers) {
                    throw new BackupException('Too many archive members');
                }
                $total += $size;
                if ($total > $this->maxTotalBytes) {
                    throw new BackupException('Archive exceeds the size limit');
                }

                $remaining = $size;
                $read = function () use (&$remaining): ?string {
                    if ($remaining === 0) {
                        return null;
                    }
                    $chunk = $this->readExactly(min($remaining, 1 << 20), 'member data');
                    $remaining -= strlen($chunk);

                    return $chunk;
                };
                $onMember($name, $size, $read);
                while ($read() !== null) {
                    // skip unread data
                }
                if ($size % 512 !== 0) {
                    $this->readExactly(512 - $size % 512, 'padding');
                }
            }
        } finally {
            gzclose($this->gz);
        }

        return array_keys($names);
    }

    /**
     * @return array{string, int}
     */
    private function parseHeader(string $header): array
    {
        $stored = trim(substr($header, 148, 8), " \0");
        $computed = array_sum(array_map('ord', str_split(substr_replace($header, '        ', 148, 8))));
        if ($stored === '' || preg_match('/^[0-7]+$/', $stored) !== 1 || octdec($stored) !== $computed) {
            throw new BackupException('Corrupt archive header (checksum mismatch)');
        }
        $type = $header[156];
        if ($type !== '0' && $type !== "\0") {
            $kinds = ['1' => 'hard link', '2' => 'symbolic link', '3' => 'character device', '4' => 'block device', '5' => 'directory',
                '6' => 'FIFO', 'x' => 'pax header', 'g' => 'pax global header', 'L' => 'GNU long name', 'K' => 'GNU long link'];
            throw new BackupException('Archive member type not allowed: ' . ($kinds[$type] ?? 'type ' . TarPath::printable($type)));
        }
        if (trim(substr($header, 345, 155), "\0") !== '') {
            throw new BackupException('Archive member names with a prefix are not allowed');
        }
        $name = rtrim(substr($header, 0, 100), "\0");
        if (str_contains($name, "\0")) {
            throw new BackupException('Archive member name contains NUL bytes');
        }
        TarPath::assertSafe($name);
        $sizeField = trim(substr($header, 124, 12), " \0");
        if (preg_match('/^[0-7]{1,11}$/', $sizeField) !== 1) {
            throw new BackupException('Invalid member size for ' . $name);
        }

        return [$name, (int) octdec($sizeField)];
    }

    private function readExactly(int $length, string $what): string
    {
        $data = $this->readUpTo($length);
        if (strlen($data) !== $length) {
            throw new BackupException('Archive is truncated (' . $what . ')');
        }

        return $data;
    }

    private function readUpTo(int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = gzread($this->gz, $length - strlen($data));
            if ($chunk === false) {
                throw new BackupException('Archive is not valid gzip data');
            }
            if ($chunk === '') {
                break;
            }
            $data .= $chunk;
        }

        return $data;
    }

    private function restIsZero(): bool
    {
        while (($chunk = gzread($this->gz, 65536)) !== false && $chunk !== '') {
            if (trim($chunk, "\0") !== '') {
                return false;
            }
        }

        return true;
    }
}
