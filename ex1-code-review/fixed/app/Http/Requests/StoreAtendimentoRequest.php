<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAtendimentoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;

        return [
            'paciente_id' => [
                'required', 'integer',
                Rule::exists('pacientes', 'id')->where('tenant_id', $tenantId),
            ],
            'profissional_id' => [
                'required', 'integer',
                Rule::exists('profissionais', 'id')
                    ->where('tenant_id', $tenantId)
                    ->where('ativo', true),
            ],
            'data'         => ['required', 'date_format:Y-m-d H:i:s'],
            'observacoes'  => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'paciente_id.exists'     => 'Paciente não pertence ao seu contexto.',
            'profissional_id.exists' => 'Profissional inválido ou inativo.',
        ];
    }
}
