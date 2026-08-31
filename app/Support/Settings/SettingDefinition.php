<?php

namespace App\Support\Settings;

use App\Models\Setting;

/**
 * The settings an administrator may edit at runtime.
 *
 * An allowlist, and deliberately a small one. **No credential ever appears
 * here.** Provider keys, webhook secrets, mail passwords, and the application
 * key stay in environment variables where they are not readable through a web
 * form, not stored in a table a backup copies, and not rendered on a screen
 * somebody can screenshot. A settings page that could hold an API key would
 * defeat the rule that put it in the environment in the first place.
 *
 * What belongs here is what a business genuinely changes without a deploy:
 * its own name and contact details, and a handful of operational toggles.
 */
final class SettingDefinition
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $group,
        public readonly string $type,
        public readonly string $description,
        /** The config key this falls back to when nothing is stored. */
        public readonly ?string $configFallback = null,
        /** @var list<string> */
        public readonly array $rules = ['nullable', 'string', 'max:255'],
        /** Whether the value may be read without authentication. */
        public readonly bool $isPublic = true,
    ) {}

    /** @return list<self> */
    public static function all(): array
    {
        return [
            new self(
                key: 'company.name',
                label: 'Company name',
                group: 'company',
                type: Setting::TYPE_STRING,
                description: 'Shown on the website, in emails, and on every generated PDF.',
                configFallback: 'pisfa.company.name',
                rules: ['required', 'string', 'min:2', 'max:180'],
            ),
            new self(
                key: 'company.tagline',
                label: 'Tagline',
                group: 'company',
                type: Setting::TYPE_STRING,
                description: 'A short line beneath the company name on documents.',
                configFallback: 'pisfa.company.tagline',
                rules: ['nullable', 'string', 'max:180'],
            ),
            new self(
                key: 'company.email',
                label: 'Contact email',
                group: 'company',
                type: Setting::TYPE_STRING,
                description: 'The address customers are told to reply to.',
                configFallback: 'pisfa.company.email',
                rules: ['required', 'email:rfc', 'max:254'],
            ),
            new self(
                key: 'company.phone',
                label: 'Contact phone',
                group: 'company',
                type: Setting::TYPE_STRING,
                description: 'Shown on the website and on documents.',
                configFallback: 'pisfa.company.phone',
                rules: ['required', 'string', 'max:40', 'regex:/\A\+?[0-9][0-9\s().-]{6,39}\z/'],
            ),
            new self(
                key: 'company.address',
                label: 'Postal address',
                group: 'company',
                type: Setting::TYPE_STRING,
                description: 'Appears in the document header.',
                configFallback: 'pisfa.company.address',
                rules: ['nullable', 'string', 'max:255'],
            ),
            new self(
                key: 'company.registration_number',
                label: 'Registration number',
                group: 'company',
                type: Setting::TYPE_STRING,
                description: 'Printed in the footer of generated documents, when set.',
                configFallback: 'pisfa.company.registration_number',
                rules: ['nullable', 'string', 'max:60'],
            ),
            new self(
                key: 'company.tax_identification_number',
                label: 'Tax identification number',
                group: 'company',
                type: Setting::TYPE_STRING,
                description: 'Printed in the footer of generated documents, when set.',
                configFallback: 'pisfa.company.tax_identification_number',
                rules: ['nullable', 'string', 'max:60'],
            ),

            new self(
                key: 'site.announcement',
                label: 'Site announcement',
                group: 'site',
                type: Setting::TYPE_STRING,
                description: 'A short notice shown across the public site. Leave blank to hide it.',
                rules: ['nullable', 'string', 'max:255'],
            ),
            new self(
                key: 'site.accepting_online_bookings',
                label: 'Accept online bookings',
                group: 'site',
                type: Setting::TYPE_BOOLEAN,
                description: 'Turning this off hides booking controls and explains why, rather than failing at submit.',
                rules: ['boolean'],
            ),
        ];
    }

    public static function find(string $key): ?self
    {
        foreach (self::all() as $definition) {
            if ($definition->key === $key) {
                return $definition;
            }
        }

        return null;
    }

    /** @return array<string, list<self>> */
    public static function grouped(): array
    {
        $groups = [];

        foreach (self::all() as $definition) {
            $groups[$definition->group][] = $definition;
        }

        return $groups;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_map(static fn (self $d): string => $d->key, self::all());
    }

    public function groupLabel(): string
    {
        return match ($this->group) {
            'company' => 'Company details',
            'site' => 'Public site',
            default => str($this->group)->headline()->toString(),
        };
    }

    /** The value from config, used when nothing has been stored yet. */
    public function fallback(): mixed
    {
        if ($this->configFallback === null) {
            return $this->type === Setting::TYPE_BOOLEAN ? true : null;
        }

        return config($this->configFallback);
    }

    /** Coerces a submitted value to this setting's declared type. */
    public function cast(mixed $value): mixed
    {
        return match ($this->type) {
            Setting::TYPE_BOOLEAN => (bool) $value,
            Setting::TYPE_INTEGER => (int) $value,
            Setting::TYPE_FLOAT => (float) $value,
            default => $value === null ? null : (string) $value,
        };
    }
}
