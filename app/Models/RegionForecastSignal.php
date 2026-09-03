<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One day of a forecast for one region and signal (BUILD_PLAN.md T4). The forecast counterpart
 * of RegionSignal — same shape, different lane. Never queried by any observed-data code path.
 */
class RegionForecastSignal extends Model
{
    protected $primaryKey = 'region_forecast_signal_id';

    public $timestamps = false;

    protected $fillable = [
        'region_id',
        'signal_type_id',
        'member',
        'forecast_issued_at',
        'target_date',
        'lead_days',
        'value',
        'raw_metadata',
        'source',
        'ingested_at',
    ];

    protected $casts = [
        'forecast_issued_at' => 'date',
        'target_date' => 'date',
        'lead_days' => 'integer',
        'value' => 'decimal:4',
        'raw_metadata' => 'array',
        'ingested_at' => 'datetime',
    ];

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class, 'region_id', 'region_id');
    }

    public function signalType(): BelongsTo
    {
        return $this->belongsTo(SignalType::class, 'signal_type_id', 'signal_type_id');
    }
}
