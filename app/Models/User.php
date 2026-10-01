<?php

namespace App\Models;

use App\Notifications\VerifyPlayerEmail;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['tenant_id', 'home_facility_id', 'name', 'email', 'password', 'avatar_path', 'two_factor_secret', 'two_factor_confirmed_at'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function homeFacility()
    {
        return $this->belongsTo(Facility::class, 'home_facility_id');
    }

    public function sendEmailVerificationNotification()
    {
        $this->notify((new VerifyPlayerEmail)->afterCommit());
    }

    public function activityLogs()
    {
        return $this->hasMany(ActivityLog::class, 'actor_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
