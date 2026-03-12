<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FranchiseeFeedback extends Model
{
    protected $table = 'franchisee_feedback';

    protected $guarded = ['id'];

    public function franchisee()
    {
        return $this->belongsTo(Franchisee::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
