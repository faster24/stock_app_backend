<?php

namespace App\Support\Media;

/**
 * The one allowlist every image upload and download is measured against.
 *
 * Laravel's bare `image` rule accepts SVG, and an SVG is a scriptable document
 * rather than a picture — a stored one can carry <script> back to whoever opens
 * it. Nothing here needs vector artwork, so the format is refused outright.
 */
final class ImageUploadPolicy
{
    /** Extensions Laravel guesses from the file's own bytes, not from its name. */
    public const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    public const MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /** 10 MB matches config/media-library.php max_file_size. */
    public const MAX_KILOBYTES = 10240;

    /**
     * Validation rules for an uploaded image.
     *
     * `mimes` and `mimetypes` are both applied on purpose: the first checks the
     * extension guessed from the content, the second the detected MIME. An SVG
     * renamed to .png fails both.
     *
     * @return array<int, string>
     */
    public static function rules(string $presence = 'required'): array
    {
        return [
            $presence,
            'file',
            'mimes:'.implode(',', self::EXTENSIONS),
            'mimetypes:'.implode(',', self::MIME_TYPES),
            'max:'.self::MAX_KILOBYTES,
        ];
    }

    public static function allows(?string $mimeType): bool
    {
        return $mimeType !== null && in_array($mimeType, self::MIME_TYPES, true);
    }
}
