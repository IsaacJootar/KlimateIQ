<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ThresholdConfig extends Model
{
    use HasFactory, HasUuids;

    protected $primaryKey = 'threshold_config_id';

    protected $fillable = [
        'user_id',
        'agency_id',
        'region_id',
        'index_id',
        'signal_type_id',
        'alert_type',
        'comparison_operator',
        'threshold_value',
        'anomaly_stddev_multiplier',
        'active',
    ];

    protected $casts = [
        'threshold_value' => 'decimal:4',
        'anomaly_stddev_multiplier' => 'decimal:2',
        'active' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class, 'region_id', 'region_id');
    }

    public function index(): BelongsTo
    {
        return $this->belongsTo(ScoringIndex::class, 'index_id', 'index_id');
    }

    public function signalType(): BelongsTo
    {
        return $this->belongsTo(SignalType::class, 'signal_type_id', 'signal_type_id');
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class, 'threshold_config_id', 'threshold_config_id');
    }

    public function isAnomalyType(): bool
    {
        return $this->alert_type === 'anomaly';
    }
}
