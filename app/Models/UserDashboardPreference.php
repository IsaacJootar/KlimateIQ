<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserDashboardPreference extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'user_id',
        'default_view',
        'alert_channels',
        'current_sector_id',
    ];

    protected $casts = [
        'alert_channels' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function currentSector(): BelongsTo
    {
        return $this->belongsTo(Sector::class, 'current_sector_id', 'sector_id');
    }

    public function wantsChannel(string $channel): bool
    {
        return in_array($channel, $this->alert_channels ?? ['in_app'], true);
    }
}
