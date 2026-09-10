<?php

namespace Database\Seeders;

use App\Enums\ListingStatus;
use App\Enums\PostStatus;
use App\Enums\UserRole;
use App\Models\Post;
use App\Models\PostCategory;
use App\Models\User;
use App\Models\VehicleListing;
use App\Support\Money;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Demo journal posts and showroom stock.
 *
 * The same rules as the catalogue seeder: additive, idempotent, and refuses to
 * run outside local/testing.
 */
class DemoContentSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('Demo content was not seeded outside local/testing.');

            return;
        }

        $author = User::query()
            ->whereIn('role', [UserRole::Manager->value, UserRole::SuperAdmin->value, UserRole::Staff->value])
            ->orderBy('id')
            ->first();

        $this->seedPosts($author);
        $this->seedListings($author);

        $this->command?->info('Demo content ready: journal posts and showroom stock.');
    }

    private function seedPosts(?User $author): void
    {
        $categories = [
            'travel-advice' => 'Travel advice',
            'destinations' => 'Destinations',
            'company-news' => 'Company news',
        ];

        $categoryIds = [];
        $order = 0;

        foreach ($categories as $slug => $name) {
            $categoryIds[$slug] = PostCategory::query()->firstOrCreate(
                ['slug' => $slug],
                ['name' => $name, 'sort_order' => $order++, 'is_active' => true],
            )->getKey();
        }

        /** @var list<array<string, mixed>> $posts */
        $posts = [
            [
                'slug' => 'when-to-visit-uganda',
                'title' => 'When to visit Uganda, and when not to',
                'category' => 'travel-advice',
                'excerpt' => 'Uganda has two dry seasons and two wet ones. Which you want depends '
                    .'entirely on what you came to do.',
                'body' => 'Uganda sits on the equator, so the temperature barely moves all year. What '
                    ."changes is the rain, and it changes what you can do.\n\n"
                    ."## The dry seasons\n\n"
                    .'June to September and December to February are the dry months. Park tracks are '
                    .'passable, gorilla trekking is less of a scramble, and game viewing is better '
                    .'because animals gather at what water is left. These are also the months when '
                    ."gorilla permits sell out, sometimes six months ahead.\n\n"
                    ."## The wet seasons\n\n"
                    .'March to May and October to November bring long afternoon rain. Trekking is '
                    .'harder and the tracks in Murchison and Queen Elizabeth get difficult. In exchange '
                    .'the country is green, the birding is at its best, and lodges are half empty and '
                    ."often cheaper.\n\n"
                    ."It rarely rains all day. A wet-season morning is usually clear.\n\n"
                    ."## What we would tell a friend\n\n"
                    .'If gorillas are the point of the trip, come in the dry season and book the permit '
                    .'first — everything else can be arranged around it. If you are coming for '
                    .'landscape, birds, or a quieter park, the shoulder months of March and November '
                    .'are the best value in the calendar.',
            ],
            [
                'slug' => 'what-a-gorilla-permit-actually-covers',
                'title' => 'What a gorilla permit actually covers',
                'category' => 'travel-advice',
                'excerpt' => 'The permit is the largest single cost of a Bwindi trip. Here is exactly '
                    .'what it buys and what it does not.',
                'body' => 'A gorilla permit is issued by the Uganda Wildlife Authority in one named '
                    ."person's name, for one named date, in one named sector. It is not transferable "
                    ."and it is not refundable once issued.\n\n"
                    ."## What it covers\n\n"
                    .'Park entry for the day, the ranger guides, the trackers who go ahead at dawn to '
                    ."find the family, and one hour with the gorillas once they are found.\n\n"
                    ."## What it does not cover\n\n"
                    .'Transport to Bwindi, your accommodation, meals, porters — about 20,000 to 30,000 '
                    ."shillings, and worth every one of them — and tips.\n\n"
                    ."## Why we ask for a deposit before we hold one\n\n"
                    .'Because we buy the permit outright. Once it is issued in your name, that money is '
                    .'spent whether or not you travel. We would rather explain this clearly at the '
                    .'start than have the conversation after a cancellation.',
            ],
            [
                'slug' => 'self-drive-or-with-a-driver',
                'title' => 'Self-drive or with a driver?',
                'category' => 'travel-advice',
                'excerpt' => 'Both work in Uganda. Which is right depends on where you are going and '
                    .'how much of the trip you want to spend navigating.',
                'body' => "We hire vehicles both ways and have no stake in which you choose.\n\n"
                    ."## Self-drive suits you if\n\n"
                    .'You are staying mainly on tarmac, you are comfortable with roundabouts full of '
                    .'boda-bodas, and you want to stop where you like. Kampala to Jinja, Kampala to '
                    ."Mbarara, Entebbe to the lake — all straightforward.\n\n"
                    ."## A driver-guide suits you if\n\n"
                    .'You are going into the parks, driving upcountry after dark, or covering long '
                    .'distances on murram. Our drivers know which tracks are passable this week, which '
                    .'is not something a map can tell you. They also spot wildlife you would drive '
                    ."straight past.\n\n"
                    ."## The cost difference is smaller than people expect\n\n"
                    .'A driver adds roughly a third to the daily rate, and removes fuel-route guesswork, '
                    .'parking, and the risk of an argument at a police checkpoint.',
            ],
            [
                'slug' => 'pisfa-adds-accommodation-booking',
                'title' => 'You can now book your stay with us too',
                'category' => 'company-news',
                'excerpt' => 'Lodges, guest houses and camps across Uganda, on the same booking and '
                    .'the same invoice as your tour or vehicle.',
                'body' => 'We have been arranging accommodation informally for years. It is now part of '
                    ."the site.\n\n"
                    .'You can book a lodge in Bwindi, a tent above the Nile at Paraa, or a room in '
                    .'Kampala the night before an early flight — on the same booking as your vehicle '
                    ."or your tour, and on one invoice.\n\n"
                    .'Availability is live. If a room shows as free, it is free.',
            ],
            [
                'slug' => 'murchison-falls-in-a-long-weekend',
                'title' => 'Murchison Falls in a long weekend',
                'category' => 'destinations',
                'excerpt' => 'Four days is comfortable. Three is possible. Two is a lot of driving for '
                    .'not much park.',
                'body' => "Murchison is 305 kilometres from Kampala, and the last stretch is slow.\n\n"
                    ."## Three days, done properly\n\n"
                    .'Leave Kampala early, break at Ziwa Rhino Sanctuary to track rhino on foot, and '
                    .'reach Paraa in the late afternoon. Day two is a morning game drive on the '
                    .'northern bank and the afternoon launch to the base of the falls. Day three is the '
                    ."top-of-the-falls walk and the drive home.\n\n"
                    ."## Why not two\n\n"
                    .'Two days means eleven hours of driving around a single game drive. People do it. '
                    ."They rarely enjoy it.\n\n"
                    .'Four days lets you add the Budongo Forest chimpanzee walk on the way back, which '
                    .'is the best value add-on in the north.',
            ],
        ];

        foreach ($posts as $index => $post) {
            Post::query()->firstOrCreate(
                ['slug' => $post['slug']],
                [
                    'title' => $post['title'],
                    'excerpt' => $post['excerpt'],
                    'body' => $post['body'],
                    'post_category_id' => $categoryIds[$post['category']],
                    'author_user_id' => $author?->getKey(),
                    'status' => PostStatus::Published,
                    'published_at' => now()->subDays(($index + 1) * 12),
                    'is_featured' => $index < 2,
                    'meta_title' => $post['title'].' | PISFA Tours and Travels',
                    'meta_description' => Str::limit($post['excerpt'], 155),
                    'reading_minutes' => max(1, (int) round(str_word_count($post['body']) / 200)),
                ],
            );
        }
    }

    /**
     * Showroom stock.
     *
     * Deliberately not linked to fleet vehicles: a listing carries its own
     * snapshot of the specification, and the seeded fleet is in service rather
     * than for sale. One sold listing is included because a showroom with
     * recent sales reads as a going concern.
     */
    private function seedListings(?User $staff): void
    {
        /** @var list<array<string, mixed>> $listings */
        $listings = [
            [
                'slug' => '2015-toyota-land-cruiser-prado-tx',
                'title' => '2015 Toyota Land Cruiser Prado TX',
                'make' => 'Toyota',
                'model' => 'Land Cruiser Prado TX',
                'year' => 2015,
                'price' => '135000000',
                'mileage' => 148_000,
                'status' => ListingStatus::Available,
                'featured' => true,
                'description' => 'Ex-fleet Prado, retired from safari work in good order. Full service '
                    .'history with us since 2019, new tyres at 142,000 km, and a recent timing belt. '
                    .'Pop-up roof removed and the panel replaced properly. Sold with a valid inspection.',
            ],
            [
                'slug' => '2017-toyota-hiace-super-custom',
                'title' => '2017 Toyota Hiace Super Custom',
                'make' => 'Toyota',
                'model' => 'Hiace Super Custom',
                'year' => 2017,
                'price' => '98000000',
                'mileage' => 96_500,
                'status' => ListingStatus::Available,
                'featured' => false,
                'description' => 'Nine-seater Super Custom, imported 2019 and used privately since. '
                    .'Automatic, diesel, second-row captain seats. Body straight, interior clean, '
                    .'air conditioning cold front and rear. Logbook in the seller name.',
            ],
            [
                'slug' => '2018-toyota-rav4-hybrid',
                'title' => '2018 Toyota RAV4 Hybrid',
                'make' => 'Toyota',
                'model' => 'RAV4 Hybrid',
                'year' => 2018,
                'price' => '78000000',
                'mileage' => 61_200,
                'status' => ListingStatus::Reserved,
                'featured' => false,
                'description' => 'Low-mileage hybrid RAV4, one owner from new in Uganda. Reserved '
                    .'pending payment. Battery health checked in the last month and reported at 92 per '
                    .'cent. Economical around Kampala in a way a petrol RAV4 is not.',
            ],
            [
                'slug' => '2014-nissan-x-trail',
                'title' => '2014 Nissan X-Trail',
                'make' => 'Nissan',
                'model' => 'X-Trail',
                'year' => 2014,
                'price' => '52000000',
                'mileage' => 173_400,
                'status' => ListingStatus::Sold,
                'featured' => false,
                'description' => 'Sold. Kept here for a short while so buyers can see what recent stock '
                    .'went for. Honest high-mileage X-Trail, sound mechanically, tidy interior.',
            ],
        ];

        foreach ($listings as $data) {
            /** @var ListingStatus $status */
            $status = $data['status'];

            VehicleListing::query()->firstOrCreate(
                ['slug' => $data['slug']],
                [
                    'reference' => 'LST-'.Str::upper((string) Str::ulid()),
                    'vehicle_id' => null,
                    'status' => $status,
                    'title' => $data['title'],
                    'make' => $data['make'],
                    'model' => $data['model'],
                    'year' => $data['year'],
                    'body_type' => str_contains($data['model'], 'Hiace') ? 'van' : 'suv',
                    'fuel_type' => $data['year'] === 2018 ? 'hybrid' : 'diesel',
                    'transmission' => 'automatic',
                    'drive_type' => '4wd',
                    'engine_cc' => 2700,
                    'colour' => 'Silver',
                    'mileage_km' => $data['mileage'],
                    'seating_capacity' => str_contains($data['model'], 'Hiace') ? 9 : 5,
                    'condition' => 'foreign_used',
                    'description' => $data['description'],
                    'asking_price_minor' => Money::parse($data['price'], 'UGX'),
                    'currency' => 'UGX',
                    'is_negotiable' => $status !== ListingStatus::Sold,
                    'is_featured' => $data['featured'],
                    'listed_at' => now()->subDays(random_int(5, 90)),
                    'reserved_at' => $status === ListingStatus::Reserved ? now()->subDays(3) : null,
                    'sold_at' => $status === ListingStatus::Sold ? now()->subDays(9) : null,
                    'sold_price_minor' => $status === ListingStatus::Sold
                        ? Money::parse('49500000', 'UGX')
                        : null,
                    'created_by_user_id' => $staff?->getKey(),
                ],
            );
        }
    }
}
