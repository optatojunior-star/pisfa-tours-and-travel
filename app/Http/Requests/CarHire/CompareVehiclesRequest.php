<?php

namespace App\Http\Requests\CarHire;

use Illuminate\Foundation\Http\FormRequest;

class CompareVehiclesRequest extends FormRequest
{
    /** The comparison shows only what the public catalogue already shows. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Slugs, not ids. The comparison is a shareable address — somebody
            // sends it to whoever is paying — so it should read as vehicles
            // rather than as row numbers, and an id would leak how many
            // vehicles the fleet has ever held.
            'vehicles' => ['required', 'array', 'min:2', 'max:'.self::maximum()],
            'vehicles.*' => ['string', 'max:200', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', 'distinct'],

            // Carried through so "Book this one" keeps the dates and mode the
            // customer had already chosen on the catalogue.
            'pickup_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'return_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'hire_mode' => ['nullable', 'string', 'max:20'],
            'currency' => ['nullable', 'string', 'max:3'],
        ];
    }

    /**
     * Four columns fits a laptop and still scrolls acceptably on a phone.
     * Beyond that a comparison stops being a comparison and becomes a list.
     */
    public static function maximum(): int
    {
        return 4;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'vehicles.required' => 'Tick at least two vehicles to compare them.',
            'vehicles.min' => 'Tick at least two vehicles to compare them.',
            'vehicles.max' => 'Compare up to '.self::maximum().' vehicles at a time.',
        ];
    }
}
