<?php

namespace App\Listeners;

use App\Services\AuditLogService;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Writes an audit_logs row for every successful/failed login and every
 * logout, so the audit index shows a rolling ledger of who accessed the
 * platform, from where, and when. Registered against Laravel's built-in
 * Auth events in AppServiceProvider::boot().
 *
 * Action types emitted (see AuditLog view/index for how these render):
 *   user.login          — successful sign-in (actor = the user themself)
 *   user.logout         — sign-out (actor = the user themself)
 *   user.login.failed   — bad credentials or unknown email
 *                         (actor null, entity_id set only if the email
 *                         resolved to a real user; new_value carries the
 *                         attempted email so admins can spot bruteforce)
 */
class AuthAuditListener
{
    public function __construct(
        private readonly AuditLogService $audit,
        private readonly Request $request,
    ) {}

    public function handleLogin(Login $event): void
    {
        if ($this->isImpersonationAuthSwitch()) {
            return;
        }

        $user = $this->userFrom($event->user);
        if (! $user) {
            return;
        }

        $this->audit->log(
            actor: $user,
            actionType: 'user.login',
            entityType: 'User',
            entityId: $user->id,
            newValue: array_merge($this->requestContext(), [
                'guard' => $event->guard,
                'remember' => (bool) $event->remember,
            ]),
        );
    }

    public function handleLogout(Logout $event): void
    {
        if ($this->isImpersonationAuthSwitch()) {
            return;
        }

        $user = $this->userFrom($event->user);
        if (! $user) {
            // Guest logouts (session flush without an authenticated user) are
            // not worth an audit row.
            return;
        }

        $this->audit->log(
            actor: $user,
            actionType: 'user.logout',
            entityType: 'User',
            entityId: $user->id,
            newValue: array_merge($this->requestContext(), [
                'guard' => $event->guard,
            ]),
        );
    }

    public function handleFailed(Failed $event): void
    {
        $targetUser = $this->userFrom($event->user);

        // Never log the submitted password. `credentials` is
        // ['email' => '...', 'password' => '...'] — take the email only.
        $attemptedEmail = is_array($event->credentials)
            ? ($event->credentials['email'] ?? null)
            : null;

        $this->audit->log(
            actor: null,
            actionType: 'user.login.failed',
            entityType: 'User',
            entityId: $targetUser?->id,
            newValue: array_merge($this->requestContext(), [
                'guard' => $event->guard,
                'attempted_email' => $attemptedEmail,
                'user_exists' => $targetUser !== null,
            ]),
        );
    }

    /**
     * Auth::loginUsingId on start/stop fires a real Login event, but that
     * switch is not a member signing in. Logging it would put the
     * developer's name on the shared /audit-logs page that admin and
     * ExCo can see. impersonation.started / stopped already record the
     * session for the developer paper-trail.
     */
    private function isImpersonationAuthSwitch(): bool
    {
        return $this->request->routeIs('impersonate.start', 'impersonate.stop');
    }

    /**
     * Narrow the ambiguous Authenticatable to our concrete User model when
     * possible, so AuditLogService can classify the actor's role correctly.
     */
    private function userFrom(?Authenticatable $auth): ?\App\Models\User
    {
        if ($auth instanceof \App\Models\User) {
            return $auth;
        }
        if ($auth === null) {
            return null;
        }
        // Any custom guard would return its own Authenticatable — fall back
        // to resolving the concrete model by ID so we still tag the right
        // actor.
        $id = $auth->getAuthIdentifier();
        return $id ? \App\Models\User::find($id) : null;
    }

    /**
     * @return array{ip: string|null, user_agent: string|null}
     */
    private function requestContext(): array
    {
        return [
            'ip' => $this->request->ip(),
            'user_agent' => Str::limit((string) $this->request->userAgent(), 480, ''),
        ];
    }
}
