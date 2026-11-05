<?php

namespace App\Models;

use App\Support\PortfolioType;
use App\Support\RiskBand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rule-based "what to do about it" text per index + risk band — not AI, just config.
 * Turns a bare score into a concrete next step for the officer reading it.
 *
 * Enterprise pivot, Module 11 (docs/ENTERPRISE_BUILD_PLAN.md) — a row can also be scoped to a
 * Portfolio Type (`portfolio_type` null = the generic text every pre-pivot recommendation already
 * is). A region reached from an Insurance & Reinsurance portfolio gets underwriting/claims
 * language where a specific row exists; everyone else keeps the original public-sector text.
 */
class IndexActionRecommendation extends Model
{
    protected $primaryKey = 'recommendation_id';

    protected $fillable = [
        'index_id',
        'risk_band',
        'portfolio_type',
        'action_text',
    ];

    public function index(): BelongsTo
    {
        return $this->belongsTo(ScoringIndex::class, 'index_id', 'index_id');
    }

    public static function textFor(int $indexId, ?float $score, ?PortfolioType $portfolioType = null): ?string
    {
        $band = RiskBand::forScore($score);

        if ($band === 'none') {
            return null;
        }

        if ($portfolioType !== null) {
            $specific = static::query()
                ->where('index_id', $indexId)
                ->where('risk_band', $band)
                ->where('portfolio_type', $portfolioType->value)
                ->value('action_text');

            if ($specific !== null) {
                return $specific;
            }
        }

        return static::query()
            ->where('index_id', $indexId)
            ->where('risk_band', $band)
            ->whereNull('portfolio_type')
            ->value('action_text');
    }
}
