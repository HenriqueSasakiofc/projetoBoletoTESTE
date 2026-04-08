<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StagingReceivable extends Model {
    protected $table = 'staging_receivables';
    
    protected $fillable = [
        'company_id', 'upload_batch_id', 'row_number', 'customer_external_code', 'customer_name',
        'normalized_customer_name', 'customer_document_number', 'receivable_number', 'nosso_numero',
        'charge_type', 'issue_date', 'due_date', 'amount_total', 'balance_amount', 'balance_without_interest',
        'status_raw', 'email_billing', 'raw_payload', 'validation_status', 'validation_errors'
    ];
}
