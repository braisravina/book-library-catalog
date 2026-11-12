<?php

declare(strict_types=1);

namespace DigitalLibrary\Metadata\Http;

interface HttpClient
{
    /**
     * GET an absolute https:// URL on an allowlisted host.
     *
     * @throws HttpException
     */
    public function get(string $url, int $maxBytes): HttpResponse;
}
