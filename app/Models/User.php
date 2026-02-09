<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'student_id',
        'name',
        'email',
        'phone_number',
        'username',
        'password',
        'role',
        'permissions',
        'status',
        'course',
        'year_level',
        'section',
        'profile_picture', // Added
        'otp_code',
        'otp_expires_at',
    ];

    /**
     * The attributes that should be hidden for arrays.
     */
    protected $hidden = [
        'password',
        'remember_token',
        'otp_code', // Hide OTP from array/JSON serialization
    ];

    /**
     * The accessors to append to the model's array form.
     */
    protected $appends = ['profile_picture_url'];

    /**
     * The attributes that should be cast to native types.
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'otp_expires_at' => 'datetime',
    ];

    /**
     * Get the profile picture URL.
     */
    public function getProfilePictureUrlAttribute()
    {
        if ($this->profile_picture) {
            if (filter_var($this->profile_picture, FILTER_VALIDATE_URL)) {
                return $this->profile_picture;
            }
            return asset('storage/' . $this->profile_picture);
        }
        return null; // Frontend handles fallback
    }

    /**
     * Relationship: User has many Transactions (borrow history)
     */
    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }
}