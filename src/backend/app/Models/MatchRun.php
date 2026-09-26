<?php

namespace App\Models;

use App\Models\Concerns\HasStringKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One execution of the scorer over an incident.
 *
 * Candidates and evidence hang off a run rather than off the records, so
 * re-scoring produces a new run instead of overwriting the evidence that a
 * past review decision was based on.
 */
class MatchRun extends Model
{
    use HasStringKey;

    protected $table = 'match_run';

    protected $primaryKey = 'run_id';

    protected $guarded = [];

    protected $casts = [
        'config' => 'array',
        'stats' => 'array',
        'assignment_applied' => 'boolean',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class, 'incident_id', 'incident_id');
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(Candidate::class, 'run_id', 'run_id');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(MatchEvidence::class, 'run_id', 'run_id');
    }
}
