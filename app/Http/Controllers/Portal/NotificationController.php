<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The inbox that makes the database notification channel real.
 *
 * Every notification in the system already writes to `notifications`; until this
 * screen existed, that half of every dispatch went nowhere a person could read
 * it. A channel nobody can read is a feature that only exists in the database.
 */
class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        $user = $this->user($request);

        return view('portal.notifications', [
            // Scoped by the notifiable relationship, so there is no path here
            // that could surface another account's messages.
            'notifications' => $user->notifications()->paginate(20),
            'unread' => $user->unreadNotifications()->count(),
            'timezone' => config('pisfa.business_timezone', 'Africa/Kampala'),
        ]);
    }

    /** Marks one message read and follows its link, if it has one. */
    public function read(Request $request, string $notification): RedirectResponse
    {
        $user = $this->user($request);

        $record = $user->notifications()->whereKey($notification)->firstOrFail();

        if ($record->read_at === null) {
            $record->markAsRead();
        }

        $data = (array) $record->data;
        $url = is_string($data['url'] ?? null) ? $data['url'] : null;

        // Only a URL this application generated is followed. A stored value
        // pointing elsewhere would turn the inbox into an open redirect.
        if ($url !== null && str_starts_with($url, rtrim((string) config('app.url'), '/'))) {
            return redirect()->away($url);
        }

        return redirect()->route('portal.notifications.index');
    }

    public function readAll(Request $request): RedirectResponse
    {
        $this->user($request)->unreadNotifications->markAsRead();

        return redirect()
            ->route('portal.notifications.index')
            ->with('success', 'All messages were marked as read.');
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
