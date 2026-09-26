<?php

namespace App\Models;

use App\Models\Concerns\HasStringKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A photograph attached to a record, or a placeholder standing in for one that
 * must never be shown or matched.
 *
 * Photographs are evidence artefacts, not matching keys. They enter scoring
 * only after a vision step converts them into the same normalised items as
 * text, carrying provenance and a confidence a human can override.
 */
class PhotoEvidence extends Model
{
    use HasStringKey;

    protected $table = 'photo_evidence';

    protected $primaryKey = 'photo_id';

    protected $guarded = [];

    protected $casts = [
        'captured_at' => 'datetime',
        'synthetic' => 'boolean',
    ];

    public const POLICY_RESTRICTED = 'restricted_display_only_never_matched';

    public const MODALITY_FACE = 'face_photo_restricted';

    public function normalisedItems(): HasMany
    {
        return $this->hasMany(PhotoNorm::class, 'photo_id', 'photo_id');
    }

    /**
     * Whether this photo may be read by the vision step at all.
     *
     * Visual recognition is unreliable after post-mortem change and is not a
     * primary identifier, so face photographs are display-only — and in this
     * dataset they carry no file at all.
     */
    public function isMatchable(): bool
    {
        return $this->use_policy !== self::POLICY_RESTRICTED
            && $this->modality !== self::MODALITY_FACE
            && filled($this->file_path);
    }

    public function scopeMatchable($query)
    {
        return $query->where('use_policy', '!=', self::POLICY_RESTRICTED)
            ->where('modality', '!=', self::MODALITY_FACE)
            ->whereNotNull('file_path')
            ->where('file_path', '!=', '');
    }

    /**
     * @return list<string>
     */
    public function qualityFlagList(): array
    {
        return array_values(array_filter(explode(',', (string) $this->quality_flags)));
    }
}
