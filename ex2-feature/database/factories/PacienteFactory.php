<?php

namespace Database\Factories;

use App\Models\Paciente;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class PacienteFactory extends Factory
{
    protected $model = Paciente::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'nome'      => $this->faker->name(),
        ];
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn () => ['tenant_id' => $tenant->id]);
    }
}
