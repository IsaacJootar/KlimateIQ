<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedView extends Model
{
    use HasFactory, HasUuids;

    protected $primaryKey = 'saved_view_id';

    protected $fillable = [
        'user_id',
        'name',
        'index_id',
        'region_ids',
        'view_config',
    ];

    protected $casts = [
        'region_ids' => 'array',
        'view_config' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function index(): BelongsTo
    {
        return $this->belongsTo(ScoringIndex::class, 'index_id', 'index_id');
    }
}
