<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * A grouping of indices that maps to a real-world responsibility — "Public Health",
 * "Agriculture & Food Security", etc. Picking a sector is a shorthand for picking its
 * indices; nothing downstream of `user_index_subscriptions` knows sectors exist.
 */
class Sector extends Model
{
    protected $table = 'sectors';

    protected $primaryKey = 'sector_id';

    public $timestamps = false;

    protected $fillable = [
        'code',
        'name',
        'description',
        'sort_order',
        'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    /**
     * One plain "here's what you'll actually get" line per sector (Clarity Pass E2), in the
     * voice the rest of the app uses — amber weeks, named crops and facilities, plain signal
     * language. Shown on the onboarding wizard and the Workspace page instead of the terser
     * `description` (which stays as the short internal summary). Keyed by sector code.
     */
    private const PROMISES = [
        'OVERVIEW' => 'One combined climate-health pressure score per LGA — the number to scan before you drill into a specific risk.',
        'PUBLIC_HEALTH' => 'A weekly malaria, cholera/typhoid, respiratory and heat-health risk score for every LGA you follow — and, on an amber week, the clinics and schools in that LGA to notify.',
        'AGRICULTURE' => 'Crop-water-stress, drought, irrigation-need and grazing-stress scores per LGA — with the rain-fed crops most exposed right now named for that zone and month.',
        'EMERGENCY_RESPONSE' => 'Which LGAs are closest to flooding, dangerous heat, bush fire or dust storms — and, on an amber week, the stadiums, schools and camps to stage a response from.',
        'WATER_SANITATION' => 'Flood risk, post-flood disease risk and dry-season water-availability scores per LGA — with the treatment plants and water points serving each one.',
        'AIR_ENVIRONMENT' => 'A daily-refreshed respiratory-health score per LGA — fine particulates, ozone, NO₂ and harmattan dust rolled into one number.',
    ];

    /**
     * A compact label for tight UI like the tab-strip headings — "Public Health &
     * Epidemiology" becomes "Public Health". Everything before the first " & " / " and ".
     */
    protected function shortName(): Attribute
    {
        return Attribute::get(fn () => (string) Str::of($this->name)->before(' & ')->before(' and '));
    }

    /**
     * The reader-facing "what you'll get" line, falling back to the internal description for any
     * sector not in the map (e.g. one added later).
     */
    protected function promise(): Attribute
    {
        return Attribute::get(fn () => self::PROMISES[$this->code] ?? $this->description);
    }

    public function indices(): BelongsToMany
    {
        return $this->belongsToMany(ScoringIndex::class, 'index_sector', 'sector_id', 'index_id')
            ->withPivot('theme', 'sort_order')
            ->orderBy('index_sector.sort_order');
    }
}
