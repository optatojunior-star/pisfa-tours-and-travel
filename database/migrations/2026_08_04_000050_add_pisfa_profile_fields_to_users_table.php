<?php

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('phone', 32)->nullable()->index();
            $table->string('role', 32)->default(UserRole::Customer->value)->index();
            $table->string('status', 32)->default(AccountStatus::Active->value)->index();
            $table->string('preferred_language', 10)->default('en');
            $table->char('preferred_currency', 3)->default('UGX');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['phone']);
            $table->dropIndex(['role']);
            $table->dropIndex(['status']);
            $table->dropColumn([
                'phone',
                'role',
                'status',
                'preferred_language',
                'preferred_currency',
            ]);
        });
    }
};
