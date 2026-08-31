<?php

use App\Enums\DriverTripStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_profiles', function (Blueprint $table): void {
            $table->id();

            // One profile per driver, enforced by the database rather than by a
            // read-then-write that could race two concurrent creations.
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // The licence number identifies a person to a regulator, so it is
            // encrypted at rest like every other identity document reference.
            $table->text('licence_number')->nullable();
            $table->string('licence_class', 24)->nullable();
            $table->date('licence_expires_at')->nullable();
            $table->date('medical_expires_at')->nullable();

            $table->unsignedSmallInteger('years_experience')->nullable();
            $table->string('emergency_contact_name', 180)->nullable();
            $table->string('emergency_contact_phone', 40)->nullable();

            // Whether the driver is currently taking work. Distinct from the
            // account status: an active account can still be on leave.
            $table->boolean('is_available')->default(true);
            $table->string('unavailable_reason', 255)->nullable();

            $table->text('internal_notes')->nullable();

            // Mirrors the fleet document pattern, so a lapsed licence is not
            // re-reported every morning.
            $table->timestamp('expiry_alert_sent_at')->nullable();

            $table->timestamps();

            $table->index(['is_available', 'licence_expires_at']);
        });

        Schema::create('driver_trips', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 40)->unique();

            // The assignment this trip fulfils, in whichever domain it came
            // from. Polymorphic because tours, car hire, and airport transfers
            // each keep their own assignment table.
            $table->string('assignment_type');
            $table->unsignedBigInteger('assignment_id');

            $table->foreignId('driver_user_id')->constrained('users')->cascadeOnDelete();

            // A tour may be run in a vehicle that is not in the hire fleet, so
            // this is nullable and the odometer capture is skipped when it is.
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();

            $table->string('status', 24)->default(DriverTripStatus::Scheduled->value);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('closure_reason', 255)->nullable();

            $table->unsignedInteger('start_odometer_km')->nullable();
            $table->unsignedInteger('end_odometer_km')->nullable();

            // Stored rather than always derived so a completed trip's distance
            // survives even if a reading is later corrected upstream.
            $table->unsignedInteger('distance_km')->nullable();

            $table->text('driver_notes')->nullable();
            $table->timestamps();

            // One trip per assignment, ever. A double submit cannot create two.
            $table->unique(['assignment_type', 'assignment_id'], 'driver_trips_assignment_unique');
            $table->index(['driver_user_id', 'status']);
            $table->index(['vehicle_id', 'completed_at']);
        });

        Schema::create('vehicle_inspections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('driver_trip_id')->nullable()->constrained()->nullOnDelete();

            $table->string('phase', 16);
            $table->unsignedInteger('odometer_km');

            // The full answer set, normalised to the known checklist so a stored
            // inspection can be re-rendered years later without depending on
            // what the form happened to say that day.
            $table->json('answers');

            $table->boolean('has_defects')->default(false);
            $table->boolean('passed')->default(true);
            $table->text('defect_notes')->nullable();

            // The maintenance job raised from a defect, so the repair queue and
            // the inspection that caused it stay linked.
            $table->foreignId('maintenance_record_id')->nullable()
                ->constrained('vehicle_maintenance_records')->nullOnDelete();

            $table->timestamps();

            // One check of each phase per trip: a driver cannot quietly redo a
            // failed pre-trip check until it passes.
            $table->unique(['driver_trip_id', 'phase'], 'vehicle_inspections_trip_phase_unique');
            $table->index(['vehicle_id', 'created_at']);
            $table->index(['has_defects', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_inspections');
        Schema::dropIfExists('driver_trips');
        Schema::dropIfExists('driver_profiles');
    }
};
