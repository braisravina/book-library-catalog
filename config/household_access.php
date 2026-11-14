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
 *   H1    enforced since phase H1
 *   H2    read enforcement: enforced since phase H2 (Security\Visibility)
 *   H3    write enforcement: enforced since phase H3. The write runs in a
 *         guarded transaction (Households\WriteGuard) that reads the actor
 *         and their memberships again under the household locks and
 *         repeats the authorization against the locked rows.
 * H2 and H3 ship together: no household functionality is enabled in
 * production before both are complete (and the owner decides to).
 *
 * 'read' => 'H2' on a route of another phase means its record lookup is
 * already scoped: the route loads its work, edition, copy, loan or cover
 * through Security\Visibility, for GET and POST alike, so a record outside
 * the user's view is answered with 404 before anything is shown,
 * validated or written. No form, error page or re-displayed submission of
 * such a route can show another household's data. The write rule of such
 * a route (phase H3) is stated after "H3:".
 *
 *   exclusive    (H3) members change a work or edition they created only
 *                while it is exclusive to their households; otherwise it is
 *                an administrator's to change. The refusal is a plain 403
 *                and names nothing of what other households hold.
 *
 * Every GET route of a record kind (bibliographic, inventory, loan,
 * collection, wishlist, export) must be read-enforced, and
 * tests/Integration/HouseholdReadIsolationTest.php must request it:
 * both are checked.
 */

$none = static fn (string $kind, string $rule): array => ['kind' => $kind, 'phase' => 'none', 'rule' => $rule];
$h1 = static fn (string $kind, string $rule): array => ['kind' => $kind, 'phase' => 'H1', 'rule' => $rule];
$h2 = static fn (string $kind, string $rule): array => ['kind' => $kind, 'phase' => 'H2', 'rule' => $rule];
$h3 = static fn (string $kind, string $rule): array => ['kind' => $kind, 'phase' => 'H3', 'rule' => $rule];
// A route whose record lookup is read-enforced since H2 (see 'read' above).
$read = static fn (array $entry): array => $entry + ['read' => 'H2'];

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
    'invite.accept.submit' => $h3('public', 'The new account gets no household membership unless the invitation carries household assignments (optional, decided by an administrator when inviting). The memberships are created in the redeeming transaction, only for households that still exist, and audited by account id. A revoked, expired, used or unknown invitation grants nothing.'),
    'logout' => $none('account', 'No catalogue data.'),
    'account.password' => $none('account', 'Own account only.'),
    'account.password.update' => $none('account', 'Own account only.'),

    // --- Catalogue: browse, search, add ------------------------------------------
    'books.index' => $h2('bibliographic', 'Search lists visible editions only; totals, pages and copy counts count visible copies only. The owner filter offers only users sharing a household with the viewer and matches on visible copies; the location filter offers only locations of households in scope; the language filter lists languages of visible editions.'),
    'books.create' => $h2('bibliographic', 'ISBN step: an ISBN that exists but is not visible is NOT reported as a duplicate with holdings; the user sees the shared bibliographic description only (no copy count, owner, household, location or link). Similar-work suggestions, and the author and publisher names offered while typing, come from visible records only. H3 adds the "add your copy" action for such an ISBN.'),
    'books.store' => $read($h3('bibliographic', 'Same-title warnings, similar-work suggestions and the duplicate-ISBN answer reveal visible records only (an invisible duplicate shows the shared description without holdings). H3: Catalogue\\BookService creates the work, edition and first copy in one guarded transaction; the first copy is placed by Catalogue\\CopyService like any other (household the user belongs to, location of that household). An ISBN held by another household is not entered again: the user adds a copy by ISBN (books.copies.*).')),

    // --- Works and editions (shared bibliographic records) ------------------------
    'works.show' => $h2('bibliographic', '404 unless the work is visible. Lists visible editions only; copy counts count visible copies.'),
    'works.edit' => $read($h3('bibliographic', '404 unless the work is visible. H3: administrators, or the creator while the work is exclusive to their households (no edition out of their view, no copy held by another household); otherwise 403. Seeing a work never implies editing it.')),
    'works.update' => $read($h3('bibliographic', 'As works.edit, decided again by Catalogue\\BookService inside the guarded transaction, with the work row locked exclusively (inventory writes lock it shared, so a copy added by another household at that moment is seen), then the optimistic lock.')),
    'works.delete' => $none('admin', $adminOnly . ' Still refused while editions exist.'),
    'editions.create' => $read($h3('bibliographic', '404 unless the work is visible. The cached-lookup prefill uses provider data only, never another household\'s records. H3: adding an edition needs catalogue.create and a visible work; it changes nothing of the work.')),
    'editions.store' => $read($h3('bibliographic', '404 unless the work is visible, checked again in the guarded transaction. A duplicate ISBN held by an invisible edition gives the generic "ISBN already used" error without a link to it.')),
    'editions.show' => $h2('bibliographic', '404 unless the edition is visible. Copies, owners, locations, loan status and borrowers are shown for visible copies only; "other editions" lists visible editions; collections listed are those the viewer may see.'),
    'editions.open_library' => $h2('bibliographic', '404 unless the edition is visible (the page uses bibliographic fields only, never inventory).'),
    'editions.edit' => $read($h3('bibliographic', '404 unless the edition is visible. H3: administrators, or the creator while no copy of the edition is held outside their households; otherwise 403.')),
    'editions.update' => $read($h3('bibliographic', 'As editions.edit, decided again by Catalogue\\BookService inside the guarded transaction with the edition row locked exclusively, then the optimistic lock. An ISBN taken by an invisible edition gives the generic error without a link.')),
    'editions.delete' => $none('admin', $adminOnly . ' Still refused while copies exist (any household).'),

    // --- Physical copies (household inventory) -----------------------------------
    'copies.create' => $read($h3('inventory', '404 unless the edition is visible. The household choice is limited to the user\'s households and the locations offered are theirs, grouped by household; the form shows those of the chosen household.')),
    'copies.store' => $read($h3('inventory', '404 unless the edition is visible. H3: Catalogue\\CopyService, in a guarded transaction: household from Households\\CopyHousehold (the default household while the feature is disabled; otherwise administrators any household, others only their own), the owner must be an administrator or a member of it, the location must belong to it, the inventory code is unique within it, and giving the copy to another owner needs copy.manage_any.')),
    'copies.edit' => $read($h3('inventory', '404 unless the copy is visible; then owner-only for members (copy.manage_own) or administrators. Household membership alone gives no right to edit. A copy left in a household its owner no longer belongs to is out of the owner\'s view.')),
    'copies.update' => $read($h3('inventory', 'As copies.edit, decided again by Catalogue\\CopyService with the copy row locked: a copy that left the user\'s households meanwhile is 404. A household change follows the rules of copies.store, is refused while the copy is on loan, and is audited. Optimistic lock.')),
    'copies.delete' => $read($h3('inventory', 'As copies.update (404 out of scope, then owner or administrator), under the copy lock. Copies with loan history are withdrawn, not deleted.')),
    'books.copies.create' => $read($h3('inventory', '"Add your copy" by ISBN. The edition is found from the ISBN on the server (never an id from the request). A visible edition redirects to its ordinary copy form; an ISBN that is not catalogued redirects to "add a book". Otherwise the form shows the shared bibliographic description only: no copy, count, owner, household, location or link, and the household and location choices are the user\'s own.')),
    'books.copies.store' => $h3('inventory', 'As books.copies.create. Catalogue\\CopyService finds the edition by ISBN inside the guarded transaction and places the copy by the rules of copies.store (household the user belongs to, owner, location of that household, inventory code unique in the household). Viewers have no copy.manage_own and are refused by the route.'),
    'copies.show' => $h2('inventory', '404 unless the copy is visible. Location path, notes and price belong to the copy\'s household; borrower identity stays with whoever may manage the loan and the borrower.'),

    // --- Loans -------------------------------------------------------------------
    'loans.create' => $read($h3('loan', '404 unless the copy is visible; then the copy\'s owner or an administrator. Borrowers offered: the active members of the copy\'s household (every active user only while the household feature is disabled), plus the free-text name. Users of other households are never listed.')),
    'loans.store' => $read($h3('loan', 'As loans.create. H3: Loans\\LoanService, under the household locks and the copy lock: the lender must still reach the copy\'s household (404 otherwise), and a borrower account must be an active member of that household at that moment, whoever records the loan (administrators included); anything else is refused like an account that does not exist. A free-text name is always possible.')),
    'loans.index' => $h2('loan', '"Lent": loans of the user\'s own copies in households in scope. "Borrowed": the user\'s own loans, always (their own data), showing the title but no inventory details of a household they no longer reach. "All": administrators.'),
    'loans.return' => $read($h3('loan', '404 unless the loan\'s copy is visible; then the copy\'s owner or an administrator.')),
    'loans.return.store' => $read($h3('loan', 'As loans.return, decided again under the household locks, the copy lock and the loan lock: a lender who no longer reaches the copy\'s household gets 404. Loan history is never rewritten when memberships change.')),

    // --- Wishlists (owner-only, unchanged) ----------------------------------------
    'wishlist.index' => $h2('wishlist', 'Own items only (unchanged). The "you own a copy" marker and links to editions apply to visible editions only.'),
    'wishlist.create' => $h2('wishlist', 'Pre-filling from an edition requires the edition to be visible (404 otherwise).'),
    'wishlist.store' => $read($h3('wishlist', 'Starting an entry from an edition requires the edition to be visible (404 otherwise), checked again in the guarded transaction. H3: an ISBN is linked only to an edition the user can see at that moment; a book held only by other households is never linked.')),
    'wishlist.edit' => $h2('wishlist', 'Own items only (unchanged); others get 404. Shows the user\'s own entry, never catalogue data.'),
    'wishlist.update' => $h3('wishlist', 'Own items only. H3: the link to a catalogue edition is kept only while that edition is visible and is otherwise resolved again, to a visible edition only, inside the guarded transaction.'),
    'wishlist.status' => $none('wishlist', 'Own items only (unchanged).'),

    // --- Collections (curated lists, not permission boundaries) -------------------
    'collections.index' => $h2('collection', '"Mine": the user\'s own. "Shared with me": other users\' collections shared with a household in the viewer\'s scope (H3: sharing is with one explicitly chosen household); the total counts the same rows. "All": administrators. Item counts count editions visible to the viewer; the owner is named only to household co-members.'),
    'collections.create' => $h2('collection', 'Own collection; an empty form with no catalogue data. The household choice lists the user\'s own households only.'),
    'collections.store' => $h3('collection', 'Own collection. H3: a shared collection is shared with one household chosen explicitly: administrators any household, others only one they belong to (unknown and forbidden give the same error); the default household while the feature is disabled. Private collections have no household.'),
    'collections.show' => $h2('collection', '404 unless the viewer is the owner, an administrator, or (shared) reaches the household the collection is shared with. Items are filtered per viewer: an edition the viewer cannot see is not listed and not counted. The owner is named only to household co-members.'),
    'collections.edit' => $h2('collection', 'Owner or administrator, 404 for a collection the viewer cannot see. The item count shown counts editions visible to the viewer.'),
    'collections.update' => $h3('collection', 'Owner or administrator, decided again under the collection lock. The household of a shared collection follows the rule of collections.store; a collection never becomes shared or changes household on its own.'),
    'collections.delete' => $h3('collection', 'Owner or administrator, decided again under the collection lock (404 for a collection the user cannot see).'),
    'collections.items.add' => $read($h3('collection', 'The edition must be visible to the user adding it, checked again in the guarded transaction (404 otherwise): a collection cannot be used to probe edition ids. An owner who left the household a collection is shared with cannot add to it.')),
    'collections.items.add_from_edition' => $read($h3('collection', 'As collections.items.add.')),
    'collections.items.remove' => $h3('collection', 'Owner or administrator. H3: an item the user cannot see is answered like one that is not in the collection (404).'),
    'collections.items.move' => $h3('collection', 'Ordering moves a book among the items visible to the manager; items out of their view keep their places, and an invisible item is 404.'),

    // --- Export ------------------------------------------------------------------
    'export.index' => $h2('export', 'Lists dataset names only; no record data.'),
    'export.personal.json' => $h2('export', 'Own data only (no user parameter, unchanged). Copies: the user\'s copies in households in scope, with locations of those households. Loans: of own visible copies, and every loan where the user is the borrower (title and dates only when the copy is out of view). Collection items: visible editions only. Wishlist links to catalogue records only for visible ones.'),
    'export.personal.csv' => $h2('export', 'As export.personal.json.'),
    'export.catalogue.json' => $h3('admin', 'Administrators only (route permission); unrestricted scope. With households enabled, copies, locations and shared collections carry household_id and the household name; memberships are never exported. With the feature disabled the columns are unchanged.'),
    'export.catalogue.csv' => $h3('admin', 'As export.catalogue.json.'),

    // --- Audit log (read-only) ----------------------------------------------------
    'admin.audit' => $none('admin', $adminOnly . ' Household events record ids, never names or e-mail addresses of members.'),
    'admin.audit.show' => $none('admin', $adminOnly),

    // --- Covers ------------------------------------------------------------------
    'covers.store' => $read($h3('bibliographic', '404 unless the edition is visible. H3: covers follow the editing rule of their edition (editions.edit), decided again by Catalogue\\BookService with the edition locked.')),
    'covers.show' => $h2('bibliographic', '404 unless the cover\'s edition is visible, checked before the ETag is compared. With households enabled it is served with "Cache-Control: private, no-cache" and an ETag, so a removed membership takes effect on the next request instead of after the cache lifetime.'),
    'covers.delete' => $read($h3('bibliographic', 'As covers.store.')),

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
    'admin.invitations.create' => $h3('admin', 'Administrators only (route permission); unrestricted scope. Optional household assignments for the invited account; default none.'),
    'admin.invitations.store' => $h3('admin', 'As admin.invitations.create; the households are locked and must exist; the assignments are stored with the invitation and applied only when it is redeemed.'),
    'admin.invitations.revoke' => $none('admin', $adminOnly),
    'admin.invitations.replace' => $h3('admin', 'Administrators only (route permission); unrestricted scope. The new invitation keeps the assignments whose household still exists.'),
    'admin.users' => $none('admin', $adminOnly),
    'admin.users.create' => $none('admin', $adminOnly),
    'admin.users.store' => $h3('admin', 'Administrators only (route permission); unrestricted scope. A new account has no household membership unless the administrator ticks households: those memberships are created with the account in one transaction and audited by account id.'),
    'admin.users.edit' => $none('admin', $adminOnly),
    'admin.users.update' => $none('admin', $adminOnly . ' Deactivating an account ends its sessions (unchanged); HouseholdScope gives a deactivated account no household.'),
    'admin.users.password' => $none('admin', $adminOnly),
    'admin.users.sessions' => $none('admin', $adminOnly),
    'admin.households' => $h1('household', $householdAdmin),
    'admin.households.create' => $h1('household', $householdAdmin),
    'admin.households.store' => $h1('household', $householdAdmin . ' Audited (household.created).'),
    'admin.households.edit' => $h1('household', $householdAdmin . ' Lists members (name, e-mail, role): administrators only.'),
    'admin.households.update' => $h1('household', $householdAdmin . ' Optimistic lock; audited (household.updated).'),
    'admin.households.delete' => $h1('household', $householdAdmin . ' Refused for the default household and while members, copies, locations or shared collections remain; audited (household.deleted).'),
    'admin.households.members.add' => $h1('household', $householdAdmin . ' Active accounts only; audited by user id (household.member_added). Applies to the member\'s next request.'),
    'admin.households.members.remove' => $h1('household', $householdAdmin . ' The member\'s copies stay in the household; audited (household.member_removed). Applies to the member\'s next request.'),
    'admin.categories' => $none('admin', $adminOnly . ' Categories are a shared vocabulary without inventory data.'),
    'admin.categories.store' => $none('admin', $adminOnly),
    'admin.categories.edit' => $none('admin', $adminOnly),
    'admin.categories.update' => $none('admin', $adminOnly),
    'admin.categories.delete' => $none('admin', $adminOnly),
    'admin.locations' => $h3('admin', 'Administrators only (route permission); unrestricted scope. One independent location tree per household, shown per household.'),
    'admin.locations.store' => $h3('admin', 'Administrators only (route permission); unrestricted scope. Households\\LocationService: a root belongs to the household chosen explicitly (the default household while the feature is disabled); a child always belongs to its parent\'s household.'),
    'admin.locations.edit' => $h3('admin', $adminOnly),
    'admin.locations.update' => $h3('admin', 'Administrators only (route permission); unrestricted scope. A location is never moved under a parent of another household and never changes household (moving inventory between households is phase H4).'),
    'admin.locations.delete' => $none('admin', $adminOnly . ' Still refused while copies use the location or it has children.'),
];
