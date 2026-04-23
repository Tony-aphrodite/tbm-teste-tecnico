<?php

namespace Database\Factories;

use App\Models\Profissional;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProfissionalFactory extends Factory
{
    protected $model = Profissional::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'nome'      => $this->faker->name(),
            'ativo'     => true,
        ];
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn () => ['tenant_id' => $tenant->id]);
    }

    public function inativo(): static
    {
        return $this->state(fn () => ['ativo' => false]);
    }
}
