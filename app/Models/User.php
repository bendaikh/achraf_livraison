<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_SUPERADMIN = 'superadmin';

    public const ROLE_ADMIN = 'admin';

    public const ROLE_USER = 'user';

    public const ROLE_LIVREUR = 'livreur';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'company_id',
        'phone',
        'service_id',
        'manager_id',
        'is_active',
        'commission_mode',
        'commission_value',
        'commission_trigger',
    ];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(AgentCommission::class);
    }

    /** Commission trigger in force (explicit or the mode's default). */
    public function commissionTrigger(): ?string
    {
        return $this->commission_trigger ?: config('commissions.modes.'.($this->commission_mode ?: 'none').'.default_trigger');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function driver(): HasOne
    {
        return $this->hasOne(Driver::class);
    }

    public function resolveCompanyId(): int
    {
        if ($this->company_id) {
            return (int) $this->company_id;
        }

        $company = Company::default();
        $this->forceFill(['company_id' => $company->id])->save();

        return (int) $company->id;
    }

    public function roleLabel(): string
    {
        return match ($this->role) {
            self::ROLE_SUPERADMIN => 'Super Admin',
            self::ROLE_ADMIN => 'Admin',
            self::ROLE_LIVREUR => 'Livreur',
            default => 'Agent',
        };
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === self::ROLE_SUPERADMIN;
    }

    public function isAdmin(): bool
    {
        return in_array($this->role, [self::ROLE_SUPERADMIN, self::ROLE_ADMIN], true);
    }

    public function isLivreur(): bool
    {
        return $this->role === self::ROLE_LIVREUR;
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'commission_value' => 'float',
        ];
    }
}
