<?php

declare(strict_types=1);

namespace DigitalLibrary\Backup;

/**
 * Verifies a backup archive without extracting anything: archive and
 * member structure (TarReader), the optional .sha256 sidecar, manifest
 * validity and compatibility, every member's size and SHA-256, the
 * database dump framing, and that no member is missing or unexpected.
 */
final class BackupVerifier
{
    private const MAX_MANIFEST_BYTES = 32 * 1024 * 1024;

    /**
     * @return array{ok: bool, errors: list<string>, warnings: list<string>, manifest: ?array<string, mixed>, sha256: ?string}
     */
    public function verify(string $path): array
    {
        $errors = [];
        $warnings = [];
        $manifest = null;
        $sha256 = is_file($path) && !is_link($path) ? (string) hash_file('sha256', $path) : null;

        $sidecar = $path . '.sha256';
        if ($sha256 !== null && is_file($sidecar)) {
            $expected = strtok((string) file_get_contents($sidecar, false, null, 0, 1024), " \n");
            if (!is_string($expected) || !hash_equals($expected, $sha256)) {
                $errors[] = 'Archive checksum does not match ' . basename($sidecar);
            }
        } elseif ($sha256 !== null) {
            $warnings[] = 'No .sha256 file next to the archive; the archive checksum was not compared';
        }

        $actual = [];
        $order = [];
        $dumpOk = false;
        try {
            $reader = new TarReader($path);
            $order = $reader->each(function (string $name, int $size, \Closure $read) use (&$manifest, &$actual, &$dumpOk): void {
                if ($name === Manifest::NAME) {
                    if ($size > self::MAX_MANIFEST_BYTES) {
                        throw new BackupException('Manifest is too large');
                    }
                    $json = '';
                    while (($chunk = $read()) !== null) {
                        $json .= $chunk;
                    }
                    $decoded = json_decode($json, true, 64);
                    if (!is_array($decoded)) {
                        throw new BackupException('Manifest is not valid JSON');
                    }
                    $manifest = $decoded;

                    return;
                }
                $hash = hash_init('sha256');
                $first = null;
                $length = 0;
                while (($chunk = $read()) !== null) {
                    $first ??= $chunk;
                    hash_update($hash, $chunk);
                    $length += strlen($chunk);
                }
                $actual[$name] = ['size' => $length, 'sha256' => hash_final($hash)];
                if ($name === Manifest::DATABASE) {
                    $dumpOk = str_starts_with((string) $first, DatabaseDumper::HEADER . "\n");
                }
            });
        } catch (BackupException $e) {
            $errors[] = $e->getMessage();

            return ['ok' => false, 'errors' => $errors, 'warnings' => $warnings, 'manifest' => null, 'sha256' => $sha256];
        }

        if ($manifest === null) {
            $errors[] = 'Backup has no manifest';
        } else {
            if ($order === [] || $order[count($order) - 1] !== Manifest::NAME) {
                $errors[] = 'The manifest must be the last archive member';
            }
            $problems = Manifest::problems($manifest);
            $errors = array_merge($errors, $problems);
            if ($problems === []) {
                $listed = [];
                foreach ($manifest['files'] as $file) {
                    $listed[$file['path']] = true;
                    $got = $actual[$file['path']] ?? null;
                    if ($got === null) {
                        $errors[] = 'Missing from the archive: ' . $file['path'];
                    } elseif ($got['size'] !== $file['size'] || !hash_equals($file['sha256'], $got['sha256'])) {
                        $errors[] = 'Checksum mismatch (corrupted or modified): ' . $file['path'];
                    }
                }
                foreach (array_keys($actual) as $name) {
                    if (!isset($listed[$name])) {
                        $errors[] = 'Unexpected archive member not in the manifest: ' . $name;
                    }
                }
                if (isset($actual[Manifest::DATABASE]) && !$dumpOk) {
                    $errors[] = 'The database dump has an unknown format';
                }
                if ($manifest['covers']['missing'] !== []) {
                    $warnings[] = sprintf('%d referenced cover file(s) were missing when this backup was made', count($manifest['covers']['missing']));
                }
            }
        }

        return ['ok' => $errors === [], 'errors' => $errors, 'warnings' => $warnings, 'manifest' => $manifest, 'sha256' => $sha256];
    }
}
