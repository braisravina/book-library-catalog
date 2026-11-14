<?php

declare(strict_types=1);

namespace DigitalLibrary\Catalogue;

/**
 * A work that still has editions, or an edition that still has copies,
 * cannot be deleted.
 */
final class RecordInUseException extends \RuntimeException
{
}
