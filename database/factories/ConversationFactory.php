<?php

namespace Database\Factories;

use App\Enums\ConversationChannel;
use App\Enums\ConversationStatus;
use App\Models\Conversation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    protected $model = Conversation::class;

    public function definition(): array
    {
        return [
            'reference' => 'CHT-'.Str::upper((string) Str::ulid()),
            'status' => ConversationStatus::Open,
            'channel' => ConversationChannel::Web,
            'customer_id' => null,
            'assigned_to_user_id' => null,
            'contact_name' => $this->faker->name(),
            'contact_email' => $this->faker->unique()->safeEmail(),
            'contact_phone' => null,
            'subject' => $this->faker->sentence(4),
            'last_message_at' => now(),
            'last_customer_message_at' => now(),
        ];
    }

    public function whatsapp(): self
    {
        return $this->state(fn (): array => [
            'channel' => ConversationChannel::WhatsApp,
            'contact_phone' => '+2567'.$this->faker->numerify('########'),
        ]);
    }

    public function awaitingCustomer(): self
    {
        return $this->state(fn (): array => [
            'status' => ConversationStatus::AwaitingCustomer,
            'last_staff_message_at' => now(),
        ]);
    }

    public function resolved(): self
    {
        return $this->state(fn (): array => [
            'status' => ConversationStatus::Resolved,
            'resolved_at' => now(),
        ]);
    }

    public function closed(): self
    {
        return $this->state(fn (): array => [
            'status' => ConversationStatus::Closed,
            'closed_at' => now(),
            'closure_reason' => 'Handled by telephone.',
        ]);
    }
}
