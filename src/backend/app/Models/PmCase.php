<?php

namespace App\Models;

use App\Models\Concerns\HasStringKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A recovered body. Scalar facts only — everything descriptive lives in the
 * attached observations and photographs.
 */
class PmCase extends Model
{
    use HasStringKey;

    protected $table = 'pm_case';

    protected $primaryKey = 'pm_id';

    protected $guarded = [];

    protected $casts = [
        'found_at' => 'datetime',
        'lat' => 'float',
        'lon' => 'float',
        'synthetic' => 'boolean',
    ];

    /**
     * Body conditions under which external description is unreliable. Used to
     * explain, in the UI, why a body has thin evidence — the absence of a
     * finding here means "could not look", not "not there".
     */
    public const DEGRADED_CONDITIONS = ['Advanced decomp.', 'Burnt'];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class, 'incident_id', 'incident_id');
    }

    public function observations(): HasMany
    {
        return $this->hasMany(Observation::class, 'record_id', 'pm_id')
            ->where('record_type', 'PM');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(PhotoEvidence::class, 'record_id', 'pm_id')
            ->where('record_type', 'PM');
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(Candidate::class, 'pm_id', 'pm_id');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(ReviewDecision::class, 'pm_id', 'pm_id');
    }

    /**
     * FDI dental chart as an array, or null when no chart was completed.
     *
     * @return array<string, list<int>>|null
     */
    public function dentalChart(): ?array
    {
        if (blank($this->dental_chart_fdi)) {
            return null;
        }

        $chart = json_decode($this->dental_chart_fdi, true);

        return is_array($chart) ? $chart : null;
    }

    public function isDegraded(): bool
    {
        return in_array($this->body_condition, self::DEGRADED_CONDITIONS, true);
    }

    public function ageRange(): ?string
    {
        if ($this->age_min === null && $this->age_max === null) {
            return null;
        }

        $min = $this->age_min ?? $this->age_max;
        $max = $this->age_max ?? $this->age_min;

        return $min === $max ? (string) $min : "{$min}–{$max}";
    }
}
