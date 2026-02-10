<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Faculty extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'faculty_id',
        'name',
        'email',
        'phone_number',
        'department',
        'status',
        'profile_picture',
    ];

    /**
     * The accessors to append to the model's array form.
     */
    protected $appends = ['profile_picture_url'];

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
        return null;
    }

    /**
     * Relationship: Faculty has many FacultyTransactions (borrow history)
     */
    public function transactions()
    {
        return $this->hasMany(FacultyTransaction::class);
    }

    /**
     * Get active loans (borrowed but not returned)
     */
    public function activeLoans()
    {
        return $this->hasMany(FacultyTransaction::class)->whereNull('returned_at');
    }

    /**
     * Get total books borrowed count
     */
    public function getTotalBorrowedAttribute()
    {
        return $this->transactions()->count();
    }

    /**
     * Get active loans count
     */
    public function getActiveLoansCountAttribute()
    {
        return $this->activeLoans()->count();
    }

    /**
     * Check if faculty has overdue books
     */
    public function getHasOverdueAttribute()
    {
        return $this->activeLoans()
            ->where('due_date', '<', now())
            ->exists();
    }
}
