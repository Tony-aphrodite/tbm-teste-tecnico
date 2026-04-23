<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Checkin extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected $fillable = [
        'profissional_id',
        'paciente_id',
        'latitude',
        'longitude',
        'check_in_at',
    ];

    protected $casts = [
        'latitude'    => 'decimal:7',
        'longitude'   => 'decimal:7',
        'check_in_at' => 'datetime',
    ];

    public function profissional()
    {
        return $this->belongsTo(Profissional::class);
    }

    public function paciente()
    {
        return $this->belongsTo(Paciente::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
