<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\AcceptStaffInvitationRequest;
use App\Services\StaffInvitationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class StaffInvitationController extends Controller
{
    public function __construct(private readonly StaffInvitationService $invitations) {}

    public function show(Request $request, string $token): Response
    {
        $invitation = $this->invitations->preview($token);

        return response()
            ->view('staff-invitation', [
                'invitation' => $invitation,
                'token' => $token,
            ], $invitation === null ? 410 : 200)
            ->withHeaders([
                'Cache-Control' => 'no-store, private',
                'Referrer-Policy' => 'no-referrer',
                'X-Robots-Tag' => 'noindex, nofollow',
            ]);
    }

    public function accept(AcceptStaffInvitationRequest $request, string $token): RedirectResponse
    {
        $user = $this->invitations->accept($token, $request->string('password')->toString());

        Auth::login($user);
        $request->session()->regenerate();

        return $user->requiresTwoFactorAuthentication()
            ? redirect()->route('profile.security')->with('status', 'Account activated. Set up two-factor authentication to continue.')
            : redirect()->route('dashboard')->with('status', 'Welcome to PISFA. Your account is ready.');
    }
}
