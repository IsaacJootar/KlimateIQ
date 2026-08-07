<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'agency_id', 'platform_role'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class, 'agency_id', 'agency_id');
    }

    public function isPlatformAdmin(): bool
    {
        return $this->platform_role === 'PLATFORM_ADMIN';
    }

    public function dashboardPreference(): HasOne
    {
        return $this->hasOne(UserDashboardPreference::class, 'user_id');
    }

    public function indexSubscriptions(): HasMany
    {
        return $this->hasMany(UserIndexSubscription::class, 'user_id');
    }

    public function regionSubscriptions(): HasMany
    {
        return $this->hasMany(UserRegionSubscription::class, 'user_id');
    }

    public function thresholdConfigs(): HasMany
    {
        return $this->hasMany(ThresholdConfig::class, 'user_id');
    }

    public function savedViews(): HasMany
    {
        return $this->hasMany(SavedView::class, 'user_id');
    }

    public function reportRequests(): HasMany
    {
        return $this->hasMany(ReportRequest::class, 'user_id');
    }
}
