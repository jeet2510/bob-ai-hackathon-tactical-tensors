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
     */
    public function latestRun(): ?MatchRun
    {
        return $this->matchRuns()->latest('created_at')->first();
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
