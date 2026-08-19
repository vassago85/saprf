<?php

use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\CommunicationsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FamilyController;
use App\Http\Controllers\MatchAnnouncementController;
use App\Http\Controllers\MatchController;
use App\Http\Controllers\MemberSearchController;
use App\Http\Controllers\MembershipController;
use App\Http\Controllers\MembershipFeeTierController;
use App\Http\Controllers\NotificationPreferencesController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PushSubscriptionController;
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

// Homepage — installed PWA launches with ?source=pwa (see manifest start_url).
// Send authenticated members straight to the app shell so an installed launch
// does not land on the guest marketing page.
Route::get('/', function () {
    if (auth()->check() && request()->query('source') === 'pwa') {
        return redirect()->route('dashboard');
    }

    return view('welcome');
})->name('home');

Route::get('/sitemap.xml', \App\Http\Controllers\SitemapController::class)->name('sitemap');
Route::get('/llms.txt', [\App\Http\Controllers\LlmsTxtController::class, 'index'])->name('llms');
Route::get('/llms-full.txt', [\App\Http\Controllers\LlmsTxtController::class, 'full'])->name('llms.full');
// Legal + governance documents are served by a controller so we can render
// the verbatim MD source under docs/legal/ and, for the T&Cs, inject the
// current membership-fee liability cap.
Route::get('/privacy', [\App\Http\Controllers\LegalController::class, 'privacy'])->name('legal.privacy');
Route::get('/terms', [\App\Http\Controllers\LegalController::class, 'terms'])->name('legal.terms');
Route::get('/code-of-conduct', [\App\Http\Controllers\LegalController::class, 'codeOfConduct'])->name('legal.code-of-conduct');
Route::get('/conflict-of-interest', [\App\Http\Controllers\LegalController::class, 'conflictOfInterest'])->name('legal.conflict-of-interest');
Route::get('/constitution', [\App\Http\Controllers\LegalController::class, 'constitution'])->name('legal.constitution');

// Sport rulebooks — rendered via the same MarkdownDocument pipeline as the
// legal docs so they share the sticky ToC / clause gutter / print chrome.
// The authoritative signed PDFs still live under public/publications/ and
// are linked from each page's header.
// Nested under /rules/ deliberately — the admin panel already owns /divisions
// via Route::resource('divisions', DivisionController::class), so the public
// pages get a /rules/ prefix to avoid the URL collision.
Route::get('/rules', [\App\Http\Controllers\RulesController::class, 'rulesAndRegulations'])->name('rules.regulations');
Route::get('/rules/divisions', [\App\Http\Controllers\RulesController::class, 'divisions'])->name('rules.divisions');
Route::get('/rules/pr22-rimfire', [\App\Http\Controllers\RulesController::class, 'pr22RimfireSeries'])->name('rules.pr22-rimfire');

// Public FAQ. Markdown source at docs/faq.md; controller splits on H2 for accordion rendering.
Route::get('/faq', [\App\Http\Controllers\FaqController::class, 'index'])->name('faq.index');

// Public contact form (with honeypot + time-trap in the controller).
// Deliberately unauthenticated so anyone can reach the federation.
Route::get('/contact', [\App\Http\Controllers\ContactController::class, 'create'])->name('contact.create');
Route::post('/contact', [\App\Http\Controllers\ContactController::class, 'store'])->name('contact.store');
Route::get('/contact/thanks', [\App\Http\Controllers\ContactController::class, 'thanks'])->name('contact.thanks');
Route::get('/events', [MatchController::class, 'publicIndex'])->name('events.index');
Route::get('/events/{match}', [MatchController::class, 'publicShow'])->name('events.show');
Route::get('/standings', [StandingController::class, 'publicIndex'])->name('standings.public');
Route::get('/standings/{season}/shooter/{user}', [StandingController::class, 'publicShooter'])->name('standings.shooter');
Route::get('/verify/{saprfNumber}', [MembershipController::class, 'verify'])->name('membership.verify');

// Public verbatim publication of the SAPRF selection policy (current
// season by default, historical seasons via the optional second segment).
Route::get('/selection/{series}-policy/{season?}', [\App\Http\Controllers\Selection\PublicSelectionPolicyController::class, 'show'])
    ->where('series', 'pr22|prs')
    ->where('season', '[0-9]{4}')
    ->name('selection.policy.public');

// Public Documents landing page — a directory of every SAPRF-published
// policy, selection process and legal document. Unauth so anyone can find
// governance material without needing to know the individual URLs. Ships
// with a cross-document search at /documents/search?q=…
Route::get('/documents', [\App\Http\Controllers\DocumentsController::class, 'index'])
    ->name('documents.index');
Route::get('/documents/search', [\App\Http\Controllers\DocumentsController::class, 'search'])
    ->name('documents.search');

// ── PayFast ITN Webhook (no auth / session / CSRF — PayFast POSTs here) ──
Route::post('/webhooks/payfast', [PaymentController::class, 'notify'])
    ->name('payments.notify')
    ->withoutMiddleware([
        \Illuminate\Cookie\Middleware\EncryptCookies::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
        \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
        \App\Http\Middleware\ForcePasswordChange::class,
    ]);

// ── Mailgun webhook (delivered / failed / complained events) ──
// No auth / session / CSRF — Mailgun POSTs here from its own servers.
// The controller itself verifies the HMAC-SHA256 signature on every
// request and rejects anything with a bad signature or stale timestamp.
Route::post('/webhooks/mailgun', [\App\Http\Controllers\MailgunWebhookController::class, 'handle'])
    ->name('webhooks.mailgun')
    ->withoutMiddleware([
        \Illuminate\Cookie\Middleware\EncryptCookies::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
        \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
        \App\Http\Middleware\ForcePasswordChange::class,
    ]);

// ── RFC 8058 one-click email unsubscribe ──
// Signed URL, no session. Gmail POSTs here directly when a user clicks
// its built-in "Unsubscribe" link on a message that carries our
// List-Unsubscribe / List-Unsubscribe-Post headers. GET is supported
// so the same link works when a human clicks it in the message body.
Route::match(['GET', 'POST'], '/email/unsubscribe/{user}', [\App\Http\Controllers\EmailUnsubscribeController::class, 'handle'])
    ->middleware('signed')
    ->name('email.unsubscribe')
    ->withoutMiddleware([
        \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
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

// PWA Web Push — routes are auth+verified only (NOT gated by profile.complete)
// so users landing on the profile page with incomplete SASCOC fields can
// still toggle push on/off from the notification-preferences form.
Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('/push/vapid-public-key', [PushSubscriptionController::class, 'vapidKey'])->name('push.vapid-key');
    Route::post('/push/subscribe', [PushSubscriptionController::class, 'store'])->name('push.subscribe');
    Route::delete('/push/subscribe', [PushSubscriptionController::class, 'destroy'])->name('push.unsubscribe');
    Route::post('/push/test', [PushSubscriptionController::class, 'test'])->name('push.test');
});

Route::middleware(['auth', 'verified', 'profile.complete'])->group(function (): void {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::post('/dashboard/view-mode', [DashboardController::class, 'switchViewMode'])->name('dashboard.view-mode');

        Route::get('/profile', [ProfileController::class, 'edit'])->name('profile');
        Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::put('/profile/notification-preferences', [NotificationPreferencesController::class, 'update'])
            ->name('profile.notification-preferences.update');

    // Family / Managed Junior Accounts
    Route::prefix('family')->name('family.')->group(function (): void {
        Route::get('/', [FamilyController::class, 'index'])->name('index');
        Route::get('/add', [FamilyController::class, 'create'])->name('create');
        Route::post('/', [FamilyController::class, 'store'])->name('store');
        Route::get('/{junior}', [FamilyController::class, 'show'])->name('show');
        Route::get('/{junior}/edit', [FamilyController::class, 'edit'])->name('edit');
        Route::put('/{junior}', [FamilyController::class, 'update'])->name('update');
        Route::delete('/{junior}', [FamilyController::class, 'destroy'])->name('destroy');
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
    // Sponsor search: look up another member by name or SAPRF number to enter
    // or pay for them on this match. Auth-only; no PII beyond name/number.
    Route::get('/events/{match}/members/search', [MemberSearchController::class, 'search'])->name('events.members.search');

    // ── Notification Centre — member archive ──
    Route::get('/communications', [CommunicationsController::class, 'index'])->name('communications.index');
    Route::get('/communications/unread-count', [CommunicationsController::class, 'unreadCount'])->name('communications.unread-count');
    Route::get('/communications/{announcement}', [CommunicationsController::class, 'show'])->name('communications.show');
    Route::post('/communications/{announcement}/acknowledge', [CommunicationsController::class, 'acknowledge'])->name('communications.acknowledge');
    Route::get('/communications/{announcement}/attachments/{attachment}', [CommunicationsController::class, 'attachment'])->name('communications.attachment');

    // ── Notification Centre — Exco / Chair compose + admin ──
    Route::middleware(['role:developer|exco|chair'])->group(function (): void {
        Route::get('/announcements', [AnnouncementController::class, 'index'])->name('announcements.index');
        Route::get('/announcements/create', [AnnouncementController::class, 'create'])->name('announcements.create');
        Route::post('/announcements', [AnnouncementController::class, 'store'])->name('announcements.store');
        Route::post('/announcements/preview', [AnnouncementController::class, 'preview'])->name('announcements.preview');
        Route::get('/announcements/{announcement}', [AnnouncementController::class, 'show'])->name('announcements.show');
        Route::get('/announcements/{announcement}/attachments/{attachment}', [AnnouncementController::class, 'attachment'])->name('announcements.attachment');
        Route::delete('/announcements/{announcement}/attachments/{attachment}', [AnnouncementController::class, 'destroyAttachment'])->name('announcements.attachment.destroy');
        Route::get('/announcements/{announcement}/outstanding-acknowledgements.csv', [AnnouncementController::class, 'outstandingAcknowledgementsCsv'])->name('announcements.outstanding-csv');
        Route::post('/announcements/{announcement}/send', [AnnouncementController::class, 'send'])->name('announcements.send');
        Route::post('/announcements/{announcement}/approve', [AnnouncementController::class, 'approve'])->name('announcements.approve');
        Route::post('/announcements/{announcement}/cancel', [AnnouncementController::class, 'cancel'])->name('announcements.cancel');
        Route::post('/announcements/{announcement}/retract', [AnnouncementController::class, 'retract'])->name('announcements.retract');
        Route::delete('/announcements/{announcement}', [AnnouncementController::class, 'destroy'])->name('announcements.destroy');

        // Reusable audience rule sets — Exco-only CRUD; resolver expands
        // saved_list rules embedded on any announcement at send time.
        Route::get('/saved-lists', [\App\Http\Controllers\SavedDistributionListController::class, 'index'])->name('saved-lists.index');
        Route::get('/saved-lists/create', [\App\Http\Controllers\SavedDistributionListController::class, 'create'])->name('saved-lists.create');
        Route::post('/saved-lists', [\App\Http\Controllers\SavedDistributionListController::class, 'store'])->name('saved-lists.store');
        Route::post('/saved-lists/preview', [\App\Http\Controllers\SavedDistributionListController::class, 'preview'])->name('saved-lists.preview');
        Route::get('/saved-lists/{savedList}/edit', [\App\Http\Controllers\SavedDistributionListController::class, 'edit'])->name('saved-lists.edit');
        Route::put('/saved-lists/{savedList}', [\App\Http\Controllers\SavedDistributionListController::class, 'update'])->name('saved-lists.update');
        Route::delete('/saved-lists/{savedList}', [\App\Http\Controllers\SavedDistributionListController::class, 'destroy'])->name('saved-lists.destroy');
    });

    // Payments
    Route::get('/payments/{payment}/redirect', [PaymentController::class, 'redirect'])->name('payments.redirect');
    Route::get('/payments/{payment}/status', [PaymentController::class, 'status'])->name('payments.status');
    Route::get('/payments/return', [PaymentController::class, 'returnFromGateway'])->name('payments.return');
    Route::get('/payments/cancel', [PaymentController::class, 'cancel'])->name('payments.cancel');
    Route::post('/payments/membership/{membership}', [PaymentController::class, 'payMembership'])->name('payments.membership');
    Route::post('/payments/registration/{registration}', [PaymentController::class, 'payRegistration'])->name('payments.registration');
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
    Route::get('/matches/{match}', [MatchController::class, 'show'])->name('matches.show')->whereNumber('match');

    // Match Director + Admin + Owner (+ Developer)
    Route::middleware(['role:developer|exco|owner|admin|match_director'])->group(function (): void {
        Route::resource('matches', MatchController::class)->except(['destroy', 'show']);
        Route::get('/matches/{match}/export-impact-scoring', [MatchController::class, 'exportImpactScoringCsv'])->name('matches.export-impact-scoring');
        // MD-side "add shooter" action from the match edit page. Seeds a
        // confirmed + paid entry without touching PayFast; used when the
        // fee was collected off-platform (cash, EFT, comp'd).
        Route::post('/matches/{match}/entries', [MatchController::class, 'storeAdminEntry'])->name('matches.entries.store');
        // MD broadcast to entrants on the match's entry list. Both actions
        // re-authorize via MatchPolicy::update so a match_director without
        // ownership of the match hits 403 even if the middleware lets them in.
        Route::get('/matches/{match}/announcements/create', [MatchAnnouncementController::class, 'create'])
            ->name('matches.announcements.create');
        Route::post('/matches/{match}/announcements', [MatchAnnouncementController::class, 'store'])
            ->name('matches.announcements.store');
        Route::post('/matches/{match}/expenses', [MatchExpenseController::class, 'store'])->name('match-expenses.store');
        Route::put('/matches/{match}/expenses/{expense}', [MatchExpenseController::class, 'update'])->name('match-expenses.update');
        Route::delete('/matches/{match}/expenses/{expense}', [MatchExpenseController::class, 'destroy'])->name('match-expenses.destroy');
        // Score-upload success page offers "complete match & request MD payout"
        // in a single click. Authorization is re-checked in the controller via
        // MatchPolicy::update so an MD can only complete their own match.
        Route::post('/matches/{match}/complete-and-request-payout', [MatchController::class, 'completeAndRequestPayout'])
            ->name('matches.complete-and-request-payout');
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
        Route::post('/memberships/{membership}/reset-password', [MembershipController::class, 'resetPassword'])->name('memberships.reset-password');
        Route::put('/registrations/{registration}/status', [RegistrationController::class, 'updateStatus'])->name('registrations.update-status');
        Route::resource('audit-logs', AuditLogController::class)
            ->only(['index', 'show'])
            ->names('audit-logs');
        Route::post('/email-logs/dismiss-queued', [\App\Http\Controllers\EmailLogController::class, 'dismissQueued'])
            ->name('email-logs.dismiss-queued');
        Route::post('/email-logs/{emailLog}/dismiss', [\App\Http\Controllers\EmailLogController::class, 'dismiss'])
            ->name('email-logs.dismiss');
        Route::post('/email-logs/{emailLog}/resend', [\App\Http\Controllers\EmailLogController::class, 'resend'])
            ->name('email-logs.resend');
        Route::resource('email-logs', \App\Http\Controllers\EmailLogController::class)
            ->only(['index', 'show'])
            ->parameters(['email-logs' => 'emailLog'])
            ->names('email-logs');
        Route::resource('sponsors', SponsorController::class)->except(['show']);

        // Shooting clubs — master list, recognition toggle, merge tool.
        // Recognition drives IPRF ELG-03 / ELG-05 checks.
        Route::get('/clubs', [\App\Http\Controllers\ClubController::class, 'index'])->name('clubs.index');
        Route::get('/clubs/create', [\App\Http\Controllers\ClubController::class, 'create'])->name('clubs.create');
        Route::post('/clubs', [\App\Http\Controllers\ClubController::class, 'store'])->name('clubs.store');
        Route::get('/clubs/{club}/edit', [\App\Http\Controllers\ClubController::class, 'edit'])->name('clubs.edit');
        Route::put('/clubs/{club}', [\App\Http\Controllers\ClubController::class, 'update'])->name('clubs.update');
        Route::post('/clubs/{club}/toggle-recognition', [\App\Http\Controllers\ClubController::class, 'toggleRecognition'])->name('clubs.toggle-recognition');
        Route::get('/clubs/{club}/merge', [\App\Http\Controllers\ClubController::class, 'mergeForm'])->name('clubs.merge-form');
        Route::post('/clubs/{club}/merge', [\App\Http\Controllers\ClubController::class, 'merge'])->name('clubs.merge');
        Route::delete('/clubs/{club}', [\App\Http\Controllers\ClubController::class, 'destroy'])->name('clubs.destroy');

        // Public /contact form submissions — triage inbox for admins.
        Route::get('/contact-messages', [\App\Http\Controllers\ContactController::class, 'index'])->name('contact-messages.index');
        Route::get('/contact-messages/{contactMessage}', [\App\Http\Controllers\ContactController::class, 'show'])->name('contact-messages.show');
        Route::post('/contact-messages/{contactMessage}/mark-handled', [\App\Http\Controllers\ContactController::class, 'markHandled'])->name('contact-messages.mark-handled');
        Route::post('/contact-messages/{contactMessage}/reopen', [\App\Http\Controllers\ContactController::class, 'reopen'])->name('contact-messages.reopen');
    });

    // The per-match report is separately reachable by the match director who
    // created it (so they can see their own P&L), in addition to the finance
    // roles. Authorization is enforced inside the controller.
    Route::get('/financials/match/{match}', [FinancialController::class, 'matchReport'])
        ->name('financials.match-report');

    // Financials (Admin + Owner)
    Route::middleware(['role:developer|exco|owner|admin'])->prefix('financials')->name('financials.')->group(function (): void {
        Route::get('/', [FinancialController::class, 'dashboard'])->name('dashboard');

        // Clear Finance Data — irreversible, developer only.
        Route::middleware('role:developer')->group(function (): void {
            Route::get('/reset', [FinancialController::class, 'confirmReset'])->name('reset');
            Route::post('/reset', [FinancialController::class, 'reset'])->name('reset.perform');
        });
        Route::get('/payouts', [FinancialController::class, 'payouts'])->name('payouts');
        Route::get('/payouts/create', [FinancialController::class, 'createPayout'])->name('payouts.create');
        Route::post('/payouts', [FinancialController::class, 'storePayout'])->name('payouts.store');
        Route::get('/payouts/platform/create', [FinancialController::class, 'createPlatformPayout'])->name('payouts.platform.create');
        Route::post('/payouts/platform', [FinancialController::class, 'storePlatformPayout'])->name('payouts.platform.store');
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
        Route::post('/site-settings/test-email', [SiteSettingsController::class, 'sendTestEmail'])->name('site-settings.test-email');

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

        Route::resource('fees', MembershipFeeTierController::class)
            ->parameters(['fees' => 'fee'])
            ->except(['show'])
            ->names('fees');

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

    // Shooter-facing IPRF dashboard. Any authenticated + verified member can
    // reach this to opt into an open cycle, complete the combined DEC-01 /
    // Eligibility-to-Compete form, and see their live ELG / PART status. The
    // staff-only subsystem below is a separate group.
    Route::prefix('iprf')->name('iprf.')->group(function (): void {
        Route::get('/', [\App\Http\Controllers\Selection\ShooterSelectionController::class, 'index'])->name('index');
        Route::post('/{cycle}/opt-in', [\App\Http\Controllers\Selection\ShooterSelectionController::class, 'optIn'])->name('opt-in');
        Route::post('/{cycle}/withdraw', [\App\Http\Controllers\Selection\ShooterSelectionController::class, 'withdraw'])->name('withdraw');
        Route::post('/{cycle}/form', [\App\Http\Controllers\Selection\ShooterSelectionController::class, 'storeForm'])->name('form');
    });

    // IPRF / national team selection subsystem.
    Route::middleware(['role:developer|exco|owner|admin|iprf_selector'])
        ->prefix('selection')
        ->name('selection.')
        ->group(function (): void {
            Route::get('/cycles', [\App\Http\Controllers\Selection\SelectionCycleController::class, 'index'])->name('cycles.index');
            Route::get('/cycles/create', [\App\Http\Controllers\Selection\SelectionCycleController::class, 'create'])->name('cycles.create');
            Route::post('/cycles', [\App\Http\Controllers\Selection\SelectionCycleController::class, 'store'])->name('cycles.store');
            Route::get('/cycles/{cycle}', [\App\Http\Controllers\Selection\SelectionCycleController::class, 'show'])->name('cycles.show');
            Route::get('/cycles/{cycle}/edit', [\App\Http\Controllers\Selection\SelectionCycleController::class, 'edit'])->name('cycles.edit');
            Route::put('/cycles/{cycle}', [\App\Http\Controllers\Selection\SelectionCycleController::class, 'update'])->name('cycles.update');

            Route::post('/cycles/{cycle}/policies', [\App\Http\Controllers\Selection\SelectionPolicyController::class, 'store'])->name('cycles.policies.store');
            Route::get('/cycles/{cycle}/policies/{policy}', [\App\Http\Controllers\Selection\SelectionPolicyController::class, 'show'])->name('cycles.policies.show');

            Route::post('/cycles/{cycle}/reevaluate', [\App\Http\Controllers\Selection\SelectionEvaluationController::class, 'run'])->name('cycles.reevaluate');

            Route::get('/cycles/{cycle}/athletes', [\App\Http\Controllers\Selection\SelectionAthleteController::class, 'index'])->name('cycles.athletes.index');
            Route::get('/cycles/{cycle}/athletes/create', [\App\Http\Controllers\Selection\SelectionAthleteController::class, 'create'])->name('cycles.athletes.create');
            Route::post('/cycles/{cycle}/athletes', [\App\Http\Controllers\Selection\SelectionAthleteController::class, 'store'])->name('cycles.athletes.store');
            Route::post('/cycles/{cycle}/athletes/bulk-register', [\App\Http\Controllers\Selection\SelectionAthleteController::class, 'bulkRegister'])->name('cycles.athletes.bulk-register');
            Route::get('/cycles/{cycle}/athletes/{athlete}', [\App\Http\Controllers\Selection\SelectionAthleteController::class, 'show'])->name('cycles.athletes.show');
            Route::put('/cycles/{cycle}/athletes/{athlete}', [\App\Http\Controllers\Selection\SelectionAthleteController::class, 'update'])->name('cycles.athletes.update');
            Route::post('/cycles/{cycle}/athletes/{athlete}/reevaluate', [\App\Http\Controllers\Selection\SelectionAthleteController::class, 'reevaluate'])->name('cycles.athletes.reevaluate');

            Route::post('/cycles/{cycle}/athletes/{athlete}/declaration', [\App\Http\Controllers\Selection\SelectionDeclarationController::class, 'store'])->name('cycles.athletes.declaration.store');

            Route::post('/cycles/{cycle}/athletes/{athlete}/waivers', [\App\Http\Controllers\Selection\SelectionWaiverController::class, 'store'])->name('cycles.athletes.waivers.store');
            Route::put('/cycles/{cycle}/athletes/{athlete}/waivers/{waiver}', [\App\Http\Controllers\Selection\SelectionWaiverController::class, 'decide'])->name('cycles.athletes.waivers.decide');
            Route::get('/cycles/{cycle}/athletes/{athlete}/waivers/{waiver}/download', [\App\Http\Controllers\Selection\SelectionWaiverController::class, 'download'])->name('cycles.athletes.waivers.download');

            Route::post('/cycles/{cycle}/athletes/{athlete}/appeals', [\App\Http\Controllers\Selection\SelectionAppealController::class, 'store'])->name('cycles.athletes.appeals.store');
            Route::put('/cycles/{cycle}/athletes/{athlete}/appeals/{appeal}', [\App\Http\Controllers\Selection\SelectionAppealController::class, 'decide'])->name('cycles.athletes.appeals.decide');
        });
});
