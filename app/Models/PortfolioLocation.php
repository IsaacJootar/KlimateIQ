<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One location in a Portfolio (Module 2) — either a seeded Region (`region_id`) or a raw
 * coordinate (`latitude`/`longitude` + `label`, since a raw point has no name of its own the way
 * a Region does). `order` is only meaningful for a corridor-type Portfolio (Module 8).
 *
 * A coordinate-only location is a real, accepted row — point/coordinate scoring is Module 3, not
 * this one. See App\Services\Portfolio\PortfolioRollupService for how it's honestly represented
 * as "not scorable yet" rather than silently guessing a nearby LGA's score.
 */
class PortfolioLocation extends Model
{
    protected $primaryKey = 'portfolio_location_id';

    protected $fillable = [
        'portfolio_id',
        'region_id',
        'latitude',
        'longitude',
        'label',
        'order',
    ];

    protected $casts = [
        'latitude' => 'decimal:6',
        'longitude' => 'decimal:6',
    ];

    public function portfolio(): BelongsTo
    {
        return $this->belongsTo(Portfolio::class, 'portfolio_id', 'portfolio_id');
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class, 'region_id', 'region_id');
    }

    public function isCoordinateOnly(): bool
    {
        return $this->region_id === null;
    }

    /**
     * What to show in the UI — the region's own name, or the label a user gave a raw
     * coordinate, or the bare coordinate pair if even that's missing.
     */
    public function displayName(): string
    {
        return $this->region?->name
            ?? $this->label
            ?? "{$this->latitude}, {$this->longitude}";
    }
}
