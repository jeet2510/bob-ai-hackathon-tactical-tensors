<?php

namespace App\Models;

use App\Models\Concerns\HasStringKey;
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
}
