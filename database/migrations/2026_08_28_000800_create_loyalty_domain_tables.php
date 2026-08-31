<?php

use App\Enums\LoyaltyTier;
use App\Enums\ReferralStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();

            // Spendable balance. Tier is derived from lifetime_points instead,
            // so redeeming never demotes a customer who already earned standing.
            $table->unsignedBigInteger('points_balance')->default(0);
            $table->unsignedBigInteger('lifetime_points')->default(0);
            $table->string('tier', 16)->default(LoyaltyTier::Bronze->value);

            // Shared publicly in referral links, so it must be unguessable
            // enough not to be enumerated and unique enough to attribute.
            $table->string('referral_code', 16)->unique();

            // Drives the 12-month inactivity expiry.
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('expiry_warned_at')->nullable();

            $table->timestamps();

            $table->index(['tier', 'lifetime_points'], 'loyalty_accounts_tier_index');
            $table->index('last_activity_at', 'loyalty_accounts_activity_index');
        });

        Schema::create('loyalty_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('loyalty_account_id')->constrained('loyalty_accounts')->cascadeOnDelete();
            $table->string('type', 24);

            // Signed: credits positive, debits negative. The running balance is
            // stored so the ledger can be audited without replaying it.
            $table->bigInteger('points');
            $table->unsignedBigInteger('balance_after');

            // What earned or spent the points.
            $table->nullableMorphs('source');
            $table->string('description', 255);
            $table->json('payload')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Duplicate-award prevention. One award per (account, source event),
            // enforced by the database rather than by application discipline.
            $table->string('idempotency_key', 191);

            $table->timestamps();

            $table->unique(
                ['loyalty_account_id', 'idempotency_key'],
                'loyalty_transactions_idempotency_unique',
            );
            $table->index(['loyalty_account_id', 'created_at'], 'loyalty_transactions_ledger_index');
            $table->index(['type', 'created_at'], 'loyalty_transactions_type_index');
        });

        Schema::create('referrals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('referrer_account_id')->constrained('loyalty_accounts')->cascadeOnDelete();

            // A person can be referred only once, ever.
            $table->foreignId('referred_user_id')->unique()->constrained('users')->cascadeOnDelete();

            $table->string('code_used', 16);
            $table->string('status', 16)->default(ReferralStatus::Pending->value);
            $table->timestamp('qualified_at')->nullable();
            $table->timestamp('rewarded_at')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['referrer_account_id', 'status'], 'referrals_referrer_status_index');
            $table->index('status', 'referrals_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referrals');
        Schema::dropIfExists('loyalty_transactions');
        Schema::dropIfExists('loyalty_accounts');
    }
};
