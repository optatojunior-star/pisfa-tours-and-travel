<?php

namespace App\Support\Payments;

use App\Enums\RefundStatus;

final class RefundResult
{
    /** @param array<string, mixed> $metadata */
    private function __construct(
        public readonly RefundStatus $status,
        public readonly ?string $providerRefundId = null,
        public readonly ?string $failureReason = null,
        public readonly array $metadata = [],
    ) {}

    public static function completed(?string $providerRefundId = null, array $metadata = []): self
    {
        return new self(RefundStatus::Completed, $providerRefundId, null, $metadata);
    }

    /** The provider accepted the request but will settle asynchronously. */
    public static function processing(?string $providerRefundId = null, array $metadata = []): self
    {
        return new self(RefundStatus::Processing, $providerRefundId, null, $metadata);
    }

    public static function failed(string $reason, array $metadata = []): self
    {
        return new self(RefundStatus::Failed, null, $reason, $metadata);
    }
}
