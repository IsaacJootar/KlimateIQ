<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Region extends Model
{
    protected $primaryKey = 'region_id';

    protected $fillable = [
        'name',
        'state',
        'lga_code',
        'latitude',
        'longitude',
        'population',
        'preferred_scoring_strategy',
    ];

    protected $casts = [
        'latitude' => 'decimal:6',
        'longitude' => 'decimal:6',
        'population' => 'integer',
    ];

    public function signals(): HasMany
    {
        return $this->hasMany(RegionSignal::class, 'region_id', 'region_id');
    }

    public function scores(): HasMany
    {
        return $this->hasMany(RegionScore::class, 'region_id', 'region_id');
    }

    public function vulnerabilityProfile(): HasOne
    {
        return $this->hasOne(RegionVulnerabilityProfile::class, 'region_id', 'region_id');
    }

    public function scoringConfigs(): HasMany
    {
        return $this->hasMany(RegionScoringConfig::class, 'region_id', 'region_id');
    }
}
