<?php

namespace App\Models;

use App\Support\RiskBand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rule-based "what to do about it" text per index + risk band — not AI, just config.
 * Turns a bare score into a concrete next step for the officer reading it.
 */
class IndexActionRecommendation extends Model
{
    protected $primaryKey = 'recommendation_id';

    protected $fillable = [
        'index_id',
        'risk_band',
        'action_text',
    ];

    public function index(): BelongsTo
    {
        return $this->belongsTo(ScoringIndex::class, 'index_id', 'index_id');
    }

    public static function textFor(int $indexId, ?float $score): ?string
    {
        $band = RiskBand::forScore($score);

        if ($band === 'none') {
            return null;
        }

        return static::query()
            ->where('index_id', $indexId)
            ->where('risk_band', $band)
            ->value('action_text');
    }
}
