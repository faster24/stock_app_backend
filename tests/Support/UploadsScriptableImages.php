<?php

namespace Tests\Support;

use Illuminate\Http\UploadedFile;

/**
 * Builds hostile uploads the way PHP would receive them from a real request.
 *
 * `UploadedFile::fake()` cannot be used here: Illuminate\Http\Testing\File
 * derives getMimeType() from the file *name*, so a fake would never exercise
 * the content sniffing our mimes/mimetypes rules rely on. These are real
 * UploadedFile instances over a real temp file, so validation sniffs the bytes.
 */
trait UploadsScriptableImages
{
    private const HOSTILE_SVG = <<<'SVG'
        <?xml version="1.0" encoding="UTF-8"?>
        <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10">
            <script>alert(document.domain)</script>
        </svg>
        SVG;

    /**
     * A scriptable SVG. Pass a .png name to check that a renamed file is still
     * caught by content, not by extension.
     */
    protected function scriptableSvgUpload(string $name = 'payload.svg'): UploadedFile
    {
        $directory = sys_get_temp_dir().'/svg-upload-'.uniqid();

        mkdir($directory);
        file_put_contents($path = $directory.'/'.$name, self::HOSTILE_SVG);

        // test: true — the file did not arrive through a real HTTP upload.
        return new UploadedFile($path, $name, null, null, true);
    }
}
