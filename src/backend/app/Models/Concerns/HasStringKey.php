<?php

namespace App\Models\Concerns;

/**
 * For models keyed by the dataset's own identifiers (PM-LS26-014, AM-LS26-007)
 * rather than autoincrement integers.
 */
trait HasStringKey
{
    public $incrementing = false;

    protected $keyType = 'string';
}
