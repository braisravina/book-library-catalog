<?php

declare(strict_types=1);

namespace DigitalLibrary\Metadata\Http;

/**
 * Hardened outbound HTTPS client for metadata providers.
 *
 *  - every URL, including each redirect hop, must pass OutboundPolicy;
 *  - redirects are followed manually (at most 3), never by curl itself;
 *  - TLS peer and host verification stay on; HTTPS only, even on redirects;
 *  - short connect/total timeouts; responses larger than $maxBytes abort.
 */
final class CurlHttpClient implements HttpClient
{
    private const MAX_REDIRECTS = 3;

    public function __construct(
        private readonly OutboundPolicy $policy,
        private readonly int $timeoutSeconds = 8,
        private readonly string $userAgent = 'DigitalLibraryArchive/1.0 (private catalogue)',
    ) {
    }

    public function get(string $url, int $maxBytes): HttpResponse
    {
        if (!extension_loaded('curl')) {
            throw new HttpException('curl extension unavailable');
        }
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            if (!$this->policy->allows($url)) {
                throw new HttpException('URL refused by outbound policy');
            }
            [$status, $headers, $body] = $this->request($url, $maxBytes);
            if ($status >= 300 && $status < 400 && isset($headers['location'])) {
                $next = OutboundPolicy::resolveRedirect($url, $headers['location']);
                if ($next === null) {
                    throw new HttpException('Unusable redirect');
                }
                $url = $next;
                continue;
            }

            return new HttpResponse($status, $body, $headers['content-type'] ?? '');
        }

        throw new HttpException('Too many redirects');
    }

    /**
     * @return array{int, array<string, string>, string}
     */
    private function request(string $url, int $maxBytes): array
    {
        $body = '';
        $headers = [];
        $tooLarge = false;
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_HTTPGET => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => min(4, $this->timeoutSeconds),
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_HTTPHEADER => ['Accept: application/json, image/*;q=0.9'],
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
                $pos = strpos($line, ':');
                if ($pos !== false) {
                    $headers[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body, &$tooLarge, $maxBytes): int {
                if (strlen($body) + strlen($chunk) > $maxBytes) {
                    $tooLarge = true;

                    return 0; // aborts the transfer
                }
                $body .= $chunk;

                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($tooLarge) {
            throw new HttpException('Response exceeds size limit');
        }
        if ($ok === false) {
            throw new HttpException('Request failed: ' . $error);
        }

        return [$status, $headers, $body];
    }
}
