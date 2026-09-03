<?php

namespace App\Models;

use App\Support\CalibrationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScoringCalibrationParameter extends Model
{
    protected $primaryKey = 'scoring_calibration_parameter_id';

    protected $fillable = [
        'index_id',
        'region_id',
        'reach',
        'parameter_key',
        'parameter_value',
        'parameter_metadata',
        'source_reference',
        'calibration_status',
    ];

    protected $casts = [
        'parameter_value' => 'decimal:6',
        'parameter_metadata' => 'array',
        'calibration_status' => CalibrationStatus::class,
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
