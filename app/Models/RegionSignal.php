<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RegionSignal extends Model
{
    protected $primaryKey = 'region_signal_id';

    public $timestamps = false;

    protected $fillable = [
        'region_id',
        'signal_type_id',
        'period_start',
        'period_end',
        'value',
        'raw_metadata',
        'source',
        'ingested_at',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
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
