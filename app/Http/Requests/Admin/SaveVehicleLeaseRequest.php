<?php

namespace App\Http\Requests\Admin;

use App\Enums\LeasePayoutModel;
use App\Models\VehicleLease;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape only.
 *
 * Which figure a payout model actually needs, and whether the owner's account
 * can receive money at all, belong to SaveVehicleLease — it decides them under
 * a lock. This request exists so the form reports its own mistakes first.
 */
class SaveVehicleLeaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        $lease = $this->route('lease');

        if ($lease instanceof VehicleLease) {
            return $this->user()?->can('update', $lease) ?? false;
        }

        return $this->user()?->can('create', VehicleLease::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'owner_id' => ['required', 'integer', 'exists:users,id'],
            'application_id' => ['nullable', 'integer', 'exists:vehicle_lease_applications,id'],
            'payout_model' => ['required', Rule::enum(LeasePayoutModel::class)],
            'monthly_retainer' => ['nullable', 'string', 'max:24'],
            'revenue_share_bps' => [
                'nullable',
                'integer',
                'min:1',
                'max:'.(int) config('leasing.max_revenue_share_bps', 10000),
            ],
            'currency' => ['required', Rule::in(config('pisfa.currency.supported', ['UGX', 'USD']))],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after:starts_on'],
            'notice_period_days' => ['required', 'integer', 'min:0', 'max:365'],
            'terms' => ['nullable', 'string', 'max:8000'],
            'internal_notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'revenue_share_bps' => 'revenue share',
            'monthly_retainer' => 'monthly retainer',
            'notice_period_days' => 'notice period',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'revenue_share_bps.max' => 'A share above 100% would pay out more than the vehicle earned.',
            'ends_on.after' => 'The agreement has to end after it starts.',
        ];
    }
}
