<?php

namespace App\Support\Navigation;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * The console menu, as data.
 *
 * It used to be 566 lines of repeated Blade — the same anchor, the same six
 * classes, the same active-state ternary, written out thirty-eight times. That
 * is how "All bookings" came to be rendered twice with different spacing: the
 * markup was copied, and nothing could tell you the list had a duplicate in it.
 *
 * Expressed as an array, the menu can be counted, grouped, filtered by role and
 * tested. A duplicate is now a visible repetition in one list rather than
 * something hiding two hundred lines apart.
 *
 * Every item is guarded by Route::has(), so a group whose feature is not routed
 * simply does not appear rather than throwing.
 */
final class ConsoleNavigation
{
    /**
     * @return list<array{label: string, icon: string, items: list<array{label: string, route: string, active: string}>}>
     */
    public static function for(User $user): array
    {
        $role = $user->role->value ?? 'staff';

        $groups = match ($role) {
            'customer' => self::customer(),
            'driver' => self::driver(),
            default => self::staff($role),
        };

        // Drop items whose route does not exist, then drop groups left empty.
        return array_values(array_filter(array_map(
            static function (array $group): array {
                $group['items'] = array_values(array_filter(
                    $group['items'],
                    static fn (array $item): bool => Route::has($item['route']),
                ));

                return $group;
            },
            $groups,
        ), static fn (array $group): bool => $group['items'] !== []));
    }

    /** @return list<array{label: string, icon: string, items: list<array{label: string, route: string, active: string}>}> */
    private static function customer(): array
    {
        return [
            [
                'label' => 'My travel',
                'icon' => 'compass',
                'items' => [
                    ['label' => 'Overview', 'route' => 'portal.index', 'active' => 'portal.index'],
                    ['label' => 'Tours', 'route' => 'tour-bookings.index', 'active' => 'tour-bookings.*'],
                    ['label' => 'Car hire', 'route' => 'car-hire-bookings.index', 'active' => 'car-hire-bookings.*'],
                    ['label' => 'Transfers', 'route' => 'airport-transfer-bookings.index', 'active' => 'airport-transfer-bookings.*'],
                    ['label' => 'Stays', 'route' => 'portal.accommodation.index', 'active' => 'portal.accommodation.*'],
                    ['label' => 'Flights', 'route' => 'flight-inquiries.index', 'active' => 'flight-inquiries.*'],
                    ['label' => 'Imports', 'route' => 'portal.vehicle-imports.index', 'active' => 'portal.vehicle-imports.*'],
                ],
            ],
            [
                'label' => 'Money',
                'icon' => 'banknote',
                'items' => [
                    ['label' => 'Quotations', 'route' => 'portal.quotations.index', 'active' => 'portal.quotations.*'],
                    ['label' => 'Invoices', 'route' => 'portal.invoices.index', 'active' => 'portal.invoices.*'],
                    ['label' => 'Loyalty', 'route' => 'loyalty.index', 'active' => 'loyalty.*'],
                ],
            ],
            [
                'label' => 'Account',
                'icon' => 'users',
                'items' => [
                    ['label' => 'Reviews', 'route' => 'reviews.index', 'active' => 'reviews.*'],
                    ['label' => 'Security', 'route' => 'profile.security', 'active' => 'profile.security'],
                    ['label' => 'Profile', 'route' => 'profile.edit', 'active' => 'profile.edit'],
                ],
            ],
        ];
    }

    /** @return list<array{label: string, icon: string, items: list<array{label: string, route: string, active: string}>}> */
    private static function driver(): array
    {
        return [
            [
                'label' => 'My work',
                'icon' => 'car',
                'items' => [
                    ['label' => 'My jobs', 'route' => 'drivers.index', 'active' => 'drivers.index'],
                    ['label' => 'Trip history', 'route' => 'drivers.history', 'active' => 'drivers.history'],
                ],
            ],
            [
                'label' => 'My pay',
                'icon' => 'banknote',
                'items' => [
                    ['label' => 'Expenses', 'route' => 'portal.expenses.index', 'active' => 'portal.expenses.*'],
                    ['label' => 'Payslips', 'route' => 'portal.payslips.index', 'active' => 'portal.payslips.*'],
                ],
            ],
            [
                'label' => 'Account',
                'icon' => 'users',
                'items' => [
                    ['label' => 'Security', 'route' => 'profile.security', 'active' => 'profile.security'],
                    ['label' => 'Profile', 'route' => 'profile.edit', 'active' => 'profile.edit'],
                ],
            ],
        ];
    }

    /**
     * Staff, managers and super administrators.
     *
     * Ordered by how often a working day touches them: bookings first, the
     * catalogue that feeds them next, then operations, money, content and
     * finally the things you set up once.
     *
     * @return list<array{label: string, icon: string, items: list<array{label: string, route: string, active: string}>}>
     */
    private static function staff(string $role): array
    {
        $groups = [
            [
                'label' => 'Bookings',
                'icon' => 'calendar',
                'items' => [
                    ['label' => 'All bookings', 'route' => 'admin.bookings.index', 'active' => 'admin.bookings.*'],
                    ['label' => 'Tours', 'route' => 'admin.tour-bookings.index', 'active' => 'admin.tour-bookings.*'],
                    ['label' => 'Car hire', 'route' => 'admin.car-hire-bookings.index', 'active' => 'admin.car-hire-bookings.*'],
                    ['label' => 'Transfers', 'route' => 'admin.airport-transfer-bookings.index', 'active' => 'admin.airport-transfer-bookings.*'],
                    ['label' => 'Stays', 'route' => 'admin.accommodation.bookings.index', 'active' => 'admin.accommodation.bookings.*'],
                    ['label' => 'Flights', 'route' => 'admin.flight-inquiries.index', 'active' => 'admin.flight-inquiries.*'],
                    ['label' => 'Groups', 'route' => 'admin.corporate.groups.index', 'active' => 'admin.corporate.groups.*'],
                ],
            ],
            [
                'label' => 'Catalogue',
                'icon' => 'grid',
                'items' => [
                    ['label' => 'Tours', 'route' => 'admin.tours.index', 'active' => 'admin.tours.*'],
                    ['label' => 'Tour categories', 'route' => 'admin.tour-categories.index', 'active' => 'admin.tour-categories.*'],
                    ['label' => 'Properties and stays', 'route' => 'admin.accommodation.properties.index', 'active' => 'admin.accommodation.properties.*'],
                    ['label' => 'Transfer settings', 'route' => 'admin.airport-transfer-settings.index', 'active' => 'admin.airport-transfer-settings.*'],
                ],
            ],
            /*
             * Vehicles, together.
             *
             * Hire, sale and import are three different records with three
             * different lives, and they were scattered across two headings with
             * nothing saying they were related — a car bought to resell was
             * filed under "Showroom", which you had to already know.
             */
            [
                'label' => 'Vehicles',
                'icon' => 'car',
                'items' => [
                    ['label' => 'Add a vehicle', 'route' => 'admin.vehicles.choose', 'active' => 'admin.vehicles.choose'],
                    ['label' => 'For hire', 'route' => 'admin.vehicles.index', 'active' => 'admin.vehicles.index'],
                    ['label' => 'For sale', 'route' => 'admin.showroom.index', 'active' => 'admin.showroom.index'],
                    ['label' => 'Sales enquiries', 'route' => 'admin.showroom.enquiries.index', 'active' => 'admin.showroom.enquiries.*'],
                    ['label' => 'Imports', 'route' => 'admin.vehicle-imports.index', 'active' => 'admin.vehicle-imports.*'],
                ],
            ],
            [
                'label' => 'Operations',
                'icon' => 'wrench',
                'items' => [
                    ['label' => 'Fleet', 'route' => 'admin.fleet.index', 'active' => 'admin.fleet.*'],
                    ['label' => 'Leasing', 'route' => 'admin.leasing.leases.index', 'active' => 'admin.leasing.*'],
                    ['label' => 'Inbox', 'route' => 'admin.inbox.index', 'active' => 'admin.inbox.*'],
                ],
            ],
            [
                'label' => 'Money',
                'icon' => 'banknote',
                'items' => [
                    ['label' => 'Quotations', 'route' => 'admin.quotations.index', 'active' => 'admin.quotations.*'],
                    ['label' => 'Quote requests', 'route' => 'admin.quotation-requests.index', 'active' => 'admin.quotation-requests.*'],
                    ['label' => 'Invoices', 'route' => 'admin.invoices.index', 'active' => 'admin.invoices.*'],
                    ['label' => 'Payments', 'route' => 'admin.payments.index', 'active' => 'admin.payments.*'],
                    ['label' => 'Expenses', 'route' => 'admin.finance.expenses.index', 'active' => 'admin.finance.expenses.*'],
                    ['label' => 'Payroll', 'route' => 'admin.finance.payroll.index', 'active' => 'admin.finance.payroll.*'],
                ],
            ],
            [
                'label' => 'Content',
                'icon' => 'document',
                'items' => [
                    ['label' => 'Journal', 'route' => 'admin.posts.index', 'active' => 'admin.posts.*'],
                    ['label' => 'Our team', 'route' => 'admin.team.index', 'active' => 'admin.team.*'],
                    ['label' => 'Service pictures', 'route' => 'admin.service-images.index', 'active' => 'admin.service-images.*'],
                    ['label' => 'Image library', 'route' => 'admin.media.index', 'active' => 'admin.media.*'],
                    ['label' => 'Reviews', 'route' => 'admin.reviews.index', 'active' => 'admin.reviews.*'],
                ],
            ],
            [
                'label' => 'People',
                'icon' => 'users',
                'items' => [
                    ['label' => 'Corporate accounts', 'route' => 'admin.corporate.index', 'active' => 'admin.corporate.index'],
                    ['label' => 'Loyalty', 'route' => 'admin.loyalty.index', 'active' => 'admin.loyalty.*'],
                ],
            ],
            [
                'label' => 'Insight',
                'icon' => 'chart',
                'items' => [
                    ['label' => 'Reports', 'route' => 'admin.reports.index', 'active' => 'admin.reports.*'],
                ],
            ],
        ];

        // Governance is super-administrator only: reading the audit trail and
        // changing what the company says about itself are not daily powers.
        if ($role === UserRole::SuperAdmin->value) {
            $groups[] = [
                'label' => 'Administration',
                'icon' => 'cog',
                'items' => [
                    ['label' => 'Team & access', 'route' => 'admin.staff.index', 'active' => 'admin.staff.*'],
                    ['label' => 'Settings', 'route' => 'admin.settings.edit', 'active' => 'admin.settings.*'],
                    ['label' => 'Audit trail', 'route' => 'admin.audit.index', 'active' => 'admin.audit.*'],
                    ['label' => 'Operations', 'route' => 'admin.operations.index', 'active' => 'admin.operations.*'],
                ],
            ];
        }

        return $groups;
    }
}
