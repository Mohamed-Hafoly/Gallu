<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCategoryRequest;
use App\Http\Requests\UpdateCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class CategoryController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return CategoryResource::collection(
            Category::withTrashed()->with('user')->orderBy('id')->get()
        );
    }

    public function picker(): AnonymousResourceCollection
    {
        return CategoryResource::collection(Category::all());
    }

    // TODO: the four write methods below are super-admin only per req.txt.
    // Gate them on a policy once the role column exists.

    public function store(StoreCategoryRequest $request): CategoryResource
    {
        $category = Category::create([
            ...$request->safe()->only(['name_en', 'name_ar']),
            'user_id' => $request->user()->id,
        ]);

        return new CategoryResource($category->load('user'));
    }

    public function update(UpdateCategoryRequest $request, Category $category): CategoryResource
    {
        $category->update($request->safe()->only(['name_en', 'name_ar']));

        return new CategoryResource($category->load('user'));
    }

    public function destroy(Category $category): Response
    {
        $category->delete();

        return response()->noContent();
    }

    public function restore(Category $category): CategoryResource
    {
        abort_if(! $category->trashed(), 404);

        $category->restore();

        return new CategoryResource($category->load('user'));
    }
}
