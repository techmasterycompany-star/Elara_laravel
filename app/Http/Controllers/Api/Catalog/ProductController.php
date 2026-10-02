<?php

namespace App\Http\Controllers\Api\Catalog;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    private const MAX_IMAGES = 5;

   
    public function index(Request $request)
    {
        $rules = [
            'search'      => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'exists:categories,id'],
            'min_price'   => ['nullable', 'numeric', 'min:0'],
            'max_price'   => ['nullable', 'numeric', 'min:0'],
            'in_stock'    => ['nullable', 'boolean'],
            'min_rating'  => ['nullable', 'numeric', 'min:0', 'max:5'],
            'sort'        => ['nullable', 'in:price_asc,price_desc,newest'],
            'per_page'    => ['nullable', 'integer', 'min:1', 'max:50'],
        ];

        
        if ($request->filled('min_price')) {
            $rules['max_price'][] = 'gte:min_price';
        }

        $validated = $request->validate($rules);

        $query = Product::with(['category', 'images'])->active();


        $search = isset($validated['search']) ? trim($validated['search']) : null;

        if ($search !== null && $search !== '') {
            $escaped = addcslashes($search, '%_\\');
            $query->where('name', 'like', "%{$escaped}%");
        } else {
            $search = null;
        }

        
        if (! empty($validated['category_id'])) {
           
        $categoryIds = Category::where('id', $validated['category_id'])
                ->orWhere('parent_id', $validated['category_id'])
                ->pluck('id');

            $query->whereIn('category_id', $categoryIds);
        }

        if (isset($validated['min_price'])) {
            $query->whereRaw('COALESCE(sale_price, price) >= ?', [$validated['min_price']]);
        }

        if (isset($validated['max_price'])) {
            $query->whereRaw('COALESCE(sale_price, price) <= ?', [$validated['max_price']]);
        }

        if ($request->filled('in_stock')) {
            $request->boolean('in_stock')
                ? $query->inStock()
                : $query->where('stock', 0);
        }

        if (isset($validated['min_rating'])) {
            $query->withAvg('reviews', 'rating')
                ->having('reviews_avg_rating', '>=', $validated['min_rating']);
        }

        
        $sort = $validated['sort'] ?? null;

        if ($sort === 'price_asc') {
            $query->orderByRaw('COALESCE(sale_price, price) asc');
        } elseif ($sort === 'price_desc') {
            $query->orderByRaw('COALESCE(sale_price, price) desc');
        } else {

            if ($search !== null) {
                $query->orderByRaw(
                    'CASE
                        WHEN LOWER(name) = LOWER(?) THEN 0
                        WHEN LOWER(name) LIKE LOWER(?) THEN 1
                        ELSE 2
                    END',
                    [$search, addcslashes($search, '%_\\') . '%']
                );
            }
            $query->latest();
        }

        $products = $query
            ->paginate($validated['per_page'] ?? 20)
            ->withQueryString();

        return response()->json($products);
    }

    public function show(Request $request, Product $product)
    {

        $user = $request->user('sanctum');

        $isAdmin = $user && $user->isAdmin();
        $isOwner = $user && $this->ownsProduct($user, $product);

        if ($product->status !== 'active' && ! $isAdmin && ! $isOwner) {
            abort(404);
        }

        $product->load(['category', 'images', 'seller']);

        return response()->json([
            'product' => $product,
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        if ($user->isSeller() && (! $user->seller || ! $user->seller->isApproved())) {
            return response()->json([
                'message' => __('Your seller account must be approved before listing products.'),
            ], 403);
        }

        $validated = $request->validate([
            'category_id' => ['required', 'exists:categories,id'],
            'name'        => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'price'       => ['required', 'numeric', 'min:0'],
            'sale_price'  => ['nullable', 'numeric', 'min:0', 'lt:price'],
            'stock'       => ['required', 'integer', 'min:0'],
            'sku'         => ['required', 'string', 'max:100', 'unique:products,sku'],
            'images'      => ['nullable', 'array', 'max:' . self::MAX_IMAGES],
            'images.*'    => ['image', 'max:2048'],
        ]);

        $status = $user->isAdmin() ? 'active' : 'pending';

        $product = DB::transaction(function () use ($request, $validated, $user, $status) {
            $product = Product::create([
                ...Arr::except($validated, ['images']),
                'slug'      => $this->generateUniqueSlug($validated['name']),
                'seller_id' => $user->isSeller() ? $user->seller->id : null,
                'status'    => $status,
            ]);

            if ($request->hasFile('images')) {
                foreach ($request->file('images') as $index => $image) {
                    $product->images()->create([
                        'path'       => $image->store('products', 'public'),
                        'sort_order' => $index,
                    ]);
                }
            }

            return $product;
        });

        return response()->json([
            'message' => __('Product created successfully.'),
            'product' => $product->load('images'),
        ], 201);
    }

    public function update(Request $request, Product $product)
    {
        $this->authorizeOwnership($request, $product);

        $validated = $request->validate([
            'category_id' => ['sometimes', 'exists:categories,id'],
            'name'        => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'price'       => [
                'sometimes', 'numeric', 'min:0',
                function ($attribute, $value, $fail) use ($request, $product) {

                if ($request->has('sale_price')) {
                        return;
                    }
                    if ($product->sale_price !== null && $value <= $product->sale_price) {
                        $fail(__('The price must be greater than the current sale price.'));
                    }
                },
            ],
            'sale_price'  => [
                'nullable', 'numeric', 'min:0',
                function ($attribute, $value, $fail) use ($request, $product) {
                    if ($value === null) {
                        return;
                    }
                    $price = $request->input('price', $product->price);
                    if ($value >= $price) {
                        $fail(__('The sale price must be less than the price.'));
                    }
                },
            ],
            'sku'         => ['sometimes', 'string', 'max:100', 'unique:products,sku,' . $product->id],
        ]);


        if (isset($validated['name']) && $validated['name'] !== $product->name) {
            $validated['slug'] = $this->generateUniqueSlug($validated['name'], $product->id);
        }

        $product->update($validated);

        return response()->json([
            'message' => __('Product updated successfully.'),
            'product' => $product->fresh(),
        ]);
    }

    public function updateStatus(Request $request, Product $product)
    {
        $validated = $request->validate([
            'status' => ['required', 'in:pending,active,hidden,rejected'],
        ]);

        $user = $request->user();

        if ($user->isSeller()) {
            $this->authorizeOwnership($request, $product);

            if (! in_array($validated['status'], ['active', 'hidden'])) {
                return response()->json([
                    'message' => __('Sellers can only toggle between active and hidden.'),
                ], 403);
            }

            if (in_array($product->status, ['pending', 'rejected'])) {
                return response()->json([
                    'message' => __('This product cannot be updated until an admin reviews it.'),
                ], 403);
            }
        }

        $product->update(['status' => $validated['status']]);

        return response()->json([
            'message' => __('Product status updated successfully.'),
            'product' => $product->fresh(),
        ]);
    }
    public function updateStock(Request $request, Product $product)
    {
        $this->authorizeOwnership($request, $product);

        $validated = $request->validate([
            'mode'     => ['required', 'in:set,adjust'],
            'quantity' => ['required', 'integer', 'min:-100000', 'max:100000'],
        ]);

        if ($validated['mode'] === 'set' && $validated['quantity'] < 0) {
            return response()->json([
                'message' => __('Stock cannot be set to a negative number.'),
            ], 422);
        }

        $updated = DB::transaction(function () use ($product, $validated) {
            $locked = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();

            $newStock = $validated['mode'] === 'set'
                ? $validated['quantity']
                : $locked->stock + $validated['quantity'];

            if ($newStock < 0) {
                return null;
            }

            $locked->stock = $newStock;
            $locked->save();

            return $locked;
        });

        if ($updated === null) {
            return response()->json([
                'message' => __('Adjustment would make stock negative.'),
            ], 422);
        }

        return response()->json([
            'message' => __('Stock updated successfully.'),
            'product' => $updated,
        ]);
    }
    public function destroy(Request $request, Product $product)
    {
        $this->authorizeOwnership($request, $product);

        $product->delete();

        return response()->json([
            'message' => __('Product deleted successfully.'),
        ]);
    }



    public function storeImage(Request $request, Product $product)
    {
        $this->authorizeOwnership($request, $product);

        $validated = $request->validate([
            'images'   => ['required', 'array', 'max:' . self::MAX_IMAGES],
            'images.*' => ['image', 'max:2048'],
        ]);

        $existingCount = $product->images()->count();

        if ($existingCount + count($validated['images']) > self::MAX_IMAGES) {
            return response()->json([
                'message' => __('A product can have at most :max images.', ['max' => self::MAX_IMAGES]),
            ], 422);
        }

        $startOrder = ($product->images()->max('sort_order') ?? -1) + 1;

        $images = collect($validated['images'])->map(function ($image, $index) use ($product, $startOrder) {
            return $product->images()->create([
                'path'       => $image->store('products', 'public'),
                'sort_order' => $startOrder + $index,
            ]);
        });

        return response()->json([
            'message' => __('Images added successfully.'),
            'images'  => $images,
        ], 201);
    }

    public function destroyImage(Request $request, Product $product, ProductImage $image)
    {
        $this->authorizeOwnership($request, $product);

        if ($image->product_id !== $product->id) {
            abort(404);
        }

        Storage::disk('public')->delete($image->path);
        $image->delete();

        return response()->json([
            'message' => __('Image deleted successfully.'),
        ]);
    }



    private function ownsProduct(User $user, Product $product): bool
    {

    return $user->isSeller()
            && $user->seller !== null
            && $product->seller_id === $user->seller->id;
    }

    private function authorizeOwnership(Request $request, Product $product): void
    {
        $user = $request->user();

        if ($user->isAdmin() || $this->ownsProduct($user, $product)) {
            return;
        }

        abort(403, __('You do not have permission to modify this product.'));
    }

    private function generateUniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $slug = Str::slug($name);
        $originalSlug = $slug;
        $counter = 1;

        while (Product::where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = "{$originalSlug}-{$counter}";
            $counter++;
        }

        return $slug;
    }
}