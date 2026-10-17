<?php

/**
 * Builds a read-only Playwright session and the list of GET pages to open.
 *
 * Refuses to run when APP_ENV is production or APP_URL is not a local host.
 * The only database write is one `sessions` row, removed by the `forget`
 * command when the crawl finishes. It does not log in through the login
 * form, so it does not write an audit row, and it never updates matches,
 * scores, memberships, or payments.
 *
 * Usage:
 *   php scripts/playwright-readonly.php routes
 *   php scripts/playwright-readonly.php session
 *   php scripts/playwright-readonly.php forget <session-id>
 */

use App\Models\Announcement;
use App\Models\AnnouncementAttachment;
use App\Models\AuditLog;
use App\Models\Barrel;
use App\Models\Club;
use App\Models\ContactMessage;
use App\Models\DisciplinaryCase;
use App\Models\DisciplinaryCaseAttachment;
use App\Models\DisciplinaryCaseNote;
use App\Models\Division;
use App\Models\EmailLog;
use App\Models\ExcoAction;
use App\Models\ExcoAgendaItem;
use App\Models\ExcoMeeting;
use App\Models\ExcoMinuteAmendment;
use App\Models\MatchEvent;
use App\Models\MatchExpense;
use App\Models\MatchRegistration;
use App\Models\MediaAlbum;
use App\Models\Membership;
use App\Models\MembershipFeeTier;
use App\Models\Payment;
use App\Models\PlatformExpense;
use App\Models\PlatformIncome;
use App\Models\Province;
use App\Models\QualificationRule;
use App\Models\SavedDistributionList;
use App\Models\Score;
use App\Models\ScoreImport;
use App\Models\SelectionCycle;
use App\Models\Sponsor;
use App\Models\SponsorTier;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

if (app()->environment('production')) {
    fwrite(STDERR, "Refusing to run: APP_ENV is production.\n");
    exit(1);
}

$host = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
$localHost = in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($host, '.test');

if (! $localHost) {
    fwrite(STDERR, "Refusing to run: APP_URL host [{$host}] is not local.\n");
    exit(1);
}

$command = $argv[1] ?? '';

match ($command) {
    'routes' => dumpRoutes(),
    'session' => dumpSession(),
    'forget' => forgetSession($argv[2] ?? ''),
    default => (function () {
        fwrite(STDERR, "Usage: routes | session | forget <session-id>\n");
        exit(1);
    })(),
};

function dumpRoutes(): void
{
    $visit = [];
    $skipped = [];

    foreach (Route::getRoutes() as $route) {
        if (! in_array('GET', $route->methods(), true)) {
            continue;
        }

        $uri = $route->uri();

        if (shouldSkip($uri)) {
            $skipped[] = ['uri' => $uri, 'reason' => 'writes data or leaves the app'];

            continue;
        }

        $concrete = concreteUri($uri);
        if ($concrete === null) {
            $skipped[] = ['uri' => $uri, 'reason' => 'no local row for a required parameter'];

            continue;
        }

        $visit[] = [
            'name' => $route->getName(),
            'uri' => '/'.ltrim($concrete, '/'),
        ];
    }

    echo json_encode([
        'base_url' => rtrim((string) config('app.url'), '/'),
        'visit' => $visit,
        'skipped' => $skipped,
    ], JSON_UNESCAPED_SLASHES);
}

function dumpSession(): void
{
    $user = null;

    foreach (['owner', 'developer', 'exco', 'admin'] as $role) {
        $user = User::role($role)
            ->whereNotNull('email_verified_at')
            ->where(function ($query) {
                $query->where('must_change_password', false)->orWhereNull('must_change_password');
            })
            ->orderBy('id')
            ->first();

        if ($user) {
            break;
        }
    }

    if (! $user) {
        fwrite(STDERR, "No verified staff account without a forced password change is available.\n");
        exit(1);
    }

    $session = app('session.store');
    $session->start();
    $guard = Auth::guard('web');
    $session->put($guard->getName(), $user->getAuthIdentifier());
    $session->put('password_hash_web', $user->getAuthPassword());
    $session->save();

    $cookieName = (string) config('session.cookie');
    $prefix = CookieValuePrefix::create($cookieName, app('encrypter')->getKey());
    $value = app('encrypter')->encrypt($prefix.$session->getId(), false);

    echo json_encode([
        'cookie' => $cookieName,
        'value' => $value,
        'session_id' => $session->getId(),
        'user_id' => $user->id,
    ]);
}

function forgetSession(string $id): void
{
    if ($id === '' || ! preg_match('/^[A-Za-z0-9]{20,}$/', $id)) {
        fwrite(STDERR, "Refusing to forget a session id that does not look like one we created.\n");
        exit(1);
    }

    $deleted = app('session.store')->getHandler()->destroy($id);

    echo json_encode(['deleted' => (bool) $deleted, 'session_id' => $id]);
}

function shouldSkip(string $uri): bool
{
    $patterns = [
        '#^(impersonate|impersonate-stop)(/|$)#',
        '#confirm-old-site-payment#',
        '#^payments/\{payment\}/redirect$#',
        '#^payments/cancel$#',
        '#^email/verify/#',
        '#unsubscribe#',
        '#handover#',
        '#^(logout|login|register|forgot-password|reset-password|invitation)(/|$)#',
        '#^livewire#',
        '#^sanctum#',
        '#^_#',
        '#^up$#',
        '#^communications/\{announcement\}($|/)#',
        '#^provincial-members/csv$#',
        '#^telescope#',
        '#^horizon#',
        '#^flux(/|$)#',
        '#^build(/|$)#',
        '#^vendor(/|$)#',
        '#\.(css|js|map|png|jpe?g|gif|svg|woff2?|ico)$#',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $uri)) {
            return true;
        }
    }

    return false;
}

function concreteUri(string $uri): ?string
{
    if (! str_contains($uri, '{')) {
        return $uri;
    }

    $out = $uri;

    if (! preg_match_all('/\{([^}]+)\}/', $uri, $matches)) {
        return $uri;
    }

    foreach ($matches[1] as $raw) {
        $optional = str_ends_with($raw, '?');
        $name = explode(':', rtrim($raw, '?'))[0];
        $value = sample($name, $uri);

        if ($value === null || $value === '') {
            if ($optional) {
                $out = preg_replace('#/\{'.preg_quote($raw, '#').'\}#', '', $out, 1) ?? $out;

                continue;
            }

            return null;
        }

        $out = preg_replace('/\{'.preg_quote($raw, '/').'\}/', rawurlencode($value), $out, 1) ?? $out;
    }

    return $out;
}

function sample(string $name, string $uri): ?string
{
    if ($name === 'season' || $name === 'year') {
        return (string) now()->year;
    }

    if ($name === 'series') {
        return 'PRS';
    }

    if ($name === 'saprfNumber') {
        $number = Membership::query()->whereNotNull('saprf_number')->value('saprf_number');

        return $number !== null ? (string) $number : null;
    }

    if ($name === 'album') {
        $slug = MediaAlbum::query()->value('slug');

        return $slug !== null ? (string) $slug : null;
    }

    if ($name === 'attachment' && str_contains($uri, 'disciplinary')) {
        return idOf(DisciplinaryCaseAttachment::class);
    }

    if ($name === 'attachment') {
        return idOf(AnnouncementAttachment::class);
    }

    if ($name === 'expense' && str_contains($uri, 'financials')) {
        return idOf(PlatformExpense::class);
    }

    $model = match ($name) {
        'action' => ExcoAction::class,
        'agendaItem' => ExcoAgendaItem::class,
        'amendment' => ExcoMinuteAmendment::class,
        'ammoLoad' => AmmoLoad::class,
        'ammoString' => AmmoString::class,
        'announcement' => Announcement::class,
        'audit_log', 'auditLog' => AuditLog::class,
        'barrel' => Barrel::class,
        'case' => DisciplinaryCase::class,
        'club' => Club::class,
        'contactMessage', 'contact_message' => ContactMessage::class,
        'cycle', 'selectionCycle', 'selection_cycle' => SelectionCycle::class,
        'division' => Division::class,
        'emailLog', 'email_log' => EmailLog::class,
        'expense' => MatchExpense::class,
        'fee' => MembershipFeeTier::class,
        'income' => PlatformIncome::class,
        'junior', 'user' => User::class,
        'ladderSession', 'ladder_session' => ladderSessionClass(),
        'match' => MatchEvent::class,
        'meeting' => ExcoMeeting::class,
        'membership' => Membership::class,
        'note' => DisciplinaryCaseNote::class,
        'payment' => Payment::class,
        'province' => Province::class,
        'qualification_rule', 'qualificationRule' => QualificationRule::class,
        'registration' => MatchRegistration::class,
        'rifleConfiguration', 'rifle_configuration' => rifleClass(),
        'savedList', 'saved_list' => SavedDistributionList::class,
        'score' => Score::class,
        'scoreImport', 'score_import' => ScoreImport::class,
        'sponsor' => Sponsor::class,
        'sponsor_tier', 'sponsorTier' => SponsorTier::class,
        'venue' => Venue::class,
        default => null,
    };

    return $model ? idOf($model) : null;
}

function ladderSessionClass(): ?string
{
    return class_exists(App\Models\LadderSession::class) ? App\Models\LadderSession::class : null;
}

function rifleClass(): ?string
{
    return class_exists(App\Models\RifleConfiguration::class) ? App\Models\RifleConfiguration::class : null;
}

function idOf(?string $model): ?string
{
    if ($model === null || ! class_exists($model)) {
        return null;
    }

    try {
        $id = $model::query()->value((new $model)->getKeyName());
    } catch (Throwable) {
        return null;
    }

    return $id === null ? null : (string) $id;
}
