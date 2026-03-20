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
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Gate::policy(MatchEvent::class, MatchPolicy::class);
        Gate::policy(Score::class, ScorePolicy::class);
        Gate::policy(MatchRegistration::class, RegistrationPolicy::class);
        Gate::policy(Membership::class, MembershipPolicy::class);
        Gate::policy(QualificationRule::class, QualificationRulePolicy::class);
    }
}
