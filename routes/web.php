<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FamilyController;
use App\Http\Controllers\MatchController;
use App\Http\Controllers\MembershipController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProvincialCommitteeController;
use App\Http\Controllers\ProvincialMembersController;
use App\Http\Controllers\QualificationRuleController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\AmmoLoadController;
use App\Http\Controllers\RifleConfigurationController;
use App\Http\Controllers\SascocReportController;
use App\Http\Controllers\ScoreController;
use App\Http\Controllers\ScoreImportController;
use App\Http\Controllers\SiteSettingsController;
use App\Http\Controllers\SponsorController;
use App\Http\Controllers\SponsorTierController;
use App\Http\Controllers\DivisionController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\StandingController;
use App\Http\Controllers\UserManagementController;
use App\Http\Controllers\MatchExpenseController;
use App\Http\Controllers\FinancialController;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\VenueController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

// ── Public Pages ──

Route::view('/', 'welcome');
Route::view('/privacy', 'legal.privacy')->name('legal.privacy');
Route::view('/terms', 'legal.terms')->name('legal.terms');
Route::get('/events', [MatchController::class, 'publicIndex'])->name('events.index');
Route::get('/events/{match}', [MatchController::class, 'publicShow'])->name('events.show');
Route::get('/standings', [StandingController::class, 'publicIndex'])->name('standings.public');
Route::get('/standings/{season}/shooter/{user}', [StandingController::class, 'publicShooter'])->name('standings.shooter');
Route::get('/verify/{saprfNumber}', [MembershipController::class, 'verify'])->name('membership.verify');

// ── PayFast ITN Webhook (no auth / session / CSRF — PayFast POSTs here) ──
Route::post('/webhooks/payfast', [PaymentController::class, 'notify'])
    ->name('payments.notify')
    ->withoutMiddleware([
        \Illuminate\Cookie\Middleware\EncryptCookies::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
        \App\Http\Middleware\ForcePasswordChange::class,
    ]);

// ── Public Account Handover (junior accepts their account from parent) ──
Route::get('/family/handover/{token}', [FamilyController::class, 'acceptHandover'])->name('family.handover.accept');
Route::post('/family/handover/{token}', [FamilyController::class, 'completeHandover'])->name('family.handover.complete');

// ── Auth (guest only) ──

Route::middleware('guest')->group(function (): void {
    Volt::route('/login', 'pages.auth.login')->name('login');
    Volt::route('/register', 'pages.auth.register')->name('register');
    Volt::route('/forgot-password', 'pages.auth.forgot-password')->name('password.request');
    Volt::route('/reset-password/{token}', 'pages.auth.reset-password')->name('password.reset');
    Volt::route('/invitation/{token}', 'pages.auth.accept-invitation')->name('invitation.accept');
});

Route::post('/logout', function () {
    Auth::logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();
    return redirect('/');
})->name('logout')->middleware('auth');

// ── Email Verification ──
// Signed link works on ANY device (no session required). OTP form remains as fallback.

Route::get('/email/verify/{id}/{hash}', EmailVerificationController::class)
    ->middleware(['signed', 'throttle:6,1'])
    ->name('verification.verify');

Route::middleware('auth')->group(function (): void {
    Volt::route('/verify-email', 'pages.auth.verify-email')->name('verification.notice');
});

// ── Authenticated ──

// Force password change — must be reachable BEFORE the profile.complete + verified
// gates so a fresh seeded user with a starter password can change it.
Route::middleware('auth')->group(function (): void {
    Route::get('/password/force-change', [\App\Http\Controllers\Auth\ForcePasswordChangeController::class, 'edit'])->name('password.force.edit');
    Route::put('/password/force-change', [\App\Http\Controllers\Auth\ForcePasswordChangeController::class, 'update'])->name('password.force.update');
});

Route::middleware(['auth', 'verified', 'profile.complete'])->group(function (): void {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');

    // Family / Managed Junior Accounts
    Route::prefix('family')->name('family.')->group(function (): void {
        Route::get('/', [FamilyController::class, 'index'])->name('index');
        Route::get('/add', [FamilyController::class, 'create'])->name('create');
        Route::post('/', [FamilyController::class, 'store'])->name('store');
        Route::get('/{junior}', [FamilyController::class, 'show'])->name('show');
        Route::get('/{junior}/edit', [FamilyController::class, 'edit'])->name('edit');
        Route::put('/{junior}', [FamilyController::class, 'update'])->name('update');
        Route::post('/{junior}/handover', [FamilyController::class, 'startHandover'])->name('handover.start');
        Route::delete('/{junior}/handover', [FamilyController::class, 'cancelHandover'])->name('handover.cancel');
    });

    // Firearm reference data — user-submitted entries
    Route::post('/api/firearm-makes', [\App\Http\Controllers\FirearmReferenceController::class, 'storeMake'])->name('api.firearm-makes.store');
    Route::post('/api/firearm-models', [\App\Http\Controllers\FirearmReferenceController::class, 'storeModel'])->name('api.firearm-models.store');
    Route::post('/api/firearm-calibres', [\App\Http\Controllers\FirearmReferenceController::class, 'storeCalibre'])->name('api.firearm-calibres.store');

    // Optic reference data — user-submitted entries
    Route::post('/api/optic-makes', [\App\Http\Controllers\FirearmReferenceController::class, 'storeOpticMake'])->name('api.optic-makes.store');
    Route::post('/api/optic-models', [\App\Http\Controllers\FirearmReferenceController::class, 'storeOpticModel'])->name('api.optic-models.store');

    // Standings (dashboard context — authenticated)
    Route::get('/app/standings', [StandingController::class, 'index'])->name('standings.index');
    Route::get('/app/standings/{series}/{season}', [StandingController::class, 'show'])->name('standings.show');

    // Event Registration (auth required)
    Route::get('/events/{match}/register', [MatchController::class, 'showRegistration'])->name('events.register');
    Route::post('/events/{match}/register', [MatchController::class, 'storeRegistration'])->name('events.register.store');

    // Payments
    Route::get('/payments/{payment}/redirect', [PaymentController::class, 'redirect'])->name('payments.redirect');
    Route::get('/payments/{payment}/status', [PaymentController::class, 'status'])->name('payments.status');
    Route::get('/payments/return', [PaymentController::class, 'returnFromGateway'])->name('payments.return');
    Route::get('/payments/cancel', [PaymentController::class, 'cancel'])->name('payments.cancel');
    Route::post('/payments/membership/{membership}', [PaymentController::class, 'payMembership'])->name('payments.membership');
    Route::post('/membership/join', [PaymentController::class, 'joinMembership'])->name('membership.join');
    Route::get('/my-membership', [MembershipController::class, 'myMembership'])->name('my-membership');
    Route::get('/my-membership/certificate', [MembershipController::class, 'certificate'])->name('membership.certificate');
    Route::get('/my-membership/activity-report', [MembershipController::class, 'activityReport'])->name('membership.activity-report');

    // Registrations — any authenticated user can view own / register
    Route::get('/registrations', [RegistrationController::class, 'index'])->name('registrations.index');
    Route::get('/registrations/{registration}', [RegistrationController::class, 'show'])->name('registrations.show');
    Route::post('/registrations', [RegistrationController::class, 'store'])->name('registrations.store');
    Route::post('/registrations/{registration}/withdraw', [RegistrationController::class, 'withdraw'])->name('registrations.withdraw');
    Route::put('/registrations/{registration}/division', [RegistrationController::class, 'updateDivision'])->name('registrations.update-division');
    Route::put('/registrations/{registration}/rifle', [RegistrationController::class, 'updateRifle'])->name('registrations.update-rifle');
    Route::put('/registrations/{registration}/shots', [RegistrationController::class, 'updateShotCount'])->name('registrations.update-shots');

    // Rifle Configurations — any authenticated user
    Route::resource('rifle-configurations', RifleConfigurationController::class)
        ->names('rifle-configurations');

    // Ammo Loads — nested under rifles for create, flat for edit/update/destroy
    Route::get('/ammo-loads', [AmmoLoadController::class, 'index'])->name('ammo-loads.index');
    Route::get('/rifle-configurations/{rifleConfiguration}/ammo-loads/create', [AmmoLoadController::class, 'create'])->name('ammo-loads.create');
    Route::post('/rifle-configurations/{rifleConfiguration}/ammo-loads', [AmmoLoadController::class, 'store'])->name('ammo-loads.store');
    Route::get('/ammo-loads/{ammoLoad}/edit', [AmmoLoadController::class, 'edit'])->name('ammo-loads.edit');
    Route::put('/ammo-loads/{ammoLoad}', [AmmoLoadController::class, 'update'])->name('ammo-loads.update');
    Route::delete('/ammo-loads/{ammoLoad}', [AmmoLoadController::class, 'destroy'])->name('ammo-loads.destroy');

    // Venues — match directors can browse + submit new ones (auto goes to approval
    // queue); edits/deletes require federation admin or owner sign-off.
    Route::middleware(['role:developer|exco|owner|admin|match_director'])->group(function (): void {
        Route::get('/venues', [VenueController::class, 'index'])->name('venues.index');
        Route::get('/venues/create', [VenueController::class, 'create'])->name('venues.create');
        Route::post('/venues', [VenueController::class, 'store'])->name('venues.store');
    });

    Route::middleware(['role:developer|exco|owner|admin'])->group(function (): void {
        Route::get('/venues/{venue}/edit', [VenueController::class, 'edit'])->name('venues.edit');
        Route::put('/venues/{venue}', [VenueController::class, 'update'])->name('venues.update');
        Route::patch('/venues/{venue}', [VenueController::class, 'update']);
        Route::delete('/venues/{venue}', [VenueController::class, 'destroy'])->name('venues.destroy');
    });

    // Match detail: members are redirected to the public event page; MDs/admins get the management view.
    Route::get('/matches/{match}', [MatchController::class, 'show'])->name('matches.show');

    // Match Director + Admin + Owner (+ Developer)
    Route::middleware(['role:developer|exco|owner|admin|match_director'])->group(function (): void {
        Route::resource('matches', MatchController::class)->except(['destroy', 'show']);
        Route::get('/matches/{match}/export-impact-scoring', [MatchController::class, 'exportImpactScoringCsv'])->name('matches.export-impact-scoring');
        Route::post('/matches/{match}/expenses', [MatchExpenseController::class, 'store'])->name('match-expenses.store');
        Route::put('/matches/{match}/expenses/{expense}', [MatchExpenseController::class, 'update'])->name('match-expenses.update');
        Route::delete('/matches/{match}/expenses/{expense}', [MatchExpenseController::class, 'destroy'])->name('match-expenses.destroy');
        Route::get('/score-imports/template', [ScoreImportController::class, 'template'])->name('score-imports.template');
        Route::resource('score-imports', ScoreImportController::class)
            ->only(['index', 'create', 'store', 'show'])
            ->names('score-imports');
        Route::get('/scores', [ScoreController::class, 'index'])->name('scores.index');
        Route::get('/scores/{score}', [ScoreController::class, 'show'])->name('scores.show');
        Route::get('/matches/{match}/scores/entry', [ScoreController::class, 'entry'])->name('scores.entry');
        Route::post('/matches/{match}/scores/entry', [ScoreController::class, 'storeEntry'])->name('scores.entry.store');
    });

    // Provincial admin + Admin + Owner + Developer (read-only member view)
    Route::middleware(['role:developer|exco|provincial_admin|owner|admin'])->group(function (): void {
        Route::get('/provincial-members', [ProvincialMembersController::class, 'index'])
            ->name('provincial-members.index');
        Route::get('/provincial-members/csv', [ProvincialMembersController::class, 'downloadCsv'])
            ->name('provincial-members.csv');
    });

    // Reports (Admin + Owner)
    Route::middleware(['role:developer|exco|owner|admin'])->prefix('reports')->name('reports.')->group(function (): void {
        Route::get('/', [ReportsController::class, 'index'])->name('index');
        Route::get('/sponsorship', [ReportsController::class, 'sponsorship'])->name('sponsorship');
        Route::get('/sponsorship/export', [ReportsController::class, 'sponsorshipExport'])->name('sponsorship.export');
        Route::get('/selection', [ReportsController::class, 'selection'])->name('selection');
        Route::get('/selection/export', [ReportsController::class, 'selectionExport'])->name('selection.export');
        Route::get('/participation', [ReportsController::class, 'participation'])->name('participation');
        Route::get('/participation/export', [ReportsController::class, 'participationExport'])->name('participation.export');
    });

    // Admin + Owner
    Route::middleware(['role:developer|exco|owner|admin'])->group(function (): void {
        Route::get('/admin/approvals', [ApprovalController::class, 'index'])->name('approvals.index');
        Route::post('/admin/approvals/{type}/{id}/approve', [ApprovalController::class, 'approve'])->name('approvals.approve');
        Route::post('/admin/approvals/{type}/{id}/reject', [ApprovalController::class, 'reject'])->name('approvals.reject');

        Route::post('/scores/{score}/override', [ScoreController::class, 'override'])->name('scores.override');
        Route::get('/memberships/export/csv', [MembershipController::class, 'downloadCsv'])->name('memberships.csv');
        Route::post('/memberships/invite-pending', [MembershipController::class, 'invitePending'])->name('memberships.invite-pending');
        Route::resource('memberships', MembershipController::class)->except(['destroy']);
        Route::delete('/memberships/{membership}', [MembershipController::class, 'destroy'])->name('memberships.destroy');
        Route::post('/memberships/{membership}/revoke', [MembershipController::class, 'revoke'])->name('memberships.revoke');
        Route::post('/memberships/{membership}/reinstate', [MembershipController::class, 'reinstate'])->name('memberships.reinstate');
        Route::post('/memberships/{membership}/invite', [MembershipController::class, 'invite'])->name('memberships.invite');
        Route::put('/registrations/{registration}/status', [RegistrationController::class, 'updateStatus'])->name('registrations.update-status');
        Route::resource('audit-logs', AuditLogController::class)
            ->only(['index', 'show'])
            ->names('audit-logs');
        Route::resource('sponsors', SponsorController::class)->except(['show']);
    });

    // Financials (Admin + Owner)
    Route::middleware(['role:developer|exco|owner|admin'])->prefix('financials')->name('financials.')->group(function (): void {
        Route::get('/', [FinancialController::class, 'dashboard'])->name('dashboard');
        Route::get('/match/{match}', [FinancialController::class, 'matchReport'])->name('match-report');
        Route::get('/payouts', [FinancialController::class, 'payouts'])->name('payouts');
        Route::get('/payouts/create', [FinancialController::class, 'createPayout'])->name('payouts.create');
        Route::post('/payouts', [FinancialController::class, 'storePayout'])->name('payouts.store');
        Route::post('/payouts/{payout}/mark-paid', [FinancialController::class, 'markPaid'])->name('payouts.mark-paid');
        Route::get('/transactions', [FinancialController::class, 'transactions'])->name('transactions');

        Route::get('/expenses', [FinancialController::class, 'expenses'])->name('expenses');
        Route::get('/expenses/create', [FinancialController::class, 'createExpense'])->name('expenses.create');
        Route::post('/expenses', [FinancialController::class, 'storeExpense'])->name('expenses.store');
        Route::get('/expenses/{expense}/edit', [FinancialController::class, 'editExpense'])->name('expenses.edit');
        Route::put('/expenses/{expense}', [FinancialController::class, 'updateExpense'])->name('expenses.update');
        Route::delete('/expenses/{expense}', [FinancialController::class, 'destroyExpense'])->name('expenses.destroy');

        Route::get('/income', [FinancialController::class, 'income'])->name('income');
        Route::get('/income/create', [FinancialController::class, 'createIncome'])->name('income.create');
        Route::post('/income', [FinancialController::class, 'storeIncome'])->name('income.store');
        Route::get('/income/{income}/edit', [FinancialController::class, 'editIncome'])->name('income.edit')->whereNumber('income');
        Route::put('/income/{income}', [FinancialController::class, 'updateIncome'])->name('income.update')->whereNumber('income');
        Route::delete('/income/{income}', [FinancialController::class, 'destroyIncome'])->name('income.destroy')->whereNumber('income');

        Route::get('/export/summary-csv', [FinancialController::class, 'exportDashboardCsv'])->name('export.dashboard-csv');
        Route::get('/export/summary-pdf', [FinancialController::class, 'exportDashboardPdf'])->name('export.dashboard-pdf');
        Route::get('/export/matches-csv', [FinancialController::class, 'exportMatchesCsv'])->name('export.matches-csv');
        Route::get('/export/payouts-csv', [FinancialController::class, 'exportPayoutsCsv'])->name('export.payouts-csv');
    });

    // Owner only
    Route::middleware(['role:developer|exco|owner'])->group(function (): void {
        Route::get('/site-settings', [SiteSettingsController::class, 'index'])->name('site-settings.index');
        Route::put('/site-settings', [SiteSettingsController::class, 'update'])->name('site-settings.update');

        Route::get('/user-management', [UserManagementController::class, 'index'])->name('user-management.index');
        Route::get('/user-management/{user}/edit', [UserManagementController::class, 'edit'])->name('user-management.edit');
        Route::put('/user-management/{user}/role', [UserManagementController::class, 'updateRole'])->name('user-management.update-role');
        Route::put('/user-management/{user}/membership', [UserManagementController::class, 'updateMembership'])->name('user-management.update-membership');
        Route::delete('/user-management/{user}', [UserManagementController::class, 'destroy'])->name('user-management.destroy');
        Route::post('/user-management/{id}/restore', [UserManagementController::class, 'restore'])->name('user-management.restore');
        Route::get('/user-management/{id}/confirm-delete', [UserManagementController::class, 'confirmForceDelete'])->name('user-management.confirm-force-delete');
        Route::delete('/user-management/{id}/force-delete', [UserManagementController::class, 'forceDelete'])->name('user-management.force-delete');

        Route::resource('qualification-rules', QualificationRuleController::class)
            ->except(['destroy', 'show'])
            ->names('qualification-rules');

        Route::resource('sponsor-tiers', SponsorTierController::class)
            ->except(['show', 'destroy'])
            ->names('sponsor-tiers');

        Route::resource('divisions', DivisionController::class)
            ->except(['show', 'destroy'])
            ->names('divisions');

        Route::get('/sascoc-report', [SascocReportController::class, 'index'])->name('sascoc-report.index');
        Route::get('/sascoc-report/excel', [SascocReportController::class, 'downloadExcel'])->name('sascoc-report.excel');
        Route::get('/sascoc-report/pdf', [SascocReportController::class, 'downloadPdf'])->name('sascoc-report.pdf');

        Route::resource('provincial-committees', ProvincialCommitteeController::class)
            ->names('provincial-committees');
    });

    // Developer (sysadmin tools)
    Route::middleware(['role:developer|exco|owner'])->prefix('developer')->name('developer.')->group(function (): void {
        Route::get('/mail', [\App\Http\Controllers\Developer\MailSettingsController::class, 'index'])->name('mail.index');
        Route::put('/mail', [\App\Http\Controllers\Developer\MailSettingsController::class, 'update'])->name('mail.update');
        Route::post('/mail/test', [\App\Http\Controllers\Developer\MailSettingsController::class, 'test'])->name('mail.test');

        Route::get('/backups', [\App\Http\Controllers\Developer\BackupController::class, 'index'])->name('backups.index');
        Route::post('/backups', [\App\Http\Controllers\Developer\BackupController::class, 'store'])->name('backups.store');
        Route::get('/backups/download/{disk}/{path}', [\App\Http\Controllers\Developer\BackupController::class, 'download'])
            ->where('path', '.*')->name('backups.download');
    });
});
