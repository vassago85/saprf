<?php

use App\Http\Controllers\AuditLogController;
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
use App\Http\Controllers\CategoryController;
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

// ── PayFast ITN Webhook (CSRF-exempt, no auth) ──
Route::post('/webhooks/payfast', [PaymentController::class, 'notify'])->name('payments.notify');

// ── Public Account Handover (junior accepts their account from parent) ──
Route::get('/family/handover/{token}', [FamilyController::class, 'acceptHandover'])->name('family.handover.accept');
Route::post('/family/handover/{token}', [FamilyController::class, 'completeHandover'])->name('family.handover.complete');

// ── Auth (guest only) ──

Route::middleware('guest')->group(function (): void {
    Volt::route('/login', 'pages.auth.login')->name('login');
    Volt::route('/register', 'pages.auth.register')->name('register');
    Volt::route('/forgot-password', 'pages.auth.forgot-password')->name('password.request');
    Volt::route('/reset-password/{token}', 'pages.auth.reset-password')->name('password.reset');
});

Route::post('/logout', function () {
    Auth::logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();
    return redirect('/');
})->name('logout')->middleware('auth');

// ── Email Verification (OTP) ──

Route::middleware('auth')->group(function (): void {
    Volt::route('/verify-email', 'pages.auth.verify-email')->name('verification.notice');
});

// ── Authenticated ──

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

    // Match Director + Admin + Owner
    Route::middleware(['role:owner|admin|match_director'])->group(function (): void {
        Route::resource('venues', VenueController::class)->except(['show']);
        Route::resource('matches', MatchController::class)->except(['destroy']);
        Route::get('/matches/{match}/export-impact-scoring', [MatchController::class, 'exportImpactScoringCsv'])->name('matches.export-impact-scoring');
        Route::post('/matches/{match}/expenses', [MatchExpenseController::class, 'store'])->name('match-expenses.store');
        Route::put('/matches/{match}/expenses/{expense}', [MatchExpenseController::class, 'update'])->name('match-expenses.update');
        Route::delete('/matches/{match}/expenses/{expense}', [MatchExpenseController::class, 'destroy'])->name('match-expenses.destroy');
        Route::resource('score-imports', ScoreImportController::class)
            ->only(['index', 'create', 'store', 'show'])
            ->names('score-imports');
        Route::get('/scores', [ScoreController::class, 'index'])->name('scores.index');
        Route::get('/scores/{score}', [ScoreController::class, 'show'])->name('scores.show');
    });

    // Provincial admin + Admin + Owner (read-only member view)
    Route::middleware(['role:provincial_admin|owner|admin'])->group(function (): void {
        Route::get('/provincial-members', [ProvincialMembersController::class, 'index'])
            ->name('provincial-members.index');
        Route::get('/provincial-members/csv', [ProvincialMembersController::class, 'downloadCsv'])
            ->name('provincial-members.csv');
    });

    // Reports (Admin + Owner)
    Route::middleware(['role:owner|admin'])->prefix('reports')->name('reports.')->group(function (): void {
        Route::get('/', [ReportsController::class, 'index'])->name('index');
        Route::get('/sponsorship', [ReportsController::class, 'sponsorship'])->name('sponsorship');
        Route::get('/sponsorship/export', [ReportsController::class, 'sponsorshipExport'])->name('sponsorship.export');
        Route::get('/selection', [ReportsController::class, 'selection'])->name('selection');
        Route::get('/selection/export', [ReportsController::class, 'selectionExport'])->name('selection.export');
        Route::get('/participation', [ReportsController::class, 'participation'])->name('participation');
        Route::get('/participation/export', [ReportsController::class, 'participationExport'])->name('participation.export');
    });

    // Admin + Owner
    Route::middleware(['role:owner|admin'])->group(function (): void {
        Route::get('/admin/approvals', [ApprovalController::class, 'index'])->name('approvals.index');
        Route::post('/admin/approvals/{type}/{id}/approve', [ApprovalController::class, 'approve'])->name('approvals.approve');
        Route::post('/admin/approvals/{type}/{id}/reject', [ApprovalController::class, 'reject'])->name('approvals.reject');

        Route::post('/scores/{score}/override', [ScoreController::class, 'override'])->name('scores.override');
        Route::resource('memberships', MembershipController::class)->except(['destroy']);
        Route::post('/memberships/{membership}/revoke', [MembershipController::class, 'revoke'])->name('memberships.revoke');
        Route::post('/memberships/{membership}/reinstate', [MembershipController::class, 'reinstate'])->name('memberships.reinstate');
        Route::put('/registrations/{registration}/status', [RegistrationController::class, 'updateStatus'])->name('registrations.update-status');
        Route::resource('audit-logs', AuditLogController::class)
            ->only(['index', 'show'])
            ->names('audit-logs');
        Route::resource('sponsors', SponsorController::class)->except(['show']);
    });

    // Financials (Admin + Owner)
    Route::middleware(['role:owner|admin'])->prefix('financials')->name('financials.')->group(function (): void {
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
    Route::middleware(['role:owner'])->group(function (): void {
        Route::get('/site-settings', [SiteSettingsController::class, 'index'])->name('site-settings.index');
        Route::put('/site-settings', [SiteSettingsController::class, 'update'])->name('site-settings.update');

        Route::get('/user-management', [UserManagementController::class, 'index'])->name('user-management.index');
        Route::get('/user-management/{user}/edit', [UserManagementController::class, 'edit'])->name('user-management.edit');
        Route::put('/user-management/{user}/role', [UserManagementController::class, 'updateRole'])->name('user-management.update-role');
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

        Route::resource('categories', CategoryController::class)
            ->except(['show', 'destroy'])
            ->names('categories');

        Route::get('/sascoc-report', [SascocReportController::class, 'index'])->name('sascoc-report.index');
        Route::get('/sascoc-report/excel', [SascocReportController::class, 'downloadExcel'])->name('sascoc-report.excel');
        Route::get('/sascoc-report/pdf', [SascocReportController::class, 'downloadPdf'])->name('sascoc-report.pdf');

        Route::resource('provincial-committees', ProvincialCommitteeController::class)
            ->names('provincial-committees');
    });
});
