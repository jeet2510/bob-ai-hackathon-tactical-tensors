<?php

namespace App\Models\Concerns;

/**
 * For models keyed by the dataset's own identifiers (PM-LS26-014, AM-LS26-007)
 * rather than autoincrement integers.
 */
trait HasStringKey
{
    /**
     * Set on construction rather than redeclared here: PHP 8.4 treats a
     * trait property with a different default than the same property on
     * Eloquent's own Model class as an incompatible composition and fatals.
     */
    public function initializeHasStringKey(): void
    {
        $this->incrementing = false;
        $this->keyType = 'string';
    }
}
