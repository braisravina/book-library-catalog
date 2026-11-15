<?php

declare(strict_types=1);

namespace DigitalLibrary\Households;

use DigitalLibrary\Catalogue\Repository\Repository;
use DigitalLibrary\Database\Params;

/** SQL for the admin-only transfer service. Never exposed directly to controllers. */
final class TransferRepository extends Repository
{
    public function operation(int $id, bool $lock = false): ?array
    {
        return $this->one('SELECT * FROM inventory_transfers WHERE id = :id' . ($lock ? ' FOR UPDATE' : ''), ['id' => $id]);
    }

    public function save(int $actor, array $plan): int
    {
        $json = json_encode($plan, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $id = $this->insert('INSERT INTO inventory_transfers (actor_id, kind, source_household_id, destination_household_id, reason, payload, digest, created_at)
            VALUES (:a, :k, :s, :d, :r, :p, :h, UTC_TIMESTAMP())', [
            'a' => $actor, 'k' => $plan['kind'], 's' => $plan['source'], 'd' => $plan['destination'], 'r' => $plan['reason'],
            'p' => $json, 'h' => hash('sha256', $json),
        ]);
        foreach ($plan['copies'] as $copy) {
            $this->run('INSERT INTO inventory_transfer_items (operation_id, copy_id, expected_version, source_location_id, destination_location_id, owner_user_id)
                VALUES (:o, :c, :v, :s, :d, :u)', ['o' => $id, 'c' => $copy['id'], 'v' => $copy['row_version'], 's' => $copy['location_id'],
                'd' => $plan['kind'] === 'subtree' ? $copy['location_id'] : $plan['location'], 'u' => $copy['owner_user_id']]);
        }
        return $id;
    }

    public function setStatus(int $id, string $status): void
    {
        $this->run('UPDATE inventory_transfers SET status = :s, completed_at = UTC_TIMESTAMP() WHERE id = :id', ['s' => $status, 'id' => $id]);
    }

    public function copies(array $ids): array
    {
        $p = new Params();
        return $this->many('SELECT id, edition_id, owner_user_id, household_id, location_id, inventory_code, status, row_version FROM copies WHERE id IN (' . $p->in($ids) . ') ORDER BY id', $p->all());
    }

    public function locations(): array
    {
        return $this->many('SELECT id, parent_id, household_id, name, row_version FROM locations ORDER BY id');
    }

    public function containedCopies(array $locations): array
    {
        $p = new Params();
        return $this->many('SELECT id FROM copies WHERE location_id IN (' . $p->in($locations) . ') ORDER BY id', $p->all());
    }

    public function hasLoan(int $id, bool $activeOnly): bool
    {
        return $this->scalar('SELECT 1 FROM loans WHERE copy_id = :id' . ($activeOnly ? ' AND returned_on IS NULL' : '') . ' LIMIT 1', ['id' => $id]) !== null;
    }

    /** Equality uses the database collation, including case/accent/trailing-space semantics. */
    public function codeConflict(int $copy, int $destination, array $selected): bool
    {
        $p = new Params();
        return $this->scalar('SELECT 1 FROM copies chosen JOIN copies other ON other.inventory_code = chosen.inventory_code AND other.id <> chosen.id
            WHERE chosen.id = ' . $p->add($copy) . ' AND (other.inventory_scope_id = ' . $p->add($destination) . ' OR other.id IN (' . $p->in($selected) . ')) LIMIT 1', $p->all()) !== null;
    }

    public function nameConflict(int $root, ?int $parent, int $destination): bool
    {
        $p = new Params();
        return $this->scalar('SELECT 1 FROM locations r JOIN locations l ON l.name = r.name AND l.id <> r.id WHERE r.id = ' . $p->add($root)
            . ' AND ' . ($parent === null ? 'l.parent_id IS NULL AND l.household_id = ' . $p->add($destination) : 'l.parent_id = ' . $p->add($parent)) . ' LIMIT 1', $p->all()) !== null;
    }

    /** Narrow updates preserve every bibliographic, acquisition, condition and note field. */
    public function moveCopy(array $copy, array $plan, int $actor): void
    {
        $count = $this->run('UPDATE copies SET household_id = :h, location_id = :l, owner_user_id = :o, row_version = row_version + 1, updated_by = :a, updated_at = UTC_TIMESTAMP()
            WHERE id = :id AND row_version = :v', ['h' => $plan['destination'], 'l' => $plan['kind'] === 'subtree' ? $copy['location_id'] : $plan['location'],
            'o' => $plan['kind'] === 'owner' ? $plan['owner'] : $copy['owner_user_id'], 'a' => $actor, 'id' => $copy['id'], 'v' => $copy['row_version']])->rowCount();
        if ($count !== 1) {
            throw TransferException::because('stale', $copy['id']);
        }
    }

    public function moveLocation(array $location, array $plan): void
    {
        $count = $this->run('UPDATE locations SET household_id = :h, parent_id = :p, row_version = row_version + 1, updated_at = UTC_TIMESTAMP() WHERE id = :id AND row_version = :v',
            ['h' => $plan['destination'], 'p' => $location['id'] === $plan['root'] ? $plan['parent'] : $location['parent_id'], 'id' => $location['id'], 'v' => $location['row_version']])->rowCount();
        if ($count !== 1) {
            throw TransferException::because('stale', $location['id']);
        }
    }

    public function history(): array
    {
        return $this->many('SELECT id, actor_id, kind, source_household_id, destination_household_id, reason, status, created_at, completed_at FROM inventory_transfers ORDER BY id DESC LIMIT 100');
    }

    public function inventory(?int $source, int $after): array
    {
        return $this->many('SELECT c.id, c.household_id, c.owner_user_id, c.location_id, c.inventory_code, c.row_version, e.title FROM copies c JOIN editions e ON e.id = c.edition_id WHERE '
            . ($source === null ? 'c.household_id IS NULL' : 'c.household_id = :h') . ' AND c.id > :after ORDER BY c.id LIMIT 100', ['after' => $after] + ($source === null ? [] : ['h' => $source]));
    }

    public function diagnostics(): array
    {
        return [
            'unassigned_copies' => (int) $this->scalar('SELECT COUNT(*) FROM copies WHERE household_id IS NULL'),
            'unassigned_locations' => (int) $this->scalar('SELECT COUNT(*) FROM locations WHERE household_id IS NULL'),
            'location_mismatches' => (int) $this->scalar('SELECT COUNT(*) FROM copies c JOIN locations l ON l.id = c.location_id WHERE NOT (c.household_id <=> l.household_id)'),
            'parent_mismatches' => (int) $this->scalar('SELECT COUNT(*) FROM locations l JOIN locations p ON p.id = l.parent_id WHERE NOT (l.household_id <=> p.household_id)'),
            'global_code_conflicts' => $this->many('SELECT inventory_code, COUNT(*) AS copies FROM copies WHERE inventory_code IS NOT NULL GROUP BY inventory_code HAVING COUNT(*) > 1 ORDER BY inventory_code LIMIT 100'),
            'dependencies' => $this->many("SELECT h.id, h.name, h.system_key,
                (SELECT COUNT(*) FROM copies c WHERE c.household_id = h.id) AS copies,
                (SELECT COUNT(*) FROM locations l WHERE l.household_id = h.id) AS locations,
                (SELECT COUNT(*) FROM household_members m WHERE m.household_id = h.id) AS members,
                (SELECT COUNT(*) FROM collections c WHERE c.household_id = h.id AND c.visibility = 'shared') AS collections,
                (SELECT COUNT(*) FROM invitation_households ih JOIN invitations i ON i.id = ih.invitation_id WHERE ih.household_id = h.id AND i.accepted_at IS NULL AND i.revoked_at IS NULL AND i.expires_at > UTC_TIMESTAMP()) AS invitations,
                (SELECT COUNT(*) FROM loans l JOIN copies c ON c.id = l.copy_id WHERE c.household_id = h.id AND l.returned_on IS NULL) AS active_loans
                FROM households h ORDER BY h.id"),
        ];
    }
}
