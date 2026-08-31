<?php

namespace App\Enums;

/**
 * What comes off a gross salary, and in what order.
 *
 * Order matters and is not alphabetical: NSSF is deducted from gross, and PAYE
 * in Uganda is charged on what is left after it. Computing them in the wrong
 * order overstates the tax and shorts the employee, so the sequence lives here
 * rather than in whoever writes the calculation next.
 */
enum PayrollDeductionType: string
{
    case Nssf = 'nssf';
    case Paye = 'paye';
    case LocalServiceTax = 'local_service_tax';
    case SalaryAdvance = 'salary_advance';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Nssf => 'NSSF (employee 5%)',
            self::Paye => 'PAYE',
            self::LocalServiceTax => 'Local service tax',
            self::SalaryAdvance => 'Salary advance recovered',
            self::Other => 'Other deduction',
        };
    }

    /** Lower runs first. */
    public function order(): int
    {
        return match ($this) {
            self::Nssf => 10,
            self::Paye => 20,
            self::LocalServiceTax => 30,
            self::SalaryAdvance => 40,
            self::Other => 50,
        };
    }

    /** Whether the law requires it, as opposed to it being an arrangement. */
    public function isStatutory(): bool
    {
        return in_array($this, [self::Nssf, self::Paye, self::LocalServiceTax], true);
    }

    /**
     * Whether the deduction is computed by the system or typed in.
     *
     * A salary advance is a number somebody knows; PAYE is arithmetic, and
     * letting it be typed in would put the tax authority's rules in a text box.
     */
    public function isComputed(): bool
    {
        return in_array($this, [self::Nssf, self::Paye], true);
    }
}
