<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Why a pairing scores what it does, for one evidence category.
 *
 * The four verdicts are forensically distinct and must not be collapsed:
 * a conflict is evidence against, a missing field is no evidence at all, and
 * an unassessable finding is evidence that could not be gathered.
 */
class MatchEvidence extends Model
{
    protected $table = 'match_evidence';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'llr' => 'float',
        'pm_item' => 'array',
        'am_item' => 'array',
    ];

    public const VERDICT_MATCH = 'match';

    public const VERDICT_CONFLICT = 'conflict';

    public const VERDICT_MISSING = 'missing';

    public const VERDICT_EXCLUDED = 'excluded';

    public function run(): BelongsTo
    {
        return $this->belongsTo(MatchRun::class, 'run_id', 'run_id');
    }

    /**
     * Whether this row actually moved the score, as opposed to recording that
     * there was nothing to compare.
     */
    public function isInformative(): bool
    {
        return in_array($this->verdict, [self::VERDICT_MATCH, self::VERDICT_CONFLICT], true);
    }
}
