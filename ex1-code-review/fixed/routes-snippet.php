<?php

use App\Http\Controllers\Api\AtendimentoController;
use Illuminate\Support\Facades\Route;

// routes/api.php (trecho relevante)

Route::middleware(['auth:sanctum', 'throttle:60,1'])
    ->prefix('v1')
    ->group(function () {
        Route::get('atendimentos', [AtendimentoController::class, 'index']);
        Route::post('atendimentos', [AtendimentoController::class, 'store']);
        Route::put('atendimentos/{id}', [AtendimentoController::class, 'update'])
            ->whereNumber('id');

        Route::get('atendimentos/{id}/evolucao', [AtendimentoController::class, 'downloadEvolucao'])
            ->whereNumber('id')
            ->middleware('throttle:30,1');
    });
