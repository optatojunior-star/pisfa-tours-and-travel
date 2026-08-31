<?php

namespace App\Actions\Settings;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\Setting;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Settings\SettingsRepository;
use App\Support\Settings\SettingDefinition;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Writes runtime settings, and records exactly what changed.
 *
 * Every settings change is audited with its before and after value, because a
 * setting that quietly altered what a document says or what the site accepts is
 * precisely the kind of change somebody later needs to trace.
 *
 * Only keys in the registry are written. A submitted key that is not registered
 * is ignored rather than stored, so the form cannot be used to plant a value the
 * application would later read.
 */
class UpdateSettings
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, array{from: mixed, to: mixed}> What actually changed.
     */
    public function execute(User $actor, array $input): array
    {
        $this->assertMayConfigure($actor);

        $values = $this->validated($input);

        $changes = DB::transaction(function () use ($actor, $values): array {
            $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
            $this->assertMayConfigure($lockedActor);

            $changed = [];

            foreach (SettingDefinition::all() as $definition) {
                if (! array_key_exists($definition->key, $values)) {
                    continue;
                }

                $previous = $this->settings->get($definition->key);
                $next = $definition->cast($values[$definition->key]);

                if ($previous === $next) {
                    continue;
                }

                Setting::setValue($definition->key, $next, [
                    'group' => $definition->group,
                    'description' => $definition->description,
                    'is_public' => $definition->isPublic,
                ]);

                $changed[$definition->key] = ['from' => $previous, 'to' => $next];
            }

            return $changed;
        }, 3);

        // The memo predates the write, so the rest of the request would keep
        // reading the old values without this.
        $this->settings->forget();

        if ($changes !== []) {
            $this->auditLogger->record(
                event: 'settings.updated',
                newValues: [
                    'keys' => array_keys($changes),
                    // The registry holds no credentials, so before-and-after
                    // values are safe to record in full — and that is the whole
                    // point of auditing a settings change.
                    'changes' => $changes,
                ],
                user: $actor,
            );
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function validated(array $input): array
    {
        $rules = [];
        $labels = [];

        foreach (SettingDefinition::all() as $definition) {
            $field = $this->fieldName($definition->key);
            $rules[$field] = $definition->rules;
            $labels[$field] = mb_strtolower($definition->label);
        }

        $validator = Validator::make($input, $rules);
        $validator->setAttributeNames($labels);
        $validated = $validator->validate();

        $values = [];

        foreach (SettingDefinition::all() as $definition) {
            $field = $this->fieldName($definition->key);

            if (array_key_exists($field, $validated)) {
                $values[$definition->key] = $validated[$field];

                continue;
            }

            // An unchecked box posts nothing at all, so a boolean absent from
            // the payload means false rather than "leave it alone".
            if ($definition->type === Setting::TYPE_BOOLEAN && array_key_exists('_submitted', $input)) {
                $values[$definition->key] = false;
            }
        }

        return $values;
    }

    /** Dots are not valid in an HTML field name, so the form uses underscores. */
    public function fieldName(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    /**
     * Configuration is a super-administrator action.
     *
     * A manager can move money; changing what every document says about the
     * company is a different kind of authority.
     */
    private function assertMayConfigure(User $actor): void
    {
        if ($actor->status !== AccountStatus::Active || ! $actor->hasRole(UserRole::SuperAdmin)) {
            throw new AuthorizationException;
        }
    }
}
