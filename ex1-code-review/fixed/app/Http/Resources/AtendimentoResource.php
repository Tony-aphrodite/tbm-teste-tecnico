<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AtendimentoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'        => $this->id,
            'data'      => optional($this->data)->toIso8601String(),
            'status'    => $this->status,
            'paciente'  => [
                'id'   => $this->paciente?->id,
                'nome' => $this->paciente?->nome,
            ],
            'profissional' => [
                'id'   => $this->profissional?->id,
                'nome' => $this->profissional?->nome,
            ],
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
