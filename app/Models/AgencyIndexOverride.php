<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One admin override of a single index's visibility for one agency, on top of that agency's
 * PortfolioType default (App\Support\PortfolioType::defaultIndexCodes()) — `enabled => true`
 * forces an index in even if the type default doesn't include it, `enabled => false` forces it
 * out even if the type default does. No row for a given index means the type default applies
 * untouched. See App\Support\AgencyIndexCoverage::resolve().
 */
class AgencyIndexOverride extends Model
{
    protected $primaryKey = 'agency_index_override_id';

    protected $fillable = [
        'agency_id',
        'index_id',
        'enabled',
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class, 'agency_id', 'agency_id');
    }

    public function index(): BelongsTo
    {
        return $this->belongsTo(ScoringIndex::class, 'index_id', 'index_id');
    }
}
