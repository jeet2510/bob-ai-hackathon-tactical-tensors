<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One structured item read out of a free-text box by an extractor.
 *
 * `reviewed_by` marks items a human has confirmed or corrected. A corrected
 * item supersedes the extractor's reading everywhere downstream.
 */
class ObservationNorm extends Model
{
    protected $table = 'observation_norm';

    protected $guarded = [];

    protected $casts = [
        'norm_json' => 'array',
        'confidence' => 'float',
        'reviewed_at' => 'datetime',
    ];

    public function observation(): BelongsTo
    {
        return $this->belongsTo(Observation::class, 'obs_id', 'obs_id');
    }

    public function scopeFromExtractor($query, string $version)
    {
        return $query->where('extractor_version', $version);
    }

    public function wasHumanReviewed(): bool
    {
        return $this->reviewed_by !== null;
    }
}
