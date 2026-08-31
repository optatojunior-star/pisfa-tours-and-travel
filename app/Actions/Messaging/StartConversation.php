<?php

namespace App\Actions\Messaging;

use App\Enums\AccountStatus;
use App\Enums\ConversationChannel;
use App\Enums\ConversationStatus;
use App\Enums\UserRole;
use App\Models\Conversation;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Opens a thread.
 *
 * Guests are first class: `customer_id` stays null rather than pointing at a
 * placeholder account, and deduplication is keyed to the person writing in so
 * one visitor's retried form cannot collide with another's.
 */
class StartConversation
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly PostMessage $postMessage,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function execute(
        ?User $customer,
        array $attributes,
        string $idempotencyKey,
        ?Model $subject = null,
    ): Conversation {
        $input = $this->validated($customer, $attributes, $idempotencyKey);

        $conversation = DB::transaction(function () use ($customer, $input, $subject): Conversation {
            $lockedCustomer = null;

            if ($customer !== null) {
                $lockedCustomer = User::query()
                    ->whereKey($customer->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedCustomer->status !== AccountStatus::Active
                    || ! $lockedCustomer->hasRole(UserRole::Customer)) {
                    throw new AuthorizationException;
                }
            }

            $existing = Conversation::query()
                ->where('idempotency_owner_hash', $input['idempotency_owner_hash'])
                ->where('idempotency_key', $input['idempotency_key'])
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $conversation = new Conversation;
            $conversation->forceFill(array_merge($input['attributes'], [
                'reference' => 'CHT-'.Str::upper((string) Str::ulid()),
                'customer_id' => $lockedCustomer?->getKey(),
                'status' => ConversationStatus::Open,
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
                'idempotency_owner_hash' => $input['idempotency_owner_hash'],
                'idempotency_key' => $input['idempotency_key'],
            ]))->save();

            $this->auditLogger->record(
                event: 'conversation.started',
                auditable: $conversation,
                newValues: [
                    'reference' => $conversation->reference,
                    'channel' => $conversation->channel->value,
                    'guest' => $conversation->isGuest(),
                    'subject_type' => $conversation->subject_type,
                ],
                user: $lockedCustomer,
            );

            return $conversation;
        }, 3);

        // The opening message is posted through the normal path rather than
        // written here, so it gets the same delivery handling, auto-reply
        // evaluation, and staff notification as every later message. A replayed
        // request finds the conversation above and adds nothing further,
        // because PostMessage is idempotent on its own key.
        if (filled($input['message'])) {
            $this->postMessage->fromCustomer(
                $conversation,
                (string) $input['message'],
                $input['idempotency_key'],
            );
        }

        return $conversation->fresh(['messages', 'customer']) ?? $conversation;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{attributes: array<string, mixed>, message: string|null, idempotency_owner_hash: string, idempotency_key: string}
     */
    private function validated(?User $customer, array $attributes, string $idempotencyKey): array
    {
        if ($customer !== null) {
            // A signed-in customer never supplies their own identity.
            $attributes = array_merge($attributes, [
                'contact_name' => $customer->name,
                'contact_email' => $customer->email,
            ]);
        }

        $attributes['idempotency_key'] = trim($idempotencyKey);

        $validated = Validator::make($attributes, [
            'contact_name' => ['required', 'string', 'min:2', 'max:180'],
            'contact_email' => ['nullable', 'email:rfc', 'max:254'],
            'contact_phone' => ['nullable', 'string', 'max:40', 'regex:/\A\+?[0-9][0-9\s().-]{6,39}\z/'],
            'subject' => ['nullable', 'string', 'max:200'],
            'channel' => ['required', Rule::enum(ConversationChannel::class)],
            'message' => ['nullable', 'string', 'max:'.(int) config('messaging.chat.max_message_length', 2000)],
            'idempotency_key' => ['required', 'uuid'],
        ])->validate();

        $channel = ConversationChannel::from((string) $validated['channel']);
        $phone = Conversation::normalisePhone($validated['contact_phone'] ?? null);

        // A WhatsApp thread with no number is one nobody can answer, so it is
        // refused at the door rather than queued and failed later.
        if ($channel->requiresPhoneNumber() && $phone === null) {
            throw ValidationException::withMessages([
                'contact_phone' => 'A WhatsApp conversation needs a phone number we can reply to.',
            ]);
        }

        // Absent and null are both "not given": an optional field the caller
        // omitted is missing from the validated array entirely.
        $email = filled($validated['contact_email'] ?? null)
            ? mb_strtolower(trim((string) $validated['contact_email']))
            : null;

        // Somebody has to be reachable somehow, or the thread is a dead letter.
        if ($email === null && $phone === null) {
            throw ValidationException::withMessages([
                'contact_email' => 'Leave an email address or a phone number so we can reply.',
            ]);
        }

        $owner = $customer === null
            ? 'guest|'.($email ?? '').'|'.($phone ?? '')
            : 'customer|'.$customer->getKey();

        return [
            'attributes' => [
                'contact_name' => trim((string) $validated['contact_name']),
                'contact_email' => $email,
                'contact_phone' => $phone,
                'subject' => filled($validated['subject'] ?? null) ? trim((string) $validated['subject']) : null,
                'channel' => $channel,
            ],
            'message' => filled($validated['message'] ?? null) ? trim((string) $validated['message']) : null,
            'idempotency_owner_hash' => hash_hmac('sha256', $owner, (string) config('app.key')),
            'idempotency_key' => (string) $validated['idempotency_key'],
        ];
    }
}
