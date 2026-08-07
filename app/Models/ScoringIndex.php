<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named risk index (e.g. Malaria Risk, Flood Risk, Composite Pressure).
 *
 * Named `ScoringIndex` rather than `Index` to avoid colliding with the database-index
 * sense of that word elsewhere in the codebase.
 */
class ScoringIndex extends Model
{
    protected $table = 'indices';

    protected $primaryKey = 'index_id';

    public $timestamps = false;

    protected $fillable = [
        'code',
        'name',
        'description',
    ];

    public function scoringConfigs(): HasMany
    {
        return $this->hasMany(RegionScoringConfig::class, 'index_id', 'index_id');
    }

    public function scores(): HasMany
    {
        return $this->hasMany(RegionScore::class, 'index_id', 'index_id');
    }

    public function calibrationParameters(): HasMany
    {
        return $this->hasMany(ScoringCalibrationParameter::class, 'index_id', 'index_id');
    }
}
