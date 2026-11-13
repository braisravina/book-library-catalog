<?php

declare(strict_types=1);

namespace DigitalLibrary\Http\Controllers\Library;

use DigitalLibrary\Auth\User;
use DigitalLibrary\Http\Controllers\Controller;
use DigitalLibrary\Http\HttpException;
use DigitalLibrary\Http\Request;
use DigitalLibrary\Http\Response;
use DigitalLibrary\Loans\LoanException;
use DigitalLibrary\Loans\LoanInput;
use DigitalLibrary\Loans\LoanService;
use DigitalLibrary\Security\Permission;
use DigitalLibrary\Validation\Validator;

/**
 * Lending of physical copies. Route guards require authentication; every
 * action on a specific copy or loan re-checks Authorizer::canManageLoans()
 * against the copy owner, and LoanService checks again under row locks.
 */
final class LoanController extends Controller
{
    private const STATUSES = ['active', 'overdue', 'returned', 'all'];

    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $canAll = $this->can($user, Permission::LoanManageAny);
        $canOwn = $this->can($user, Permission::LoanManageOwn);
        $scope = (string) $request->query('scope', $canOwn ? 'lent' : 'borrowed');
        if (!in_array($scope, ['lent', 'borrowed', 'all'], true) || ($scope === 'all' && !$canAll) || ($scope === 'lent' && !$canOwn)) {
            $scope = 'borrowed';
        }
        $status = (string) $request->query('status', 'active');
        $status = in_array($status, self::STATUSES, true) ? $status : 'active';
        $page = max(1, min(10_000, (int) ($request->query('page') ?? '1')));

        $result = $this->services->loans()->search(
            $scope === 'lent' ? $user->id : null,
            $scope === 'borrowed' ? $user->id : null,
            $status,
            $page,
            $this->visibility(),
        );

        return $this->render('loans/index', [
            'page_title' => 'loans.title',
            'scope' => $scope,
            'status' => $status,
            'statuses' => self::STATUSES,
            'scopes' => array_keys(array_filter(['lent' => $canOwn, 'borrowed' => true, 'all' => $canAll])),
            'rows' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / \DigitalLibrary\Loans\LoanRepository::PER_PAGE)),
            'today' => LoanService::today(),
        ]);
    }

    /**
     * Copy details: availability for everyone who can view the catalogue,
     * borrower identity and history only for those who may manage loans.
     *
     * @param array<string, string> $params
     */
    public function copy(Request $request, array $params): Response
    {
        $user = $this->user($request);
        $copy = $this->found($this->services->copies()->find($this->id($params), $this->visibility()));
        $copyId = (int) $copy['id'];
        $ownerId = (int) $copy['owner_user_id'];
        $canManage = $this->services->authorizer()->canManageLoans($user, $ownerId);
        $active = $this->services->loans()->activeForCopy($copyId);
        $paths = $this->services->locations()->visiblePaths($this->visibility());

        return $this->render('loans/copy', [
            'page_title' => null,
            'title' => (string) $copy['edition_title'],
            'copy' => $copy,
            'location_path' => $copy['location_id'] !== null ? ($paths[(int) $copy['location_id']] ?? null) : null,
            'active' => $active,
            'can_manage' => $canManage,
            'can_see_active' => $active !== null && $this->services->authorizer()->canSeeLoanDetails($user, $ownerId, $active['borrower_user_id'] === null ? null : (int) $active['borrower_user_id']),
            'can_edit_copy' => $this->services->authorizer()->canManageCopy($user, $ownerId),
            'history' => $canManage ? $this->services->loans()->historyForCopy($copyId) : [],
            'today' => LoanService::today(),
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function create(Request $request, array $params): Response
    {
        $copy = $this->manageableCopy($request, $params);

        return $this->form($request, $copy, LoanInput::blank(LoanService::today()), []);
    }

    /**
     * @param array<string, string> $params
     */
    public function store(Request $request, array $params): Response
    {
        $user = $this->user($request);
        $copy = $this->manageableCopy($request, $params);
        $input = LoanInput::fromRequest($request);
        $validator = Validator::make([], []);
        $input->validate($validator, LoanService::today(), (int) $copy['owner_user_id']);
        if ($input->borrowerUserId() !== null && !isset($validator->errors()['borrower_user_id'])) {
            // Only someone offered in the form: a user of another household
            // is refused exactly like an account that does not exist, so
            // lending cannot be used to learn who else has an account.
            $offered = array_map(static fn (User $u): int => $u->id, $this->borrowers($copy));
            if (!in_array($input->borrowerUserId(), $offered, true)) {
                $validator->addError('borrower_user_id', 'validation.in');
            }
        }
        if ($validator->fails()) {
            return $this->form($request, $copy, $input, $validator->errors(), 422);
        }

        try {
            $loanId = $this->services->loanService()->lend($user, (int) $copy['id'], $input);
        } catch (LoanException $e) {
            if ($e->getMessage() === 'loans.error.forbidden') {
                throw HttpException::forbidden();
            }

            return $this->form($request, $copy, $input, [], 409, $e->getMessage());
        }
        // Borrower names are personal data and stay out of the audit trail.
        $this->audit($request, 'loan.created', 'loan', $loanId, [
            'copy_id' => (int) $copy['id'],
            'borrower_user_id' => $input->borrowerUserId(),
            'due_on' => $input->dueOn === '' ? null : $input->dueOn,
        ]);
        $this->flash('success', 'loans.created');

        return Response::redirect('/copies/' . (int) $copy['id']);
    }

    /**
     * Confirmation page (works without JavaScript).
     *
     * @param array<string, string> $params
     */
    public function confirmReturn(Request $request, array $params): Response
    {
        $loan = $this->manageableLoan($request, $params);
        if ($loan['returned_on'] !== null) {
            $this->flash('info', 'loans.error.already_returned');

            return Response::redirect('/copies/' . (int) $loan['copy_id']);
        }

        return $this->render('loans/return', [
            'page_title' => 'loans.return.title',
            'loan' => $loan,
            'today' => LoanService::today(),
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function storeReturn(Request $request, array $params): Response
    {
        $loan = $this->manageableLoan($request, $params);
        try {
            $this->services->loanService()->returnLoan($this->user($request), (int) $loan['id']);
        } catch (LoanException $e) {
            if ($e->getMessage() === 'loans.error.forbidden') {
                throw HttpException::forbidden();
            }
            $this->flash('error', $e->getMessage());

            return Response::redirect('/copies/' . (int) $loan['copy_id']);
        }
        $this->audit($request, 'loan.returned', 'loan', (int) $loan['id'], ['copy_id' => (int) $loan['copy_id']]);
        $this->flash('success', 'loans.returned');

        return Response::redirect('/copies/' . (int) $loan['copy_id']);
    }

    /**
     * @param array<string, string> $params
     *
     * @return array<string, mixed>
     */
    private function manageableCopy(Request $request, array $params): array
    {
        // 404 before 403: a copy outside the user's households does not exist for them.
        $copy = $this->found($this->services->copies()->find($this->id($params), $this->visibility()));
        $this->authorize($this->services->authorizer()->canManageLoans($this->user($request), (int) $copy['owner_user_id']));

        return $copy;
    }

    /**
     * @param array<string, string> $params
     *
     * @return array<string, mixed>
     */
    private function manageableLoan(Request $request, array $params): array
    {
        $loan = $this->found($this->services->loans()->find($this->id($params), $this->visibility()));
        $this->authorize($this->services->authorizer()->canManageLoans($this->user($request), (int) $loan['owner_user_id']));

        return $loan;
    }

    /**
     * Accounts the copy may be lent to (besides a free-text name): every
     * active user for an unrestricted viewer (administrators, and everyone
     * while the household feature is disabled); otherwise only the active
     * members of the copy's household. Users of other households are never
     * offered or accepted.
     *
     * @param array<string, mixed> $copy
     *
     * @return list<User>
     */
    private function borrowers(array $copy): array
    {
        $ownerId = (int) $copy['owner_user_id'];
        $visibility = $this->visibility();
        if ($visibility->isUnrestricted()) {
            $users = $this->services->users()->activeKnownTo($visibility);
        } else {
            $users = $copy['household_id'] === null ? [] : $this->services->users()->activeMembersOf((int) $copy['household_id']);
        }

        return array_values(array_filter($users, static fn (User $u): bool => $u->id !== $ownerId));
    }

    /**
     * @param array<string, mixed>        $copy
     * @param array<string, list<string>> $errors
     */
    private function form(Request $request, array $copy, LoanInput $input, array $errors, int $status = 200, ?string $refusal = null): Response
    {
        return $this->render('loans/lend', [
            'page_title' => 'loans.lend.title',
            'copy' => $copy,
            'input' => $input,
            'errors' => $errors,
            'refusal' => $refusal,
            'borrowers' => $this->borrowers($copy),
            'available' => $copy['status'] === 'available' && $this->services->loans()->activeForCopy((int) $copy['id']) === null,
            'today' => LoanService::today(),
        ], $status);
    }
}
