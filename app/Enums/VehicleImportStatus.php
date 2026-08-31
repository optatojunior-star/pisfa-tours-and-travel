<?php

namespace App\Enums;

/**
 * The import lifecycle from the brief, as a guarded graph.
 *
 * Two stages gate on money rather than on an operator's judgement:
 * Quoted -> DepositPaid requires a settled deposit, and Delivered requires the
 * balance to be settled. Those checks live in the transition action, not here.
 */
enum VehicleImportStatus: string
{
    case Inquiry = 'inquiry';
    case Reviewing = 'reviewing';
    case Quoted = 'quoted';
    case DepositPaid = 'deposit_paid';
    case CarLocated = 'car_located';
    case Shipping = 'shipping';
    case PortClearance = 'port_clearance';
    case InTransit = 'in_transit';
    case ReadyForDelivery = 'ready_for_delivery';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Inquiry => 'Inquiry received',
            self::Reviewing => 'Under review',
            self::Quoted => 'Quoted',
            self::DepositPaid => 'Deposit paid',
            self::CarLocated => 'Vehicle located',
            self::Shipping => 'Shipping',
            self::PortClearance => 'Port clearance',
            self::InTransit => 'In transit',
            self::ReadyForDelivery => 'Ready for delivery',
            self::Delivered => 'Delivered',
            self::Cancelled => 'Cancelled',
        };
    }

    /** Plain-language description for the customer timeline. */
    public function customerDescription(): string
    {
        return match ($this) {
            self::Inquiry => 'We have your request and will review it shortly.',
            self::Reviewing => 'Our sourcing team is reviewing your requirements.',
            self::Quoted => 'Your quotation is ready. Pay the deposit to begin sourcing.',
            self::DepositPaid => 'Deposit received. We are sourcing your vehicle.',
            self::CarLocated => 'We have located a vehicle matching your requirements.',
            self::Shipping => 'Your vehicle is booked for shipping.',
            self::PortClearance => 'Your vehicle is clearing customs at the port.',
            self::InTransit => 'Your vehicle is on its way to the delivery point.',
            self::ReadyForDelivery => 'Your vehicle is ready. Settle the balance to take delivery.',
            self::Delivered => 'Your vehicle has been delivered. Thank you.',
            self::Cancelled => 'This import request was cancelled.',
        };
    }

    /**
     * Position in the customer-facing progress bar. Cancelled has no position.
     */
    public function step(): ?int
    {
        return match ($this) {
            self::Inquiry => 1,
            self::Reviewing => 2,
            self::Quoted => 3,
            self::DepositPaid => 4,
            self::CarLocated => 5,
            self::Shipping => 6,
            self::PortClearance => 7,
            self::InTransit => 8,
            self::ReadyForDelivery => 9,
            self::Delivered => 10,
            self::Cancelled => null,
        };
    }

    public static function totalSteps(): int
    {
        return 10;
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /** A quotation only exists from Quoted onwards. */
    public function hasQuote(): bool
    {
        return $this->step() !== null && $this->step() >= self::Quoted->step();
    }

    /** The deposit has been taken by this point in the lifecycle. */
    public function depositSettled(): bool
    {
        return $this->step() !== null && $this->step() >= self::DepositPaid->step();
    }

    /**
     * A customer may still withdraw before sourcing has cost PISFA money.
     * After the deposit is paid, cancellation is an operations decision.
     */
    public function customerMayCancel(): bool
    {
        return in_array($this, [self::Inquiry, self::Reviewing, self::Quoted], true);
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Inquiry => [self::Reviewing, self::Cancelled],
            self::Reviewing => [self::Quoted, self::Cancelled],
            self::Quoted => [self::DepositPaid, self::Cancelled],
            self::DepositPaid => [self::CarLocated, self::Cancelled],
            self::CarLocated => [self::Shipping, self::Cancelled],
            self::Shipping => [self::PortClearance, self::Cancelled],
            self::PortClearance => [self::InTransit, self::Cancelled],
            self::InTransit => [self::ReadyForDelivery, self::Cancelled],
            self::ReadyForDelivery => [self::Delivered, self::Cancelled],
            self::Delivered, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    /** @return list<string> */
    public static function openValues(): array
    {
        return array_map(
            static fn (self $status): string => $status->value,
            array_filter(self::cases(), static fn (self $s): bool => ! $s->isTerminal()),
        );
    }

    /**
     * Coarse bucket for the cross-domain booking list.
     *
     * An import is only Confirmed once the deposit has actually arrived:
     * a quotation the customer has not paid is still awaiting them.
     */
    public function stage(): BookingStage
    {
        return match ($this) {
            self::Inquiry, self::Reviewing, self::Quoted => BookingStage::AwaitingAction,
            self::DepositPaid => BookingStage::Confirmed,
            self::CarLocated, self::Shipping, self::PortClearance,
            self::InTransit, self::ReadyForDelivery => BookingStage::InProgress,
            self::Delivered => BookingStage::Completed,
            self::Cancelled => BookingStage::Closed,
        };
    }

    /** @return list<string> */
    public static function valuesInStage(BookingStage $stage): array
    {
        return array_map(
            static fn (self $case): string => $case->value,
            array_values(array_filter(
                self::cases(),
                static fn (self $case): bool => $case->stage() === $stage,
            )),
        );
    }
}
