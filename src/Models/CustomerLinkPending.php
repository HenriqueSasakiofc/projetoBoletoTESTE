<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerLinkPending extends Model {
    protected $table = 'customer_link_pendings';

    protected $fillable = [
        'company_id',
        'upload_batch_id',
        'staging_receivable_id',
        'suggested_customer_id',
        'resolved_customer_id',
        'status',
        'note',
        'resolved_by_user_id',
        'resolved_at'
    ];
}
