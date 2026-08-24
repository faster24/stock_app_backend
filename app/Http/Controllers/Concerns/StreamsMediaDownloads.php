<?php

namespace App\Http\Controllers\Concerns;

use App\Support\Media\ImageUploadPolicy;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Every stored image leaves the API through here.
 *
 * `response()->download()` already sends `Content-Disposition: attachment`, but
 * that was the only thing standing between a hostile upload and the viewer. Two
 * more guards ride along now: an unexpected stored MIME is handed back as an
 * opaque download rather than as itself, and nosniff stops a client from
 * second-guessing the type we declared.
 */
trait StreamsMediaDownloads
{
    protected function downloadMedia(Media $media): BinaryFileResponse
    {
        return response()->download(
            $media->getPath(),
            $media->file_name,
            [
                'Content-Type' => ImageUploadPolicy::allows($media->mime_type)
                    ? $media->mime_type
                    : 'application/octet-stream',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
