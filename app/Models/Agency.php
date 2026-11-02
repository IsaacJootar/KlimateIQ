<?php

namespace App\Models;

use App\Support\PortfolioType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Agency extends Model
{
    use HasFactory, HasUuids;

    protected $primaryKey = 'agency_id';

    protected $fillable = [
        'name',
        'type',
        'federal_oversight',
        'portfolio_type',
    ];

    protected $casts = [
        'federal_oversight' => 'boolean',
        'portfolio_type' => PortfolioType::class,
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'agency_id', 'agency_id');
    }

    /**
     * Per-agency admin overrides on top of the portfolio-type default — see
     * App\Support\AgencyIndexCoverage::resolve().
     */
    public function indexOverrides(): HasMany
    {
        return $this->hasMany(AgencyIndexOverride::class, 'agency_id', 'agency_id');
    }
}
