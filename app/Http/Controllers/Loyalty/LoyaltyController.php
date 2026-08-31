<?php

namespace App\Http\Controllers\Loyalty;

use App\Actions\Loyalty\RedeemLoyaltyPoints;
use App\Enums\LoyaltyTier;
use App\Http\Controllers\Controller;
use App\Http\Requests\Loyalty\RedeemLoyaltyPointsRequest;
use App\Models\LoyaltyAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class LoyaltyController extends Controller
{
    public function index(Request $request): View
    {
        // Created on first visit so a customer always has a code to share,
        // even before they have earned anything.
        $account = LoyaltyAccount::forUser($request->user());

        return view('loyalty.index', [
            'account' => $account,
            'transactions' => $account->transactions()->with('actor:id,name')->paginate(15),
            'referrals' => $account->referrals()->with('referredUser:id,name')->limit(20)->get(),
            'tiers' => LoyaltyTier::cases(),
            'idempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function redeem(
        RedeemLoyaltyPointsRequest $request,
        RedeemLoyaltyPoints $action,
    ): RedirectResponse {
        $transaction = $action->execute(
            $request->user(),
            (int) $request->validated('points'),
            $request->validated('idempotency_key'),
        );

        return redirect()
            ->route('portal.loyalty.index')
            ->with('success', $transaction->description);
    }
}
