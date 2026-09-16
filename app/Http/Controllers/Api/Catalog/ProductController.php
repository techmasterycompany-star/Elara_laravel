<?php

namespace App\Http\Controllers\Api\Catalog;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    public function index()
    {
        $products = Product::with(['category', 'images'])
            ->active()
            ->latest()
            ->paginate(20);

        return response()->json($products);
    }

    // ---- #15 Search ----
    public function search(Request $request)
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:255'],
        ]);

        $q = $validated['q'];

        $products = Product::with(['category', 'images'])
            ->active()
            ->where('name', 'like', "%{$q}%")
            ->orderByRaw(
                'CASE
                    WHEN LOWER(name) = LOWER(?) THEN 0
                    WHEN LOWER(name) LIKE LOWER(?) THEN 1
                    ELSE 2
                END',
                [$q, "{$q}%"]
            )
            ->latest()
            ->paginate(20);

        return response()->json($products);
    }

    // ---- #16 Filter ----
    public function filter(Request $request)
    {
        $validated = $request->validate([
            'category_id' => ['nullable', 'exists:categories,id'],
            'min_price'   => ['nullable', 'numeric', 'min:0'],
            'max_price'   => ['nullable', 'numeric', 'min:0', 'gte:min_price'],
            'min_rating'  => ['nullable', 'numeric', 'min:0', 'max:5'],
            'sort'        => ['nullable', 'in:price_asc,price_desc,newest'],
        ]);

        $query = Product::with(['category', 'images'])->active();

        if (! empty($validated['category_id'])) {
            $query->where('category_id', $validated['category_id']);
        }

        if (isset($validated['min_price'])) {
            $query->where('price', '>=', $validated['min_price']);
        }

        if (isset($validated['max_price'])) {
            $query->where('price', '<=', $validated['max_price']);
        }

        if ($request->has('in_stock')) {
            $request->boolean('in_stock')
                ? $query->inStock()
                : $query->where('stock', 0);
        }

        if (isset($validated['min_rating'])) {
            $query->withAvg('reviews', 'rating')
                ->having('reviews_avg_rating', '>=', $validated['min_rating']);
        }

        match ($validated['sort'] ?? 'newest') {
            'price_asc'  => $query->orderBy('price', 'asc'),
            'price_desc' => $query->orderBy('price', 'desc'),
            default      => $query->latest(),
        };

        $products = $query->paginate(20);

        return response()->json($products);
    }

    public function show(Request $request, Product $product)
    {
        $user = $request->user();

        $isAdmin = $user && $user->isAdmin();
        $isOwner = $user && $user->isSeller() && $product->seller_id === $user->seller?->id;

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
        $validated = $request->validate([
            'category_id'  => ['required', 'exists:categories,id'],
            'name'         => ['required', 'string', 'max:255'],
            'description'  => ['nullable', 'string'],
            'price'        => ['required', 'numeric', 'min:0'],
            'sale_price'   => ['nullable', 'numeric', 'min:0', 'lt:price'],
            'stock'        => ['required', 'integer', 'min:0'],
            'sku'          => ['required', 'string', 'max:100', 'unique:products,sku'],
            'images'       => ['nullable', 'array', 'max:5'],
            'images.*'     => ['image', 'max:2048'],
        ]);

        $user = $request->user();

        $status = $user->isAdmin() ? 'active' : 'pending';

        if ($user->isSeller()) {
            if (! $user->seller || ! $user->seller->isApproved()) {
                return response()->json([
                    'message' => 'Your seller account must be approved before listing products.',
                ], 403);
            }
        }

        $product = DB::transaction(function () use ($request, $validated, $user, $status) {
            $product = Product::create([
                ...$validated,
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
            'message' => 'Product created successfully.',
            'product' => $product->load('images'),
        ], 201);
    }

    public function update(Request $request, Product $product)
    {
        $this->authorizeOwnership($request, $product);

        $validated = $request->validate([
            'category_id'  => ['sometimes', 'exists:categories,id'],
            'name'         => ['sometimes', 'string', 'max:255'],
            'description'  => ['nullable', 'string'],
            'price'        => ['sometimes', 'numeric', 'min:0'],
            'sale_price'   => [
                'nullable', 'numeric', 'min:0',
                function ($attribute, $value, $fail) use ($request, $product) {
                    if ($value === null) {
                        return;
                    }
                    $price = $request->input('price', $product->price);
                    if ($value >= $price) {
                        $fail('The sale price must be less than the price.');
                    }
                },
            ],
            'sku'          => ['sometimes', 'string', 'max:100', 'unique:products,sku,' . $product->id],
        ]);

        if (isset($validated['name'])) {
            $validated['slug'] = $this->generateUniqueSlug($validated['name'], $product->id);
        }

        $product->update($validated);

        return response()->json([
            'message' => 'Product updated successfully.',
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
                    'message' => 'Sellers can only toggle between active and hidden.',
                ], 403);
            }

            if (in_array($product->status, ['pending', 'rejected'])) {
                return response()->json([
                    'message' => 'This product cannot be updated until an admin reviews it.',
                ], 403);
            }
        }

        $product->update(['status' => $validated['status']]);

        return response()->json([
            'message' => 'Product status updated successfully.',
            'product' => $product->fresh(),
        ]);
    }

    public function destroy(Request $request, Product $product)
    {
        $this->authorizeOwnership($request, $product);

        $product->delete();

        return response()->json([
            'message' => 'Product deleted successfully.',
        ]);
    }

    private function authorizeOwnership(Request $request, Product $product): void
    {
        $user = $request->user();

        if ($user->isAdmin()) {
            return;
        }

        if ($user->isSeller() && $product->seller_id === $user->seller?->id) {
            return;
        }

        abort(403, 'You do not have permission to modify this product.');
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