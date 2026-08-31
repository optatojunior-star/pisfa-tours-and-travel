<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SystemSettingSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            'company.name' => [config('app.name'), 'general', 'Public company name', true],
            'company.contact_email' => [config('pisfa.contact.email'), 'contact', 'Primary public email', true],
            'company.contact_phone' => [config('pisfa.contact.phone'), 'contact', 'Primary public phone', true],
            'localization.business_timezone' => [config('pisfa.business_timezone'), 'localization', 'Business display timezone', false],
            'payments.default_currency' => [config('pisfa.currency.default'), 'payments', 'Default transaction currency', true],
            'payments.supported_currencies' => [config('pisfa.currency.supported'), 'payments', 'Enabled currencies', true],
        ];

        foreach ($settings as $key => [$value, $group, $description, $isPublic]) {
            if (Setting::query()->where('key', $key)->exists()) {
                continue;
            }

            Setting::setValue($key, $value, [
                'group' => $group,
                'description' => $description,
                'is_public' => $isPublic,
            ]);
        }
    }
}
