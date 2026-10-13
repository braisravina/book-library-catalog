<?php

declare(strict_types=1);

namespace DigitalLibrary\Http\Controllers;

use DigitalLibrary\Http\Request;
use DigitalLibrary\Http\Response;
use DigitalLibrary\Invitations\InvitationException;
use DigitalLibrary\Invitations\InvitationToken;
use DigitalLibrary\Validation\Validator;

/**
 * Invitation-only registration (there is no open sign-up).
 *
 * 1. GET /invite?token=…  never renders a page: it keeps only the token's
 *    hash in the session and redirects (no-referrer, no-store) to
 *    /invite/accept, or to /invite/invalid for any failure. The token is
 *    therefore in exactly one request and never in a document URL, a
 *    Referer header or a page the browser could cache. (Rendered pages keep
 *    the site-wide same-origin referrer policy: "no-referrer" would make
 *    browsers send "Origin: null" on form posts, which the CSRF origin
 *    check rightly refuses.)
 * 2. GET/POST /invite/accept  the invitee re-types the invited address,
 *    chooses a display name and password; the account is created and the
 *    invitation consumed in one transaction. The user then signs in.
 *
 * Failures share one generic message and count towards an IP-based limit.
 */
final class RegistrationController extends Controller
{
    private const SESSION_KEY = 'invitation';
    private const SESSION_TTL = 1800;

    public function open(Request $request): Response
    {
        $ip = $request->remoteAddress();
        if ($this->retryAfter($ip) > 0) {
            return $this->tokenless(Response::redirect('/invite/invalid'));
        }
        $token = (string) $request->query('token', '');
        $hash = InvitationToken::isWellFormed($token) ? InvitationToken::hash($token) : null;
        $invitation = $hash === null ? null : $this->services->invitationService()->redeemable($hash);
        if ($invitation === null) {
            $this->recordFailure($request, 'open');

            return $this->tokenless(Response::redirect('/invite/invalid'));
        }

        $session = $this->services->session();
        $session->regenerate();
        $session->set(self::SESSION_KEY, ['hash' => $hash, 'until' => time() + self::SESSION_TTL]);

        return $this->tokenless(Response::redirect('/invite/accept'));
    }

    /**
     * Generic page for every unusable link (and for throttled clients).
     */
    public function invalidPage(Request $request): Response
    {
        if (($retry = $this->retryAfter($request->remoteAddress())) > 0) {
            return $this->throttled($retry);
        }

        return $this->private($this->render('invitations/invalid', ['page_title' => 'invitations.invalid.title'], 404));
    }

    public function show(Request $request): Response
    {
        if ($this->pendingHash() === null) {
            return $this->invalid($request, 'session', false);
        }

        return $this->form(['email' => '', 'display_name' => ''], []);
    }

    public function accept(Request $request): Response
    {
        $ip = $request->remoteAddress();
        if (($retry = $this->retryAfter($ip)) > 0) {
            return $this->throttled($retry);
        }
        $hash = $this->pendingHash();
        if ($hash === null) {
            return $this->invalid($request, 'session', false);
        }

        $input = [
            'email' => mb_substr(trim((string) $request->input('email', '')), 0, 300),
            'display_name' => trim((string) $request->input('display_name', '')),
        ];
        $password = (string) $request->input('password', '');
        $confirmation = (string) $request->input('password_confirmation', '');
        $validator = Validator::make($input + ['password' => $password], [
            'email' => ['required', 'email', 'max:254'],
            'display_name' => ['required', 'max:100', 'no_control'],
            'password' => ['required'],
        ]);
        if (strlen($password) > 4096) {
            $validator->addError('password', 'validation.password_too_long');
        } elseif ($password !== '') {
            foreach ($this->services->passwordPolicy()->validate($password, $input['email']) as $key) {
                $validator->addError('password', $key);
            }
        }
        if (!hash_equals($password, $confirmation)) {
            $validator->addError('password_confirmation', 'validation.confirmed');
        }
        if ($validator->fails()) {
            return $this->form($input, $validator->errors(), 422);
        }

        try {
            $result = $this->services->invitationService()->redeem(
                $hash,
                $input['email'],
                $input['display_name'],
                $this->services->hasher()->hash($password),
                $this->services->view()->locale(),
            );
        } catch (InvitationException $e) {
            if ($e->reason === 'email_mismatch') {
                $this->services->invitationThrottle()->recordFailure($this->bucket($ip), $ip);
                $this->services->audit()->log('invitation.rejected', null, 'invitation', null, ['reason' => 'email_mismatch'], $ip, $request->userAgent());

                return $this->form($input, ['email' => ['invitations.error.email_mismatch']], 422);
            }

            return $this->invalid($request, $e->reason);
        }

        $this->services->audit()->log('invitation.accepted', $result['user_id'], 'invitation', $result['invitation_id'], ['user_id' => $result['user_id']], $ip, $request->userAgent());
        $this->services->audit()->log('user.created', $result['user_id'], 'user', $result['user_id'], [
            'role' => $result['role']->value,
            'via' => 'invitation',
            'invitation_id' => $result['invitation_id'],
        ], $ip, $request->userAgent());

        $session = $this->services->session();
        $session->remove(self::SESSION_KEY);
        $session->regenerate();
        $this->flash('success', 'invitations.accept.done');

        return $this->private(Response::redirect('/login'));
    }

    private function pendingHash(): ?string
    {
        $pending = $this->services->session()->get(self::SESSION_KEY);
        if (!is_array($pending) || !is_string($pending['hash'] ?? null) || (int) ($pending['until'] ?? 0) < time()) {
            $this->services->session()->remove(self::SESSION_KEY);

            return null;
        }

        return $pending['hash'];
    }

    /**
     * One generic answer for unknown, malformed, expired, revoked and used
     * tokens. Failed link attempts count towards the IP limit.
     */
    private function invalid(Request $request, string $reason, bool $countFailure = true): Response
    {
        $this->services->session()->remove(self::SESSION_KEY);
        if ($countFailure) {
            $this->recordFailure($request, $reason);
        }

        return $this->private($this->render('invitations/invalid', ['page_title' => 'invitations.invalid.title'], 404));
    }

    private function recordFailure(Request $request, string $reason): void
    {
        $ip = $request->remoteAddress();
        $this->services->invitationThrottle()->recordFailure($this->bucket($ip), $ip);
        $this->services->audit()->log('invitation.rejected', null, 'invitation', null, ['reason' => $reason], $ip, $request->userAgent());
    }

    private function throttled(int $retryAfter): Response
    {
        $response = $this->render('invitations/invalid', [
            'page_title' => 'invitations.invalid.title',
            'retry_minutes' => (int) ceil($retryAfter / 60),
        ], 429);
        $response->setHeader('Retry-After', (string) $retryAfter);

        return $this->private($response);
    }

    private function retryAfter(string $ip): int
    {
        return $this->services->invitationThrottle()->retryAfter($this->bucket($ip), $ip);
    }

    /**
     * Throttle bucket for invitation attempts from one address (hashed by LoginThrottle).
     */
    private function bucket(string $ip): string
    {
        return 'invitation:' . $ip;
    }

    /**
     * @param array<string, string>       $input
     * @param array<string, list<string>> $errors
     */
    private function form(array $input, array $errors, int $status = 200): Response
    {
        return $this->private($this->render('invitations/accept', [
            'page_title' => 'invitations.accept.title',
            'input' => $input,
            'errors' => $errors,
        ], $status));
    }

    private function private(Response $response): Response
    {
        $response->setHeader('Cache-Control', 'no-store, private, max-age=0');
        $response->setHeader('Pragma', 'no-cache');

        return $response;
    }

    /**
     * For the response to the token-bearing request only: the next request
     * carries no Referer at all.
     */
    private function tokenless(Response $response): Response
    {
        $response->setHeader('Referrer-Policy', 'no-referrer');

        return $this->private($response);
    }
}
