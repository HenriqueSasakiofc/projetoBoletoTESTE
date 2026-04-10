<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ManualMessage extends Model {
    protected $table = 'manual_messages';

    protected $fillable = [
        'company_id',
        'customer_id',
        'created_by_user_id',
        'recipient_email',
        'subject',
        'body',
        'preview_hash'
    ];
}
