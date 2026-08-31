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
     * A compact label for tight UI like the tab-strip headings — "Public Health &
     * Epidemiology" becomes "Public Health". Everything before the first " & " / " and ".
     */
    protected function shortName(): Attribute
    {
        return Attribute::get(fn () => (string) Str::of($this->name)->before(' & ')->before(' and '));
    }

    public function indices(): BelongsToMany
    {
        return $this->belongsToMany(ScoringIndex::class, 'index_sector', 'sector_id', 'index_id')
            ->withPivot('theme', 'sort_order')
            ->orderBy('index_sector.sort_order');
    }
}
