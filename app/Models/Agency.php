<?php

namespace App\Models;

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
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'agency_id', 'agency_id');
    }
}
