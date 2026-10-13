<?php

declare(strict_types=1);

namespace DigitalLibrary\Metadata\Http;

/**
 * Network failure, policy refusal, timeout or oversized response.
 */
final class HttpException extends \RuntimeException
{
}
