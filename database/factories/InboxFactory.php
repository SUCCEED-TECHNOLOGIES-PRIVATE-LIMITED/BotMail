<?php

namespace Database\Factories;

use App\Enums\InboxStatus;
use App\Models\Domain;
use App\Models\Inbox;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Inbox>
 */
class InboxFactory extends Factory
{
    protected $model = Inbox::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'domain_id' => Domain::factory(),
            'user_id' => fn (array $attributes): int => (int) Domain::query()->findOrFail($attributes['domain_id'])->user_id,
            'local_part' => fake()->unique()->bothify('agent-????'),
            'address' => function (array $attributes): string {
                $domain = Domain::query()->findOrFail($attributes['domain_id']);

                return strtolower($attributes['local_part']).'@'.$domain->domain;
            },
            'display_name' => fake()->name(),
            'status' => InboxStatus::Active,
            'cloudflare_rule_id' => null,
        ];
    }
}
