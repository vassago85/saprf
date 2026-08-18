<?php

namespace App\Http\Controllers;

use App\Enums\AnnouncementCategory;
use App\Models\Announcement;
use App\Models\AnnouncementRecipient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Member-side of the Notification Centre. Every route here is gated to
 * "you must be on the recipient list for this announcement". Staff still
 * have their own view under AnnouncementController — this one exists so
 * shooters have a permanent archive of what was sent to them.
 */
class CommunicationsController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        $query = AnnouncementRecipient::query()
            ->with('announcement')
            ->where('user_id', $user->id)
            ->whereHas('announcement', function ($q) {
                $q->whereNotNull('sent_at');
            });

        if ($category = $request->input('category')) {
            $query->whereHas('announcement', fn ($q) => $q->where('category', $category));
        }

        if ($request->input('unread') === '1') {
            $query->whereNull('read_at');
        }

        if ($request->input('unread') === '0') {
            $query->whereNotNull('read_at');
        }

        if ($from = $request->input('from')) {
            $query->whereHas('announcement', fn ($q) => $q->whereDate('sent_at', '>=', $from));
        }

        if ($to = $request->input('to')) {
            $query->whereHas('announcement', fn ($q) => $q->whereDate('sent_at', '<=', $to));
        }

        if ($search = trim((string) $request->input('q'))) {
            $query->whereHas('announcement', function ($q) use ($search) {
                $q->where(function ($qq) use ($search) {
                    $qq->where('title', 'like', "%{$search}%")
                        ->orWhere('body', 'like', "%{$search}%");
                });
            });
        }

        $items = $query
            ->join('announcements', 'announcements.id', '=', 'announcement_recipients.announcement_id')
            ->orderByDesc('announcements.sent_at')
            ->select('announcement_recipients.*')
            ->paginate(20)
            ->withQueryString();

        $unreadCount = AnnouncementRecipient::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->count();

        return view('communications.index', [
            'items' => $items,
            'unreadCount' => $unreadCount,
            'categories' => AnnouncementCategory::cases(),
        ]);
    }

    public function show(Request $request, Announcement $announcement): View
    {
        $user = $request->user();

        $recipient = AnnouncementRecipient::query()
            ->where('announcement_id', $announcement->id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $recipient->markRead();

        return view('communications.show', [
            'announcement' => $announcement->load('attachments', 'creator:id,name'),
            'recipient' => $recipient,
        ]);
    }

    public function acknowledge(Request $request, Announcement $announcement): RedirectResponse
    {
        $user = $request->user();

        $recipient = AnnouncementRecipient::query()
            ->where('announcement_id', $announcement->id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        if (! $announcement->requires_acknowledgement) {
            return back()->with('error', 'This announcement does not require an acknowledgement.');
        }

        $recipient->markAcknowledged();

        return back()->with('success', 'Acknowledged. Thank you.');
    }

    public function attachment(Request $request, Announcement $announcement, int $attachmentId): StreamedResponse|Response
    {
        $user = $request->user();

        $isRecipient = AnnouncementRecipient::query()
            ->where('announcement_id', $announcement->id)
            ->where('user_id', $user->id)
            ->exists();

        if (! $isRecipient && ! $user->isExco()) {
            abort(403);
        }

        $attachment = $announcement->attachments()->where('id', $attachmentId)->firstOrFail();

        if (! Storage::disk('announcements')->exists($attachment->path)) {
            abort(404);
        }

        return Storage::disk('announcements')->download(
            $attachment->path,
            $attachment->filename,
            ['Content-Type' => $attachment->mime],
        );
    }

    /**
     * Small polling endpoint the sidebar bell uses to update the unread
     * badge without a full page load. Deliberately cheap: one COUNT query,
     * no ORM hydration.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $user = $request->user();

        $unread = AnnouncementRecipient::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->count();

        $latest = AnnouncementRecipient::query()
            ->with(['announcement:id,title,category,sent_at'])
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(function (AnnouncementRecipient $recipient) {
                $a = $recipient->announcement;
                if (! $a) {
                    return null;
                }

                return [
                    'id' => $a->id,
                    'title' => $a->title,
                    'category' => $a->category?->value,
                    'category_label' => $a->category?->label(),
                    'sent_at' => $a->sent_at?->toDateTimeString(),
                    'url' => route('communications.show', $a),
                ];
            })
            ->filter()
            ->values();

        return response()->json([
            'unread' => $unread,
            'latest' => $latest,
        ]);
    }
}
