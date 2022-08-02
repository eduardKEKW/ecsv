<?php

namespace Webkul\Product\Models;

use Webkul\Product\Models\Like;
use Webkul\Product\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Webkul\Customer\Models\CustomerProxy;
use Webkul\Product\Contracts\ProductReview as ProductReviewContract;

class ProductReview extends Model implements ProductReviewContract
{
    protected $fillable = [
        'comment',
        'title',
        'rating',
        'status',
        'product_id',
        'customer_id',
        'name',
        'likes'
    ];
    
    protected static function booted()
    {
        static::created(function ($review) {
            $totalReviews = 0;
            $totalReviewsSum = 0;

            if($review->status !== 'pending') {
                $product = Product::where('id', $review->product_id)->first();

                $totalReviews = $product->reviews()->where('status', 'approved')->count();
                $averageRating = number_format(round($product->reviews()->where('status', 'approved')->avg('rating'), 2), 1);

                foreach ($product->product_flats as $productFlat) {
                    $productFlat->update([
                        'reviews_count' => $totalReviews,
                        'reviews_average' => $averageRating,
                    ]);
                }
            }
        });

        static::updated(function ($review) {
            $totalReviews = 0;
            $totalReviewsSum = 0;

            if($review->status !== 'pending') {
                $product = Product::where('id', $review->product_id)->first();

                $totalReviews = $product->reviews()->where('status', 'approved')->count();
                $averageRating = number_format(round($product->reviews()->where('status', 'approved')->avg('rating'), 2), 1);

                foreach ($product->product_flats as $productFlat) {
                    $productFlat->update([
                        'reviews_count' => $totalReviews,
                        'reviews_average' => $averageRating,
                    ]);
                }
            }
        });
    }

    /**
     * Get the product attribute family that owns the product.
     */
    public function customer()
    {
        return $this->belongsTo(CustomerProxy::modelClass());
    }

    /**
     * Get the product.
     */
    public function product()
    {
        return $this->belongsTo(ProductProxy::modelClass());
    }

    public function likes()
    {
        return $this->hasMany(Like::class);
    }

    /**
     * The images that belong to the review.
     */
    public function images()
    {
        return $this->hasMany(ProductReviewImageProxy::modelClass(), 'review_id');
    }
}