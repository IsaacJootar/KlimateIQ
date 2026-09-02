<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
        'is_forecast',
    ];

    protected $casts = [
        'is_forecast' => 'boolean',
    ];

    /** Indices scored from a completed period — everything scores:calculate owns. */
    public function scopeObserved($query)
    {
        return $query->where('is_forecast', false);
    }

    /** Forward-looking indices — scores:forecast owns these, read from region_forecast_scores. */
    public function scopeForecast($query)
    {
        return $query->where('is_forecast', true);
    }

    /**
     * Every index scores:forecast should run: the dedicated forecast indices, plus any observed
     * index that weights at least one signal with a forecast source (Flood Risk on forecast
     * rainfall, Heat Stress on forecast temperature). BUILD_PLAN.md T4.
     *
     * @param  array<string>  $forecastSignalCodes  signal_types.code that have a ForecastIngestionService
     */
    public function scopeForwardScorable($query, array $forecastSignalCodes)
    {
        return $query->where(function ($q) use ($forecastSignalCodes) {
            $q->where('is_forecast', true)
                ->orWhereIn('index_id', function ($sub) use ($forecastSignalCodes) {
                    $sub->select('rsc.index_id')
                        ->from('region_scoring_configs as rsc')
                        ->join('signal_types as st', 'st.signal_type_id', '=', 'rsc.signal_type_id')
                        ->where('rsc.enabled', true)
                        ->whereIn('st.code', $forecastSignalCodes);
                });
        });
    }

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

    public function sectors(): BelongsToMany
    {
        return $this->belongsToMany(Sector::class, 'index_sector', 'index_id', 'sector_id')
            ->withPivot('theme', 'sort_order')
            ->orderBy('sectors.sort_order');
    }
}
