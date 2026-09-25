<?php

namespace Database\Factories;

use App\Models\Domain;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Domain>
 */
class DomainFactory extends Factory
{
    protected $model = Domain::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'domain' => fake()->unique()->domainName(),
            'cloudflare_zone_id' => 'zone_'.fake()->lexify('??????????'),
            'verified_at' => now(),
        ];
    }
}
