<?php

use App\Enums\CorporateAccountStatus;
use App\Enums\CorporateMemberRole;
use App\Enums\GroupBookingStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('corporate_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 200)->unique();
            $table->string('name', 200);
            $table->string('registration_number', 60)->nullable();
            $table->string('tax_identification_number', 40)->nullable();
            $table->string('industry', 120)->nullable();

            $table->string('billing_contact_name', 180);
            $table->string('billing_contact_email', 254);
            $table->string('billing_contact_phone', 40);
            $table->string('billing_address', 500)->nullable();

            $table->string('status', 24)->default(CorporateAccountStatus::Prospect->value);

            /*
             * Terms. The credit limit is a ceiling on what may be outstanding at
             * once; the balance itself is never stored, because a stored figure
             * drifts the moment an invoice is voided or a payment lands out of
             * band. It is always computed from live invoices.
             */
            $table->unsignedSmallInteger('payment_terms_days')->default(30);
            $table->unsignedBigInteger('credit_limit_minor')->default(0);
            $table->char('currency', 3);

            // Integer basis points, so an agreed discount is never a float.
            $table->unsignedSmallInteger('discount_bps')->default(0);

            $table->text('notes')->nullable();
            $table->text('internal_notes')->nullable();

            $table->timestamp('activated_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspension_reason', 255)->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('closure_reason', 255)->nullable();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'name']);
        });

        Schema::create('corporate_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('corporate_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('role', 24)->default(CorporateMemberRole::Traveller->value);
            $table->string('job_title', 120)->nullable();

            /*
             * Deactivated rather than deleted: revoking somebody's authority to
             * book must not remove the bookings they already made, and the
             * record of who could do what when is part of the audit story.
             */
            $table->boolean('is_active')->default(true);
            $table->timestamp('deactivated_at')->nullable();

            $table->foreignId('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // One membership per person per company.
            $table->unique(['corporate_account_id', 'user_id'], 'corporate_members_unique');
            $table->index(['user_id', 'is_active']);
        });

        Schema::create('group_bookings', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 40)->unique();

            /*
             * Nullable: a school trip or a family reunion is a group without
             * being a company, and forcing an account would turn a lead away.
             */
            $table->foreignId('corporate_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('organiser_id')->constrained('users')->restrictOnDelete();

            $table->string('status', 24)->default(GroupBookingStatus::Enquiry->value);

            $table->string('title', 200);
            $table->string('service_kind', 40);

            // Calendar dates: a trip runs from a day to a day.
            $table->date('starts_on');
            $table->date('ends_on');

            /*
             * What was agreed. The manifest is checked against this before the
             * group may be confirmed — a coach booked for forty with
             * thirty-seven names on the list is three people with no seat.
             */
            $table->unsignedSmallInteger('headcount');

            $table->string('pickup_location', 500)->nullable();
            $table->string('destination', 500)->nullable();
            $table->text('requirements')->nullable();
            $table->text('internal_notes')->nullable();

            $table->unsignedBigInteger('quoted_total_minor')->nullable();
            $table->char('currency', 3);

            $table->foreignId('quotation_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('closure_reason', 255)->nullable();

            $table->timestamps();

            $table->index(['status', 'starts_on']);
            $table->index(['corporate_account_id', 'status']);
            $table->index(['organiser_id', 'status']);
        });

        Schema::create('group_travelers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('group_booking_id')->constrained()->cascadeOnDelete();

            // Nullable: most people on a manifest have no account, and demanding
            // one would make a forty-person list impossible to assemble.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('full_name', 180);
            $table->string('traveler_type', 24);
            $table->string('contact_phone', 40)->nullable();
            $table->string('contact_email', 254)->nullable();

            /*
             * Identity document details, needed for park permits and border
             * crossings. Hidden from serialisation on the model.
             */
            $table->string('identity_document', 60)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('nationality', 80)->nullable();

            $table->string('dietary_requirements', 255)->nullable();
            $table->string('accessibility_needs', 255)->nullable();
            $table->string('emergency_contact_name', 180)->nullable();
            $table->string('emergency_contact_phone', 40)->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['group_booking_id', 'full_name']);
        });

        Schema::table('invoices', function (Blueprint $table): void {
            /*
             * The account an invoice is billed to. `company_name` stays as the
             * snapshot of what was printed; this is the link the credit check
             * follows.
             */
            $table->foreignId('corporate_account_id')
                ->nullable()
                ->after('customer_id')
                ->constrained()
                ->nullOnDelete();

            $table->index('corporate_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropForeign(['corporate_account_id']);
            $table->dropIndex(['corporate_account_id']);
            $table->dropColumn('corporate_account_id');
        });

        Schema::dropIfExists('group_travelers');
        Schema::dropIfExists('group_bookings');
        Schema::dropIfExists('corporate_members');
        Schema::dropIfExists('corporate_accounts');
    }
};
