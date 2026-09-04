<?php

namespace Database\Factories;

use App\Models\TeamMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TeamMember> */
class TeamMemberFactory extends Factory
{
    protected $model = TeamMember::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'role_title' => fake()->randomElement([
                'Head of Safaris', 'Fleet Manager', 'Reservations Officer', 'Lead Guide',
            ]),
            'summary' => fake()->sentence(12),
            'biography' => fake()->paragraph(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '+256700'.fake()->unique()->numerify('######'),
            'is_published' => false,
            'sort_order' => 0,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (): array => ['is_published' => true]);
    }
}
