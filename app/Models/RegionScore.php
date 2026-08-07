<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RegionScore extends Model
{
    protected $table = 'region_scores';

    // Composite PK (index_id, region_id, period_start) — declare the first column so
    // Eloquent doesn't break. Writes go through upsert(), not save()/find().
    protected $primaryKey = 'index_id';

    public $incrementing = false;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'index_id',
        'region_id',
        'period_start',
        'period_end',
        'score',
        'scoring_strategy',
        'scoring_version',
        'breakdown',
        'ai_summary',
        'ai_summary_generated_at',
        'ai_summary_model',
        'calculated_at',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'score' => 'decimal:2',
        'breakdown' => 'array',
        'ai_summary_generated_at' => 'datetime',
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
