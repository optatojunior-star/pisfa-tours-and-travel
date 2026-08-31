<?php

use App\Enums\LeaseApplicationStatus;
use App\Enums\LeasePayoutStatus;
use App\Enums\LeaseStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_lease_applications', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 40)->unique();

            /*
             * An owner may offer a car before they have an account — the form is
             * the first contact, and demanding a registration first would lose
             * the lead. The lease itself needs an account, because that is where
             * payouts are addressed.
             */
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('status', 32)->default(LeaseApplicationStatus::Submitted->value);

            $table->string('contact_name', 180);
            $table->string('contact_email', 254);
            $table->string('contact_phone', 40);

            // The vehicle as the owner describes it. Verified at inspection, and
            // copied onto the fleet record only once a lease is activated.
            $table->string('make', 60);
            $table->string('model', 80);
            $table->unsignedSmallInteger('year');
            $table->string('registration_plate', 32);
            $table->string('colour', 40)->nullable();
            $table->string('transmission', 24)->nullable();
            $table->string('fuel_type', 24)->nullable();
            $table->unsignedSmallInteger('seating_capacity')->nullable();
            $table->unsignedInteger('mileage_km')->nullable();
            $table->string('condition', 40)->nullable();

            // What the owner hopes for. Recorded because a declined expectation
            // is still information about what owners think their cars are worth.
            $table->string('preferred_payout_model', 24)->nullable();
            $table->unsignedBigInteger('expected_monthly_minor')->nullable();
            $table->char('expected_currency', 3)->nullable();

            $table->date('available_from')->nullable();
            $table->text('notes')->nullable();
            $table->text('internal_notes')->nullable();

            $table->timestamp('inspection_at')->nullable();
            $table->string('inspection_location', 255)->nullable();
            $table->text('inspection_findings')->nullable();

            $table->string('closure_reason', 255)->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->char('idempotency_owner_hash', 64);
            $table->uuid('idempotency_key');

            $table->timestamps();

            // A repeated submit from the same browser is one application.
            $table->unique(['idempotency_owner_hash', 'idempotency_key'], 'lease_applications_idem_unique');
            $table->index(['status', 'created_at']);
            $table->index('owner_id');
        });

        Schema::create('vehicle_leases', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 40)->unique();

            // Payouts are addressed to an account, so this is required even
            // though the application it came from may have been a guest's.
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();

            /*
             * The fleet vehicle this lease supplies. Null while the agreement is
             * still a draft: the vehicle joins the fleet when the lease is
             * activated, and leaves it when the lease ends, so the two facts
             * cannot drift apart.
             */
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('application_id')->nullable();

            $table->string('status', 24)->default(LeaseStatus::Draft->value);

            $table->string('payout_model', 24);

            // Integer minor units for the retainer; integer basis points for the
            // share, so a percentage is never a float. 25% is 2500.
            $table->unsignedBigInteger('monthly_retainer_minor')->nullable();
            $table->unsignedSmallInteger('revenue_share_bps')->nullable();
            $table->char('currency', 3);

            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->unsignedSmallInteger('notice_period_days')->default(30);

            $table->text('terms')->nullable();
            $table->text('internal_notes')->nullable();

            $table->timestamp('activated_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspension_reason', 255)->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('termination_reason', 255)->nullable();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['status', 'starts_on']);
            $table->index(['owner_id', 'status']);
            $table->index('vehicle_id');
        });

        Schema::create('vehicle_lease_payouts', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->foreignId('vehicle_lease_id')->constrained()->cascadeOnDelete();

            $table->string('status', 24)->default(LeasePayoutStatus::Draft->value);

            /*
             * A calendar period, not a moment. "August" is the same month for
             * the owner and the office, and storing timestamps would invite a
             * timezone bug into what everybody treats as a date range.
             */
            $table->date('period_start');
            $table->date('period_end');

            /*
             * The basis, kept beside the result so a statement explains itself:
             * how much the vehicle earned, what share of it was due, what was
             * deducted, and what was actually payable.
             */
            $table->unsignedBigInteger('gross_revenue_minor')->default(0);
            $table->unsignedInteger('hire_count')->default(0);
            $table->unsignedSmallInteger('revenue_share_bps')->nullable();
            $table->unsignedBigInteger('earned_minor')->default(0);
            $table->unsignedBigInteger('deductions_minor')->default(0);
            $table->string('deductions_note', 255)->nullable();
            $table->unsignedBigInteger('net_payable_minor')->default(0);
            $table->char('currency', 3);

            /*
             * Hire income in a currency the lease is not denominated in. Never
             * converted and never added: money is not summed across currencies,
             * so it is reported as excluded rather than silently dropped.
             */
            $table->unsignedInteger('excluded_hire_count')->default(0);

            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_reference', 120)->nullable();
            $table->string('closure_reason', 255)->nullable();

            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            /*
             * One payout per lease per period, enforced by the database rather
             * than by a read-then-write check two runs could both pass. This is
             * what stops an owner being paid twice for the same month.
             */
            $table->unique(['vehicle_lease_id', 'period_start'], 'lease_payouts_period_unique');
            $table->index(['status', 'period_start']);
        });

        Schema::table('vehicle_leases', function (Blueprint $table): void {
            $table->foreign('application_id')
                ->references('id')
                ->on('vehicle_lease_applications')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_leases', function (Blueprint $table): void {
            $table->dropForeign(['application_id']);
        });

        Schema::dropIfExists('vehicle_lease_payouts');
        Schema::dropIfExists('vehicle_leases');
        Schema::dropIfExists('vehicle_lease_applications');
    }
};
