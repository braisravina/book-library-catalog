<?php

declare(strict_types=1);

namespace DigitalLibrary\Security;

use DigitalLibrary\Auth\User;
use DigitalLibrary\Database\Params;

/**
 * What one viewer may see, as SQL conditions (phase H2).
 *
 * This is the single definition of household visibility. Every read of
 * works, editions, copies, locations, loans, authors, publishers and user
 * names takes a Visibility and adds its conditions; nothing else decides
 * what is in view.
 *
 *  - copy      visible when its household is in the viewer's scope. A copy
 *              without a household is visible to administrators only.
 *  - edition   visible when the viewer created it, or it has a visible copy.
 *  - work      visible when the viewer created it, or it has a visible edition.
 *  - location  visible when its household is in the viewer's scope.
 *  - author    visible when credited on a visible work or edition.
 *  - publisher visible when it publishes a visible edition.
 *  - user      known when it is the viewer or shares a household with them.
 *
 * Creating a bibliographic record keeps it in view for its creator even
 * when only other households hold copies of it, but never shows those
 * copies: inventory is reached through the copy rule alone.
 *
 * Administrators are unrestricted. So is everyone while the household
 * feature is disabled (Services::visibility()): the conditions are then the
 * constant "1 = 1" and queries behave as they did before households.
 *
 * It is built per request from Security\HouseholdScope (read from the
 * database, never from the session), so a membership change applies to the
 * member's next request. Every value is bound through Database\Params.
 */
final class Visibility
{
    private const ALL = '1 = 1';
    private const NOTHING = '0 = 1';

    private function __construct(
        private readonly HouseholdScope $scope,
        private readonly int $userId,
    ) {
    }

    /**
     * No household restriction: administrators, the disabled feature, and
     * internal callers (services working under their own authorization,
     * the console). Name it explicitly; it is never a default.
     */
    public static function unrestricted(): self
    {
        return new self(HouseholdScope::all(), 0);
    }

    /** Sees nothing (signed out or deactivated). */
    public static function nobody(): self
    {
        return new self(HouseholdScope::none(), 0);
    }

    public static function forUser(?User $user, HouseholdScope $scope): self
    {
        if ($user === null || !$user->isActive) {
            return self::nobody();
        }

        return new self($scope, $user->id);
    }

    public function isUnrestricted(): bool
    {
        return $this->scope->unrestricted;
    }

    /**
     * Whether inventory of this household (null: none assigned) is in view.
     */
    public function includesHousehold(?int $householdId): bool
    {
        return $this->scope->includes($householdId);
    }

    public function copy(string $alias, Params $params): string
    {
        return $this->household($alias . '.household_id', $params);
    }

    public function location(string $alias, Params $params): string
    {
        return $this->household($alias . '.household_id', $params);
    }

    public function edition(string $alias, Params $params): string
    {
        if ($this->scope->unrestricted) {
            return self::ALL;
        }
        $own = $this->createdByViewer($alias, $params);
        if ($this->scope->isEmpty()) {
            return $own;
        }

        return '(' . $own . ' OR EXISTS (SELECT 1 FROM copies vis_c WHERE vis_c.edition_id = ' . $alias . '.id AND '
            . $this->copy('vis_c', $params) . '))';
    }

    public function work(string $alias, Params $params): string
    {
        if ($this->scope->unrestricted) {
            return self::ALL;
        }

        return '(' . $this->createdByViewer($alias, $params) . ' OR EXISTS (SELECT 1 FROM editions vis_e WHERE vis_e.work_id = '
            . $alias . '.id AND ' . $this->edition('vis_e', $params) . '))';
    }

    public function author(string $alias, Params $params): string
    {
        if ($this->scope->unrestricted) {
            return self::ALL;
        }

        return '(EXISTS (SELECT 1 FROM work_authors vis_wa JOIN works vis_w ON vis_w.id = vis_wa.work_id WHERE vis_wa.author_id = '
            . $alias . '.id AND ' . $this->work('vis_w', $params) . ')'
            . ' OR EXISTS (SELECT 1 FROM edition_contributors vis_ec JOIN editions vis_ce ON vis_ce.id = vis_ec.edition_id WHERE vis_ec.author_id = '
            . $alias . '.id AND ' . $this->edition('vis_ce', $params) . '))';
    }

    public function publisher(string $alias, Params $params): string
    {
        if ($this->scope->unrestricted) {
            return self::ALL;
        }

        return 'EXISTS (SELECT 1 FROM editions vis_pe WHERE vis_pe.publisher_id = ' . $alias . '.id AND '
            . $this->edition('vis_pe', $params) . ')';
    }

    /**
     * A user whose name the viewer may be shown: the viewer, or someone
     * sharing a household with them.
     */
    public function user(string $alias, Params $params): string
    {
        if ($this->scope->unrestricted) {
            return self::ALL;
        }
        $self = $alias . '.id = ' . $params->add($this->userId);
        if ($this->scope->isEmpty()) {
            return $self;
        }

        return '(' . $self . ' OR EXISTS (SELECT 1 FROM household_members vis_m WHERE vis_m.user_id = ' . $alias . '.id AND vis_m.household_id IN ('
            . $params->in($this->scope->ids()) . ')))';
    }

    private function household(string $column, Params $params): string
    {
        if ($this->scope->unrestricted) {
            return self::ALL;
        }
        if ($this->scope->isEmpty()) {
            return self::NOTHING;
        }

        return $column . ' IN (' . $params->in($this->scope->ids()) . ')';
    }

    private function createdByViewer(string $alias, Params $params): string
    {
        return $alias . '.created_by = ' . $params->add($this->userId);
    }
}
