<?php

declare(strict_types=1);

/*
 * Household route/access matrix.
 *
 * One entry per named route in config/routes.php, stating how the route
 * relates to household boundaries and in which phase the rule is enforced.
 * tests/Unit/Security/HouseholdAccessMatrixTest.php fails when a route is
 * added without being classified here, so no endpoint can be forgotten
 * when the boundary is enforced.
 *
 * This file is documentation that is checked, not runtime configuration:
 * nothing reads it to make an access decision.
 *
 * Terms
 *   scope        Security\HouseholdScope of the signed-in user: administrators
 *                reach every household; everyone else reaches exactly the
 *                households they are a member of (read from the database on
 *                every request).
 *   visible copy a copy whose household is in the scope. A copy without a
 *                household is visible to administrators only.
 *   visible edition
 *                an edition with at least one visible copy, or one with no
 *                copies at all that the user created.
 *   visible work a work with at least one visible edition, or one with no
 *                editions that the user created.
 *   404          an object outside the scope is answered exactly like an
 *                object that does not exist (never 403), so URLs are not an
 *                existence oracle.
 *   exclusive    a shared bibliographic record that is held ONLY by
 *                households in the user's scope (every copy of the edition,
 *                or of every edition of the work).
 *
 * Kinds
 *   public        no sign-in, no catalogue data
 *   account       the signed-in user's own account
 *   admin         administrators only; the scope is unrestricted
 *   bibliographic shared works, editions, authors, publishers, covers
 *   inventory     copies (household-specific)
 *   loan          loans of copies (household-specific)
 *   collection    curated lists of editions (never a permission boundary)
 *   wishlist      the user's private wishlist
 *   export        data export
 *   household     household administration
 *
 * Phases
 *   none  no household rule applies to the route
 *   H1    enforced now (phase H1)
 *   H2    read enforcement (planned, not implemented)
 *   H3    write enforcement (planned, not implemented)
 * H2 and H3 ship together: no household functionality is enabled in
 * production before both are complete.
 */

$none = static fn (string $kind, string $rule): array => ['kind' => $kind, 'phase' => 'none', 'rule' => $rule];
$h1 = static fn (string $kind, string $rule): array => ['kind' => $kind, 'phase' => 'H1', 'rule' => $rule];
$h2 = static fn (string $kind, string $rule): array => ['kind' => $kind, 'phase' => 'H2', 'rule' => $rule];
$h3 = static fn (string $kind, string $rule): array => ['kind' => $kind, 'phase' => 'H3', 'rule' => $rule];

$householdAdmin = 'household.manage (administrators) and the household feature enabled, else 404; re-checked in HouseholdService.';
$adminOnly = 'Administrators only (route permission); unrestricted scope.';

return [
    // --- Public and account ---------------------------------------------------
    'home' => $h2('bibliographic', 'Dashboard: every count (works, editions, copies, authors), "my copies", the recently added list and the overdue count are computed over visible records only. Guests see no catalogue data (unchanged).'),
    'health' => $none('public', 'No catalogue data.'),
    'locale.update' => $none('public', 'No catalogue data.'),
    'login' => $none('public', 'No catalogue data.'),
    'login.submit' => $none('public', 'No catalogue data.'),
    'invite.open' => $none('public', 'Invitation token exchange; no catalogue data.'),
    'invite.redeem' => $none('public', 'Invitation token exchange; no catalogue data.'),
    'invite.redeem.submit' => $none('public', 'Invitation token exchange; no catalogue data.'),
    'invite.accept' => $none('public', 'No catalogue data.'),
    'invite.invalid' => $none('public', 'No catalogue data.'),
    'invite.accept.submit' => $h3('public', 'The new account gets no household membership unless the invitation carries household assignments (optional, decided by an administrator when inviting). Default: no household access.'),
    'logout' => $none('account', 'No catalogue data.'),
    'account.password' => $none('account', 'Own account only.'),
    'account.password.update' => $none('account', 'Own account only.'),

    // --- Catalogue: browse, search, add ------------------------------------------
    'books.index' => $h2('bibliographic', 'Search lists visible editions only; totals, pages and copy counts count visible copies only. The owner filter offers only users sharing a household with the viewer; the location filter offers only locations of households in scope; the language filter lists languages of visible editions.'),
    'books.create' => $h2('bibliographic', 'ISBN step: an ISBN that exists but is not visible is NOT reported as a duplicate with holdings; the user is offered to add their own copy to the shared record and sees bibliographic fields only (no copy count, owner, household or location). Similar-work suggestions list visible works only.'),
    'books.store' => $h3('bibliographic', 'Same-title and duplicate-ISBN outcomes reveal visible records only (an invisible duplicate becomes "add your copy" without holdings). The first copy\'s household comes from Households\\CopyHousehold (phase H1) and its location must belong to that household.'),

    // --- Works and editions (shared bibliographic records) ------------------------
    'works.show' => $h2('bibliographic', '404 unless the work is visible. Lists visible editions only; copy counts count visible copies.'),
    'works.edit' => $h3('bibliographic', '404 unless visible. Editing shared metadata: administrators, or the creator while the work is exclusive to the user\'s scope; a work also held by another household is edited by administrators only.'),
    'works.update' => $h3('bibliographic', 'As works.edit, re-checked on submit (with the optimistic lock).'),
    'works.delete' => $none('admin', $adminOnly . ' Still refused while editions exist.'),
    'editions.create' => $h3('bibliographic', '404 unless the work is visible. The cached-lookup prefill never shows another household\'s data.'),
    'editions.store' => $h3('bibliographic', '404 unless the work is visible. A duplicate ISBN held by an invisible edition gives the generic "ISBN already used" error without a link to it.'),
    'editions.show' => $h2('bibliographic', '404 unless the edition is visible. Copies, owners, locations, loan status and borrowers are shown for visible copies only; "other editions" lists visible editions; collections listed are those the viewer may see.'),
    'editions.open_library' => $h2('bibliographic', '404 unless the edition is visible (the page uses bibliographic fields only, never inventory).'),
    'editions.edit' => $h3('bibliographic', '404 unless visible. Administrators, or the creator while the edition is exclusive to the user\'s scope.'),
    'editions.update' => $h3('bibliographic', 'As editions.edit, re-checked on submit. An ISBN taken by an invisible edition gives the generic error without a link.'),
    'editions.delete' => $none('admin', $adminOnly . ' Still refused while copies exist (any household).'),

    // --- Physical copies (household inventory) -----------------------------------
    'copies.create' => $h3('inventory', '404 unless the edition is visible, or reached through the "add your copy" step of an existing ISBN. The household choice is limited to the user\'s households (phase H1); locations offered belong to the chosen household.'),
    'copies.store' => $h1('inventory', 'Household decided by Households\\CopyHousehold: the default household while the feature is disabled; with it enabled, administrators any household, others only their own households; the owner must be an administrator or a member of the household; the location must belong to it. H3 adds: 404 unless the edition is visible or being adopted.'),
    'copies.edit' => $h3('inventory', '404 unless the copy is visible; then owner-only for members (copy.manage_own) or administrators. Household membership alone gives no right to edit.'),
    'copies.update' => $h1('inventory', 'Owner or administrator (unchanged). A household change goes through Households\\CopyHousehold (same rules as copies.store) and is audited. H3 adds: 404 unless the copy is visible, and the membership check is repeated under the row lock.'),
    'copies.delete' => $h3('inventory', '404 unless the copy is visible; then owner or administrator. Copies with loan history are withdrawn, not deleted (unchanged).'),
    'copies.show' => $h2('inventory', '404 unless the copy is visible. Location path, notes and price belong to the copy\'s household; borrower identity stays with whoever may manage the loan and the borrower.'),

    // --- Loans -------------------------------------------------------------------
    'loans.create' => $h3('loan', '404 unless the copy is visible; then the copy\'s owner or an administrator. Borrowers offered: users sharing a household with the copy, plus the free-text name. Users of other households are never listed.'),
    'loans.store' => $h3('loan', 'As loans.create; a borrower user must share a household with the copy (administrators may lend to any active user). LoanService repeats the household check under the copy lock.'),
    'loans.index' => $h2('loan', '"Lent": loans of the user\'s own copies in households in scope. "Borrowed": the user\'s own loans, always (their own data), showing the title but no inventory details of a household they no longer reach. "All": administrators.'),
    'loans.return' => $h3('loan', '404 unless the loan\'s copy is visible; then the copy\'s owner or an administrator.'),
    'loans.return.store' => $h3('loan', 'As loans.return, re-checked under the row locks.'),

    // --- Wishlists (owner-only, unchanged) ----------------------------------------
    'wishlist.index' => $h2('wishlist', 'Own items only (unchanged). The "you own a copy" marker and links to editions apply to visible editions only.'),
    'wishlist.create' => $h2('wishlist', 'Pre-filling from an edition requires the edition to be visible (404 otherwise).'),
    'wishlist.store' => $h3('wishlist', 'Linking to an existing edition by id or ISBN happens only when that edition is visible; otherwise the item is stored unlinked.'),
    'wishlist.edit' => $none('wishlist', 'Own items only (unchanged); others get 404.'),
    'wishlist.update' => $h3('wishlist', 'Own items only; ISBN auto-linking only to visible editions.'),
    'wishlist.status' => $none('wishlist', 'Own items only (unchanged).'),

    // --- Collections (curated lists, not permission boundaries) -------------------
    'collections.index' => $h2('collection', 'Private/shared visibility is unchanged and independent of households. Item counts count editions visible to the viewer.'),
    'collections.create' => $none('collection', 'Own collection (unchanged).'),
    'collections.store' => $none('collection', 'Own collection (unchanged).'),
    'collections.show' => $h2('collection', 'Collection visibility unchanged (owner, shared, administrator). Items are filtered per viewer: an edition the viewer cannot see is not listed and not counted, even in a shared collection.'),
    'collections.edit' => $none('collection', 'Owner or administrator (unchanged).'),
    'collections.update' => $none('collection', 'Owner or administrator (unchanged).'),
    'collections.delete' => $none('collection', 'Owner or administrator (unchanged).'),
    'collections.items.add' => $h3('collection', 'The edition must be visible to the user adding it (404 otherwise): a collection cannot be used to probe edition ids.'),
    'collections.items.add_from_edition' => $h3('collection', 'As collections.items.add.'),
    'collections.items.remove' => $none('collection', 'Owner or administrator (unchanged); removing needs no visibility.'),
    'collections.items.move' => $h3('collection', 'Ordering works on the items visible to the manager.'),

    // --- Export ------------------------------------------------------------------
    'export.index' => $none('export', 'Lists dataset names only.'),
    'export.personal.json' => $h2('export', 'Own data only (no user parameter, unchanged). Copies: the user\'s copies in households in scope. Loans: own loans; inventory details only for visible copies. Collection items: visible editions only.'),
    'export.personal.csv' => $h2('export', 'As export.personal.json.'),
    'export.catalogue.json' => $h3('admin', $adminOnly . ' Gains household columns for copies and locations; memberships are not exported.'),
    'export.catalogue.csv' => $h3('admin', 'As export.catalogue.json.'),

    // --- Audit log (read-only) ----------------------------------------------------
    'admin.audit' => $none('admin', $adminOnly . ' Household events record ids, never names or e-mail addresses of members.'),
    'admin.audit.show' => $none('admin', $adminOnly),

    // --- Covers ------------------------------------------------------------------
    'covers.store' => $h3('bibliographic', 'As editions.update: administrators, or the creator while the edition is exclusive to the user\'s scope.'),
    'covers.show' => $h2('bibliographic', '404 unless the cover\'s edition is visible. Served with "Cache-Control: private, no-cache" and an ETag, so a removed membership takes effect on the next request instead of after the cache lifetime.'),
    'covers.delete' => $h3('bibliographic', 'As covers.store.'),

    // --- Authors and publishers (shared vocabulary) --------------------------------
    'authors.index' => $h2('bibliographic', 'Lists authors credited on visible works or editions; work counts count visible works.'),
    'authors.show' => $h2('bibliographic', '404 unless the author is credited on a visible work or edition; lists visible works and contributions only.'),
    'authors.edit' => $none('admin', $adminOnly),
    'authors.update' => $none('admin', $adminOnly),
    'authors.merge' => $none('admin', $adminOnly),
    'authors.delete' => $none('admin', $adminOnly),
    'publishers.index' => $h2('bibliographic', 'Lists publishers of visible editions; counts count visible editions.'),
    'publishers.show' => $h2('bibliographic', '404 unless the publisher has a visible edition; lists visible editions only.'),
    'publishers.edit' => $none('admin', $adminOnly),
    'publishers.update' => $none('admin', $adminOnly),
    'publishers.merge' => $none('admin', $adminOnly),
    'publishers.delete' => $none('admin', $adminOnly),

    // --- Administration ------------------------------------------------------------
    'admin.invitations' => $none('admin', $adminOnly),
    'admin.invitations.create' => $h3('admin', $adminOnly . ' Optional household assignments for the invited account; default none.'),
    'admin.invitations.store' => $h3('admin', 'As admin.invitations.create; assignments are stored with the invitation and applied only when it is redeemed.'),
    'admin.invitations.revoke' => $none('admin', $adminOnly),
    'admin.invitations.replace' => $none('admin', $adminOnly),
    'admin.users' => $none('admin', $adminOnly),
    'admin.users.create' => $none('admin', $adminOnly),
    'admin.users.store' => $h1('admin', $adminOnly . ' A new account has no household membership (nothing is assigned on creation).'),
    'admin.users.edit' => $none('admin', $adminOnly),
    'admin.users.update' => $none('admin', $adminOnly . ' Deactivating an account ends its sessions (unchanged); HouseholdScope gives a deactivated account no household.'),
    'admin.users.password' => $none('admin', $adminOnly),
    'admin.users.sessions' => $none('admin', $adminOnly),
    'admin.households' => $h1('household', $householdAdmin),
    'admin.households.create' => $h1('household', $householdAdmin),
    'admin.households.store' => $h1('household', $householdAdmin . ' Audited (household.created).'),
    'admin.households.edit' => $h1('household', $householdAdmin . ' Lists members (name, e-mail, role): administrators only.'),
    'admin.households.update' => $h1('household', $householdAdmin . ' Optimistic lock; audited (household.updated).'),
    'admin.households.delete' => $h1('household', $householdAdmin . ' Refused for the default household and while members, copies or locations remain; audited (household.deleted).'),
    'admin.households.members.add' => $h1('household', $householdAdmin . ' Active accounts only; audited by user id (household.member_added). Applies to the member\'s next request.'),
    'admin.households.members.remove' => $h1('household', $householdAdmin . ' The member\'s copies stay in the household; audited (household.member_removed). Applies to the member\'s next request.'),
    'admin.categories' => $none('admin', $adminOnly . ' Categories are a shared vocabulary without inventory data.'),
    'admin.categories.store' => $none('admin', $adminOnly),
    'admin.categories.edit' => $none('admin', $adminOnly),
    'admin.categories.update' => $none('admin', $adminOnly),
    'admin.categories.delete' => $none('admin', $adminOnly),
    'admin.locations' => $h3('admin', $adminOnly . ' One independent location tree per household, managed per household.'),
    'admin.locations.store' => $h1('admin', $adminOnly . ' A child location inherits its parent\'s household; a new root goes to the default household. H3 adds an explicit household for roots.'),
    'admin.locations.edit' => $h3('admin', $adminOnly),
    'admin.locations.update' => $h3('admin', $adminOnly . ' A location cannot be moved under a parent of another household.'),
    'admin.locations.delete' => $none('admin', $adminOnly . ' Still refused while copies use the location or it has children.'),
];
