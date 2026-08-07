<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserRegionSubscription extends Model
{
    protected $table = 'user_region_subscriptions';

    // Composite PK (user_id, region_id) — declare the first column so Eloquent doesn't break.
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'int';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'region_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class, 'region_id', 'region_id');
    }
}
