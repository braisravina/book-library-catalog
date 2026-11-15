<?php

declare(strict_types=1);

namespace DigitalLibrary\Database;

/** Reviewed runtime writes. Provisioned by the owner/DBA, never by the app. */
final class RuntimeGrants
{
    /**
     * Schema SELECT keeps logical backups complete. New tables get no writes.
     * No user DELETE: fk_audit_user's SET NULL must not modify old audit rows.
     * @var array<string, string>
     */
    public const WRITES = [
        'audit_log' => 'INSERT',
        'users' => 'INSERT, UPDATE',
        'authors' => 'INSERT, UPDATE, DELETE',
        'publishers' => 'INSERT, UPDATE, DELETE',
        'works' => 'INSERT, UPDATE, DELETE',
        'work_authors' => 'INSERT, UPDATE, DELETE',
        'editions' => 'INSERT, UPDATE, DELETE',
        'edition_contributors' => 'INSERT, UPDATE, DELETE',
        'categories' => 'INSERT, UPDATE, DELETE',
        'work_categories' => 'INSERT, DELETE',
        'covers' => 'INSERT, UPDATE, DELETE',
        'copies' => 'INSERT, UPDATE, DELETE',
        'locations' => 'INSERT, UPDATE, DELETE',
        'collections' => 'INSERT, UPDATE, DELETE',
        'collection_items' => 'INSERT, UPDATE, DELETE',
        'invitations' => 'INSERT, UPDATE, DELETE',
        'invitation_households' => 'INSERT',
        'households' => 'INSERT, UPDATE, DELETE',
        'household_members' => 'INSERT, DELETE',
        'loans' => 'INSERT, UPDATE',
        'wishlist_items' => 'INSERT, UPDATE, DELETE',
        'metadata_records' => 'INSERT, UPDATE',
        'login_attempts' => 'INSERT, DELETE',
        'inventory_transfers' => 'INSERT, UPDATE',
        'inventory_transfer_items' => 'INSERT',
    ];

    /** Generates SQL only. Does not connect or revoke historical privileges. */
    public static function sql(string $database, string $user, string $host = '%'): string
    {
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/D', $database) !== 1
            || preg_match('/^[A-Za-z0-9_]{1,32}$/D', $user) !== 1) {
            throw new \InvalidArgumentException('Use simple identifiers: database at most 64 characters, user at most 32.');
        }
        if (preg_match('/^[A-Za-z0-9_.:%-]{1,255}$/D', $host) !== 1) {
            throw new \InvalidArgumentException('Invalid account host.');
        }
        $account = "'{$user}'@'{$host}'";
        // Database grants treat underscores as wildcards on the supported
        // default grant model. Table-qualified grants below are literal.
        $selectDatabase = str_replace('_', '\\_', $database);
        $sql = "-- Apply only to a verified account WITHOUT broader grants or roles.\n"
            . "-- Tables must already exist; this does not revoke old privileges.\n"
            . "GRANT SELECT ON `{$selectDatabase}`.* TO {$account};\n";
        foreach (self::WRITES as $table => $privileges) {
            $sql .= "GRANT {$privileges} ON `{$database}`.`{$table}` TO {$account};\n";
        }

        return $sql;
    }
}
