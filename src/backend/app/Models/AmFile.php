<?php

namespace App\Models;

use App\Models\Concerns\HasStringKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A missing-person report filed by a family.
 *
 * `reported_name` is displayed to reviewers but is never scored: a name on a
 * form is not evidence about a body.
 */
class AmFile extends Model
{
    use HasStringKey;

    protected $table = 'am_file';

    protected $primaryKey = 'am_id';

    protected $guarded = [];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'dental_records_available' => 'boolean',
        'prints_on_file' => 'boolean',
        'synthetic' => 'boolean',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class, 'incident_id', 'incident_id');
    }

    public function observations(): HasMany
    {
        return $this->hasMany(Observation::class, 'record_id', 'am_id')
            ->where('record_type', 'AM');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(PhotoEvidence::class, 'record_id', 'am_id')
            ->where('record_type', 'AM');
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(Candidate::class, 'am_id', 'am_id');
    }

    /**
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
}
