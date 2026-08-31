<?php

namespace App\Enums;

enum FlightInquiryEntryType: string
{
    case InternalNote = 'internal_note';
    case EmailSent = 'email_sent';
    case PhoneCall = 'phone_call';
    case WhatsApp = 'whatsapp';
    case QuoteShared = 'quote_shared';
    case StatusChanged = 'status_changed';
    case Assigned = 'assigned';

    public function label(): string
    {
        return match ($this) {
            self::InternalNote => 'Internal note',
            self::EmailSent => 'Email sent',
            self::PhoneCall => 'Phone call',
            self::WhatsApp => 'WhatsApp message',
            self::QuoteShared => 'Quote shared',
            self::StatusChanged => 'Status changed',
            self::Assigned => 'Assignment changed',
        };
    }

    /**
     * Communication entries record contact with the traveller; the remaining
     * types are operational history written by the system.
     */
    public function isCommunication(): bool
    {
        return in_array($this, [self::EmailSent, self::PhoneCall, self::WhatsApp, self::QuoteShared], true);
    }

    /**
     * Types a staff member may record by hand. System types are written only by
     * the transition and assignment actions.
     */
    public static function manualCases(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $type): bool => $type === self::InternalNote || $type->isCommunication(),
        ));
    }
}
