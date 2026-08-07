<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RegionScoringConfig extends Model
{
    protected $primaryKey = 'region_scoring_config_id';

    protected $fillable = [
        'index_id',
        'region_id',
        'signal_type_id',
        'weight',
        'vulnerability_weight',
        'higher_is_worse',
        'enabled',
    ];

    protected $casts = [
        'weight' => 'decimal:4',
        'vulnerability_weight' => 'decimal:4',
        'higher_is_worse' => 'boolean',
        'enabled' => 'boolean',
    ];

    public function index(): BelongsTo
    {
        return $this->belongsTo(ScoringIndex::class, 'index_id', 'index_id');
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class, 'region_id', 'region_id');
    }

    public function signalType(): BelongsTo
    {
        return $this->belongsTo(SignalType::class, 'signal_type_id', 'signal_type_id');
    }
}
