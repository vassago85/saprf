<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MatchController;
use App\Http\Controllers\MembershipController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProvincialCommitteeController;
use App\Http\Controllers\ProvincialMembersController;
use App\Http\Controllers\QualificationRuleController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\AmmoLoadController;
use App\Http\Controllers\RifleConfigurationController;
use App\Http\Controllers\SascocReportController;
use App\Http\Controllers\ScoreController;
use App\Http\Controllers\ScoreImportController;
use App\Http\Controllers\SiteSettingsController;
use App\Http\Controllers\SponsorController;
use App\Http\Controllers\SponsorTierController;
use App\Http\Controllers\StandingController;
use App\Http\Controllers\UserManagementController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

// ── Public Pages ──

Route::view('/', 'welcome');
Route::get('/events', [MatchController::class, 'publicIndex'])->name('events.index');
Route::get('/events/{match}', [MatchController::class, 'publicShow'])->name('events.show');
Route::get('/standings', [StandingController::class, 'publicIndex'])->name('standings.public');
Route::get('/standings/{season}/shooter/{user}', [StandingController::class, 'publicShooter'])->name('standings.shooter');

// ── Auth (guest only) ──

Route::middleware('guest')->group(function (): void {
    Volt::route('/login', 'pages.auth.login')->name('login');
    Volt::route('/register', 'pages.auth.register')->name('register');
});

Route::post('/logout', function () {
    Auth::logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();
    return redirect('/');
})->name('logout')->middleware('auth');

// ── Authenticated ──

Route::middleware(['auth'])->group(function (): void {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');

    // Firearm reference data — user-submitted entries
    Route::post('/api/firearm-makes', [\App\Http\Controllers\FirearmReferenceController::class, 'storeMake'])->name('api.firearm-makes.store');
    Route::post('/api/firearm-models', [\App\Http\Controllers\FirearmReferenceController::class, 'storeModel'])->name('api.firearm-models.store');
    Route::post('/api/firearm-calibres', [\App\Http\Controllers\FirearmReferenceController::class, 'storeCalibre'])->name('api.firearm-calibres.store');

    // Standings (dashboard context — authenticated)
    Route::get('/app/standings', [StandingController::class, 'index'])->name('standings.index');
    Route::get('/app/standings/{series}/{season}', [StandingController::class, 'show'])->name('standings.show');

    // Event Registration (auth required)
    Route::get('/events/{match}/register', [MatchController::class, 'showRegistration'])->name('events.register');
    Route::post('/events/{match}/register', [MatchController::class, 'storeRegistration'])->name('events.register.store');

    // Registrations — any authenticated user can view own / register
    Route::get('/registrations', [RegistrationController::class, 'index'])->name('registrations.index');
    Route::get('/registrations/{registration}', [RegistrationController::class, 'show'])->name('registrations.show');
    Route::post('/registrations', [RegistrationController::class, 'store'])->name('registrations.store');
    Route::post('/registrations/{registration}/withdraw', [RegistrationController::class, 'withdraw'])->name('registrations.withdraw');

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
        Route::resource('matches', MatchController::class)->except(['destroy']);
        Route::get('/matches/{match}/export-impact-scoring', [MatchController::class, 'exportImpactScoringCsv'])->name('matches.export-impact-scoring');
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
    });

    // Admin + Owner
    Route::middleware(['role:owner|admin'])->group(function (): void {
        Route::post('/scores/{score}/override', [ScoreController::class, 'override'])->name('scores.override');
        Route::resource('memberships', MembershipController::class)->except(['destroy']);
        Route::put('/registrations/{registration}/status', [RegistrationController::class, 'updateStatus'])->name('registrations.update-status');
        Route::resource('audit-logs', AuditLogController::class)
            ->only(['index', 'show'])
            ->names('audit-logs');
        Route::resource('sponsors', SponsorController::class)->except(['show']);
    });

    // Owner only
    Route::middleware(['role:owner'])->group(function (): void {
        Route::get('/site-settings', [SiteSettingsController::class, 'index'])->name('site-settings.index');
        Route::put('/site-settings', [SiteSettingsController::class, 'update'])->name('site-settings.update');

        Route::get('/user-management', [UserManagementController::class, 'index'])->name('user-management.index');
        Route::get('/user-management/{user}/edit', [UserManagementController::class, 'edit'])->name('user-management.edit');
        Route::put('/user-management/{user}/role', [UserManagementController::class, 'updateRole'])->name('user-management.update-role');

        Route::resource('qualification-rules', QualificationRuleController::class)
            ->except(['destroy', 'show'])
            ->names('qualification-rules');

        Route::resource('sponsor-tiers', SponsorTierController::class)
            ->except(['show', 'destroy'])
            ->names('sponsor-tiers');

        Route::get('/sascoc-report', [SascocReportController::class, 'index'])->name('sascoc-report.index');
        Route::get('/sascoc-report/excel', [SascocReportController::class, 'downloadExcel'])->name('sascoc-report.excel');
        Route::get('/sascoc-report/pdf', [SascocReportController::class, 'downloadPdf'])->name('sascoc-report.pdf');

        Route::resource('provincial-committees', ProvincialCommitteeController::class)
            ->names('provincial-committees');
    });
});
