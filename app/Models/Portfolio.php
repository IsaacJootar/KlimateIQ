<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Enterprise pivot, Module 2 (docs/ENTERPRISE_BUILD_PLAN.md) — a named list of locations
 * belonging to an agency: a bank's loan-book LGAs, a telecom's site list, an insurer's covered
 * LGAs. Rolled up into one view by App\Services\Portfolio\PortfolioRollupService.
 */
class Portfolio extends Model
{
    protected $primaryKey = 'portfolio_id';

    protected $fillable = [
        'agency_id',
        'name',
        'type',
    ];

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class, 'agency_id', 'agency_id');
    }

    /**
     * Ordered by `order` (meaningful for a corridor, Module 8) then insertion order, so a flat
     * point-list portfolio still has a stable, predictable display order.
     */
    public function locations(): HasMany
    {
        return $this->hasMany(PortfolioLocation::class, 'portfolio_id', 'portfolio_id')
            ->orderByRaw('"order" is null, "order" asc')
            ->orderBy('portfolio_location_id');
    }

    public function isCorridor(): bool
    {
        return $this->type === 'corridor';
    }

    /**
     * The Sanctum ability string a token must carry to read this portfolio's API feed —
     * Module 5 (docs/ENTERPRISE_BUILD_PLAN.md). One shared source of truth for the ability
     * PortfolioController issues and PortfolioFeedApiController checks.
     */
    public function feedAbility(): string
    {
        return "portfolio:{$this->portfolio_id}";
    }
}
