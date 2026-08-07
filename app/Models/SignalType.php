<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SignalType extends Model
{
    protected $primaryKey = 'signal_type_id';

    public $timestamps = false;

    protected $fillable = [
        'code',
        'name',
        'unit',
        'source',
        'higher_is_worse',
    ];

    protected $casts = [
        'higher_is_worse' => 'boolean',
    ];

    public function regionSignals(): HasMany
    {
        return $this->hasMany(RegionSignal::class, 'signal_type_id', 'signal_type_id');
    }
}
