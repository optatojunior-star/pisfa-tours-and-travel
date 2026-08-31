<?php

$requiredRoles = array_values(array_filter(array_map(
    static fn (string $role): string => trim($role),
    explode(',', (string) env('PISFA_TWO_FACTOR_REQUIRED_ROLES', 'super_admin,manager')),
)));

return [
    'two_factor' => [
        'required_roles' => $requiredRoles,
        'challenge_ttl_seconds' => max(
            60,
            min(900, (int) env('PISFA_TWO_FACTOR_CHALLENGE_TTL', 300)),
        ),
    ],
];
