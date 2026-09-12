<?php

use App\Support\VehicleSpecificationNormaliser;
use Illuminate\Database\Migrations\Migration;

/**
 * Brings existing vehicle rows onto the shared specification vocabulary.
 *
 * The showroom stored "SUV", "Diesel", "Automatic" and the hire fleet stored
 * "suv", "diesel", "automatic" — the same facts, spelled two ways, so a filter
 * built on one silently missed the other and a car moving from hire to sale
 * changed its own specification on the way.
 *
 * The work itself is in App\Support\VehicleSpecificationNormaliser, because it
 * rewrites production rows and therefore needs tests —
 * `tests/Feature/CarHire/VehicleSpecificationNormalisationTest`, which runs
 * against MariaDB as well as SQLite. An earlier version of this migration
 * passed on SQLite and was wrong on MySQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new VehicleSpecificationNormaliser)->run();
    }

    /**
     * Irreversible by design.
     *
     * Rolling back would mean restoring "Diesel" on some rows and "diesel" on
     * others with nothing recording which was which. No column changes shape
     * here, so a rollback of the surrounding migrations needs nothing.
     */
    public function down(): void {}
};
