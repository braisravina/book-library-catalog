<?php

declare(strict_types=1);

namespace DigitalLibrary\I18n;

final class LocaleNegotiator
{
    /**
     * Picks the best supported locale from an Accept-Language header.
     *
     * @param list<string> $supported
     */
    public static function fromAcceptLanguage(?string $header, array $supported, string $default): string
    {
        if ($header === null || trim($header) === '') {
            return $default;
        }
        $candidates = [];
        foreach (explode(',', $header) as $index => $part) {
            $pieces = explode(';', trim($part));
            $tag = strtolower(trim($pieces[0]));
            $quality = 1.0;
            foreach (array_slice($pieces, 1) as $param) {
                if (preg_match('/^\s*q=([01](?:\.\d{1,3})?)\s*$/', $param, $m)) {
                    $quality = (float) $m[1];
                }
            }
            if ($tag === '' || $quality <= 0.0) {
                continue;
            }
            $candidates[] = ['tag' => $tag, 'q' => $quality, 'i' => $index];
        }
        usort($candidates, static fn (array $a, array $b): int => [$b['q'], $a['i']] <=> [$a['q'], $b['i']]);

        foreach ($candidates as $candidate) {
            $primary = explode('-', $candidate['tag'])[0];
            if (in_array($primary, $supported, true)) {
                return $primary;
            }
        }

        return $default;
    }
}
