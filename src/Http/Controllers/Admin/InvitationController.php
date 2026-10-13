<?php

declare(strict_types=1);

namespace DigitalLibrary\Http\Controllers\Admin;

use DigitalLibrary\Http\Controllers\Controller;
use DigitalLibrary\Http\HttpException;
use DigitalLibrary\Http\Request;
use DigitalLibrary\Http\Response;
use DigitalLibrary\Invitations\InvitationException;
use DigitalLibrary\Invitations\InvitationRepository;
use DigitalLibrary\Invitations\InvitationService;
use DigitalLibrary\Security\Role;
use DigitalLibrary\Validation\Validator;

/**
 * Administrator-issued invitations (invitation.manage).
 *
 * The raw invitation link is rendered exactly once, in the response to the
 * POST that issued or replaced it (no-store; the page URL holds no token). It is never put
 * in the session, a flash message, a redirect URL or the audit log.
 */
final class InvitationController extends Controller
{
    private const FILTERS = ['pending', 'accepted', 'expired', 'revoked', 'all'];
    private const DEFAULT_DAYS = 7;

    public function index(Request $request): Response
    {
        $status = (string) $request->query('status', 'pending');
        $status = in_array($status, self::FILTERS, true) ? $status : 'pending';
        $page = max(1, min(10_000, (int) ($request->query('page') ?? '1')));
        $result = $this->services->invitations()->list($status, $page);

        return $this->render('admin/invitations/index', [
            'page_title' => 'invitations.admin.title',
            'status' => $status,
            'filters' => self::FILTERS,
            'rows' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / InvitationRepository::PER_PAGE)),
            'default_expiry' => $this->defaultExpiry(),
            'max_expiry' => $this->services->invitationService()->maxExpiryDate(gmdate('Y-m-d')),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->form(['email' => '', 'role' => Role::Member->value, 'expires_on' => $this->defaultExpiry()], []);
    }

    public function store(Request $request): Response
    {
        $admin = $this->user($request);
        $input = [
            'email' => mb_substr(trim((string) $request->input('email', '')), 0, 300),
            'role' => (string) $request->input('role', ''),
            'expires_on' => trim((string) $request->input('expires_on', '')),
        ];
        $validator = Validator::make($input, [
            'email' => ['required', 'email', 'max:254'],
            'role' => ['required', 'in:' . implode(',', array_map(static fn (Role $r): string => $r->value, InvitationService::INVITABLE_ROLES))],
            'expires_on' => ['required', 'date'],
        ]);
        if ($validator->fails()) {
            return $this->form($input, $validator->errors(), 422);
        }
        try {
            $issued = $this->services->invitationService()->issue($admin, $input['email'], Role::from($input['role']), $input['expires_on']);
        } catch (InvitationException $e) {
            if ($e->getMessage() === 'invitations.error.forbidden') {
                throw HttpException::forbidden();
            }
            $field = $e->getMessage() === 'invitations.error.expiry' ? 'expires_on' : 'email';

            return $this->form($input, [$field => [$e->getMessage()]], 422);
        }
        $this->audit($request, 'invitation.created', 'invitation', $issued['id'], [
            'email' => InvitationService::normaliseEmail($input['email']),
            'role' => $input['role'],
            'expires_at' => $issued['expires_at'],
        ]);

        return $this->link($issued['id'], $issued['token'], InvitationService::normaliseEmail($input['email']), $input['role'], $issued['expires_at'], false);
    }

    /**
     * @param array<string, string> $params
     */
    public function revoke(Request $request, array $params): Response
    {
        $id = $this->id($params);
        try {
            $this->services->invitationService()->revoke($this->user($request), $id);
        } catch (InvitationException $e) {
            $this->refuse($e);
            $this->flash('error', $e->getMessage());

            return Response::redirect('/admin/invitations?status=all');
        }
        $this->audit($request, 'invitation.revoked', 'invitation', $id);
        $this->flash('success', 'invitations.admin.revoked');

        return Response::redirect('/admin/invitations');
    }

    /**
     * Revokes the old token and shows a new link for the same address and role.
     *
     * @param array<string, string> $params
     */
    public function replace(Request $request, array $params): Response
    {
        $id = $this->id($params);
        $expiresOn = trim((string) $request->input('expires_on', ''));
        try {
            $issued = $this->services->invitationService()->replace($this->user($request), $id, $expiresOn === '' ? $this->defaultExpiry() : $expiresOn);
        } catch (InvitationException $e) {
            $this->refuse($e);
            $this->flash('error', $e->getMessage());

            return Response::redirect('/admin/invitations?status=all');
        }
        $this->audit($request, 'invitation.replaced', 'invitation', $issued['id'], [
            'replaces' => $id,
            'email' => $issued['email'],
            'role' => $issued['role']->value,
            'expires_at' => $issued['expires_at'],
        ]);

        return $this->link($issued['id'], $issued['token'], $issued['email'], $issued['role']->value, $issued['expires_at'], true);
    }

    private function refuse(InvitationException $e): void
    {
        match ($e->getMessage()) {
            'invitations.error.forbidden' => throw HttpException::forbidden(),
            'invitations.error.not_found' => throw HttpException::notFound(),
            default => null,
        };
    }

    /**
     * The one-time display of the link. If a delivery channel is configured
     * and succeeds, the link is not shown at all.
     */
    private function link(int $id, #[\SensitiveParameter] string $token, string $email, string $role, string $expiresAt, bool $replaced): Response
    {
        $link = rtrim((string) $this->services->config->get('APP_URL', ''), '/') . '/invite?token=' . $token;
        $delivered = $this->services->invitationDelivery()->deliver(
            $email,
            $link,
            new \DateTimeImmutable($expiresAt, new \DateTimeZone('UTC')),
            $this->services->view()->locale(),
        );
        $response = $this->render('admin/invitations/link', [
            'page_title' => 'invitations.admin.link_title',
            'invitation_id' => $id,
            'email' => $email,
            'role' => $role,
            'expires_at' => $expiresAt,
            'link' => $delivered ? null : $link,
            'replaced' => $replaced,
        ]);
        // The page URL is /admin/invitations (no token); keep the site's
        // same-origin referrer policy so forms on the page pass the origin check.
        $response->setHeader('Cache-Control', 'no-store, private, max-age=0');
        $response->setHeader('Pragma', 'no-cache');

        return $response;
    }

    private function defaultExpiry(): string
    {
        return gmdate('Y-m-d', strtotime('+' . self::DEFAULT_DAYS . ' days'));
    }

    /**
     * @param array<string, string>       $input
     * @param array<string, list<string>> $errors
     */
    private function form(array $input, array $errors, int $status = 200): Response
    {
        $roles = [];
        foreach (InvitationService::INVITABLE_ROLES as $role) {
            $roles[$role->value] = $this->services->translator()->get($role->labelKey());
        }

        return $this->render('admin/invitations/form', [
            'page_title' => 'invitations.admin.new',
            'input' => $input,
            'errors' => $errors,
            'roles' => $roles,
            'min_expiry' => gmdate('Y-m-d'),
            'max_expiry' => $this->services->invitationService()->maxExpiryDate(gmdate('Y-m-d')),
        ], $status);
    }
}
