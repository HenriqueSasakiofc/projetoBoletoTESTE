<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationTemplate extends Model {
    protected $table = 'notification_templates';

    protected $fillable = [
        'company_id',
        'event_code',
        'subject',
        'body',
        'is_active',
    ];
}
