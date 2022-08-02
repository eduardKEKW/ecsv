<?php

namespace Webkul\GraphQLAPI\Queries\Shop\Product;

use Webkul\GraphQLAPI\Queries\BaseFilter;
use Webkul\Product\Repositories\ProductFlatRepository;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Product\Models\ProductAttributeValueProxy;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Category\Repositories\CategoryRepository;
use Illuminate\Support\Facades\DB;

class ProductListingQuery extends BaseFilter
{
    /**
     * ProductFlatRepository object
     *
     * @var \Webkul\Product\Repositories\ProductFlatRepository
     */
    protected $productFlatRepository;

    /**
     * ProductRepository object
     *
     * @var \Webkul\Product\Repositories\ProductRepository
     */
    protected $productRepository;

    /**
     * CategoryRepository object
     *
     * @var \Webkul\Category\Repositories\CategoryRepository
     */
    protected $categoryRepository;

    /**
     * AttributeRepository object
     *
     * @var \Webkul\Attribute\Repositories\AttributeRepository
     */
    protected $attributeRepository;

    /**
     * Create a new controller instance.
     *
     * @param  \Webkul\Product\Repositories\ProductFlatRepository  $productFlatRepository
     * @param  \Webkul\Product\Repositories\ProductRepository  $productRepository
     * @param  \Webkul\Attribute\Repositories\AttributeRepository $attributeRepository
     * @return void
     */
    public function __construct(
        ProductFlatRepository $productFlatRepository,
        ProductRepository $productRepository,
        CategoryRepository $categoryRepository,
        AttributeRepository $attributeRepository
    ) {
        $this->productFlatRepository = $productFlatRepository;

        $this->productRepository = $productRepository;

        $this->categoryRepository = $categoryRepository;

        $this->attributeRepository = $attributeRepository;
    }
    
    /**
     * filter the data .
     *
     * @param  object  $query
     * @param  array $input
     * @return \Illuminate\Http\Response
     */
    public function getAll($query, $input)
    {
        $params = $input;

        $channel = core()->getRequestedChannelCode();

        $locale = core()->getRequestedLocaleCode();
        
        $qb = $query->distinct()
            ->select('products.*')
            ->leftJoin('product_flat as pf', 'pf.product_id', '=', 'products.id')
            ->leftJoin('product_categories', 'product_categories.product_id', '=', 'pf.product_id')
            ->leftJoin('product_attribute_values', 'product_attribute_values.product_id', '=', 'pf.product_id')
            ->whereIn('products.type', ['simple', 'virtual'])
            ->where('pf.channel', $channel)
            ->where('pf.locale', $locale);

        if (isset($params['categoryId']) && $params['categoryId']) {
            $qb->where('product_categories.category_id', $params['categoryId']);
        }

        if (is_null(request()->input('status'))) {
            $qb->where('pf.status', 1);
        }
        
        if(isset($params['reviews']) && $params['reviews']) {
            $qb->whereRaw('cast(pf.reviews_average as int) = ' . $params['reviews']);
        }

        if (isset($params['search']) && $params['search']) {
            $qb->where('pf.name', 'like', '%' . urldecode($params['search']) . '%');
        }

        /* added for api as per the documentation */
        if (isset($params['name']) && $params['name']) {
            $qb->where('pf.name', 'like', '%' . urldecode($params['name']) . '%');
        }

        /* added for api as per the documentation */
        if (isset($params['url_key']) && $params['url_key']) {
            $qb->where('pf.url_key', 'like', '%' . urldecode($params['url_key']) . '%');
        }

        # sort direction
        $orderDirection = 'asc';
        if (isset($params['order']) && in_array($params['order'], ['desc', 'asc'])) {
            $orderDirection = $params['order'];
        } else {
            $sortOptions = $this->getDefaultSortByOption();
            $orderDirection = ! empty($sortOptions) ? $sortOptions[1] : 'asc';
        }

        if (isset($params['sort'])) {
            $this->checkSortAttributeAndGenerateQuery($qb, $params['sort'], $orderDirection);
        } else {
            $sortOptions = $this->getDefaultSortByOption();
            if (! empty($sortOptions)) {
                $this->checkSortAttributeAndGenerateQuery($qb, $sortOptions[0], $orderDirection);
            }
        }

        if (isset($params['price']) && $params['price']) {
            $priceFilter = $params['price'];
            $priceRange = [$priceFilter["min"], $priceFilter["max"]];
            // throw new \Exception(json_encode( $priceRange ), 1);
            
            if (count($priceRange) > 0) {

                $customerGroupId = null;

                $customerGuestGroup = app('Webkul\Customer\Repositories\CustomerGroupRepository')->getCustomerGuestGroup();

                if ($customerGuestGroup) {
                    $customerGroupId = $customerGuestGroup->id;
                }

                $qb
                    ->leftJoin('catalog_rule_product_prices', 'catalog_rule_product_prices.product_id', '=', 'pf.product_id')
                    ->leftJoin('product_customer_group_prices', 'product_customer_group_prices.product_id', '=', 'pf.product_id')
                    ->where(function ($qb) use ($priceRange, $customerGroupId) {
                        $qb->where(function ($qb) use ($priceRange){
                            $qb
                                ->where('pf.min_price', '>=',  core()->convertToBasePrice($priceRange[0]))
                                ->where('pf.min_price', '<=',  core()->convertToBasePrice(end($priceRange)));
                        })
                        ->orWhere(function ($qb) use ($priceRange) {    
                            $qb
                                ->where('catalog_rule_product_prices.price', '>=',  core()->convertToBasePrice($priceRange[0]))
                                ->where('catalog_rule_product_prices.price', '<=',  core()->convertToBasePrice(end($priceRange)));
                        })
                        ->orWhere(function ($qb) use ($priceRange, $customerGroupId) {
                            $qb
                                ->where('product_customer_group_prices.value', '>=',  core()->convertToBasePrice($priceRange[0]))
                                ->where('product_customer_group_prices.value', '<=',  core()->convertToBasePrice(end($priceRange)))
                                ->where('product_customer_group_prices.customer_group_id', '=', $customerGroupId);
                        });
                    });
            }
        }

        $attributeFilterParams = array_reduce($params['attributes'] ?? [], function ($result, $item) {
            $result[$item['code']] = $item['values'];
            return $result;
        }, []);
        
        if ( isset($params['price'])) {
            unset($attributeFilterParams['price']);
        };

        $attributeFilters = $this->attributeRepository
            ->getProductDefaultAttributes(array_keys($attributeFilterParams));
// throw new \Exception(json_encode($attributeFilters ), 1);

        if (count($attributeFilters) > 0) {
            $qb->where(function ($filterQuery) use ($attributeFilters, $attributeFilterParams) {

                foreach ($attributeFilters as $attribute) {
                    $filterQuery->orWhere(function ($attributeQuery) use ($attribute, $attributeFilterParams, $attributeFilters) {

                        $column = DB::getTablePrefix() . 'product_attribute_values.' . ProductAttributeValueProxy::modelClass()::$attributeTypeFields[$attribute->type];

                        $filterInputValues = $attributeFilterParams[$attribute->code];
                        // throw new \Exception(json_encode($filterInputValues), 1);
                        # define the attribute we are filtering
                        $attributeQuery = $attributeQuery->where('product_attribute_values.attribute_id', $attribute->id);

                        # apply the filter values to the correct column for this type of attribute.
                        if ($attribute->type != 'price') {

                            $attributeQuery->where(function ($attributeValueQuery) use ($column, $filterInputValues) {
                                foreach ($filterInputValues as $filterValue) {
                                    if (! is_numeric($filterValue)) {
                                        continue;
                                    }
                                    $attributeValueQuery->orWhereRaw("find_in_set(?, {$column})", [$filterValue]);
                                }
                            });

                        } else {
                            $attributeQuery->where($column, '>=', core()->convertToBasePrice(current($filterInputValues)))
                                ->where($column, '<=', core()->convertToBasePrice(end($filterInputValues)));
                        }
                    });
                }

            });

            # this is key! if a product has been filtered down to the same number of attributes that we filtered on,
            # we know that it has matched all of the requested filters.
            // $qb->groupBy('variants.id');
            $qb->havingRaw('COUNT(*) = ' . count($attributeFilters));
        }

        // throw new \Exception(json_encode(($qb->groupBy('pf.id')->get()[0])), 1);
        
        return $qb->groupBy('pf.id');
    }

    public function getSuggestions($query, $input) 
    {
        $params = $input;
            // throw new \Exception(json_encode($params), 1);
            
        $qb = $query->distinct()
            ->selectRaw('products.*, pf.id as pfid, pf.name, pf.product_id, pf.locale')
            ->leftJoin('product_flat as pf', function ($join) {
                $join->on('pf.product_id', '=', 'products.id')->where('pf.locale', 'en');
            })
            ->whereIn('products.type', ['simple'])
            ->limit(5);

        if (isset($params['term']) && $params['term']) {
            $qb->where('pf.name', 'like', '%' . urldecode($params['term']) . '%');
        }

        // if (isset($params['limit']) && $params['limit']) {
        //     $qb->limit(urldecode($params['limit']));
        // }
        
        return $qb->groupBy('pf.id');
    }

    /**
     * Get default sort by option
     *
     * @return array
     */
    private function getDefaultSortByOption()
    {
        $value = core()->getConfigData('catalog.products.storefront.sort_by');

        $config = $value ? $value : 'name-desc';

        return explode('-', $config);
    }

    /**
     * Check sort attribute and generate query
     *
     * @param object $query
     * @param string $sort
     * @param string $direction
     *
     * @return object
     */
    private function checkSortAttributeAndGenerateQuery($query, $sort = "Popular")
    {
        $sortOptions = [
            'Popular' => function () use ($query) {
                return $query->orderBy('pf.new', 'DESC');
            },
            'Newest' => function () use ($query) {
                return $query->orderBy('pf.created_at', 'DESC');
            },
            'Most Expensive' => function () use ($query) {
                return $query->orderBy('pf.min_price', 'DESC');
            },
            'List Expensive' => function () use ($query) {
                return $query->orderBy('pf.min_price', 'ASC');
            },
            'Reviews Count' => function () use ($query) {
                return $query->orderBy('pf.created_at', 'ASC');
            },
            'Discount' => function () use ($query) {
                return $query->orderBy('pf.special_price', 'DESC');
            },
        ];

        if(array_key_exists($sort, $sortOptions)) {
            $sortOptions[$sort]();
        }

        return $query;
    }
}
