<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserIndexSubscription extends Model
{
    protected $table = 'user_index_subscriptions';

    // Composite PK (user_id, index_id) — declare the first column so Eloquent doesn't break.
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'index_id',
        'wants_alerts',
    ];

    protected $casts = [
        'wants_alerts' => 'boolean',
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
