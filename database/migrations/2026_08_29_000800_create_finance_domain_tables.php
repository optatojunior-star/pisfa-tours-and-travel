<?php

use App\Enums\ExpenseStatus;
use App\Enums\PayrollRunStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 40)->unique();

            // Who is out of pocket, or who recorded the company spending.
            $table->foreignId('incurred_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            /*
             * What it was spent on. Nullable because office rent belongs to no
             * vehicle, and restrictOnDelete because an expense is an accounting
             * record that must not vanish with the asset it was charged to.
             */
            $table->foreignId('vehicle_id')->nullable()->constrained()->restrictOnDelete();

            $table->string('status', 24)->default(ExpenseStatus::Draft->value);
            $table->string('category', 32);

            // A calendar date: "when the money was spent" has no timezone.
            $table->date('spent_on');

            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);

            $table->string('description', 255);
            $table->string('supplier', 180)->nullable();
            $table->text('internal_notes')->nullable();

            /*
             * Whether this may be proposed as a deduction against a lease. Set
             * when the expense is approved, and cleared once it has actually
             * been applied, so the same repair cannot be recovered twice.
             */
            $table->boolean('is_recoverable')->default(false);
            $table->foreignId('recovered_on_payout_id')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('reimbursed_at')->nullable();
            $table->string('reimbursement_reference', 120)->nullable();
            $table->string('closure_reason', 255)->nullable();

            $table->timestamps();

            $table->index(['status', 'spent_on']);
            $table->index(['vehicle_id', 'status', 'spent_on']);
            $table->index(['incurred_by_user_id', 'status']);
            $table->index(['category', 'spent_on']);
        });

        Schema::create('payroll_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 40)->unique();

            $table->string('status', 24)->default(PayrollRunStatus::Draft->value);

            // A calendar month, like a lease payout period.
            $table->date('period_start');
            $table->date('period_end');

            $table->char('currency', 3);

            // Roll-ups, kept so a closed run explains itself without recomputing
            // against employee records that have moved on since.
            $table->unsignedBigInteger('gross_total_minor')->default(0);
            $table->unsignedBigInteger('deductions_total_minor')->default(0);
            $table->unsignedBigInteger('net_total_minor')->default(0);
            $table->unsignedBigInteger('employer_cost_minor')->default(0);
            $table->unsignedInteger('employee_count')->default(0);

            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_reference', 120)->nullable();
            $table->string('closure_reason', 255)->nullable();

            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            /*
             * One run per period per currency. A business paying some staff in
             * dollars runs a second payroll rather than mixing currencies in one
             * total, because money is never summed across currencies.
             */
            $table->unique(['period_start', 'currency'], 'payroll_runs_period_unique');
            $table->index(['status', 'period_start']);
        });

        Schema::create('payroll_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            // Snapshots: a payslip stays truthful after somebody is promoted,
            // renamed, or leaves.
            $table->string('employee_name_snapshot', 180);
            $table->string('role_snapshot', 32);

            $table->unsignedBigInteger('gross_minor');
            $table->unsignedBigInteger('allowances_minor')->default(0);
            $table->unsignedBigInteger('deductions_minor')->default(0);
            $table->unsignedBigInteger('net_minor');
            $table->unsignedBigInteger('employer_nssf_minor')->default(0);
            $table->char('currency', 3);

            $table->text('notes')->nullable();

            $table->timestamps();

            // One line per person per run. The database refuses a double
            // payment rather than a read-then-write check two runs could pass.
            $table->unique(['payroll_run_id', 'user_id'], 'payroll_lines_person_unique');
        });

        Schema::create('payroll_deductions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payroll_line_id')->constrained()->cascadeOnDelete();

            $table->string('type', 32);
            $table->string('label', 120);

            /*
             * The basis is kept beside the result so a payslip explains itself:
             * what the deduction was charged on, and what came off.
             */
            $table->unsignedBigInteger('basis_minor');
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->unsignedSmallInteger('sequence')->default(0);

            $table->timestamps();

            $table->index(['payroll_line_id', 'sequence']);
        });

        Schema::table('expenses', function (Blueprint $table): void {
            $table->foreign('recovered_on_payout_id')
                ->references('id')
                ->on('vehicle_lease_payouts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table): void {
            $table->dropForeign(['recovered_on_payout_id']);
        });

        Schema::dropIfExists('payroll_deductions');
        Schema::dropIfExists('payroll_lines');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('expenses');
    }
};
