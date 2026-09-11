<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Your notifications.
 *
 * Two views of the same list: the inbox, and everything you have archived out
 * of it. There is no delete — archiving is as far as it goes, and an archived
 * notification stays readable under ?scope=archived indefinitely.
 */
class NotificationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'scope' => ['nullable', Rule::in(['inbox', 'archived', 'all'])],
            'status' => ['nullable', Rule::in(['unread', 'read', 'all'])],
            'category' => ['nullable', Rule::in(Notification::CATEGORIES)],
            'type' => ['nullable', 'string', 'max:60'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $scope = $filters['scope'] ?? 'inbox';
        $status = $filters['status'] ?? 'all';

        $notifications = $this->mine($request)
            ->with('actor')
            ->when($scope === 'inbox', fn ($q) => $q->inbox())
            ->when($scope === 'archived', fn ($q) => $q->archived())
            ->when($status === 'unread', fn ($q) => $q->unread())
            ->when($status === 'read', fn ($q) => $q->whereNotNull('read_at'))
            ->when($filters['category'] ?? null, fn ($q, $c) => $q->where('category', $c))
            ->when($filters['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            // By when it happened, not by insertion order — a backfilled or
            // backdated row should still sit under the date it is shown with.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return NotificationResource::collection($notifications)
            ->additional(['summary' => $this->counts($request)]);
    }

    /** What the bell needs: one small call, safe to poll. */
    public function summary(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->counts($request)]);
    }

    public function markRead(Request $request, Notification $notification): JsonResponse
    {
        $this->assertOwner($request, $notification);
        $notification->markRead();

        return $this->one($notification);
    }

    public function markUnread(Request $request, Notification $notification): JsonResponse
    {
        $this->assertOwner($request, $notification);
        $notification->markUnread();

        return $this->one($notification);
    }

    public function archive(Request $request, Notification $notification): JsonResponse
    {
        $this->assertOwner($request, $notification);
        $notification->archive();

        return $this->one($notification);
    }

    /** Pull one back out of the archive and into the inbox. */
    public function unarchive(Request $request, Notification $notification): JsonResponse
    {
        $this->assertOwner($request, $notification);
        $notification->unarchive();

        return $this->one($notification);
    }

    public function readAll(Request $request): JsonResponse
    {
        $marked = $this->mine($request)->inbox()->unread()->update(['read_at' => now()]);

        return response()->json([
            'message' => $marked === 1 ? '1 notification marked as read.' : "{$marked} notifications marked as read.",
            'data' => $this->counts($request),
        ]);
    }

    /**
     * Clear the inbox. `only_read=1` leaves anything still unread in place,
     * which is the safer default for a "tidy up" button.
     */
    public function archiveAll(Request $request): JsonResponse
    {
        $now = now();

        $ids = $this->mine($request)
            ->inbox()
            ->when($request->boolean('only_read'), fn ($q) => $q->whereNotNull('read_at'))
            ->pluck('id');

        // Filing something away implies you have seen it, so anything still
        // unread is marked read on the way out rather than archived unread.
        Notification::whereIn('id', $ids)->unread()->update(['read_at' => $now]);
        $archived = Notification::whereIn('id', $ids)->update(['archived_at' => $now]);

        return response()->json([
            'message' => $archived === 1 ? '1 notification archived.' : "{$archived} notifications archived.",
            'data' => $this->counts($request),
        ]);
    }

    /** Unread and total counts, overall and per pillar, for badges and tabs. */
    protected function counts(Request $request): array
    {
        $rows = $this->mine($request)
            ->selectRaw('category, COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN read_at IS NULL THEN 1 ELSE 0 END) as unread')
            ->selectRaw('SUM(CASE WHEN archived_at IS NULL THEN 1 ELSE 0 END) as in_inbox')
            ->selectRaw('SUM(CASE WHEN archived_at IS NULL AND read_at IS NULL THEN 1 ELSE 0 END) as inbox_unread')
            ->groupBy('category')
            ->get();

        return [
            'unread' => (int) $rows->sum('inbox_unread'),
            'inbox' => (int) $rows->sum('in_inbox'),
            'archived' => (int) ($rows->sum('total') - $rows->sum('in_inbox')),
            'by_category' => $rows->mapWithKeys(fn ($row) => [
                $row->category => [
                    'unread' => (int) $row->inbox_unread,
                    'inbox' => (int) $row->in_inbox,
                ],
            ])->all(),
        ];
    }

    protected function mine(Request $request)
    {
        return Notification::where('user_id', $request->user()->id);
    }

    protected function one(Notification $notification): JsonResponse
    {
        return response()->json([
            'data' => new NotificationResource($notification->fresh()->load('actor')),
        ]);
    }

    protected function assertOwner(Request $request, Notification $notification): void
    {
        // Deliberately no admin bypass: an inbox is nobody else's to manage.
        if ($notification->user_id !== $request->user()->id) {
            throw new AccessDeniedHttpException('This notification is not yours.');
        }
    }
}
