<?php

namespace LucaLongo\Licensing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use LucaLongo\Licensing\Enums\LicenseStatus;
use LucaLongo\Licensing\Models\License;

class LicenseFactory extends Factory
{
    protected $model = License::class;

    public function definition(): array
    {
        return [
            'key_hash' => hash('sha256', $this->faker->uuid()),
            'status' => LicenseStatus::Pending,
            'licensable_type' => null,
            'licensable_id' => null,
            'activated_at' => null,
            'expires_at' => $this->faker->optional()->dateTimeBetween('now', '+1 year'),
            'max_usages' => $this->faker->numberBetween(1, 10),
            'meta' => [],
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => LicenseStatus::Active,
            'activated_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => LicenseStatus::Expired,
            'expires_at' => now()->subDay(),
        ]);
    }

    public function withUsages(int $count): static
    {
        return $this->state(fn (array $attributes) => [
            'max_usages' => $count,
        ]);
    }

    public function licensable(Model $model): static
    {
        return $this->state(fn (array $attributes) => [
            'licensable_type' => get_class($model),
            'licensable_id' => $model->getKey(),
        ]);
    }
}
