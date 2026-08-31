<?php

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\Console\Command\Command;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('pisfa:create-super-admin', function () {
    $this->info('Create the initial PISFA super administrator. Password input is hidden and is never accepted as a command option.');

    $name = trim((string) $this->ask('Full name'));
    $email = mb_strtolower(trim((string) $this->ask('Email address')));
    $password = (string) $this->secret('Password (at least 12 characters with upper/lowercase, a number, and a symbol)');
    $passwordConfirmation = (string) $this->secret('Confirm password');

    $validator = Validator::make([
        'name' => $name,
        'email' => $email,
        'password' => $password,
        'password_confirmation' => $passwordConfirmation,
    ], [
        'name' => ['required', 'string', 'max:120'],
        'email' => [
            'required',
            'email:rfc',
            'max:255',
            Rule::unique(User::class, 'email'),
        ],
        'password' => [
            'required',
            'confirmed',
            Password::min(12)->letters()->mixedCase()->numbers()->symbols(),
        ],
    ]);

    if ($validator->fails()) {
        foreach ($validator->errors()->all() as $error) {
            $this->error($error);
        }

        return Command::FAILURE;
    }

    if (! $this->confirm('Create this active, email-verified super administrator?', false)) {
        $this->warn('No account was created.');

        return Command::SUCCESS;
    }

    $user = DB::transaction(function () use ($name, $email, $password): User {
        $user = new User;
        $user->forceFill([
            'name' => $name,
            'email' => $email,
            'email_verified_at' => now(),
            'password' => Hash::make($password),
            'role' => UserRole::SuperAdmin,
            'status' => AccountStatus::Active,
            'preferred_language' => 'en',
            'preferred_currency' => 'UGX',
            'must_change_password' => false,
            'two_factor_required' => true,
        ])->save();

        app(AuditLogger::class)->record(
            event: 'staff.super_admin_bootstrapped',
            auditable: $user,
            newValues: [
                'email' => $user->email,
                'role' => UserRole::SuperAdmin->value,
                'status' => AccountStatus::Active->value,
                'two_factor_required' => true,
            ],
            context: ['url' => 'console:pisfa:create-super-admin'],
            user: $user,
        );

        return $user;
    });

    $this->newLine();
    $this->info('Super administrator created for '.$user->email.'.');
    $this->warn('Sign in and configure two-factor authentication before using protected administration tools.');

    return Command::SUCCESS;
})->purpose('Interactively create the initial production super administrator');

Schedule::command('tours:send-departure-reminders')
    ->everyMinute()
    ->withoutOverlapping(10);

Schedule::command('car-hire:expire-pending-bookings')
    ->everyMinute()
    ->withoutOverlapping(10);

// Availability already ignores an expired hold, so this sweep is about giving
// the guest a definite answer and keeping the desk queue honest rather than
// about protecting the inventory.
Schedule::command('accommodation:expire-pending-bookings')
    ->everyMinute()
    ->withoutOverlapping(10);

// A whole-day matter, so one morning pass is enough. The event marker keeps a
// repeat run quiet.
Schedule::command('accommodation:send-arrival-reminders')
    ->dailyAt('07:00')
    ->timezone(config('pisfa.business_timezone', 'Africa/Kampala'))
    ->withoutOverlapping(15);

Schedule::command('car-hire:send-return-reminders')
    ->everyMinute()
    ->withoutOverlapping(10);

Schedule::command('airport-transfers:expire-pending-requests')
    ->everyMinute()
    ->withoutOverlapping(10);

Schedule::command('airport-transfers:send-pickup-reminders')
    ->everyMinute()
    ->withoutOverlapping(10);

// Loyalty awards run frequently so points appear soon after a booking settles.
Schedule::command('loyalty:process-awards')
    ->everyFiveMinutes()
    ->withoutOverlapping(10);

// Expiry is a monthly sweep; the command is idempotent within a period.
Schedule::command('loyalty:expire-points')
    ->monthlyOn(1, '02:00')
    ->withoutOverlapping(30);

// One invitation per booking, so an hourly sweep is frequent enough and the
// unique marker makes a repeat run harmless.
Schedule::command('reviews:send-requests')
    ->hourlyAt(20)
    ->withoutOverlapping(15);

// Validity is a whole-day boundary, so a daily pass just after midnight in
// Kampala is enough. Accepting an expired offer is refused regardless.
Schedule::command('quotations:expire')
    ->dailyAt('00:15')
    ->timezone(config('pisfa.business_timezone', 'Africa/Kampala'))
    ->withoutOverlapping(15);

// A scheduled post is invisible until this promotes it, so a late sweep
// delays an article rather than leaking one. Every five minutes is close
// enough for editorial timing.
Schedule::command('content:publish-scheduled')
    ->everyFiveMinutes()
    ->withoutOverlapping(10);

// Paperwork expiry and service due are whole-day matters, so one morning pass
// is enough. Reported records are marked, so a repeat run stays quiet.
Schedule::command('fleet:send-alerts')
    ->dailyAt('06:30')
    ->timezone(config('pisfa.business_timezone', 'Africa/Kampala'))
    ->withoutOverlapping(15);

// The heartbeat the scheduler health check reads. Every minute, so a stale
// value means the cron entry itself has stopped rather than that one job is
// slow. Deliberately its own command: folding it into an existing task would
// make it prove that task still works, not that the scheduler is running.
Schedule::command('pisfa:heartbeat')
    ->everyMinute()
    ->withoutOverlapping();

// Backups run before dawn, when the site is quiet. The dump is taken through
// PDO rather than mysqldump, because shared hosts routinely disable exec().
Schedule::command('pisfa:backup')
    ->dailyAt('02:15')
    ->timezone(config('pisfa.business_timezone', 'Africa/Kampala'))
    ->withoutOverlapping(60);
