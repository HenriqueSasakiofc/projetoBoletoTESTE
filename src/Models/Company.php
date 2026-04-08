<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Company extends Model {
    protected $table = 'companies';
    
    protected $fillable = [
        'slug',
        'legal_name',
        'trade_name',
        'is_active'
    ];

    public function users() {
        return $this->hasMany(User::class);
    }
}
