<?php

namespace App\Providers;

use App\Models\Announcement;
use App\Models\Club;
use App\Models\ContactMessage;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Membership;
use App\Models\QualificationRule;
use App\Models\Score;
use App\Models\SelectionAppeal;
use App\Models\SelectionAthlete;
use App\Models\SelectionCycle;
use App\Models\SelectionWaiver;
use App\Observers\MembershipObserver;
use App\Policies\AnnouncementPolicy;
use App\Policies\ClubPolicy;
use App\Policies\ContactMessagePolicy;
use App\Policies\MatchPolicy;
use App\Policies\MembershipPolicy;
use App\Policies\QualificationRulePolicy;
use App\Policies\RegistrationPolicy;
use App\Policies\ScorePolicy;
use App\Policies\Selection\SelectionAppealPolicy;
use App\Policies\Selection\SelectionAthletePolicy;
use App\Policies\Selection\SelectionCyclePolicy;
use App\Policies\Selection\SelectionWaiverPolicy;
use App\Listeners\AuthAuditListener;
use App\Models\Setting;
use App\Notifications\EmailOtpNotification;
use App\Notifications\ResetPasswordNotification;
use App\Services\SettingsService;
use Illuminate\Auth\Events\Failed as AuthFailedEvent;
use Illuminate\Auth\Events\Login as AuthLoginEvent;
use Illuminate\Auth\Events\Logout as AuthLogoutEvent;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Mail\Events\MessageSending;
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
        Gate::before(function ($user, $ability) {
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

        Membership::observe(MembershipObserver::class);

        $this->applyMailgunSettings();
        $this->registerNotificationsToggle();
        $this->registerMailReplyTo();
        $this->registerMailLog();
        $this->registerMailRateLimiter();
        $this->registerAuthAuditListener();
    }

    /**
     * Persist every outgoing email into `email_logs`. Registered AFTER
     * registerMailReplyTo so the Reply-To header is on the message by
     * the time LogSendingMail captures it — MessageSending listeners
     * fire in registration order.
     */
    private function registerMailLog(): void
    {
        Event::listen(\Illuminate\Mail\Events\MessageSending::class, \App\Listeners\LogSendingMail::class);
        Event::listen(\Illuminate\Mail\Events\MessageSent::class, \App\Listeners\LogSentMail::class);
    }

    /**
     * Global outgoing-mail throttle used by every non-auth notification via
     * the RateLimited queue middleware. Values are per-worker; we run one
     * queue worker in prod so this is effectively the site-wide send rate.
     *
     * 5/sec keeps us comfortably under Mailgun connection-pool contention
     * for any plan tier; 300/min is a burst ceiling so a runaway loop or a
     * match-director broadcast to hundreds of shooters can't punch through
     * before an operator notices. When a limit is hit, RateLimited releases
     * the job with the correct wait time — attempts are NOT incremented on
     * release, so `--tries=3` on the worker command is safe.
     */
    private function registerMailRateLimiter(): void
    {
        RateLimiter::for('mail', fn () => [
            Limit::perSecond(5),
            Limit::perMinute(300),
        ]);
    }

    /**
     * Every successful login, logout, and failed login attempt lands in the
     * audit log with request IP + user-agent, so admins can spot suspicious
     * activity and see who was on the platform when.
     */
    private function registerAuthAuditListener(): void
    {
        Event::listen(AuthLoginEvent::class, [AuthAuditListener::class, 'handleLogin']);
        Event::listen(AuthLogoutEvent::class, [AuthAuditListener::class, 'handleLogout']);
        Event::listen(AuthFailedEvent::class, [AuthAuditListener::class, 'handleFailed']);
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

    /**
     * Point Reply-To at the secretary inbox when a notification did not
     * set its own. Contact-form mail already replyTo()s the enquirer and
     * is left alone. Never ExCo or owner — admin@ forwards to the whole
     * ExCo, so those must not be the public Reply-To.
     */
    private function registerMailReplyTo(): void
    {
        Event::listen(function (MessageSending $event) {
            if ($event->message->getReplyTo()) {
                return;
            }

            try {
                $reply = app(SettingsService::class)->replyToEmail();
            } catch (\Throwable) {
                return;
            }

            if ($reply) {
                $event->message->replyTo($reply);
            }
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
