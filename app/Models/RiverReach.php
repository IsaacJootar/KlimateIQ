<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A curated GloFAS sample point on one named river reach within one LGA (T4/T5 follow-up).
 * A confluence LGA has several — the Niger and the Benue at Lokoja, say. An LGA with no rows
 * is scored at its centroid as before.
 */
class RiverReach extends Model
{
    protected $primaryKey = 'river_reach_id';

    protected $fillable = ['region_id', 'reach', 'river', 'latitude', 'longitude', 'source'];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
    ];

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class, 'region_id', 'region_id');
    }
}
