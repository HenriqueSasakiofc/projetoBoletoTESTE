<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StagingCustomer extends Model {
    protected $table = 'staging_customers';
    
    protected $fillable = [
        'company_id', 'upload_batch_id', 'row_number', 'external_code', 'full_name',
        'normalized_name', 'document_number', 'email_billing', 'email_financial',
        'phone', 'other_contacts', 'raw_payload', 'validation_status', 'validation_errors'
    ];
}
