<?php

namespace Database\Seeders;

use App\Enums\PropertyStatus;
use App\Enums\PropertyType;
use App\Enums\TourPackageStatus;
use App\Enums\UserRole;
use App\Enums\VehicleCatalogueStatus;
use App\Enums\VehicleOperationalStatus;
use App\Models\Property;
use App\Models\PropertyRoomRate;
use App\Models\PropertyRoomType;
use App\Models\TourCategory;
use App\Models\TourDeparture;
use App\Models\TourPackage;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleHireRate;
use App\Support\Money;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Realistic demo catalogue: tours, vehicles, and places to stay.
 *
 * Written so somebody can open the site and see a business rather than an empty
 * shell — real Ugandan destinations, vehicles PISFA would actually run, and
 * prices in the range these things cost.
 *
 * Two rules govern every seeder in this directory:
 *
 *  1. **Additive and idempotent.** Everything is keyed on a natural key and
 *     created with `firstOrCreate`, so running it twice changes nothing and
 *     running it against a database that already has real records adds to them
 *     rather than replacing them. Nothing here truncates, and nothing calls
 *     `migrate:fresh`.
 *  2. **Refuses to run in production.** Demo bookings in a real ledger are
 *     indistinguishable from real ones a month later.
 */
class DemoCatalogueSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('The demo catalogue was not seeded outside local/testing.');

            return;
        }

        $staff = $this->staffMember();

        $this->seedTours($staff);
        $this->seedVehicles($staff);
        $this->seedProperties($staff);

        $this->command?->info('Demo catalogue ready: tours, vehicles and stays.');
    }

    /**
     * Somebody to own the created records.
     *
     * Falls back to creating nothing rather than inventing a user: the demo
     * user seeder runs first and owns that responsibility.
     */
    private function staffMember(): ?User
    {
        return User::query()
            ->whereIn('role', [UserRole::Manager->value, UserRole::SuperAdmin->value, UserRole::Staff->value])
            ->orderBy('id')
            ->first();
    }

    private function seedTours(?User $staff): void
    {
        $categories = TourCategory::query()->pluck('id', 'slug');

        /** @var list<array<string, mixed>> $tours */
        $tours = [
            [
                'slug' => 'gorilla-trekking-bwindi-3-days',
                'name' => 'Gorilla Trekking in Bwindi — 3 Days',
                'category' => 'safaris',
                'destination' => 'Bwindi Impenetrable National Park',
                'duration_days' => 3,
                'price' => '5400000',
                'min' => 1,
                'max' => 8,
                'summary' => 'Three days to Bwindi for a permit-led gorilla trek, with a night either '
                    .'side in Buhoma so the trek itself is not rushed.',
                'description' => "Bwindi holds roughly half the world's remaining mountain gorillas, and a "
                    ."trek there is the single thing most visitors to Uganda come for.\n\n"
                    .'Day one is the drive from Kampala or Entebbe, breaking at the Equator and again at '
                    .'Mbarara, arriving in Buhoma in the late afternoon. Day two is the trek: an early '
                    .'briefing at the park headquarters, then anything from two to seven hours on foot '
                    ."depending on where the family has moved overnight. You get one hour with them.\n\n"
                    .'Day three returns to Kampala. The gorilla permit is the largest single cost and is '
                    .'booked in your name through the Uganda Wildlife Authority; we hold it once your '
                    .'deposit clears, because permits sell out months ahead in high season.',
            ],
            [
                'slug' => 'murchison-falls-safari-4-days',
                'name' => 'Murchison Falls Safari — 4 Days',
                'category' => 'safaris',
                'destination' => 'Murchison Falls National Park',
                'duration_days' => 4,
                'price' => '2850000',
                'min' => 2,
                'max' => 12,
                'summary' => 'Game drives on the northern bank, a launch trip to the base of the falls, '
                    .'and the top-of-the-falls walk.',
                'description' => 'The Nile squeezes through a seven-metre gap and drops forty-three '
                    ."metres. You hear it before you see it.\n\n"
                    .'Two nights inside the park mean two early game drives on the northern bank, where '
                    .'the elephant, giraffe and buffalo are, and an afternoon launch upriver to the base '
                    .'of the falls among hippo and crocodile. The top-of-the-falls walk is short but '
                    ."steep, and worth it.\n\n"
                    .'We break the drive at Ziwa Rhino Sanctuary, which is the only place in Uganda to '
                    .'track rhino on foot and adds an hour to the day rather than a night.',
            ],
            [
                'slug' => 'queen-elizabeth-tree-lions-3-days',
                'name' => 'Queen Elizabeth and the Ishasha Tree Lions — 3 Days',
                'category' => 'safaris',
                'destination' => 'Queen Elizabeth National Park',
                'duration_days' => 3,
                'price' => '2250000',
                'min' => 2,
                'max' => 12,
                'summary' => 'Kasenyi game drives, the Kazinga Channel launch, and the Ishasha sector '
                    .'where the lions climb fig trees.',
                'description' => "Queen Elizabeth is the most biodiverse of Uganda's parks and the "
                    ."easiest to combine with Bwindi, which is why it is usually done on the way.\n\n"
                    .'The Kazinga Channel launch is the highlight for most people: two hours between '
                    .'Lake Edward and Lake George with hippo, buffalo and an unreasonable number of '
                    ."birds within a few metres of the boat.\n\n"
                    .'The Ishasha sector in the south is where lions rest in fig trees through the heat '
                    .'of the day. Nobody can promise you will see them, and we do not.',
            ],
            [
                'slug' => 'jinja-source-of-the-nile-day-trip',
                'name' => 'Jinja and the Source of the Nile — Day Trip',
                'category' => 'adventure-tours',
                'destination' => 'Jinja',
                'duration_days' => 1,
                'price' => '320000',
                'min' => 1,
                'max' => 14,
                'summary' => 'A day from Kampala to the source of the Nile, with optional rafting or a '
                    .'quieter boat to the point where the river begins.',
                'description' => 'Two hours from Kampala on a good road, and the most popular day out '
                    ."from the city.\n\n"
                    .'The morning is the source itself — a boat to the point where the Nile leaves Lake '
                    .'Victoria — and the afternoon is yours: grade five rafting, a bungee jump over the '
                    .'river, quad bikes through the villages, or a slow lunch overlooking the water if '
                    ."none of that appeals.\n\n"
                    .'Rafting is booked separately with the operator on the day and is not included in '
                    .'the price below.',
            ],
            [
                'slug' => 'kibale-chimpanzee-tracking-2-days',
                'name' => 'Kibale Chimpanzee Tracking — 2 Days',
                'category' => 'safaris',
                'destination' => 'Kibale Forest National Park',
                'duration_days' => 2,
                'price' => '1450000',
                'min' => 1,
                'max' => 8,
                'summary' => 'Chimpanzee tracking in Kibale with a guided walk through the Bigodi '
                    .'wetland in the afternoon.',
                'description' => 'Kibale has the highest density of primates in East Africa and the '
                    ."best-habituated chimpanzee communities in Uganda.\n\n"
                    .'Tracking starts at eight in the morning from Kanyanchu. Chimpanzees move faster '
                    .'and further than gorillas, so this is a brisker walk, and the hour you get with '
                    ."them is noisier.\n\n"
                    .'The afternoon walk through Bigodi wetland is run by the community and turns up '
                    .'red colobus, grey-cheeked mangabey and, for anybody who cares, about two hundred '
                    .'bird species.',
            ],
            [
                'slug' => 'sipi-falls-and-mount-elgon-3-days',
                'name' => 'Sipi Falls and Mount Elgon — 3 Days',
                'category' => 'upcountry-escapes',
                'destination' => 'Kapchorwa',
                'duration_days' => 3,
                'price' => '980000',
                'min' => 2,
                'max' => 10,
                'summary' => 'The three Sipi waterfalls, a working coffee farm, and the lower slopes of '
                    .'Mount Elgon.',
                'description' => 'Sipi is three waterfalls on the north-western slope of Mount Elgon, '
                    ."and the walk between them takes most of a day.\n\n"
                    .'The coffee here is Arabica grown between 1,600 and 1,900 metres, and the farm '
                    .'tour is the genuine article: picking, pulping, drying and roasting, finishing '
                    ."with a cup of what you watched being made.\n\n"
                    .'The drive from Kampala is long — about six hours — so this works best as three '
                    .'days rather than two.',
            ],
        ];

        foreach ($tours as $tour) {
            $package = TourPackage::query()->firstOrCreate(
                ['slug' => $tour['slug']],
                [
                    'tour_category_id' => $categories[$tour['category']] ?? $categories->first(),
                    'name' => $tour['name'],
                    'destination' => $tour['destination'],
                    'summary' => $tour['summary'],
                    'description' => $tour['description'],
                    'status' => TourPackageStatus::Published,
                    'published_at' => now()->subDays(random_int(20, 200)),
                    'is_featured' => in_array($tour['slug'], [
                        'gorilla-trekking-bwindi-3-days',
                        'murchison-falls-safari-4-days',
                    ], true),
                    'duration_days' => $tour['duration_days'],
                    'base_price_minor' => Money::parse($tour['price'], 'UGX'),
                    'currency' => 'UGX',
                    'min_travelers' => $tour['min'],
                    'max_travelers' => $tour['max'],
                    'cancellation_cutoff_hours' => 72,
                    'created_by_user_id' => $staff?->getKey(),
                ],
            );

            $this->seedDepartures($package);
        }
    }

    /**
     * Forward-dated departures.
     *
     * Dated relative to now rather than fixed, so a demo database seeded months
     * ago still shows something bookable instead of a page of past dates.
     */
    private function seedDepartures(TourPackage $package): void
    {
        // Keyed on how many future departures already exist rather than on the
        // dates themselves. The dates are derived from `now()`, so keying on
        // them would be idempotent within a day and would quietly accumulate a
        // fresh set every day after that.
        $existing = TourDeparture::query()
            ->where('tour_package_id', $package->getKey())
            ->where('starts_at', '>=', now())
            ->count();

        if ($existing >= 3) {
            return;
        }

        foreach ([18, 46, 74] as $offset) {
            $starts = Carbon::now()
                ->addDays($offset)
                ->setTime(7, 0)
                ->startOfMinute();

            $ends = $starts->copy()->addDays(max(0, $package->duration_days - 1))->setTime(18, 0);

            TourDeparture::query()->firstOrCreate(
                [
                    'tour_package_id' => $package->getKey(),
                    'starts_at' => $starts,
                ],
                [
                    'ends_at' => $ends,
                    'cancellation_cutoff_at' => $starts->copy()
                        ->subHours($package->cancellation_cutoff_hours ?? 72),
                    'capacity' => $package->max_travelers ?? 10,
                    'currency' => 'UGX',
                    'status' => 'scheduled',
                    'meeting_point' => 'PISFA office, Kampala — pickup from Entebbe by arrangement.',
                    'customer_notes' => 'Bring a passport, sturdy boots and a light rain jacket. '
                        .'Park fees are included; personal spending is not.',
                ],
            );
        }
    }

    private function seedVehicles(?User $staff): void
    {
        /** @var list<array<string, mixed>> $vehicles */
        $vehicles = [
            [
                'slug' => 'toyota-land-cruiser-prado-safari',
                'plate' => 'UBK 274F',
                'make' => 'Toyota',
                'model' => 'Land Cruiser Prado',
                'year' => 2019,
                'type' => 'suv',
                'seats' => 7,
                'luggage' => 4,
                'self_drive' => '350000',
                'with_driver' => '480000',
                'deposit' => '1000000',
                'summary' => 'Pop-up roof 4x4 built for park roads, with a fridge and long-range tank.',
            ],
            [
                'slug' => 'toyota-hiace-safari-van',
                'plate' => 'UBH 918K',
                'make' => 'Toyota',
                'model' => 'Hiace Safari Van',
                'year' => 2018,
                'type' => 'van',
                'seats' => 12,
                'luggage' => 8,
                'self_drive' => '280000',
                'with_driver' => '400000',
                'deposit' => '800000',
                'summary' => 'Twelve seats with a pop-up roof — the usual choice for a group tour.',
            ],
            [
                'slug' => 'toyota-rav4-city',
                'plate' => 'UAX 552M',
                'make' => 'Toyota',
                'model' => 'RAV4',
                'year' => 2020,
                'type' => 'suv',
                'seats' => 5,
                'luggage' => 3,
                'self_drive' => '180000',
                'with_driver' => '290000',
                'deposit' => '500000',
                'summary' => 'Comfortable around Kampala and fine on tarmac upcountry.',
            ],
            [
                'slug' => 'toyota-coaster-group-bus',
                'plate' => 'UBG 401C',
                'make' => 'Toyota',
                'model' => 'Coaster',
                'year' => 2017,
                'type' => 'van',
                'seats' => 28,
                'luggage' => 20,
                'self_drive' => null,
                'with_driver' => '650000',
                'deposit' => '1200000',
                'summary' => 'Twenty-eight seats for conferences, weddings and school trips. '
                    .'With a driver only.',
            ],
            [
                'slug' => 'nissan-patrol-expedition',
                'plate' => 'UBJ 663T',
                'make' => 'Nissan',
                'model' => 'Patrol',
                'year' => 2016,
                'type' => 'suv',
                'seats' => 7,
                'luggage' => 5,
                'self_drive' => '320000',
                'with_driver' => '450000',
                'deposit' => '900000',
                'summary' => 'Heavy 4x4 for Karamoja and the far north, where the roads stop being roads.',
            ],
        ];

        foreach ($vehicles as $data) {
            $vehicle = Vehicle::query()->firstOrCreate(
                ['slug' => $data['slug']],
                [
                    'registration_plate' => $data['plate'],
                    'make' => $data['make'],
                    'model' => $data['model'],
                    'year' => $data['year'],
                    'color' => 'White',
                    'condition' => 'good',
                    'vehicle_type' => $data['type'],
                    'fuel_type' => 'diesel',
                    'transmission' => 'automatic',
                    'seating_capacity' => $data['seats'],
                    'luggage_capacity' => $data['luggage'],
                    'current_odometer_km' => random_int(48_000, 210_000),
                    'odometer_updated_at' => now()->subDays(random_int(1, 30)),
                    'summary' => $data['summary'],
                    'description' => $data['summary'].' Serviced on schedule and inspected before every '
                        .'hire. Comprehensive insurance is included; the excess is the deposit shown.',
                    'catalogue_status' => VehicleCatalogueStatus::Published,
                    'operational_status' => VehicleOperationalStatus::Available,
                    'published_at' => now()->subDays(random_int(10, 120)),
                    'is_featured' => $data['slug'] === 'toyota-land-cruiser-prado-safari',
                    'created_by_user_id' => $staff?->getKey(),
                ],
            );

            VehicleHireRate::query()->firstOrCreate(
                [
                    'vehicle_id' => $vehicle->getKey(),
                    'currency' => 'UGX',
                    // A Carbon, not a date string: the column is cast to a date
                    // and stored with a midnight time component, so a bare
                    // 'Y-m-d' would never match on the lookup and every run
                    // would try to insert a duplicate.
                    'effective_from' => now()->startOfYear(),
                ],
                [
                    'self_drive_daily_minor' => $data['self_drive'] === null
                        ? null
                        : Money::parse($data['self_drive'], 'UGX'),
                    'with_driver_daily_minor' => Money::parse($data['with_driver'], 'UGX'),
                    'security_deposit_minor' => Money::parse($data['deposit'], 'UGX'),
                    'is_active' => true,
                    'created_by_user_id' => $staff?->getKey(),
                ],
            );
        }
    }

    private function seedProperties(?User $staff): void
    {
        /** @var list<array<string, mixed>> $properties */
        $properties = [
            [
                'slug' => 'buhoma-forest-lodge',
                'name' => 'Buhoma Forest Lodge',
                'type' => PropertyType::Lodge,
                'region' => 'Western',
                'district' => 'Kanungu',
                'address' => 'Buhoma sector, Bwindi Impenetrable National Park',
                'summary' => 'Eight bandas on the forest edge, ten minutes from the Buhoma trailhead.',
                'rooms' => [
                    ['slug' => 'forest-banda', 'name' => 'Forest Banda', 'qty' => 6, 'adults' => 2, 'children' => 1,
                        'beds' => 'One king, or two singles on request', 'rate' => '420000'],
                    ['slug' => 'family-banda', 'name' => 'Family Banda', 'qty' => 2, 'adults' => 4, 'children' => 2,
                        'beds' => 'One king and two singles across two rooms', 'rate' => '640000'],
                ],
            ],
            [
                'slug' => 'paraa-river-camp',
                'name' => 'Paraa River Camp',
                'type' => PropertyType::Campsite,
                'region' => 'Northern',
                'district' => 'Nwoya',
                'address' => 'Paraa, Murchison Falls National Park',
                'summary' => 'Permanent tents above the Nile, a short drive from the northern ferry.',
                'rooms' => [
                    ['slug' => 'safari-tent', 'name' => 'Safari Tent', 'qty' => 10, 'adults' => 2, 'children' => 1,
                        'beds' => 'Two singles, convertible to a double', 'rate' => '310000'],
                ],
            ],
            [
                'slug' => 'kampala-hill-guest-house',
                'name' => 'Kampala Hill Guest House',
                'type' => PropertyType::GuestHouse,
                'region' => 'Central',
                'district' => 'Kampala',
                'address' => 'Naguru, Kampala',
                'summary' => 'A quiet eleven-room guest house in Naguru, twenty minutes from the city centre.',
                'rooms' => [
                    ['slug' => 'standard-double', 'name' => 'Standard Double', 'qty' => 7, 'adults' => 2, 'children' => 1,
                        'beds' => 'One double', 'rate' => '180000'],
                    ['slug' => 'executive-suite', 'name' => 'Executive Suite', 'qty' => 4, 'adults' => 2, 'children' => 2,
                        'beds' => 'One king with a sitting room', 'rate' => '290000'],
                ],
            ],
            [
                'slug' => 'sipi-ridge-cottages',
                'name' => 'Sipi Ridge Cottages',
                'type' => PropertyType::Cottage,
                'region' => 'Eastern',
                'district' => 'Kapchorwa',
                'address' => 'Sipi, Kapchorwa',
                'summary' => 'Six cottages facing the middle falls, on the coffee slopes of Mount Elgon.',
                'rooms' => [
                    ['slug' => 'falls-view-cottage', 'name' => 'Falls View Cottage', 'qty' => 4, 'adults' => 2, 'children' => 2,
                        'beds' => 'One queen', 'rate' => '240000'],
                    ['slug' => 'garden-cottage', 'name' => 'Garden Cottage', 'qty' => 2, 'adults' => 3, 'children' => 1,
                        'beds' => 'One double and one single', 'rate' => '270000'],
                ],
            ],
        ];

        foreach ($properties as $data) {
            $property = Property::query()->firstOrCreate(
                ['slug' => $data['slug']],
                [
                    'name' => $data['name'],
                    'property_type' => $data['type'],
                    'region' => $data['region'],
                    'district' => $data['district'],
                    'address' => $data['address'],
                    'summary' => $data['summary'],
                    'description' => $data['summary'].' Rates include breakfast. Park entry fees, where '
                        .'they apply, are paid separately at the gate.',
                    'directions' => 'Directions and a pin are sent with your confirmation.',
                    'check_in_from' => '14:00',
                    'check_out_by' => '10:00',
                    'cancellation_cutoff_hours' => 48,
                    'status' => PropertyStatus::Published,
                    'published_at' => now()->subDays(random_int(15, 150)),
                    'is_featured' => $data['slug'] === 'buhoma-forest-lodge',
                    'created_by_user_id' => $staff?->getKey(),
                ],
            );

            foreach ($data['rooms'] as $room) {
                $roomType = PropertyRoomType::query()->firstOrCreate(
                    ['property_id' => $property->getKey(), 'slug' => $room['slug']],
                    [
                        'name' => $room['name'],
                        'description' => $room['beds'].'. Ensuite, with mosquito nets and hot water.',
                        'quantity' => $room['qty'],
                        'max_adults' => $room['adults'],
                        'max_children' => $room['children'],
                        'bed_configuration' => $room['beds'],
                        'is_active' => true,
                    ],
                );

                // A room without a rate is not bookable, so the two are seeded
                // together rather than left for somebody to notice later.
                PropertyRoomRate::query()->firstOrCreate(
                    [
                        'property_room_type_id' => $roomType->getKey(),
                        'currency' => 'UGX',
                        'effective_from' => now()->startOfYear(),
                    ],
                    [
                        'nightly_rate_minor' => Money::parse($room['rate'], 'UGX'),
                        'minimum_nights' => 1,
                        'is_active' => true,
                    ],
                );
            }
        }
    }
}
