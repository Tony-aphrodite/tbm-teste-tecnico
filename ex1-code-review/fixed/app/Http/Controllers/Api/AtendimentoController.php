<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexAtendimentoRequest;
use App\Http\Requests\StoreAtendimentoRequest;
use App\Http\Resources\AtendimentoResource;
use App\Models\Atendimento;
use App\Services\EvolucaoClinicaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AtendimentoController extends Controller
{
    public function __construct(
        private readonly EvolucaoClinicaService $evolucaoService
    ) {
    }

    /**
     * Corrige problemas #1 (SQL Injection) e #2 (tenant pelo header).
     *
     * - tenant_id vem do global scope BelongsToTenant, nunca do request.
     * - filtros passam por FormRequest (whitelist + tipagem).
     * - Query Builder parametrizado, sem concatenação.
     * - Eager loading para evitar N+1 no Resource.
     * - Paginação obrigatória.
     */
    public function index(IndexAtendimentoRequest $request): AnonymousResourceCollection
    {
        $data = $request->validated();

        $query = Atendimento::query()
            ->with(['paciente:id,tenant_id,nome', 'profissional:id,tenant_id,nome'])
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when(
                $data['profissional'] ?? null,
                fn ($q, $nome) => $q->whereHas(
                    'profissional',
                    fn ($sub) => $sub->where('nome', 'like', '%' . $nome . '%')
                )
            )
            ->when(
                isset($data['data_inicio'], $data['data_fim']),
                fn ($q) => $q->whereBetween('data', [$data['data_inicio'], $data['data_fim']])
            )
            ->orderByDesc('data');

        return AtendimentoResource::collection(
            $query->paginate($data['per_page'] ?? 25)
        );
    }

    public function store(StoreAtendimentoRequest $request): JsonResponse
    {
        $atendimento = Atendimento::create($request->validated());

        return (new AtendimentoResource($atendimento->fresh(['paciente', 'profissional'])))
            ->response()
            ->setStatusCode(201);
    }

    public function update(StoreAtendimentoRequest $request, int $id): AtendimentoResource
    {
        $atendimento = Atendimento::findOrFail($id);

        $this->authorize('update', $atendimento);

        $atendimento->update($request->validated());

        return new AtendimentoResource($atendimento->fresh(['paciente', 'profissional']));
    }

    /**
     * Corrige problema #3 (Path Traversal + IDOR em download).
     *
     * - Recebe apenas o atendimento_id; o nome do arquivo nunca vem do cliente.
     * - findOrFail + global scope rejeita IDs de outro tenant (404).
     * - Gate::authorize aplica a policy de negócio.
     * - EvolucaoClinicaService monta o path a partir de campos do model,
     *   nunca de input, e registra log de auditoria.
     */
    public function downloadEvolucao(int $atendimentoId): StreamedResponse
    {
        $atendimento = Atendimento::findOrFail($atendimentoId);

        $this->authorize('downloadEvolucao', $atendimento);

        return $this->evolucaoService->download($atendimento, auth()->id());
    }
}
