<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportRequest extends Model
{
    use HasFactory, HasUuids;

    protected $primaryKey = 'report_request_id';

    protected $fillable = [
        'user_id',
        'agency_id',
        'index_id',
        'region_ids',
        'date_from',
        'date_to',
        'format',
        'status',
        'file_path',
        'generated_at',
    ];

    protected $casts = [
        'region_ids' => 'array',
        'date_from' => 'date',
        'date_to' => 'date',
        'generated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function index(): BelongsTo
    {
        return $this->belongsTo(ScoringIndex::class, 'index_id', 'index_id');
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class, 'agency_id', 'agency_id');
    }
}
