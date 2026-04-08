<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Receivable extends Model {
    protected $table = 'receivables';
    
    protected $fillable = [
        'company_id',
        'customer_id',
        'upload_batch_id',
        'receivable_number',
        'nosso_numero',
        'charge_type',
        'issue_date',
        'due_date',
        'amount_total',
        'balance_amount',
        'balance_without_interest',
        'status',
        'snapshot_customer_name',
        'snapshot_customer_document',
        'snapshot_email_billing',
        'is_active',
        'last_standard_message_at'
    ];

    public function customer() {
        return $this->belongsTo(Customer::class);
    }
}
