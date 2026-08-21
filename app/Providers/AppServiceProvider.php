<?php

namespace App\Providers;

use App\Models\Announcement;
use App\Models\Barrel;
use App\Models\Club;
use App\Models\ContactMessage;
use App\Models\LadderSession;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Membership;
use App\Models\QualificationRule;
use App\Models\Score;
use App\Models\SelectionAppeal;
use App\Models\SelectionAthlete;
use App\Models\SelectionCycle;
use App\Models\SelectionWaiver;
use App\Models\Setting;
use App\Notifications\EmailOtpNotification;
use App\Notifications\ResetPasswordNotification;
use App\Observers\MembershipObserver;
use App\Policies\AnnouncementPolicy;
use App\Policies\BarrelPolicy;
use App\Policies\ClubPolicy;
use App\Policies\ContactMessagePolicy;
use App\Policies\LadderSessionPolicy;
use App\Policies\MatchPolicy;
use App\Policies\MembershipPolicy;
use App\Policies\QualificationRulePolicy;
use App\Policies\RegistrationPolicy;
use App\Policies\ScorePolicy;
use App\Policies\Selection\SelectionAppealPolicy;
use App\Policies\Selection\SelectionAthletePolicy;
use App\Policies\Selection\SelectionCyclePolicy;
use App\Policies\Selection\SelectionWaiverPolicy;
use App\Services\SettingsService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        if (app()->environment('production')) {
            URL::forceScheme('https');
            // Ensure password-reset / verification links in emails always use APP_URL
            // (works when opened on a different device than the one that requested them).
            if ($root = config('app.url')) {
                URL::forceRootUrl($root);
            }
        }

        // Developers + EXCO bypass every policy check — developer is the sysadmin
        // superuser, EXCO is a shared board-walkthrough login that's been
        // explicitly granted owner-equivalent access by the user.
        //
        // Exception: ladders and barrels are personal reloading records that
        // several nationally-ranked shooters compete against each other on.
        // The bypass explicitly does not extend to them, so the policy's
        // ownership check is the only authorisation path in and out.
        Gate::before(function ($user, $ability, $arguments = []) {
            $subject = $arguments[0] ?? null;
            if ($subject instanceof Barrel || $subject instanceof LadderSession) {
                return null;
            }

            return $user->hasAnyRole(['developer', 'exco']) ? true : null;
        });

        Gate::policy(MatchEvent::class, MatchPolicy::class);
        Gate::policy(Score::class, ScorePolicy::class);
        Gate::policy(MatchRegistration::class, RegistrationPolicy::class);
        Gate::policy(Membership::class, MembershipPolicy::class);
        Gate::policy(QualificationRule::class, QualificationRulePolicy::class);
        Gate::policy(Club::class, ClubPolicy::class);
        Gate::policy(ContactMessage::class, ContactMessagePolicy::class);
        Gate::policy(SelectionCycle::class, SelectionCyclePolicy::class);
        Gate::policy(SelectionAthlete::class, SelectionAthletePolicy::class);
        Gate::policy(SelectionWaiver::class, SelectionWaiverPolicy::class);
        Gate::policy(SelectionAppeal::class, SelectionAppealPolicy::class);
        Gate::policy(Announcement::class, AnnouncementPolicy::class);
        Gate::policy(Barrel::class, BarrelPolicy::class);
        Gate::policy(LadderSession::class, LadderSessionPolicy::class);

        Membership::observe(MembershipObserver::class);

        $this->applyMailgunSettings();
        $this->registerNotificationsToggle();
        $this->registerMailRateLimiter();

        // NOTE: LogSendingMail, LogSentMail, and AuthAuditListener are all
        // registered automatically by Laravel 12's listener auto-discovery
        // (any method in app/Listeners/ whose first argument type-hints an
        // event class gets bound to that event, including handleLogin /
        // handleLogout / handleFailed). Adding Event::listen() calls here
        // as well would fire every listener twice — see the audit-log and
        // email-log double-row bug fixed by this change. Reply-To fallback
        // now lives inside LogSendingMail so ordering can't get bungled.
    }

    /**
     * Global outgoing-mail throttle used by every non-auth notification via
     * the RateLimited queue middleware. Values are per-worker; we run one
     * queue worker in prod so this is effectively the site-wide send rate.
     *
     * Sizing rationale:
     *   - 60 per minute (1/second) lets a match director's 10-recipient
     *     bulletin fan out in ~10 seconds instead of taking 5 minutes.
     *     Anything slower runs into Laravel's default `tries` budget
     *     because `RateLimited` middleware DOES increment attempts on
     *     each `release()` — a 2/min limit against a 10-recipient send
     *     will burn `tries` before the slot opens, and the last few
     *     jobs land in `failed_jobs` with `MaxAttemptsExceededException`
     *     while their `announcement_deliveries` rows sit stranded on
     *     `sent`.
     *   - 500 per hour is the outer safety cap. Well below Mailgun's
     *     10k/hour paid-tier limit and comfortably above realistic
     *     federation-wide broadcast volume (~400 recipients × a few
     *     bulletins/day). Auth-critical mail (password reset, OTP)
     *     skips this limiter entirely so members can always log in
     *     even mid-broadcast.
     *
     * If you find yourself needing to raise these further, first check
     * whether the send is actually legitimate or a runaway loop.
     */
    private function registerMailRateLimiter(): void
    {
        RateLimiter::for('mail', fn () => [
            Limit::perMinute(60),
            Limit::perHour(500),
        ]);
    }

    /**
     * Honour the `notifications_enabled` site setting.
     *
     * When the setting is off, outgoing notifications are suppressed EXCEPT for
     * auth-critical ones (login OTP, password reset) — users still need those
     * to access their accounts. Suppressed notifications are logged so admins
     * can see what would have been sent.
     */
    private function registerNotificationsToggle(): void
    {
        $authExempt = [
            EmailOtpNotification::class,
            ResetPasswordNotification::class,
        ];

        Event::listen(function (NotificationSending $event) use ($authExempt) {
            // Auth-critical notifications always go through.
            if (in_array($event->notification::class, $authExempt, true)) {
                return true;
            }

            // Only touch the mail channel — DB / broadcast channels are unaffected.
            if ($event->channel !== 'mail') {
                return true;
            }

            try {
                $enabled = (bool) app(SettingsService::class)->get('notifications_enabled', true);
            } catch (\Throwable) {
                // If settings can't be read (migrations, boot, etc.), fail open
                // so we don't silently swallow every outgoing mail.
                return true;
            }

            if ($enabled) {
                return true;
            }

            Log::info('Email notification suppressed by notifications_enabled=false', [
                'notification' => $event->notification::class,
                'notifiable_id' => method_exists($event->notifiable, 'getKey')
                    ? $event->notifiable->getKey()
                    : null,
                'notifiable_class' => $event->notifiable::class,
            ]);

            return false;
        });
    }

    private function applyMailgunSettings(): void
    {
        try {
            if (! Schema::hasTable('settings')) {
                return;
            }

            $keys = ['mailgun_domain', 'mailgun_secret', 'mailgun_endpoint', 'mailgun_webhook_signing_key', 'mail_from_address', 'mail_from_name'];
            $settings = Setting::whereIn('key', $keys)->pluck('value', 'key');

            $domain = $settings->get('mailgun_domain');
            $secret = $settings->get('mailgun_secret');

            if ($domain && $secret) {
                Config::set('mail.default', 'mailgun');
                Config::set('services.mailgun.domain', $domain);
                Config::set('services.mailgun.secret', $secret);

                if ($settings->get('mailgun_endpoint')) {
                    Config::set('services.mailgun.endpoint', $settings->get('mailgun_endpoint'));
                }
            }

            if ($settings->get('mailgun_webhook_signing_key')) {
                Config::set('services.mailgun.webhook_signing_key', $settings->get('mailgun_webhook_signing_key'));
            }

            if ($settings->get('mail_from_address')) {
                Config::set('mail.from.address', $settings->get('mail_from_address'));
            }
            if ($settings->get('mail_from_name')) {
                Config::set('mail.from.name', $settings->get('mail_from_name'));
            }
        } catch (\Throwable) {
            // DB not available yet (migrations, etc.)
        }
    }
}
