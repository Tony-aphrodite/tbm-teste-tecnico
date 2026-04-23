<?php

namespace Tests\Feature;

use App\Models\Checkin;
use App\Models\Paciente;
use App\Models\Profissional;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CheckinTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/checkin';

    /** @test */
    public function profissional_autenticado_cria_checkin_no_proprio_tenant(): void
    {
        $tenant        = Tenant::factory()->create();
        $user          = User::factory()->forTenant($tenant)->create();
        $profissional  = Profissional::factory()->forTenant($tenant)->create();
        $paciente      = Paciente::factory()->forTenant($tenant)->create();

        Sanctum::actingAs($user);

        $response = $this->postJson(self::ENDPOINT, [
            'profissional_id' => $profissional->id,
            'paciente_id'     => $paciente->id,
            'latitude'        => -29.9177,
            'longitude'       => -51.1836,
        ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'profissional'   => ['id', 'nome'],
                    'paciente'       => ['id', 'nome'],
                    'localizacao'    => ['latitude', 'longitude'],
                    'check_in_at',
                    'created_at',
                ],
            ])
            ->assertJsonPath('data.profissional.id', $profissional->id)
            ->assertJsonPath('data.paciente.id', $paciente->id)
            ->assertJsonPath('data.profissional.nome', $profissional->nome)
            ->assertJsonPath('data.paciente.nome', $paciente->nome);

        $this->assertDatabaseHas('checkins', [
            'tenant_id'       => $tenant->id,
            'profissional_id' => $profissional->id,
            'paciente_id'     => $paciente->id,
        ]);
    }

    /** @test */
    public function nao_permite_checkin_com_profissional_de_outro_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $user               = User::factory()->forTenant($tenantA)->create();
        $profissionalOutro  = Profissional::factory()->forTenant($tenantB)->create();
        $pacienteProprio    = Paciente::factory()->forTenant($tenantA)->create();

        Sanctum::actingAs($user);

        $response = $this->postJson(self::ENDPOINT, [
            'profissional_id' => $profissionalOutro->id,
            'paciente_id'     => $pacienteProprio->id,
            'latitude'        => -29.9177,
            'longitude'       => -51.1836,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['profissional_id']);

        $this->assertDatabaseCount('checkins', 0);
    }

    /** @test */
    public function nao_permite_checkin_com_paciente_de_outro_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $user              = User::factory()->forTenant($tenantA)->create();
        $profissional      = Profissional::factory()->forTenant($tenantA)->create();
        $pacienteOutro     = Paciente::factory()->forTenant($tenantB)->create();

        Sanctum::actingAs($user);

        $response = $this->postJson(self::ENDPOINT, [
            'profissional_id' => $profissional->id,
            'paciente_id'     => $pacienteOutro->id,
            'latitude'        => -29.9177,
            'longitude'       => -51.1836,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['paciente_id']);

        $this->assertDatabaseCount('checkins', 0);
    }

    /** @test */
    public function requisicao_nao_autenticada_retorna_401(): void
    {
        $response = $this->postJson(self::ENDPOINT, [
            'profissional_id' => 1,
            'paciente_id'     => 1,
            'latitude'        => -29.9177,
            'longitude'       => -51.1836,
        ]);

        $response->assertUnauthorized();
    }

    /** @test */
    public function profissional_inativo_e_rejeitado(): void
    {
        $tenant        = Tenant::factory()->create();
        $user          = User::factory()->forTenant($tenant)->create();
        $profissional  = Profissional::factory()->forTenant($tenant)->inativo()->create();
        $paciente      = Paciente::factory()->forTenant($tenant)->create();

        Sanctum::actingAs($user);

        $response = $this->postJson(self::ENDPOINT, [
            'profissional_id' => $profissional->id,
            'paciente_id'     => $paciente->id,
            'latitude'        => -29.9177,
            'longitude'       => -51.1836,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['profissional_id']);
    }

    /** @test */
    public function coordenadas_invalidas_retornam_422(): void
    {
        $tenant        = Tenant::factory()->create();
        $user          = User::factory()->forTenant($tenant)->create();
        $profissional  = Profissional::factory()->forTenant($tenant)->create();
        $paciente      = Paciente::factory()->forTenant($tenant)->create();

        Sanctum::actingAs($user);

        $response = $this->postJson(self::ENDPOINT, [
            'profissional_id' => $profissional->id,
            'paciente_id'     => $paciente->id,
            'latitude'        => 91,
            'longitude'       => -200,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['latitude', 'longitude']);
    }

    /** @test */
    public function tenant_id_no_body_e_ignorado_e_sobrescrito_pelo_tenant_do_usuario(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $user          = User::factory()->forTenant($tenantA)->create();
        $profissional  = Profissional::factory()->forTenant($tenantA)->create();
        $paciente      = Paciente::factory()->forTenant($tenantA)->create();

        Sanctum::actingAs($user);

        $this->postJson(self::ENDPOINT, [
            'tenant_id'       => $tenantB->id,
            'profissional_id' => $profissional->id,
            'paciente_id'     => $paciente->id,
            'latitude'        => -29.9177,
            'longitude'       => -51.1836,
        ])->assertCreated();

        $this->assertDatabaseHas('checkins', [
            'tenant_id'       => $tenantA->id,
            'profissional_id' => $profissional->id,
        ]);

        $this->assertDatabaseMissing('checkins', [
            'tenant_id' => $tenantB->id,
        ]);
    }

    /** @test */
    public function global_scope_isola_leitura_de_checkins_entre_tenants(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $userA           = User::factory()->forTenant($tenantA)->create();
        $profissionalA   = Profissional::factory()->forTenant($tenantA)->create();
        $pacienteA       = Paciente::factory()->forTenant($tenantA)->create();

        $profissionalB   = Profissional::factory()->forTenant($tenantB)->create();
        $pacienteB       = Paciente::factory()->forTenant($tenantB)->create();

        // Cria checkin do tenant B diretamente no banco (simula dado pré-existente).
        // forceFill contorna $fillable (tenant_id é propositalmente omitido de fillable);
        // withoutEvents evita o creating-hook do trait, que dispararia sem user autenticado.
        Checkin::withoutEvents(function () use ($tenantB, $profissionalB, $pacienteB) {
            (new Checkin())->forceFill([
                'tenant_id'       => $tenantB->id,
                'profissional_id' => $profissionalB->id,
                'paciente_id'     => $pacienteB->id,
                'latitude'        => -29.9177,
                'longitude'       => -51.1836,
                'check_in_at'     => now(),
            ])->save();
        });

        Sanctum::actingAs($userA);

        Checkin::create([
            'profissional_id' => $profissionalA->id,
            'paciente_id'     => $pacienteA->id,
            'latitude'        => -29.9177,
            'longitude'       => -51.1836,
            'check_in_at'     => now(),
        ]);

        // Do contexto do userA, apenas o checkin do tenant A é visível.
        $this->assertSame(1, Checkin::count());
        $this->assertSame($tenantA->id, Checkin::first()->tenant_id);
    }
}
