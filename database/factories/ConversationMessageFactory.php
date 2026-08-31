<?php

namespace Database\Factories;

use App\Enums\ConversationChannel;
use App\Enums\MessageAuthorType;
use App\Enums\MessageDeliveryStatus;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConversationMessage>
 */
class ConversationMessageFactory extends Factory
{
    protected $model = ConversationMessage::class;

    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'author_type' => MessageAuthorType::Customer,
            'author_user_id' => null,
            'channel' => ConversationChannel::Web,
            'body' => $this->faker->sentence(),
            'is_internal_note' => false,
            'delivery_status' => MessageDeliveryStatus::Delivered,
            'delivered_at' => now(),
        ];
    }

    public function fromStaff(): self
    {
        return $this->state(fn (): array => [
            'author_type' => MessageAuthorType::Staff,
        ]);
    }

    public function internalNote(): self
    {
        return $this->state(fn (): array => [
            'author_type' => MessageAuthorType::System,
            'is_internal_note' => true,
        ]);
    }

    public function pending(): self
    {
        return $this->state(fn (): array => [
            'delivery_status' => MessageDeliveryStatus::Pending,
            'delivered_at' => null,
        ]);
    }
}
