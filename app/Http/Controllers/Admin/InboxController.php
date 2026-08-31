<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Messaging\PostMessage;
use App\Actions\Messaging\TransitionConversation;
use App\Enums\ConversationChannel;
use App\Enums\ConversationStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Messaging\MessagingTransportRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class InboxController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Conversation::class);

        $statusInput = $request->query('status');
        $status = is_string($statusInput) ? ConversationStatus::tryFrom($statusInput) : null;

        $channelInput = $request->query('channel');
        $channel = is_string($channelInput) ? ConversationChannel::tryFrom($channelInput) : null;

        $query = Conversation::query()
            ->with(['assignee:id,name', 'customer:id,name'])
            ->withCount('messages');

        if ($status !== null) {
            $query->where('status', $status->value);
        } elseif ($request->query('show') !== 'all') {
            // The inbox opens on live work; closed threads are a deliberate
            // choice rather than the default view.
            $query->open();
        }

        if ($channel !== null) {
            $query->where('channel', $channel->value);
        }

        if ($request->query('mine') === '1' && $request->user() !== null) {
            $query->where('assigned_to_user_id', $request->user()->getKey());
        }

        if ($request->query('unassigned') === '1') {
            $query->whereNull('assigned_to_user_id');
        }

        if (filled($request->query('q'))) {
            $query->search((string) $request->query('q'));
        }

        // Oldest unanswered first: the queue's job is to surface who has been
        // waiting longest, not who wrote most recently.
        $query->orderByRaw('case when status = ? then 0 else 1 end', [ConversationStatus::Open->value])
            ->orderBy('last_customer_message_at')
            ->orderByDesc('id');

        return view('admin.inbox.index', [
            'conversations' => $query->paginate(20)->withQueryString(),
            'status' => $status,
            'channel' => $channel,
            'search' => $request->query('q'),
            'showAll' => $request->query('show') === 'all',
            'mine' => $request->query('mine') === '1',
            'unassignedOnly' => $request->query('unassigned') === '1',
            'counts' => $this->counts(),
        ]);
    }

    public function show(Conversation $conversation, MessagingTransportRegistry $transports): View
    {
        $this->authorize('view', $conversation);

        return view('admin.inbox.show', [
            'conversation' => $conversation->load(['customer:id,name,email', 'assignee:id,name', 'subject']),
            'messages' => $conversation->messages()->with('author:id,name')->get(),
            'assignees' => $this->assignees(),
            'nextStatuses' => $conversation->status->allowedTransitions(),
            // The console says plainly when WhatsApp is not wired up on this
            // deployment, rather than accepting a reply that will only fail.
            'canSendOnChannel' => ! $conversation->channel->usesProvider() || $transports->canSend(),
        ]);
    }

    public function reply(Request $request, Conversation $conversation, PostMessage $action): RedirectResponse
    {
        $this->authorize('reply', $conversation);

        $validated = $request->validate([
            'body' => [
                'required',
                'string',
                'min:1',
                'max:'.(int) config('messaging.chat.max_message_length', 2000),
            ],
            'internal_note' => ['nullable', 'boolean'],
        ]);

        $isNote = (bool) ($validated['internal_note'] ?? false);

        $action->fromStaff($request->user(), $conversation, (string) $validated['body'], $isNote);

        return back()->with('success', $isNote ? 'Note added.' : 'Reply sent.');
    }

    public function assign(
        Request $request,
        Conversation $conversation,
        TransitionConversation $action,
    ): RedirectResponse {
        $this->authorize('reply', $conversation);

        $validated = $request->validate([
            'assigned_to_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $assignee = isset($validated['assigned_to_user_id'])
            ? User::query()->whereKey((int) $validated['assigned_to_user_id'])->firstOrFail()
            : null;

        $action->assign($request->user(), $conversation, $assignee);

        return back()->with('success', $assignee === null ? 'Unassigned.' : 'Assigned to '.$assignee->name.'.');
    }

    public function resolve(
        Request $request,
        Conversation $conversation,
        TransitionConversation $action,
    ): RedirectResponse {
        $this->authorize('reply', $conversation);

        $action->resolve($request->user(), $conversation);

        return back()->with('success', 'Marked resolved. It reopens by itself if the customer writes again.');
    }

    public function reopen(
        Request $request,
        Conversation $conversation,
        TransitionConversation $action,
    ): RedirectResponse {
        $this->authorize('reply', $conversation);

        $action->reopen($request->user(), $conversation);

        return back()->with('success', 'Reopened.');
    }

    public function close(
        Request $request,
        Conversation $conversation,
        TransitionConversation $action,
    ): RedirectResponse {
        $this->authorize('reply', $conversation);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        $action->close($request->user(), $conversation, (string) $validated['reason']);

        return back()->with('success', 'Closed. A reply from the customer will start a new conversation.');
    }

    /** @return Collection<int, User> */
    private function assignees(): Collection
    {
        return User::query()
            ->whereIn('role', [UserRole::Staff->value, UserRole::Manager->value, UserRole::SuperAdmin->value])
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $counts = [];

        foreach (ConversationStatus::cases() as $case) {
            $counts[$case->value] = Conversation::query()->where('status', $case->value)->count();
        }

        $counts['unassigned'] = Conversation::query()->open()->whereNull('assigned_to_user_id')->count();

        return $counts;
    }
}
