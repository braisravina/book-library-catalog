# Household inventory management — H4

Development-only. Work in `/home/brais/Projects/book-library-catalog/` on the
ordinary development branch. The owner performs all commits and deployments.
Neither ConfigValidator's production block nor Services' production restriction
has been removed. Completing H4 does not authorize production enablement.

## Inventory review

Open **Households → Household inventory** as an administrator. The source filter
separates the protected system default household from NULL/unassigned copies.
The overview reports counts of copies, locations, members, shared collections,
pending invitations and active loans for every household, plus NULL assignments,
copy/location and parent-household mismatches, and the first 100 groups of codes
that are duplicated globally. Those codes are legal in different households.
This is an administrator-only diagnostic; ordinary users cannot enumerate it.

Copies are shown in fixed pages of 100 with explicit id/version checkbox values.
Selection does not persist across pages. Individual transfers select one copy;
a bulk operation selects at most HOUSEHOLD_TRANSFER_LIMIT (default 100, supported
range 1–100). This setting cannot increase the tested maximum. Plans are separate
atomic batches; there is no implicit all-pages selection or background chunking.

## Preview and confirmation

1. Select the explicit source and exact copies, destination, and destination
   location (or deliberately leave the copies without a location).
2. Supply a reason. Choose **Dry run without saving** for a read-only validation
   result. It writes no plan, inventory or audit rows.
3. Choose **Save plan and review** to persist the administrative plan and item
   records. This does not change inventory or produce completion audit events.
4. Review the operation id, source/destination names and ids, exact copy ids,
   inventory identifiers, expected versions, owner and location before/after,
   subtree hierarchy if applicable, and access consequences.
5. Tick the explicit confirmation and apply. Every condition is rechecked inside
   the write transaction. Any conflict rejects the whole batch with no inventory
   or completion-audit changes. Revisit the source and create a fresh plan rather
   than trying to overwrite a stale version.

Completed and cancelled plans are immutable. Completed re-confirmation returns
the recorded outcome, without writing copies or duplicate audit events again.
An interrupted connection is resolved by opening the saved operation id: either
its transaction committed completely or it is still planned. Cancelling an
unapplied plan does not change inventory. Reversal is a new reviewed operation,
subject to current loans, memberships, locations, versions and code conflicts.

Plans persist explicit snapshots, not a search query. They have no automatic
expiry or automatic execution. Lists show the most recent 100 operations; a
known older operation remains accessible to administrators by its id. No
operation id, digest, preview or saved plan grants authorization.

## Ownership and loans

Transfers preserve the owner, who must be active and a destination household
member or administrator. Otherwise they fail; an administrator must explicitly
review membership or use the separate ownership operation. No memberships are
created automatically.

The ownership operation selects exactly one copy, the same source/destination
household and existing location, a new eligible owner and a reason, then has its
own preview and confirmation. It is refused for **any loan history**, including
returned loans. The current permission model grants copy owners access to loan
history, so allowing such reassignment would disclose previous borrowers. This
conservative boundary also protects legacy feature-disabled ownership edits.
Supporting reassignment of copies with history requires a separately approved
historical-access model; no history is erased to make reassignment possible.

Active loans and an `on_loan` copy status block household and owner operations.
Returns must be recorded legitimately through the lending workflow. All loan
rows and borrower references are retained. After transfer, inventory visibility
follows the copy's current household on the next request; existing owner/admin
and borrower privacy rules still govern historical loan details. A former
household member cannot access transferred inventory just from an old URL.

Ordinary copy edits cannot change household or ownership while enabled, even
for administrators. Members still edit their own copies within the same
household. Initial copy creation retains its existing placement rules.


For an ownership-only operation that preserves a NULL household, the replacement
owner must be an active administrator: NULL is no household to which a member
can belong. Assign to a real household through the reviewed workflow before
reassigning to an eligible non-administrator.

## Location subtrees

Choose the source, complete subtree root (with its version), destination and
optional destination parent. Do not select a subset of contained copies: the
preview includes all descendants and copies referencing them. Location and
copy ids are preserved, as are internal parent and location references. Only
the subtree root is attached to a new parent or becomes a root.

Mixed household trees, cycles, invalid parent ancestry, conflicting names,
ineligible owners, duplicate codes, active loans and changed membership of the
reviewed subtree/copy set block the entire operation. There is no automatic
merge, rename, owner change or orphaning of copies. Bounds are 100 location
nodes and the configured copy limit; oversized trees are refused, never split.
An oversized production transfer needs a separately reviewed procedure.

Location edits now carry row_version. Missing or stale HTTP edit versions yield
409 and retain the submitted version. All current location writers increment
versions; transfer snapshots also verify the entire tree membership and copy
state, rather than relying only on the root's version.

## Transactions and audit

WriteGuard opens READ COMMITTED and takes household rows exclusively for H4
mutations. It locks the actor and all required owner accounts in global id
order, then the transfer operation, all editions, all works, all copies, and
locations. Loan checks happen after copy locks; LoanService must obtain the
same copy/household locks before changing lending state. No loan rows are
rewritten by a transfer. Household membership changes/deletion serialize on
these household locks. A role or account change that committed before locking
is seen again; a change blocked by an in-flight lock waits for that transaction.

Metadata locks remain shared for inventory changes. Destination code checks
use database string equality, matching the existing unique-index collation;
the generated scope index remains the final database constraint. Codes are
never silently changed. All SQL values are bound. AuditLogger uses the same PDO
transaction: per-copy inventory.transferred plus inventory.transfer_completed
with operation/reason and location/copy ids. Audit failure rolls back inventory
and operation status. A deadlock retry performs database-only work.

The exclusive household lock is deliberately broad for small installations;
measure contention before increasing scale. Do not replace it with partial
locking without revisiting root-name, subtree and membership races.

## Local preparation tool

`inventory:check` is **read-only and local-only**. It has no apply or production
mode. It requires a development/testing environment, loopback database host,
non-3306 dedicated port, database name ending `_test` or `_rehearsal`, enabled
development households, active administrator id, maintenance mode, the shared
exclusive operations lock and both explicit acknowledgements:

```bash
php bin/console inventory:check --actor=ADMIN_ID --writers-stopped --confirm=LOCAL-INVENTORY-CHECK
```

Run only with explicit isolated rehearsal configuration. Do not copy normal
production credentials into a development environment. `--writers-stopped` is
an operator acknowledgement, not detection or proof: stop the local rehearsal
web process and all other writers first. The command validates environment and
confirmation before connecting, and prints dependency/conflict diagnostics.
It never assigns records. Tests assert both its exclusive lock and no domain
writes, and refusal for production/normal local database settings.

There is intentionally no mutating maintenance CLI or production flag bypass.
A later production assignment process requires an owner-reviewed release and
procedure. Do not attempt to use disabled-mode copy editing to stage transfers:
it ignores destination choices, and ordinary disabled-mode copy writes do not
participate in enabled household locking.

## Default, NULL and rollback policy

Keep the system default household protected, including when empty. Disabled-mode
writers still use it. Review its broad H1 memberships before eventual activation:
remaining inventory is visible to its members. Collections and pending invitation
assignments do not move with copies or locations and require independent review.

Keep copies.household_id and locations.household_id nullable. Legacy writers can
omit them; disabled copy edits can preserve NULL; a child can inherit a legacy
NULL parent. Restored older databases and missing defaults are additional
compatibility cases. No arbitrary assignment, default deletion, NOT NULL
finalization, global unique index or identifier rewrite is part of H4.

After cross-household code duplication, disabling households or using older code
can reject unchanged codes through global application validation. More seriously,
feature-disabled reads are catalogue-wide: turning the flag off is not a privacy
rollback after separation. During incidents keep maintenance active and recover
to a reviewed household-aware version. A full backup restore is non-atomic and
loses later writes; rehearse it and agree the recovery point explicitly.
