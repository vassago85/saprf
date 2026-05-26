<?php

namespace App\Providers;

use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Membership;
use App\Models\QualificationRule;
use App\Models\Score;
use App\Policies\MatchPolicy;
use App\Policies\MembershipPolicy;
use App\Policies\QualificationRulePolicy;
use App\Policies\RegistrationPolicy;
use App\Policies\ScorePolicy;
use App\Models\Setting;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
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

        $this->applyMailgunSettings();
    }

    private function applyMailgunSettings(): void
    {
        try {
            if (! Schema::hasTable('settings')) {
                return;
            }

            $keys = ['mailgun_domain', 'mailgun_secret', 'mailgun_endpoint', 'mail_from_address', 'mail_from_name'];
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
