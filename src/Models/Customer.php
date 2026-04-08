<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model {
    protected $table = 'customers';
    
    protected $fillable = [
        'company_id',
        'external_code',
        'full_name',
        'normalized_name',
        'document_number',
        'email_billing',
        'email_financial',
        'phone',
        'other_contacts',
        'is_active'
    ];

    public function receivables() {
        return $this->hasMany(Receivable::class);
    }
}
