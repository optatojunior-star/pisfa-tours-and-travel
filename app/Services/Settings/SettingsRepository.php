<?php

namespace App\Services\Settings;

use App\Models\Setting;
use App\Support\Settings\SettingDefinition;

/**
 * Reads runtime settings, falling back to config.
 *
 * A stored value wins; config is what the application ships with. That ordering
 * is what lets a business change its own name or phone number without a deploy,
 * which is the only reason these live in a table at all.
 *
 * Values are memoised for the life of the request. A settings page that queried
 * once per document header would issue a dozen identical reads per PDF.
 */
class SettingsRepository
{
    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    public function get(string $key, mixed $default = null): mixed
    {
        $definition = SettingDefinition::find($key);

        // An unknown key is a programming mistake, not a value to invent: the
        // registry is the list of what exists.
        if ($definition === null) {
            return $default;
        }

        $stored = $this->stored();

        if (array_key_exists($key, $stored)) {
            return $stored[$key];
        }

        $fallback = $definition->fallback();

        return $fallback ?? $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->get($key, $default);

        return $value === null ? $default : (string) $value;
    }

    public function bool(string $key, bool $default = true): bool
    {
        $value = $this->get($key, $default);

        return $value === null ? $default : (bool) $value;
    }

    /**
     * Every registered setting with its effective value, for the settings form.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $values = [];

        foreach (SettingDefinition::all() as $definition) {
            $values[$definition->key] = $this->get($definition->key);
        }

        return $values;
    }

    /** Drops the memo, so a write is visible to the rest of the request. */
    public function forget(): void
    {
        $this->cache = null;
    }

    /**
     * The stored values, read once.
     *
     * Only keys in the registry are returned: a row left behind by an older
     * version of the application must not resurface as a live setting.
     *
     * @return array<string, mixed>
     */
    private function stored(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $keys = SettingDefinition::keys();

        $this->cache = Setting::query()
            ->whereIn('key', $keys)
            ->get()
            ->mapWithKeys(static fn (Setting $setting): array => [
                $setting->key => $setting->typedValue(),
            ])
            ->all();

        return $this->cache;
    }

    /**
     * Company branding, in the shape the PDF layout and the public footer both
     * expect.
     *
     * @return array<string, string>
     */
    public function brand(): array
    {
        return [
            'name' => $this->string('company.name', 'PISFA Tours and Travels'),
            // The registered name, for the documents that must carry it.
            // Falls back to the trading name so a footer is never blank.
            'legal_name' => $this->string('company.legal_name')
                ?: $this->string('company.name', 'PISFA Tours and Travels'),
            'tagline' => $this->string('company.tagline'),
            'email' => $this->string('company.email'),
            'phone' => $this->string('company.phone'),
            'address' => $this->string('company.address'),
            'registration_number' => $this->string('company.registration_number'),
            'tax_identification_number' => $this->string('company.tax_identification_number'),
            'website' => rtrim((string) config('app.url'), '/'),
        ];
    }
}
