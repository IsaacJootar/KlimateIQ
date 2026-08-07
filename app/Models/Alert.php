<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Alert extends Model
{
    use HasFactory, HasUuids;

    protected $primaryKey = 'alert_id';

    protected $fillable = [
        'threshold_config_id',
        'region_id',
        'index_id',
        'signal_type_id',
        'score_at_trigger',
        'threshold_value',
        'status',
        'triggered_at',
        'acknowledged_at',
        'resolved_at',
    ];

    protected $casts = [
        'score_at_trigger' => 'decimal:4',
        'threshold_value' => 'decimal:4',
        'triggered_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function thresholdConfig(): BelongsTo
    {
        return $this->belongsTo(ThresholdConfig::class, 'threshold_config_id', 'threshold_config_id');
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

    public function acknowledge(): void
    {
        $this->update(['status' => 'ACKNOWLEDGED', 'acknowledged_at' => now()]);
    }

    public function resolve(): void
    {
        $this->update(['status' => 'RESOLVED', 'resolved_at' => now()]);
    }
}
