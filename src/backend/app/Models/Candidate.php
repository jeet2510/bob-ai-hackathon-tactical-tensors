<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A ranked PM/AM pairing offered to a reviewer.
 *
 * A candidate is a suggestion about which ante-mortem file to *test* first,
 * never a conclusion. Bands are ordinal evidence strength, not calibrated
 * probabilities, and `NO_CREDIBLE_CANDIDATE` is a legitimate outcome rather
 * than a failure to find one.
 */
class Candidate extends Model
{
    protected $table = 'candidate';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'score' => 'float',
        'coverage' => 'float',
        'assigned' => 'boolean',
        'recommended_route' => 'array',
    ];

    public const BAND_HIGH = 'high';

    public const BAND_MODERATE = 'moderate';

    public const BAND_LOW = 'low';

    public const BAND_NONE = 'no_credible_candidate';

    public function run(): BelongsTo
    {
        return $this->belongsTo(MatchRun::class, 'run_id', 'run_id');
    }

    public function pmCase(): BelongsTo
    {
        return $this->belongsTo(PmCase::class, 'pm_id', 'pm_id');
    }

    public function amFile(): BelongsTo
    {
        return $this->belongsTo(AmFile::class, 'am_id', 'am_id');
    }

    /**
     * Evidence rows behind this pairing, in the same run.
     */
    public function evidence(): HasMany
    {
        return $this->hasMany(MatchEvidence::class, 'run_id', 'run_id')
            ->where('pm_id', $this->pm_id)
            ->where('am_id', $this->am_id);
    }
}
