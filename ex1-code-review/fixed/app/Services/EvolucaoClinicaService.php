<?php

namespace App\Services;

use App\Models\Atendimento;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EvolucaoClinicaService
{
    private const DISK = 'evolucoes';

    public function download(Atendimento $atendimento, int $actorUserId): StreamedResponse
    {
        $path = $this->pathFor($atendimento);

        abort_unless($this->disk()->exists($path), 404);

        Log::channel('audit')->info('evolucao.download', [
            'atendimento_id' => $atendimento->id,
            'tenant_id'      => $atendimento->tenant_id,
            'user_id'        => $actorUserId,
            'ip'             => request()->ip(),
        ]);

        return $this->disk()->download(
            $path,
            'evolucao-' . $atendimento->id . '.pdf'
        );
    }

    private function pathFor(Atendimento $atendimento): string
    {
        return sprintf(
            '%d/%s.pdf',
            $atendimento->tenant_id,
            $atendimento->evolucao_uuid
        );
    }

    private function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }
}
