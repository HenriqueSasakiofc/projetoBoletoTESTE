<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MessageTemplate extends Model {
    protected $table = 'message_templates';
    
    protected $fillable = [
        'company_id',
        'subject',
        'body',
        'is_active'
    ];
}
