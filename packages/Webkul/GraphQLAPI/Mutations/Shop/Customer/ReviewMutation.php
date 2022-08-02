<?php

namespace Webkul\GraphQLAPI\Mutations\Shop\Customer;

use Exception;
use Webkul\Product\Models\Like;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Webkul\Product\Models\ProductReview;
use Webkul\Customer\Http\Controllers\Controller;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Webkul\Product\Repositories\ProductReviewRepository;
use Webkul\GraphQLAPI\Validators\Customer\CustomException;

class ReviewMutation extends Controller
{
    /**
     * Contains current guard
     *
     * @var array
     */
    protected $guard;

    /**
     * ProductReviewRepository object
     *
     * @var \Webkul\Product\Repositories\ProductReviewRepository
     */
    protected $productReviewRepository;

    /**
     * Create a new controller instance.
     *
     * @param  \Webkul\Product\Repositories\ProductReviewRepository  $productReviewRepository
     * @return void
     */
    public function __construct(
        ProductReviewRepository $productReviewRepository
    )
    {
        $this->guard = 'api';

        auth()->setDefaultDriver($this->guard);
        
        $this->middleware('auth:' . $this->guard);
        
        $this->productReviewRepository = $productReviewRepository;
    }

    public function like ($rootValue, array $args , GraphQLContext $context)
    {
        if (! bagisto_graphql()->validateAPIUser($this->guard)) {
            throw new CustomException(
                trans('bagisto_graphql::app.admin.response.error-invalid-parameter'),
                'Invalid request header parameter.'
            );
        }

        if (! bagisto_graphql()->guard($this->guard)->check() ) {
            throw new CustomException(
                trans('bagisto_graphql::app.shop.customer.no-login-customer'),
                'Customer Not Login.'
            );
        }

        try {
            $user = Auth::user();
        
            $review = ProductReview::find($args['commentId']);
            
            $like = Like::where(['customer_id' => $user->id, 'product_review_id' => $review->id])->first();

            if(! is_null($like))
            {   
                $like = $review->likes()->delete([
                    'customer_id' => $user->id,
                ]);

                $review->likes = max($review->likes - 1, 0);
            } else 
            {            
                $like = $review->likes()->create([
                    'customer_id' => $user->id,
                ]);

                $review->likes = $review->likes + 1;
            }

            $review->save();

            return [
                'status' => true,
                'message' => 'Review Liked',
                'review' => $review
            ];
        } catch (\Throwable $th) {
            throw new \Exception($th, 1);
            
            return [
                'status' => false,
                'message' => 'Something went wrong',
                'review' => null
            ];
        }
        
    }

    /**
     * Returns loggedin customer's reviews data.
     *
     * @return \Illuminate\Http\Response
     */
    public function reviews($rootValue, array $args , GraphQLContext $context)
    {
        $params = isset($args['input']) ? $args['input'] : (isset($args['id']) ? $args : []);

        try {
            $params['customer_id'] = null;
            if ( bagisto_graphql()->guard($this->guard)->check() ) {
                $params['customer_id'] = bagisto_graphql()->guard($this->guard)->user()->id;
            }

            $currentPage = isset($params['page']) ? $params['page'] : 1;
            
            Paginator::currentPageResolver(function () use ($currentPage) {
                return $currentPage;
            });

            $reviews = app(ProductReviewRepository::class)->scopeQuery(function ($query) use ($params) {
                $channel = isset($params['channel']) ?: (core()->getCurrentChannelCode() ?: core()->getDefaultChannelCode());

                $locale = isset($params['locale']) ?: app()->getLocale();
                    
                $qb = $query->distinct()
                    ->addSelect('product_reviews.*')
                    ->addSelect('product_flat.name as product_name')
                    ->leftJoin('product_flat', 'product_reviews.product_id', '=', 'product_flat.product_id')
                    ->where('product_flat.channel', $channel)
                    ->where('product_flat.locale', $locale)
                    ->where('product_reviews.customer_id', $params['customer_id']);

                if ( isset($params['id']) && $params['id']) {
                    $qb->where('product_reviews.id', $params['id']);
                }

                if ( isset($params['title']) && $params['title']) {
                    $qb->where('product_reviews.title', 'like', '%' . urldecode($params['title']) . '%');
                }
                
                if ( isset($params['rating']) && $params['rating'] && is_numeric($params['rating'])) {
                    $qb->where('product_reviews.rating', $params['rating']);
                    
                }

                if ( isset($params['customer_name']) && $params['customer_name']) {
                    $qb->where('product_reviews.name', 'like', '%' . urldecode($params['customer_name']) . '%');
                }

                if ( isset($params['product_name']) && $params['product_name']) {
                    $qb->where('product_flat.name', 'like', '%' . urldecode($params['product_name']) . '%');
                }
                
                if ( isset($params['product_id']) && $params['product_id']) {
                    $qb->where('product_reviews.product_id', $params['product_id']);
                }

                if ( isset($params['status']) && $params['status']) {
                    $qb->where('product_reviews.status', 'like', '%' . urldecode($params['status']) . '%');
                }

                return $qb;
            });

            if ( isset($args['id'])) {
                $reviews = $reviews->first();
            } else {
                $reviews = $reviews->paginate( isset($params['limit']) ? $params['limit'] : 10);
            }
            
            if ( ($reviews && isset($reviews->first()->id)) || isset($reviews->id) ) {
                return $reviews;
            } else {
                throw new Exception(trans('bagisto_graphql::app.shop.response.not-found', ['name'   => 'Review']));
            }
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function store($rootValue, array $args, GraphQLContext $context)
    {
        if (! isset($args['input']) || (isset($args['input']) && !$args['input'])) {
            throw new Exception(trans('bagisto_graphql::app.admin.response.error-invalid-parameter'));
        }

        $data = $args['input'];
        
        $validator = \Validator::make($data, [
            'comment'       => 'required',
            'rating'        => 'required|numeric|min:1|max:5',
            'title'         => 'required',
            'product_id'    => 'required',
        ]);
        
        if ($validator->fails()) {
            throw new Exception($validator->messages());
        }

        try {
            if (bagisto_graphql()->guard($this->guard)->check()) {
                $customer = bagisto_graphql()->guard($this->guard)->user();
                $data['customer_id']    = $customer->id;
                $data['name']    = $customer->first_name . ' ' . $customer->last_name;;
            }
    
            $data['status'] = 'pending';
    
            $review = $this->productReviewRepository->create($data);

            return [
                'success'   => trans('shop::app.response.submit-success', ['name' => 'Product Review']),
                'review'    => $review
            ];
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function delete($rootValue, array $args, GraphQLContext $context)
    {
        if (! isset($args['id']) || (isset($args['id']) && !$args['id'])) {
            throw new CustomException(
                trans('bagisto_graphql::app.admin.response.error-invalid-parameter'),
                'Invalid request parameter.'
            );
        }

        if (! bagisto_graphql()->validateAPIUser($this->guard)) {
            throw new CustomException(
                trans('bagisto_graphql::app.admin.response.error-invalid-parameter'),
                'Invalid request header parameter.'
            );
        }

        if (! bagisto_graphql()->guard($this->guard)->check() ) {
            throw new CustomException(
                trans('bagisto_graphql::app.shop.customer.no-login-customer'),
                'Customer Not Login.'
            );
        }

        $id = $args['id'];
        
        try {
            $customer = bagisto_graphql()->guard($this->guard)->user();

            $customerReview = $this->productReviewRepository->findOrFail($id);
            
            if ( isset($customerReview->customer_id) && $customerReview->customer_id !== $customer->id ) {
                throw new CustomException(
                    trans('bagisto_graphql::app.shop.customer.not-authorized'),
                    'You are not authorized to perform this action.'
                );
            }
        
            Event::dispatch('customer.review.delete.before', $id);

            $this->productReviewRepository->delete($id);

            Event::dispatch('customer.review.delete.after', $id);
            
            return [
                'status'    => (isset($customerReview->id)) ? true : false,
                'reviews'   => $customer->all_reviews,
                'message'   => ($customerReview->id) ? trans('admin::app.response.delete-success', ['name' => 'Customer\'s Review']) : trans('bagisto_graphql::app.shop.response.not-found', ['name'   => 'Review'])
            ];
        } catch (Exception $e) {
            throw new CustomException(
                $e->getMessage(),
                'Review remove Failed.'
            );
        }
    }

    public function commentLikes($rootValue, array $args , GraphQLContext $context)
    {
        $user = Auth::user();
        
        $likes = Like::join('product_reviews', function ($join) use ($user, $args) {
                $join->on('comment_likes.product_review_id', '=', 'product_reviews.id')
                    ->where('product_reviews.product_id', '=', $args['input']['productId']);
            })
            ->where([
                'comment_likes.customer_id' => $user->id,
            ])->get();
            
        // throw new \Exception(json_encode($likes), 1);
            
        $likes =  $likes->map(function ($like) {
            return [
                'id'            => $like->id,
                'reviewId'      => $like->product_review_id
            ];
        });

        // $productComments = $likes->map(function ($like) {
        //     return [
        //         'id'            => $like->id,
        //         'reviewId'      => $like->product_review_id
        //     ];
        // });
        // throw new \Exception(json_encode($likes), 1);
        return [
            'commentLikes' => $likes,
            // 'productComments' => $productComments
        ];
    }
}