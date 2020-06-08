<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\ServiceException;
use App\Model\Category;
use App\Model\Product;
use App\Model\User;
use App\Request\FavorRequest;
use App\Request\ProductRequest;
use App\Services\ProductService;
use App\Utils\ElasticSearch;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Paginator\LengthAwarePaginator;

class ProductController extends BaseController
{
    /**
     * @Inject()
     * @var ProductService
     */
    private $productService;

    /**
     * @Inject()
     * @var ElasticSearch
     */
    private $es;

    public function index(ProductRequest $request)
    {
        $search = $request->input('search');
        $order = $request->input('order');
        $field = $request->input('field');
        $builder = Product::query();

        if ($search)
        {
            $like = "%$search%";
            $builder->where(function ($query) use ($like)
            {
                $query->where('title', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    ->orWhereHas('skus', function ($query) use ($like)
                    {
                        $query->where('title', 'like', $like)
                            ->orWhere('description', 'like', $like);
                    });
            });
        }
        $builder->with('skus');


        if ($order && $field)
        {
            $builder->orderBy($field, $order);
        }

        $data = $this->getPaginateData($builder->paginate());
        return $this->response->json(responseSuccess(200, '', $data));
    }

    public function show()
    {
        $product = Product::with('skus')->where('id', $this->request->route('id'))->first();
        if (!$product)
        {
            throw new ServiceException(422, '商品不存在');
        }
        if (!$product->on_sale)
        {
            throw new ServiceException(422, '商品没上架');
        }
        return $this->response->json(responseSuccess(200, '', $product));
    }

    public function productList()
    {
        $page = $this->request->input('page', 1);
        $perPage = $this->getPageSize();

        // 构建查询
        $params = [
            'index' => 'products',
            'type' => '_doc',
            'body' => [
                'from' => ($page - 1) * $perPage, // 通过当前页数与每页数量计算偏移值
                'size' => $perPage,
                'query' => [
                    'bool' => [
                        'filter' => [
                            ['term' => ['on_sale' => true]],
                        ],
                    ],
                ],
            ],
        ];

        // 是否有提交 order 参数，如果有就赋值给 $order 变量
        // order 参数用来控制商品的排序规则
        if ($order = $this->request->input('order', ''))
        {
            // 是否是以 _asc 或者 _desc 结尾
            if (preg_match('/^(.+)_(asc|desc)$/', $order, $m))
            {
                // 如果字符串的开头是这 3 个字符串之一，说明是一个合法的排序值
                if (in_array($m[1], ['price', 'sold_count', 'rating']))
                {
                    // 根据传入的排序值来构造排序参数
                    $params['body']['sort'] = [[$m[1] => $m[2]]];
                }
            }
        }

        if ($this->request->input('category_id') && $category = Category::find($this->request->input('category_id')))
        {
            if ($category->is_directory)
            {
                // 如果是一个父类目，则使用 category_path 来筛选
                $params['body']['query']['bool']['filter'][] = [
                    'prefix' => ['category_path' => $category->path . $category->id . '-'],
                ];
            }
            else
            {
                // 否则直接通过 category_id 筛选
                $params['body']['query']['bool']['filter'][] = ['term' => ['category_id' => $category->id]];
            }
        }

        //关键词搜索
        if ($search = $this->request->input('search', ''))
        {
            // 将搜索词根据空格拆分成数组，并过滤掉空项
            $keywords = array_filter(explode(' ', $search));

            $params['body']['query']['bool']['must'] = [];
            // 遍历搜索词数组，分别添加到 must 查询中
            foreach ($keywords as $keyword)
            {
                $params['body']['query']['bool']['must'][] = [
                    'multi_match' => [
                        'query' => $keyword,
                        'fields' => [
                            'title^2',
                            'long_title^2',
                            'category^2',
                            'description',
                            'skus.title^2',
                            'skus.description',
                            'properties.value',
                        ],
                    ],
                ];
            }
        }

        $result = $this->es->es_client->search($params);

        // 通过 collect 函数将返回结果转为集合，并通过集合的 pluck 方法取到返回的商品 ID 数组
        $productIds = collect($result['hits']['hits'])->pluck('_id')->all();
        // 通过 whereIn 方法从数据库中读取商品数据
        $products = Product::query()
            ->with('skus')
            ->with('properties')
            ->with('category')
            ->whereIn('id', $productIds)
            // orderByRaw 可以让我们用原生的 SQL 来给查询结果排序
            ->orderByRaw(sprintf("FIND_IN_SET(id, '%s')", join(',', $productIds)))
            ->get();

        $data = $this->getPaginateData(new LengthAwarePaginator($products, (int)$result['hits']['total'], $perPage, $page));

        return $this->response->json(responseSuccess(200, '', $data));
    }

    public function store(ProductRequest $request)
    {
        $this->productService->createProduct($request->validated());
        return $this->response->json(responseSuccess(201));
    }

    public function update(ProductRequest $request)
    {
        $product = Product::getFirstById($request->route('id'));
        if (!$product)
        {
            throw new ServiceException(403, '商品不存在');
        }
        $product->update($request->validated());
        $properties = $request->validated()['properties'] ?? null;
        if ($properties)
        {
            $product->properties()->delete();
            foreach ($properties as $property)
            {
                $productProperty = $product->properties()->make($property);
                $productProperty->save();
            }
        }
        return $this->response->json(responseSuccess(200, '更新成功'));
    }

    public function delete(ProductRequest $request)
    {
        $product = Product::getFirstById($request->route('id'));
        if (!$product)
        {
            throw new ServiceException(403, '商品不存在');
        }
        $product->delete();
        return $this->response->json(responseSuccess(201, '删除成功'));
    }

    public function favor(FavorRequest $request)
    {
        $productId = $request->route('id');
        $product = Product::getFirstById($productId);
        if (!$product)
        {
            throw new ServiceException(403, '商品不存在');
        }

        /** @var $user User */
        $user = $request->getAttribute('user');
        if ($user->favoriteProducts()->find($productId))
        {
            throw new ServiceException(403, '已经收藏过本商品');
        }

        $user->favoriteProducts()->attach($productId);
        return $this->response->json(responseSuccess(201, '收藏成功'));
    }

    public function detach(FavorRequest $request)
    {
        $productId = $request->route('id');
        $product = Product::getFirstById($productId);
        if (!$product)
        {
            throw new ServiceException(403, '商品不存在');
        }

        /** @var $user User */
        $user = $request->getAttribute('user');
        if (!$user->favoriteProducts()->find($productId))
        {
            throw new ServiceException(403, '没有收藏过本商品');
        }

        $user->favoriteProducts()->detach($productId);
        return $this->response->json(responseSuccess(201, '取消成功'));
    }

    public function favorites()
    {
        /** @var $user User */
        $user = $this->request->getAttribute('user');
        $data = $this->getPaginateData($user->favoriteProducts()->paginate());
        return $this->response->json(responseSuccess(200, '', $data));
    }
}
