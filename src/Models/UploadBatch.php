<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UploadBatch extends Model {
    protected $table = 'upload_batches';

    protected $fillable = [
        'company_id',
        'uploaded_by_user_id',
        'approved_by_user_id',
        'customers_filename',
        'receivables_filename',
        'customers_hash',
        'receivables_hash',
        'status',
        'preview_customers_total',
        'preview_receivables_total',
        'preview_invalid_customers',
        'preview_invalid_receivables',
        'preview_pending_links',
        'merged_customers_count',
        'merged_receivables_count',
        'error_message'
    ];
}
