<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Engine capacity and drive type, on hire vehicles and showroom listings.
 *
 * Both were missing entirely. They are the two specifications a Ugandan buyer
 * or hirer asks about first — "how many cc" and "is it 4WD" — and neither the
 * fleet nor the showroom could record an answer, so they were being typed into
 * the free-text description where nothing could filter or compare them.
 *
 * Nullable on purpose. Existing stock has no recorded capacity and inventing
 * one would be worse than admitting it is not stated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table): void {
            $table->unsignedSmallInteger('engine_cc')->nullable()->after('year');
            $table->string('drive_type', 16)->nullable()->after('transmission');
        });

        Schema::table('vehicle_listings', function (Blueprint $table): void {
            $table->unsignedSmallInteger('engine_cc')->nullable()->after('year');
            $table->string('drive_type', 16)->nullable()->after('transmission');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table): void {
            $table->dropColumn(['engine_cc', 'drive_type']);
        });

        Schema::table('vehicle_listings', function (Blueprint $table): void {
            $table->dropColumn(['engine_cc', 'drive_type']);
        });
    }
};
