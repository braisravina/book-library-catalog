<?php

declare(strict_types=1);

namespace DigitalLibrary\Database;

/**
 * The migration history was written by the other database engine (its
 * checksum matches the other engine's variant of an engine-specific
 * migration). Nothing is changed and history is never rewritten: the schema
 * on disk may differ from what this engine's variant would have created.
 */
final class MigrationEngineMismatchException extends \RuntimeException
{
    /**
     * @param list<string> $versions
     */
    public function __construct(public readonly string $engine, public readonly array $versions)
    {
        $other = $engine === Migrator::ENGINE_MARIADB ? 'MySQL' : 'MariaDB';
        parent::__construct(sprintf(
            'This %s server holds a migration history written by %s (engine-specific migration(s): %s). '
            . 'Nothing was changed. Do not edit schema_migrations, and do not restore backups across engines '
            . '(their table definitions are engine-specific). Use this database with the engine that created it, '
            . 'or migrate an empty database on this engine (docs/OPERATIONS.md, "Database engine").',
            $engine === Migrator::ENGINE_MARIADB ? 'MariaDB' : 'MySQL',
            $other,
            implode(', ', $versions),
        ));
    }
}
