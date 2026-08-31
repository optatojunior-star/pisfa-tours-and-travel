<?php

namespace App\Actions\Accommodation;

use App\Enums\PropertyStatus;
use App\Enums\PropertyType;
use App\Models\Property;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Creates or edits a property.
 *
 * Publication is deliberately not part of saving: a property goes live through
 * TransitionProperty, by somebody who is allowed to publish, so a typo fix
 * cannot put an unfinished listing on the public site as a side effect.
 */
class SaveProperty
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $attributes */
    public function create(User $actor, array $attributes): Property
    {
        $input = $this->validated($attributes);

        return DB::transaction(function () use ($actor, $input): Property {
            $lockedActor = AccommodationAccess::lockedManager($actor);

            $property = new Property;
            $property->forceFill(array_merge($input, [
                'slug' => $this->uniqueSlug($input['name']),
                'status' => PropertyStatus::Draft,
                'created_by_user_id' => $lockedActor->getKey(),
                'updated_by_user_id' => $lockedActor->getKey(),
            ]))->save();

            $this->auditLogger->record(
                event: 'property.created',
                auditable: $property,
                newValues: [
                    'slug' => $property->slug,
                    'name' => $property->name,
                    'property_type' => $property->property_type->value,
                    'region' => $property->region,
                ],
                user: $lockedActor,
            );

            return $property;
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function update(User $actor, Property $property, array $attributes): Property
    {
        $input = $this->validated($attributes);

        return DB::transaction(function () use ($actor, $property, $input): Property {
            $lockedActor = AccommodationAccess::lockedManager($actor);

            $locked = Property::query()
                ->whereKey($property->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->status->isEditable()) {
                throw ValidationException::withMessages([
                    'status' => 'An archived property is read-only. Restore it to a draft first.',
                ]);
            }

            $previous = [
                'name' => $locked->name,
                'region' => $locked->region,
                'cancellation_cutoff_hours' => $locked->cancellation_cutoff_hours,
            ];

            // The slug is the public URL. Once a property has been live, renaming
            // it would break every link anyone has shared, so it is frozen.
            $keepSlug = $locked->published_at !== null;

            $locked->forceFill(array_merge($input, [
                'slug' => $keepSlug ? $locked->slug : $this->uniqueSlug($input['name'], $locked->getKey()),
                'updated_by_user_id' => $lockedActor->getKey(),
            ]))->save();

            $this->auditLogger->record(
                event: 'property.updated',
                auditable: $locked,
                oldValues: $previous,
                newValues: [
                    'name' => $locked->name,
                    'region' => $locked->region,
                    'cancellation_cutoff_hours' => $locked->cancellation_cutoff_hours,
                ],
                user: $lockedActor,
            );

            return $locked->fresh();
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function validated(array $attributes): array
    {
        $validated = Validator::make($attributes, [
            'name' => ['required', 'string', 'min:3', 'max:200'],
            'property_type' => ['required', Rule::enum(PropertyType::class)],
            'region' => ['required', 'string', 'min:2', 'max:120'],
            'district' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:500'],
            'summary' => ['required', 'string', 'min:20', 'max:400'],
            'description' => ['required', 'string', 'min:50', 'max:8000'],
            'directions' => ['nullable', 'string', 'max:2000'],
            'internal_notes' => ['nullable', 'string', 'max:5000'],
            'check_in_from' => ['required', 'date_format:H:i'],
            'check_out_by' => ['required', 'date_format:H:i'],
            'cancellation_cutoff_hours' => ['required', 'integer', 'min:0', 'max:2160'],
            'is_featured' => ['nullable', 'boolean'],
        ])->validate();

        return [
            'name' => trim((string) $validated['name']),
            'property_type' => PropertyType::from((string) $validated['property_type']),
            'region' => trim((string) $validated['region']),
            'district' => $this->nullable($validated['district'] ?? null),
            'address' => $this->nullable($validated['address'] ?? null),
            'summary' => trim((string) $validated['summary']),
            'description' => trim((string) $validated['description']),
            'directions' => $this->nullable($validated['directions'] ?? null),
            'internal_notes' => $this->nullable($validated['internal_notes'] ?? null),
            'check_in_from' => $validated['check_in_from'].':00',
            'check_out_by' => $validated['check_out_by'].':00',
            'cancellation_cutoff_hours' => (int) $validated['cancellation_cutoff_hours'],
            'is_featured' => (bool) ($validated['is_featured'] ?? false),
        ];
    }

    /** Soft-deleted properties are counted, so a removed URL is never reused. */
    private function uniqueSlug(string $source, ?int $ignoreId = null): string
    {
        $base = Str::slug($source) ?: 'property';
        $slug = $base;
        $suffix = 1;

        while (Property::query()
            ->withTrashed()
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists()
        ) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }

    private function nullable(mixed $value): ?string
    {
        $value = $value === null ? null : trim((string) $value);

        return $value === '' ? null : $value;
    }
}
