<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A real place on record in an LGA (Clarity Pass D1). Populated from GRID3 Nigeria; shown in
 * the UI only ever as examples to verify locally.
 *
 * @property string $name
 * @property string $type health | school | market | water_point | shelter
 * @property ?string $category
 */
class Facility extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'region_id', 'name', 'type', 'category', 'state',
        'latitude', 'longitude', 'source', 'source_year', 'sort_order',
    ];

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class, 'region_id', 'region_id');
    }
}
