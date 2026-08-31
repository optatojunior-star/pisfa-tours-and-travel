<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Loyalty\RegisterReferral;
use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(Request $request): View
    {
        // A referral link arrives as ?ref=CODE; the form carries it forward so
        // it survives a validation failure and a page refresh.
        return view('auth.register', [
            'referralCode' => $this->normalisedCode((string) $request->query('ref', '')),
        ]);
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'phone' => ['nullable', 'string', 'max:32', 'regex:/^\+?[0-9\s().-]{7,32}$/'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            // Loosely validated on purpose: a mistyped code must never block
            // someone from registering. An unknown code is ignored downstream.
            'referral_code' => ['nullable', 'string', 'max:16'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'password' => Hash::make($validated['password']),
            'role' => UserRole::Customer,
            'status' => AccountStatus::Active,
        ]);

        // Attach the referral and pay the welcome bonus. Registration must
        // succeed even if loyalty processing fails, so a failure here is
        // reported rather than thrown — the account is what matters.
        try {
            app(RegisterReferral::class)->execute(
                $user,
                $this->normalisedCode((string) ($validated['referral_code'] ?? '')),
            );
        } catch (Throwable $exception) {
            report($exception);
        }

        event(new Registered($user));

        Auth::login($user);

        return redirect(route('dashboard', absolute: false));
    }

    /** Codes are stored uppercase; accept any casing from a shared link. */
    private function normalisedCode(string $code): ?string
    {
        $code = strtoupper(trim($code));

        return $code === '' ? null : $code;
    }
}
