<?php

namespace App\Http\Controllers\Api\Catalog;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CategoryController extends Controller
{

    public function index()
    {
        $categories = Category::whereNull('parent_id')
            ->with('children')
            ->get();

        return response()->json([
            'categories' => $categories,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'      => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable', 'exists:categories,id'],
            'image'     => ['nullable', 'image', 'max:2048'],
        ]);

       
        if (! empty($validated['parent_id'])) {
            $parent = Category::find($validated['parent_id']);

            if ($parent->parent_id !== null) {
                return response()->json([
                    'message' => 'Cannot create a sub-category under another sub-category. Only 2 levels are allowed.',
                ], 422);
            }
        }

        $slug = Str::slug($validated['name']);
        $originalSlug = $slug;
        $counter = 1;

        while (Category::where('slug', $slug)->exists()) {
            $slug = "{$originalSlug}-{$counter}";
            $counter++;
        }

        $category = Category::create([
            ...$validated,
            'slug' => $slug,
        ]);

        if ($request->hasFile('image')) {
            $category->update([
                'image' => $request->file('image')->store('categories', 'public'),
            ]);
        }

        return response()->json([
            'message'  => 'Category created successfully.',
            'category' => $category,
        ], 201);
    }

    
    public function update(Request $request, Category $category)
    {
        $validated = $request->validate([
            'name'      => ['sometimes', 'string', 'max:255'],
            'parent_id' => ['nullable', 'exists:categories,id'],
            'image'     => ['nullable', 'image', 'max:2048'],
        ]);

        if (array_key_exists('parent_id', $validated) && $validated['parent_id'] !== null) {
            if ((int) $validated['parent_id'] === $category->id) {
                return response()->json([
                    'message' => 'A category cannot be its own parent.',
                ], 422);
            }

            $newParent = Category::find($validated['parent_id']);

            if ($newParent->parent_id !== null) {
                return response()->json([
                    'message' => 'Cannot move under a sub-category. Only 2 levels are allowed.',
                ], 422);
            }

            if ($category->children()->exists()) {
                return response()->json([
                    'message' => 'Cannot make this category a sub-category because it already has sub-categories of its own.',
                ], 422);
            }
        }

        if (isset($validated['name'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        $category->update($validated);

        if ($request->hasFile('image')) {
            $category->update([
                'image' => $request->file('image')->store('categories', 'public'),
            ]);
        }

        return response()->json([
            'message'  => 'Category updated successfully.',
            'category' => $category->fresh(),
        ]);
    }

    public function destroy(Category $category)
    {
        $category->delete();

        return response()->json([
            'message' => 'Category deleted successfully.',
        ]);
    }
}