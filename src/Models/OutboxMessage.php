<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OutboxMessage extends Model {
    protected $table = 'outbox_messages';
    
    protected $fillable = [
        'company_id',
        'receivable_id',
        'customer_id',
        'created_by_user_id',
        'message_kind',
        'notification_event',
        'scheduled_for_date',
        'recipient_email',
        'subject',
        'body',
        'dedupe_key',
        'status',
        'error_message',
        'sent_at'
    ];

    public function receivable() {
        return $this->belongsTo(Receivable::class);
    }
}
