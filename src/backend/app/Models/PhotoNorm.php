<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One structured item read out of a photograph by the vision step — the same
 * item schema as text-derived items, so both enter the scorer identically.
 */
class PhotoNorm extends Model
{
    protected $table = 'photo_norm';

    protected $guarded = [];

    protected $casts = [
        'norm_json' => 'array',
        'confidence' => 'float',
        'reviewed_at' => 'datetime',
    ];

    public function photo(): BelongsTo
    {
        return $this->belongsTo(PhotoEvidence::class, 'photo_id', 'photo_id');
    }

    public function scopeFromExtractor($query, string $version)
    {
        return $query->where('extractor_version', $version);
    }
}
