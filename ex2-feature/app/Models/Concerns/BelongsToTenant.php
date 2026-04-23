<?php

namespace App\Models\Concerns;

use App\Models\Scopes\TenantScope;
use Illuminate\Support\Facades\Auth;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function ($model) {
            $user = Auth::user();

            if ($user !== null) {
                $model->tenant_id = $user->tenant_id;

                return;
            }

            if ($model->tenant_id === null) {
                throw new \RuntimeException(
                    'Tentativa de criar ' . get_class($model) .
                    ' sem usuário autenticado e sem tenant_id explícito.'
                );
            }
        });
    }
}
