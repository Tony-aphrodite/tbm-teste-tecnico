<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CheckinResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'profissional' => [
                'id'   => $this->profissional?->id,
                'nome' => $this->profissional?->nome,
            ],
            'paciente' => [
                'id'   => $this->paciente?->id,
                'nome' => $this->paciente?->nome,
            ],
            'localizacao' => [
                'latitude'  => (float) $this->latitude,
                'longitude' => (float) $this->longitude,
            ],
            'check_in_at' => $this->check_in_at->toIso8601String(),
            'created_at'  => $this->created_at->toIso8601String(),
        ];
    }
}
