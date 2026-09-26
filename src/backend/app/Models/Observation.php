<?php

namespace App\Models;

use App\Models\Concerns\HasStringKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One free-text box from an INTERPOL form, exactly as it was written.
 *
 * Text arrives in English, Hindi and Marathi — including Marathi written in
 * Latin script and mixed with English ("black blouse ani purple saree ghatle
 * hote"). Nothing here is comparable until Stage 1 turns it into items.
 */
class Observation extends Model
{
    use HasStringKey;

    protected $table = 'observation';

    protected $primaryKey = 'obs_id';

    protected $guarded = [];

    protected $casts = [
        'recorded_at' => 'datetime',
        'synthetic' => 'boolean',
    ];

    public function normalisedItems(): HasMany
    {
        return $this->hasMany(ObservationNorm::class, 'obs_id', 'obs_id');
    }

    public function scopeForRecord($query, string $type, string $id)
    {
        return $query->where('record_type', $type)->where('record_id', $id);
    }

    public function isTranslated(): bool
    {
        return $this->lang !== 'en';
    }
}
