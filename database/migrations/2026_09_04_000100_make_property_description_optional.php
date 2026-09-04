<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The long description is no longer compulsory on a property.
 *
 * The admin form now collects a summary and photographs, which is what the
 * public listing and the cards actually read. Asking for fifty more words of
 * prose before anything could be saved was the reason properties were being
 * left half-entered.
 *
 * Widening a column to accept null cannot fail on existing rows and loses
 * nothing already written. The down direction is deliberately narrow: it only
 * puts the constraint back, and it fills any null with an empty string first so
 * the rollback cannot fail on data added since.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table): void {
            $table->text('description')->nullable()->change();
        });
    }

    public function down(): void
    {
        // NOT NULL cannot be restored while nulls exist.
        Schema::table('properties', function (Blueprint $table): void {
            $table->text('description')->nullable()->change();
        });

        DB::table('properties')
            ->whereNull('description')
            ->update(['description' => '']);

        Schema::table('properties', function (Blueprint $table): void {
            $table->text('description')->nullable(false)->change();
        });
    }
};
