<?php

namespace App\Support;

/**
 * The services PISFA offers, in one place.
 *
 * The marketing pages, the quotation-request form, and the admin console all
 * read this, so a service cannot be quotable on one screen and unknown on the
 * next.
 *
 * A `route` is what marks a service as self-serve: the homepage badges anything
 * with one as available online, and anything without one as still to come, with
 * its link falling through to a quotation request. That makes this array the
 * single place where "is this bookable yet" is answered — so when a module ships,
 * adding its route here is what turns the marketing on, and forgetting to is
 * what leaves a working feature advertised as unavailable.
 */
final class ServiceCatalogue
{
    /** @var array<string, array<string, string>> */
    public const SERVICES = [
        'tours-safaris' => [
            'name' => 'Tours & Safaris',
            'summary' => 'Curated Ugandan safaris, cultural journeys, adventures, and town trips.',
            'icon' => '🦒',
            'route' => 'tours.index',
            'action' => 'Browse tours',
        ],
        'car-hire' => [
            'name' => 'Car Hire',
            'summary' => 'Self-drive and chauffeur-driven vehicle hire for business or leisure.',
            'icon' => '🚙',
            'route' => 'car-hire.index',
            'action' => 'Browse vehicles',
        ],
        'airport-transfers' => [
            'name' => 'Airport Transfers',
            'summary' => 'Pickups and drop-offs for Entebbe, Kampala, and onward travel.',
            'icon' => '✈️',
            'route' => 'airport-transfers.index',
            'action' => 'Book a transfer',
        ],
        'vehicle-imports' => [
            'name' => 'Vehicle Imports',
            'summary' => 'Guided sourcing, shipping, clearance, and delivery of imported vehicles.',
            'icon' => '🚢',
            'route' => 'vehicle-imports.create',
            'action' => 'Start an import',
        ],
        'vehicle-sales' => [
            'name' => 'Vehicle Showroom',
            'summary' => 'Inspected vehicles from our own fleet and selected stock, for sale.',
            'icon' => '🚗',
            'route' => 'showroom.index',
            'action' => 'Browse cars for sale',
        ],
        'accommodation' => [
            'name' => 'Accommodation',
            'summary' => 'Lodges near the parks, hotels in town, and places for longer stays.',
            'icon' => '🏡',
            'route' => 'accommodation.index',
            'action' => 'Find a place to stay',
        ],
        'vehicle-leasing' => [
            'name' => 'Lease Your Car',
            'summary' => 'Put your vehicle to work in the PISFA fleet on agreed terms.',
            'icon' => '🤝',
            'route' => 'leasing.create',
            'action' => 'Offer your vehicle',
        ],
        // Deliberately has no route. Corporate accounts carry credit terms and
        // agreed rates, which are set up by a person rather than self-served, so
        // the card links to a quotation request and says so.
        'corporate-travel' => [
            'name' => 'Corporate & Group Travel',
            'summary' => 'Coordinated transport and travel planning for teams, institutions, and groups.',
            'icon' => '🏢',
        ],
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::SERVICES);
    }

    public static function exists(?string $key): bool
    {
        return $key !== null && array_key_exists($key, self::SERVICES);
    }

    public static function label(string $key): string
    {
        return self::SERVICES[$key]['name']
            ?? str($key)->replace('-', ' ')->headline()->toString();
    }
}
