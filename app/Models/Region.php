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

    public function subscribers(): HasMany
    {
        return $this->hasMany(UserRegionSubscription::class, 'region_id', 'region_id');
    }

    public function facilities(): HasMany
    {
        return $this->hasMany(Facility::class, 'region_id', 'region_id');
    }

    /**
     * A region is "active" — worth pulling live signals for — once it's ever had a signal
     * recorded, or at least one user is currently watching it. Most of Nigeria's 774 LGAs
     * are seeded but dormant; this is what keeps weekly ingestion scoped to the ones that
     * actually matter to someone, rather than all 774 regardless of relevance.
     */
    public function scopeActive($query)
    {
        return $query->where(fn ($q) => $q->whereHas('signals')->orWhereHas('subscribers'));
    }
}
