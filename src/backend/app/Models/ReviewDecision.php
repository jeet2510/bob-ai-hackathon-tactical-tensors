<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reviewer's conclusion about one body. Append-only: changing your mind adds
 * a row, it never rewrites one, so the history of who concluded what and when
 * survives as evidence in its own right.
 *
 * Note the vocabulary. The strongest decision available is
 * `recommend_confirm_test` — the system recommends which primary-identifier
 * test to run, and never asserts an identification itself.
 */
class ReviewDecision extends Model
{
    protected $table = 'review_decision';

    protected $guarded = [];

    protected $casts = [
        'decided_at' => 'datetime',
    ];

    public const RECOMMEND_CONFIRM_TEST = 'recommend_confirm_test';

    public const REJECT = 'reject';

    public const DEFER = 'defer';

    public const NO_CREDIBLE_CANDIDATE = 'no_credible_candidate';

    public const DECISIONS = [
        self::RECOMMEND_CONFIRM_TEST,
        self::REJECT,
        self::DEFER,
        self::NO_CREDIBLE_CANDIDATE,
    ];

    public function pmCase(): BelongsTo
    {
        return $this->belongsTo(PmCase::class, 'pm_id', 'pm_id');
    }

    public function amFile(): BelongsTo
    {
        return $this->belongsTo(AmFile::class, 'am_id', 'am_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The decision currently in force for a body: the most recent row.
     */
    public static function currentFor(string $pmId): ?self
    {
        return static::where('pm_id', $pmId)->latest('decided_at')->latest('id')->first();
    }
}
