<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_are_read_using_their_original_types(): void
    {
        $values = [
            'site.name' => 'PISFA',
            'booking.minimum_days' => 2,
            'tax.rate' => 0.18,
            'booking.enabled' => true,
            'booking.disabled' => false,
            'site.contacts' => ['phone' => '+256700000000', 'channels' => ['sms', 'whatsapp']],
            'site.optional_notice' => null,
        ];

        foreach ($values as $key => $value) {
            Setting::setValue($key, $value);
        }

        foreach ($values as $key => $value) {
            $this->assertSame($value, Setting::getValue($key));
        }

        $this->assertNull(Setting::getValue('site.optional_notice', 'fallback'));
        $this->assertSame('fallback', Setting::getValue('missing.setting', 'fallback'));
    }

    public function test_setting_metadata_is_preserved_when_a_value_is_updated(): void
    {
        Setting::setValue('site.currency', 'UGX', [
            'group' => 'payments',
            'description' => 'Default display currency',
            'is_public' => true,
        ]);

        Setting::setValue('site.currency', 'USD');

        $setting = Setting::query()->where('key', 'site.currency')->firstOrFail();

        $this->assertSame('USD', $setting->typedValue());
        $this->assertSame('payments', $setting->group);
        $this->assertSame('Default display currency', $setting->description);
        $this->assertTrue($setting->is_public);
    }
}
