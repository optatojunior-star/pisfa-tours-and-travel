<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            // Reference data first: these are safe everywhere and the demo
            // seeders below depend on them.
            TourCategorySeeder::class,
            SystemSettingSeeder::class,

            // Demo data. Each of these refuses to run outside local/testing,
            // and each is additive and idempotent — nothing here truncates.
            DemoRoleUserSeeder::class,
            DemoCatalogueSeeder::class,
            DemoContentSeeder::class,
        ]);
    }
}
