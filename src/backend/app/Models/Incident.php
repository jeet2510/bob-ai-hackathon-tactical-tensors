<?php

namespace App\Models;

use App\Models\Concerns\HasStringKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Incident extends Model
{
    use HasStringKey;

    protected $table = 'incident';

    protected $primaryKey = 'incident_id';

    protected $guarded = [];

    protected $casts = [
        'incident_date' => 'date:Y-m-d',
        'synthetic' => 'boolean',
    ];

    public function pmCases(): HasMany
    {
        return $this->hasMany(PmCase::class, 'incident_id', 'incident_id');
    }

    public function amFiles(): HasMany
    {
        return $this->hasMany(AmFile::class, 'incident_id', 'incident_id');
    }

    public function matchRuns(): HasMany
    {
        return $this->hasMany(MatchRun::class, 'incident_id', 'incident_id');
    }

    /**
     * The run whose candidates the dashboard should show.
     *
     * Scoped to the deterministic pipeline specifically (scorer_version
     * 'matcher-v1...') so that a Gemini refinement run — which only ever
     * covers one body and was never passed through GlobalAssignment — can
     * never silently become "the" run for triage, assignment, decisions or
     * the reconciliation report just by being the most recent row.
     */
    public function latestRun(): ?MatchRun
    {
        return $this->matchRuns()
            ->where('scorer_version', 'like', 'matcher-v1%')
            ->latest('created_at')
            ->first();
    }

    /**
     * The most recent Gemini multimodal refinement for one body, if any has
     * been run. Kept entirely separate from latestRun() — see above.
     */
    public function latestGeminiRun(string $pmId): ?MatchRun
    {
        return $this->matchRuns()
            ->where('scorer_version', 'gemini-v1')
            ->whereHas('candidates', fn ($q) => $q->where('pm_id', $pmId))
            ->latest('created_at')
            ->first();
    }

    /**
     * Photographic evidence belonging to this incident's own bodies and
     * family reports. photo_evidence has no incident_id of its own — it is
     * scoped through record_type/record_id, one incident at a time.
     */
    public function photos(): Builder
    {
        return PhotoEvidence::query()->where(
            fn (Builder $q) => $q
                ->where(fn (Builder $p) => $p->where('record_type', 'PM')->whereIn('record_id', $this->pmCases()->select('pm_id')))
                ->orWhere(fn (Builder $p) => $p->where('record_type', 'AM')->whereIn('record_id', $this->amFiles()->select('am_id')))
        );
    }
}
