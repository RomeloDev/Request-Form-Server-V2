<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'firstName', 'lastName', 'contact', 'branch_code', 'branch', 'position', 'userName', 'employee_id', 'signature', 'profile_picture'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

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

    /**
     * Assignments where this user is the official AVP Finance.
     *
     * One AVP may have multiple delegated staff members.
     */
    public function avpStaffAssignments(): HasMany
    {
        return $this->hasMany(
            AvpStaffAssignment::class,
            'avp_user_id'
        );
    }

    /**
     * Assignment where this user acts as delegated AVP staff.
     *
     * The database currently allows one assignment per staff user.
     */
    public function avpStaffAssignment(): HasOne
    {
        return $this->hasOne(
            AvpStaffAssignment::class,
            'staff_user_id'
        );
    }
}
