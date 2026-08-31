@php
    use App\Models\LoyaltyAccount;
    use App\Support\Money;

    $timezone = config('pisfa.business_timezone', 'Africa/Kampala');
    $minimum = $account->minimumRedemption();
    $expiresOn = $account->expiresOn();
@endphp
<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Loyalty</p>
            <h1 class="mt-1 text-2xl font-bold text-slate-950">Points and referrals</h1>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900" role="status">{{ session('success') }}</div>
            @endif
            @if ($errors->any())
                <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800" role="alert">
                    <p class="font-bold">That could not be completed.</p>
                    <ul class="mt-2 list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                </div>
            @endif

            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="loyalty-balance">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h2 id="loyalty-balance" class="text-xs font-semibold uppercase tracking-wide text-slate-500">Available points</h2>
                        <p class="mt-1 text-4xl font-black text-emerald-800">{{ number_format($account->points_balance) }}</p>
                        <p class="mt-1 text-sm text-slate-600">Worth {{ $account->formattedBalanceValue() }}</p>
                    </div>
                    <div class="text-right">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Tier</p>
                        <p class="mt-1 text-2xl font-black text-slate-900">{{ $account->tier->label() }}</p>
                        <p class="mt-1 text-sm font-semibold text-emerald-700">{{ $account->tier->discountPercent() }}% member discount</p>
                    </div>
                </div>

                @php $toNext = $account->pointsToNextTier(); @endphp
                <div class="mt-6">
                    <div class="flex items-center justify-between text-xs font-semibold text-slate-500">
                        <span>{{ number_format($account->lifetime_points) }} lifetime points</span>
                        <span>
                            @if ($toNext === null)
                                Highest tier reached
                            @else
                                {{ number_format($toNext) }} to {{ $account->tier->next()->label() }}
                            @endif
                        </span>
                    </div>
                    <div class="mt-2 h-2.5 w-full overflow-hidden rounded-full bg-slate-200" role="progressbar"
                         aria-valuenow="{{ $account->tierProgressPercent() }}" aria-valuemin="0" aria-valuemax="100"
                         aria-label="Progress to the next loyalty tier">
                        <div class="h-full rounded-full bg-emerald-600" style="width: {{ $account->tierProgressPercent() }}%"></div>
                    </div>
                    <p class="mt-2 text-xs text-slate-500">Your tier is based on points you have earned in total. Redeeming never lowers it.</p>
                </div>

                @if ($expiresOn !== null)
                    <p class="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm font-semibold text-amber-900" role="note">
                        These points expire on {{ $expiresOn->timezone($timezone)->format('j M Y') }} unless you book or redeem before then.
                    </p>
                @endif
            </section>

            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="loyalty-tiers">
                <h2 id="loyalty-tiers" class="text-lg font-black text-slate-950">Tiers</h2>
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <caption class="sr-only">Loyalty tiers, thresholds, and discounts</caption>
                        <thead class="bg-slate-50 text-left text-xs font-bold uppercase tracking-wide text-slate-600">
                            <tr>
                                <th scope="col" class="px-4 py-3">Tier</th>
                                <th scope="col" class="px-4 py-3">Lifetime points</th>
                                <th scope="col" class="px-4 py-3">Discount</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($tiers as $tier)
                                <tr @class(['bg-emerald-50/60' => $tier === $account->tier])>
                                    <td class="px-4 py-3 font-bold text-slate-900">
                                        {{ $tier->label() }}
                                        @if ($tier === $account->tier)<span class="ml-2 rounded-full bg-emerald-600 px-2 py-0.5 text-xs font-bold text-white">You</span>@endif
                                    </td>
                                    <td class="px-4 py-3">{{ number_format($tier->threshold()) }}+</td>
                                    <td class="px-4 py-3 font-semibold">{{ $tier->discountPercent() }}%</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="loyalty-redeem">
                <h2 id="loyalty-redeem" class="text-lg font-black text-slate-950">Redeem points</h2>
                <p class="mt-2 text-sm text-slate-600">
                    Minimum {{ number_format($minimum) }} points. Each point is worth
                    {{ Money::format(LoyaltyAccount::pointsValueMinor(1), config('payments.base_currency', 'UGX')) }}
                    of account credit.
                </p>

                @if (! $account->canRedeem())
                    <p class="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm font-semibold text-slate-700" role="status">
                        You need {{ number_format($minimum - $account->points_balance) }} more points to redeem.
                    </p>
                @else
                    <form method="POST" action="{{ route('portal.loyalty.redeem') }}" class="mt-4 space-y-4">
                        @csrf
                        <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">
                        <div class="sm:max-w-xs">
                            <label for="points" class="block text-sm font-semibold text-slate-800">Points to redeem</label>
                            <input id="points" name="points" type="number" required min="{{ $minimum }}" max="{{ $account->points_balance }}" step="1" value="{{ old('points', $account->points_balance) }}" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            <x-input-error :messages="$errors->get('points')" class="mt-1" />
                        </div>
                        <div class="flex items-start gap-3">
                            <input id="confirm-redemption" name="confirm_redemption" type="checkbox" value="1" required class="mt-0.5 size-5 rounded border-slate-400 text-emerald-700 focus:ring-emerald-600">
                            <label for="confirm-redemption" class="text-sm text-slate-700">I confirm I want to redeem these points for account credit.</label>
                        </div>
                        <x-input-error :messages="$errors->get('confirm_redemption')" class="mt-1" />
                        <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-emerald-700 px-5 py-3 text-sm font-bold text-white hover:bg-emerald-800">Redeem points</button>
                    </form>
                @endif
            </section>

            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="loyalty-referrals">
                <h2 id="loyalty-referrals" class="text-lg font-black text-slate-950">Refer a friend</h2>
                <p class="mt-2 text-sm text-slate-600">
                    They get {{ number_format((int) config('loyalty.referral.join_points', 100)) }} points when they join.
                    You get {{ number_format((int) config('loyalty.referral.reward_points', 200)) }} points once they complete their first booking.
                </p>

                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Your code</p>
                        <p class="mt-1 font-mono text-2xl font-black tracking-wider text-emerald-800">{{ $account->referral_code }}</p>
                    </div>
                    <div>
                        <label for="referral-link" class="text-xs font-semibold uppercase tracking-wide text-slate-500">Your link</label>
                        <input id="referral-link" type="text" readonly value="{{ $account->referralUrl() }}" onfocus="this.select()" class="mt-1 block w-full rounded-xl border-slate-300 bg-slate-50 font-mono text-xs">
                    </div>
                </div>

                @if ($referrals->isEmpty())
                    <p class="mt-5 text-sm text-slate-600">No referrals yet. Share your link to get started.</p>
                @else
                    <ul class="mt-5 space-y-2">
                        @foreach ($referrals as $referral)
                            <li class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 p-3 text-sm">
                                <span class="font-semibold text-slate-900">{{ $referral->referredUser?->name ?? 'A friend' }}</span>
                                <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700">{{ $referral->status->label() }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="loyalty-ledger">
                <h2 id="loyalty-ledger" class="text-lg font-black text-slate-950">Points history</h2>

                @if ($transactions->isEmpty())
                    <p class="mt-3 text-sm text-slate-600">Nothing yet. Points appear here after a booking is paid in full.</p>
                @else
                    <div class="mt-4 overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <caption class="sr-only">Loyalty points ledger</caption>
                            <thead class="bg-slate-50 text-left text-xs font-bold uppercase tracking-wide text-slate-600">
                                <tr>
                                    <th scope="col" class="px-4 py-3">Date</th>
                                    <th scope="col" class="px-4 py-3">Activity</th>
                                    <th scope="col" class="px-4 py-3 text-right">Points</th>
                                    <th scope="col" class="px-4 py-3 text-right">Balance</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($transactions as $transaction)
                                    <tr>
                                        <td class="px-4 py-3 whitespace-nowrap text-xs">{{ $transaction->created_at->timezone($timezone)->format('j M Y') }}</td>
                                        <td class="px-4 py-3">
                                            <span class="block font-semibold text-slate-900">{{ $transaction->type->label() }}</span>
                                            <span class="block text-xs text-slate-500">{{ $transaction->description }}</span>
                                        </td>
                                        <td class="px-4 py-3 text-right font-bold {{ $transaction->isCredit() ? 'text-emerald-700' : 'text-rose-700' }}">{{ $transaction->signedPoints() }}</td>
                                        <td class="px-4 py-3 text-right font-semibold">{{ number_format($transaction->balance_after) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-4">{{ $transactions->links() }}</div>
                @endif
            </section>
        </div>
    </div>
</x-app-layout>
