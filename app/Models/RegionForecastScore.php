<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The current forward score for one region and forecast index (BUILD_PLAN.md T4). The forecast
 * counterpart of RegionScore — composite PK (index_id, region_id), writes via DB upsert.
 * Never queried by any observed-data code path.
 */
class RegionForecastScore extends Model
{
    protected $table = 'region_forecast_scores';

    protected $primaryKey = 'index_id';

    public $incrementing = false;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'index_id',
        'region_id',
        'forecast_issued_at',
        'horizon_days',
        'score',
        'peak_date',
        'lead_days_to_peak',
        'scoring_strategy',
        'scoring_version',
        'breakdown',
        'calculated_at',
    ];

    protected $casts = [
        'forecast_issued_at' => 'date',
        'peak_date' => 'date',
        'horizon_days' => 'integer',
        'lead_days_to_peak' => 'integer',
        'score' => 'decimal:2',
        'breakdown' => 'array',
        'calculated_at' => 'datetime',
    ];

    public function index(): BelongsTo
    {
        return $this->belongsTo(ScoringIndex::class, 'index_id', 'index_id');
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class, 'region_id', 'region_id');
    }
}
