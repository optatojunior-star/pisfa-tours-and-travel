<?php

use App\Enums\MaintenanceStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table): void {
            /*
             * The fleet's single odometer truth.
             *
             * Every maintenance record and fuel log carries its own reading, but
             * this column is what they are checked against: a reading below it
             * is refused, so the number can only ever go forwards.
             */
            $table->unsignedInteger('current_odometer_km')->default(0)->after('luggage_capacity');
            $table->timestamp('odometer_updated_at')->nullable()->after('current_odometer_km');
        });

        Schema::table('documents', function (Blueprint $table): void {
            // Insurance, registration, and inspection certificates expire.
            // Nullable because most document categories never do.
            $table->date('expires_at')->nullable()->after('metadata');

            // When the expiry sweep last warned about this document. Without
            // it, expired insurance would produce an identical email every
            // morning until renewal, which is how alerts get filtered away.
            $table->timestamp('expiry_alert_sent_at')->nullable()->after('expires_at');

            $table->index(['expires_at', 'is_current']);
        });

        Schema::create('vehicle_maintenance_records', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();

            $table->string('type', 24);
            $table->string('status', 24)->default(MaintenanceStatus::Scheduled->value);

            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->string('vendor', 180)->nullable();

            $table->date('scheduled_for')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('closure_reason', 255)->nullable();

            // Recorded on completion, which is when a real reading exists.
            $table->unsignedInteger('odometer_km')->nullable();

            // Integer minor units, like every other money column in the system.
            $table->unsignedBigInteger('cost_minor')->default(0);
            $table->char('currency', 3);

            // What the completed work implies about the next one. Either or both
            // may be set; whichever arrives first is what the alert fires on.
            $table->date('next_due_on')->nullable();
            $table->unsignedInteger('next_due_odometer_km')->nullable();

            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('internal_notes')->nullable();

            // Set once an alert has gone out, so a daily sweep does not send the
            // same warning every morning.
            $table->timestamp('due_alert_sent_at')->nullable();

            $table->timestamps();

            $table->index(['vehicle_id', 'status']);
            $table->index(['status', 'scheduled_for']);
            $table->index(['status', 'next_due_on']);
        });

        Schema::create('vehicle_fuel_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();

            $table->timestamp('filled_at');
            $table->unsignedInteger('odometer_km');

            // Millilitres, so volume is an integer for the same reason money is:
            // no float ever participates in a stored quantity.
            $table->unsignedInteger('volume_ml');

            $table->unsignedBigInteger('cost_minor');
            $table->char('currency', 3);

            $table->string('station', 180)->nullable();

            // Consumption can only be computed between two full tanks. A partial
            // fill still records cost, but is excluded from the economy figure.
            $table->boolean('is_full_tank')->default(true);

            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['vehicle_id', 'filled_at']);
            $table->index(['vehicle_id', 'odometer_km']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_fuel_logs');
        Schema::dropIfExists('vehicle_maintenance_records');

        Schema::table('documents', function (Blueprint $table): void {
            $table->dropIndex(['expires_at', 'is_current']);
            $table->dropColumn(['expires_at', 'expiry_alert_sent_at']);
        });

        Schema::table('vehicles', function (Blueprint $table): void {
            $table->dropColumn(['current_odometer_km', 'odometer_updated_at']);
        });
    }
};
