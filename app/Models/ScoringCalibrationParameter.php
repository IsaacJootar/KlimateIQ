<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScoringCalibrationParameter extends Model
{
    protected $primaryKey = 'scoring_calibration_parameter_id';

    protected $fillable = [
        'index_id',
        'region_id',
        'parameter_key',
        'parameter_value',
        'parameter_metadata',
        'source_reference',
    ];

    protected $casts = [
        'parameter_value' => 'decimal:6',
        'parameter_metadata' => 'array',
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
