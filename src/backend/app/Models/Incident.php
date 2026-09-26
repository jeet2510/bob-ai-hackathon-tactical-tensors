<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Incident extends Model
{
    use Auditable;

    protected $fillable = [
        'incident_id',
        'name',
        'description',
        'incident_date',
        'district',
        'location',
        'environment',
        'status',
        'synthetic',
    ];

    protected $casts = [
        'incident_date' => 'date',
        'synthetic' => 'boolean',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
