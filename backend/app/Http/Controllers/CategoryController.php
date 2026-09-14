<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCategoryRequest;
use App\Http\Requests\UpdateCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Categories, managed by super-admins only via CategoryPolicy.
 *
 * The one exception is picker(): every user who uploads an image needs the
 * category list to tag it with, so that endpoint is deliberately ungated and
 * serves live rows without a creator. Everything else here - including the
 * listing, which carries trashed rows and creator names for the admin screen -
 * goes through the policy.
 */
class CategoryController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Category::class);

        return CategoryResource::collection(
            Category::withTrashed()->with('user')->orderBy('id')->get()
        );
    }

    public function picker(): AnonymousResourceCollection
    {
        return CategoryResource::collection(Category::all());
    }

    public function store(StoreCategoryRequest $request): CategoryResource
    {
        Gate::authorize('create', Category::class);

        $category = Category::create([
            ...$request->safe()->only(['name_en', 'name_ar']),
            'user_id' => $request->user()->id,
        ]);

        return new CategoryResource($category->load('user'));
    }

    public function update(UpdateCategoryRequest $request, Category $category): CategoryResource
    {
        Gate::authorize('update', $category);

        $category->update($request->safe()->only(['name_en', 'name_ar']));

        return new CategoryResource($category->load('user'));
    }

    public function destroy(Category $category): Response
    {
        Gate::authorize('delete', $category);

        $category->delete();

        return response()->noContent();
    }

    public function restore(Category $category): CategoryResource
    {
        Gate::authorize('restore', $category);

        abort_if(! $category->trashed(), 404);

        $category->restore();

        return new CategoryResource($category->load('user'));
    }
}
