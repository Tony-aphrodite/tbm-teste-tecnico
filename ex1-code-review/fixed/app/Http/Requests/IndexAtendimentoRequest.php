<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IndexAtendimentoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'status'       => ['nullable', 'string', 'in:agendado,em_andamento,concluido,cancelado,faturado'],
            'profissional' => ['nullable', 'string', 'max:120'],
            'data_inicio'  => ['nullable', 'date_format:Y-m-d', 'required_with:data_fim'],
            'data_fim'     => ['nullable', 'date_format:Y-m-d', 'after_or_equal:data_inicio', 'required_with:data_inicio'],
            'per_page'     => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
