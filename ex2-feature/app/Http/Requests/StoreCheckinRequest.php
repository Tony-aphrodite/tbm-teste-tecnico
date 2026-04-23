<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCheckinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;

        return [
            'profissional_id' => [
                'required',
                'integer',
                Rule::exists('profissionais', 'id')
                    ->where('tenant_id', $tenantId)
                    ->where('ativo', true),
            ],
            'paciente_id' => [
                'required',
                'integer',
                Rule::exists('pacientes', 'id')
                    ->where('tenant_id', $tenantId),
            ],
            'latitude'  => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ];
    }

    public function messages(): array
    {
        return [
            'profissional_id.exists' => 'Profissional inválido, inativo ou fora do seu contexto.',
            'paciente_id.exists'     => 'Paciente inválido ou fora do seu contexto.',
        ];
    }
}
