<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class User extends Model {
    protected $table = 'users';
    
    // Disable Laravel's default timestamps if they don't exactly match `created_at` and `updated_at` setup
    // public $timestamps = false;
    
    protected $fillable = [
        'company_id',
        'email',
        'full_name',
        'password_hash',
        'role',
        'is_active'
    ];

    public function company() {
        return $this->belongsTo(Company::class);
    }
}
