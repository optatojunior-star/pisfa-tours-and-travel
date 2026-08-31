<?php

namespace App\Support\Fleet;

/**
 * The fixed set of items a driver checks before and after a job.
 *
 * Defined in code rather than as free text so two drivers' checks are
 * comparable, and so a stored inspection can be re-rendered years later without
 * depending on what the form happened to say that day.
 */
final class InspectionChecklist
{
    /**
     * Keyed by the value stored in the inspection payload.
     *
     * `critical` items are the ones that stop a vehicle leaving: a defect on any
     * of them fails the whole pre-trip check.
     *
     * @var array<string, array{label: string, critical: bool}>
     */
    public const ITEMS = [
        'tyres' => ['label' => 'Tyres and pressure', 'critical' => true],
        'brakes' => ['label' => 'Brakes', 'critical' => true],
        'lights' => ['label' => 'Lights and indicators', 'critical' => true],
        'fluids' => ['label' => 'Oil, coolant, and washer fluid', 'critical' => true],
        'seatbelts' => ['label' => 'Seatbelts', 'critical' => true],
        'mirrors' => ['label' => 'Mirrors and glass', 'critical' => false],
        'bodywork' => ['label' => 'Bodywork and damage', 'critical' => false],
        'cleanliness' => ['label' => 'Interior cleanliness', 'critical' => false],
        'spare_and_tools' => ['label' => 'Spare wheel, jack, and tools', 'critical' => false],
        'first_aid' => ['label' => 'First aid kit and extinguisher', 'critical' => true],
        'documents' => ['label' => 'Insurance and registration in the vehicle', 'critical' => true],
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::ITEMS);
    }

    public static function label(string $key): string
    {
        return self::ITEMS[$key]['label'] ?? str($key)->replace('_', ' ')->headline()->toString();
    }

    public static function isCritical(string $key): bool
    {
        return self::ITEMS[$key]['critical'] ?? false;
    }

    /**
     * Normalises submitted answers to the known item set.
     *
     * Anything not on the list is dropped and anything missing is recorded as
     * unchecked, so a stored inspection always has the same shape regardless of
     * what a form posted.
     *
     * @param  array<string, mixed>  $answers
     * @return array<string, string>
     */
    public static function normalise(array $answers): array
    {
        $result = [];

        foreach (self::keys() as $key) {
            $value = $answers[$key] ?? null;

            $result[$key] = match ($value) {
                'ok', true, '1', 1 => 'ok',
                'defect' => 'defect',
                'not_applicable', 'na' => 'not_applicable',
                default => 'unchecked',
            };
        }

        return $result;
    }

    /**
     * Items reported as defective.
     *
     * @param  array<string, string>  $answers
     * @return list<string>
     */
    public static function defects(array $answers): array
    {
        return array_values(array_filter(
            self::keys(),
            static fn (string $key): bool => ($answers[$key] ?? null) === 'defect',
        ));
    }

    /**
     * Critical items reported as defective or left unchecked.
     *
     * An unchecked critical item is treated as a failure on purpose: "I did not
     * look" is not the same as "it is fine", and a pre-trip check that can be
     * passed by skipping it is worth nothing.
     *
     * @param  array<string, string>  $answers
     * @return list<string>
     */
    public static function criticalFailures(array $answers): array
    {
        return array_values(array_filter(
            self::keys(),
            static fn (string $key): bool => self::isCritical($key)
                && in_array($answers[$key] ?? 'unchecked', ['defect', 'unchecked'], true),
        ));
    }
}
