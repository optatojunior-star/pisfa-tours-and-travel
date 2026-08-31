<?php

namespace App\Http\Controllers\Messaging;

use App\Actions\Messaging\PostMessage;
use App\Actions\Messaging\StartConversation;
use App\Enums\ConversationChannel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Messaging\PostChatMessageRequest;
use App\Http\Requests\Messaging\StartChatRequest;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The website chat widget.
 *
 * Hostinger Premium has no persistent Node process and no self-hosted WebSocket
 * server, so the widget polls `messages` rather than holding a socket open. A
 * reply appears within roughly one poll interval — the honest cost of the
 * hosting constraint, and the reason the interval is configuration rather than
 * a number buried in JavaScript.
 *
 * A guest's claim on a conversation is the signed reference in their session,
 * not the reference itself: references appear in emails and would otherwise let
 * anybody who saw one read the thread.
 */
class ChatController extends Controller
{
    private const SESSION_KEY = 'chat.conversations';

    public function start(StartChatRequest $request, StartConversation $action): JsonResponse
    {
        $conversation = $action->execute(
            $request->user(),
            $request->validated() + ['channel' => ConversationChannel::Web->value],
            (string) $request->validated('idempotency_key'),
        );

        $this->remember($request, $conversation);

        return response()->json([
            'reference' => $conversation->reference,
            'status' => $conversation->status->value,
            'poll_seconds' => (int) config('messaging.chat.poll_seconds', 8),
            'messages' => $this->serialise($this->visibleMessages($conversation)),
        ], 201);
    }

    public function messages(Request $request, string $reference): JsonResponse
    {
        $conversation = $this->authorised($request, $reference);

        $cursorInput = $request->query('after');
        $cursor = is_numeric($cursorInput) ? (int) $cursorInput : null;

        $messages = $this->visibleMessages($conversation, $cursor);

        return response()->json([
            'reference' => $conversation->reference,
            'status' => $conversation->status->value,
            'status_label' => $conversation->status->label(),
            'poll_seconds' => (int) config('messaging.chat.poll_seconds', 8),
            'messages' => $this->serialise($messages),
            'cursor' => $messages->last()?->getKey() ?? $cursor,
        ]);
    }

    public function send(PostChatMessageRequest $request, string $reference, PostMessage $action): JsonResponse
    {
        $conversation = $this->authorised($request, $reference);

        $message = $action->fromCustomer(
            $conversation,
            (string) $request->validated('body'),
            (string) $request->validated('idempotency_key'),
        );

        // The reply is fetched after posting rather than returned bare, so an
        // automatic answer written in the same request arrives with it instead
        // of a poll interval later.
        $messages = $this->visibleMessages($conversation, $message->getKey() - 1);

        return response()->json([
            'reference' => $conversation->reference,
            'messages' => $this->serialise($messages),
            'cursor' => $messages->last()?->getKey() ?? $message->getKey(),
        ], 201);
    }

    /**
     * Resolves a conversation the caller is entitled to read.
     *
     * A signed-in customer owns their own threads. A guest is entitled only to
     * the references their own session opened — 404, not 403, so a probe cannot
     * learn that a reference exists.
     */
    private function authorised(Request $request, string $reference): Conversation
    {
        $user = $request->user();

        $query = Conversation::query()->where('reference', $reference);

        if ($user !== null) {
            $query->where(function ($nested) use ($request, $user): void {
                $nested->where('customer_id', $user->getKey())
                    ->orWhereIn('reference', $this->remembered($request));
            });
        } else {
            $references = $this->remembered($request);

            abort_if($references === [], 404);

            $query->whereIn('reference', $references);
        }

        return $query->firstOrFail();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, ConversationMessage> */
    private function visibleMessages(Conversation $conversation, ?int $after = null)
    {
        return $conversation->messages()
            ->visibleToCustomer()
            ->after($after)
            ->limit((int) config('messaging.chat.page_size', 50))
            ->get();
    }

    /**
     * @param  Collection<int, ConversationMessage>  $messages
     * @return list<array<string, mixed>>
     */
    private function serialise($messages): array
    {
        return $messages->map(static fn (ConversationMessage $message): array => [
            'id' => $message->getKey(),
            'author' => $message->author_type->label(),
            'from_customer' => $message->isFromCustomer(),
            'body' => $message->body,
            'at' => $message->created_at->toIso8601String(),
        ])->values()->all();
    }

    private function remember(Request $request, Conversation $conversation): void
    {
        $references = $this->remembered($request);
        $references[] = $conversation->reference;

        $request->session()->put(self::SESSION_KEY, array_values(array_unique(array_slice($references, -10))));
    }

    /** @return list<string> */
    private function remembered(Request $request): array
    {
        $stored = $request->session()->get(self::SESSION_KEY, []);

        return is_array($stored) ? array_values(array_filter($stored, 'is_string')) : [];
    }
}
