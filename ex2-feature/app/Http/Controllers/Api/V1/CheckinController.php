<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCheckinRequest;
use App\Http\Resources\CheckinResource;
use App\Models\Checkin;
use Illuminate\Http\JsonResponse;

class CheckinController extends Controller
{
    public function store(StoreCheckinRequest $request): JsonResponse
    {
        $checkin = Checkin::create([
            ...$request->validated(),
            'check_in_at' => now(),
        ]);

        $checkin->load(['profissional:id,tenant_id,nome', 'paciente:id,tenant_id,nome']);

        return (new CheckinResource($checkin))
            ->response()
            ->setStatusCode(201);
    }
}
