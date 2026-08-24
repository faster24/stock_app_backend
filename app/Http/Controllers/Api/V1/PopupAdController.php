<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\StreamsMediaDownloads;
use App\Http\Controllers\Controller;
use App\Http\Requests\PopupAd\StorePopupAdRequest;
use App\Http\Requests\PopupAd\UpdatePopupAdRequest;
use App\Models\PopupAd;
use App\Services\PopupAd\PopupAdService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PopupAdController extends Controller
{
    use StreamsMediaDownloads;

    public function __construct(private PopupAdService $popupAdService) {}

    public function index(): JsonResponse
    {
        return $this->respond('Popup ads retrieved successfully.', [
            'popup_ads' => $this->popupAdService->list()
                ->map(fn (PopupAd $ad) => $this->popupAdPayload($ad))
                ->all(),
        ]);
    }

    public function userIndex(): JsonResponse
    {
        return $this->respond('Popup ads retrieved successfully.', [
            'popup_ads' => $this->popupAdService->listActive()
                ->map(fn (PopupAd $ad) => $this->popupAdPayload($ad))
                ->all(),
        ]);
    }

    public function show(PopupAd $popupAd): JsonResponse
    {
        return $this->respond('Popup ad retrieved successfully.', [
            'popup_ad' => $this->popupAdPayload($this->popupAdService->show($popupAd)),
        ]);
    }

    public function store(StorePopupAdRequest $request): JsonResponse
    {
        $popupAd = $this->popupAdService->create(
            $request->safe()->except('image'),
            $request->file('image'),
        );

        return $this->respond('Popup ad created successfully.', [
            'popup_ad' => $this->popupAdPayload($popupAd),
        ], 201);
    }

    public function update(UpdatePopupAdRequest $request, PopupAd $popupAd): JsonResponse
    {
        $updated = $this->popupAdService->update(
            $popupAd,
            $request->safe()->except('image'),
            $request->file('image'),
        );

        return $this->respond('Popup ad updated successfully.', [
            'popup_ad' => $this->popupAdPayload($updated),
        ]);
    }

    public function destroy(PopupAd $popupAd): JsonResponse
    {
        $this->popupAdService->delete($popupAd);

        return $this->respond('Popup ad deleted successfully.', null);
    }

    /**
     * The media disk has no public URL, so the artwork is streamed through here.
     * Deactivating an ad hides its image from players — otherwise a guessed id
     * would keep serving artwork the admin has pulled. Admins still see it, so
     * the dashboard can preview inactive ads.
     */
    public function downloadImage(Request $request, string $popupAd): BinaryFileResponse|JsonResponse
    {
        $found = PopupAd::query()->with('media')->whereKey($popupAd)->first();

        if ($found === null) {
            return $this->respond('Popup ad not found.', null, 404);
        }

        if (! $found->is_active && ! $request->user()->hasRole('admin')) {
            return $this->respond('Popup ad not found.', null, 404);
        }

        $media = $found->getFirstMedia('ad_image');

        if ($media === null) {
            return $this->respond('Popup ad image not found.', null, 404);
        }

        return $this->downloadMedia($media);
    }

    private function popupAdPayload(PopupAd $popupAd): array
    {
        return [
            'id' => $popupAd->id,
            'title' => $popupAd->title,
            'link_url' => $popupAd->link_url,
            'is_active' => $popupAd->is_active,
            'image' => $popupAd->image,
            'created_at' => $popupAd->created_at?->toIso8601String(),
            'updated_at' => $popupAd->updated_at?->toIso8601String(),
        ];
    }

    private function respond(string $message, ?array $data, int $status = 200, ?array $errors = null): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'data' => $data,
            'errors' => $errors,
        ], $status);
    }
}
