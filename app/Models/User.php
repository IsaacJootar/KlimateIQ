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

#[Fillable(['name', 'email', 'password', 'agency_id', 'platform_role', 'phone_number', 'designation', 'state', 'theme'])]
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
            'disabled_at' => 'datetime',
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

    /**
     * disabled_at is deliberately not in #[Fillable] — a user must never be able to set this
     * on their own account through any normal update path. Only UserManagementController
     * writes to it, via forceFill().
     */
    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    public function dashboardPreference(): HasOne
    {
        return $this->hasOne(UserDashboardPreference::class, 'user_id');
    }

    /**
     * Every user gets a preference row lazily on first access rather than at registration, so
     * the defaults (alert_channels => ['in_app']) live in one place.
     *
     * Uses firstOrCreate() rather than the cached `dashboardPreference` relation — accessing
     * that relation caches its (possibly null) result on the model instance, and creating the
     * row afterwards doesn't refresh that cache, so a second call in the same request would
     * see the stale null and try to create the row again.
     */
    public function getOrCreateDashboardPreference(): UserDashboardPreference
    {
        return UserDashboardPreference::query()->firstOrCreate(
            ['user_id' => $this->id],
            ['default_view' => 'list', 'alert_channels' => ['in_app']]
        );
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
