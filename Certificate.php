<?php

namespace App\Models;


use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Certificate extends Model
{
    use HasFactory;

    protected $table = 'certificates';

    protected $fillable = [
        'domain_name',
        'certificate_id',
        'user_id',
        'issued_at',
        'renewal_at',
        'status',
        'cname',
        'cf_domain',
        'cf_id',
        'stack_id',
        'domain_status'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function getIssuedAtAttribute($value)
    {
        return $value ? Carbon::parse($value)->format('m/d/y h:i:s A') : '-';
    }
    // Accessor for renewal_at date formatting
    public function getRenewalAtAttribute($value)
    {
        return $value ? Carbon::parse($value)->format('m/d/y h:i:s A') : '-';
    }
}
