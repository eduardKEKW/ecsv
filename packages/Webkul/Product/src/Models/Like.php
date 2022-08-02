<?php

namespace Webkul\Product\Models;

use Exception;
use Illuminate\Database\Eloquent\Model;

class Like extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var $fillable
     */
    protected $fillable = [
        'customer_id',
        'product_review_id',
        'created_at',
        'updated_at'
    ];

    protected $table = 'comment_likes';
   
}
