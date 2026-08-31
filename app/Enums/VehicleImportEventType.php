<?php

namespace App\Enums;

enum VehicleImportEventType: string
{
    case StatusChanged = 'status_changed';
    case Quoted = 'quoted';
    case DepositSettled = 'deposit_settled';
    case BalanceSettled = 'balance_settled';
    case DocumentAdded = 'document_added';
    case MessagePosted = 'message_posted';
    case LoyaltyEligible = 'loyalty_eligible';

    public function label(): string
    {
        return match ($this) {
            self::StatusChanged => 'Status changed',
            self::Quoted => 'Quotation issued',
            self::DepositSettled => 'Deposit received',
            self::BalanceSettled => 'Balance received',
            self::DocumentAdded => 'Document added',
            self::MessagePosted => 'Message posted',
            self::LoyaltyEligible => 'Eligible for loyalty processing',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
