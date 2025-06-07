<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MembershipTier extends Model
{
    use HasFactory;

    protected $table = 'membership_tiers';
    protected $fillable = [
        'shop', 'name', 'description', 'minimum_spend',
        'discount_value', 'discount_type', 'is_active'
    ];
}
