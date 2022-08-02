<?php

namespace App\Observers;

use App\ProductReview;

class ReviewObserver
{
    /**
     * Handle the ProductReview "created" event.
     *
     * @param  \App\ProductReview  $productReview
     * @return void
     */
    public function created(ProductReview $productReview)
    {
        //
    }

    /**
     * Handle the ProductReview "updated" event.
     *
     * @param  \App\ProductReview  $productReview
     * @return void
     */
    public function updated(ProductReview $productReview)
    {
        //
    }

    /**
     * Handle the ProductReview "deleted" event.
     *
     * @param  \App\ProductReview  $productReview
     * @return void
     */
    public function deleted(ProductReview $productReview)
    {
        //
    }

    /**
     * Handle the ProductReview "restored" event.
     *
     * @param  \App\ProductReview  $productReview
     * @return void
     */
    public function restored(ProductReview $productReview)
    {
        //
    }

    /**
     * Handle the ProductReview "force deleted" event.
     *
     * @param  \App\ProductReview  $productReview
     * @return void
     */
    public function forceDeleted(ProductReview $productReview)
    {
        //
    }
}
