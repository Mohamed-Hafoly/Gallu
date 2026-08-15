<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreImageRequest;
use App\Http\Requests\UpdateImageRequest;
use App\Http\Resources\ImageResource;
use App\Models\Image;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class ImageController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $images = $request->user()
            ->images()
            ->with(['categories', 'media'])
            ->latest()
            ->get();

        return ImageResource::collection($images);
    }

    public function store(StoreImageRequest $request): ImageResource
    {
        $image = $request->user()
            ->images()
            ->create($request->safe()->only(['title', 'description']));

        $image->addMediaFromRequest('image')
            ->usingName($image->title)
            ->usingFileName(Str::uuid().'.'.$request->file('image')->getClientOriginalExtension())
            ->toMediaCollection(Image::IMAGES_COLLECTION);

        $image->categories()->sync($request->input('selected_category_ids'));

        return new ImageResource($image->load(['categories', 'media']));
    }

    public function update(UpdateImageRequest $request, Image $image): ImageResource
    {
        $image->update($request->safe()->only(['title', 'description']));

        if ($request->hasFile('image')) {
            $image->clearMediaCollection(Image::IMAGES_COLLECTION);
            $image->addMediaFromRequest('image')
                ->usingName($image->title)
                ->usingFileName(Str::uuid().'.'.$request->file('image')->getClientOriginalExtension())
                ->toMediaCollection(Image::IMAGES_COLLECTION);
        } else {
            $image->getFirstMedia(Image::IMAGES_COLLECTION)?->update(['name' => $image->title]);
        }

        $image->categories()->sync($request->input('selected_category_ids'));

        return new ImageResource($image->load(['categories', 'media']));
    }

    public function destroy(Request $request, Image $image): Response
    {
        abort_if($image->user_id !== $request->user()->id, 404);

        $image->delete();

        return response()->noContent();
    }
}
