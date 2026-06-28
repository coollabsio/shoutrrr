<?php

declare(strict_types=1);

namespace App\Http\Requests\Post;

use App\Services\Media\ImageCompressor;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class StorePostMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('post'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimetypes:image/jpeg,image/png,image/webp,image/gif',
                'max:8192', // KiB (8 MiB, LinkedIn's cap — the most permissive)
                $this->withinPixelCeiling(...),
            ],
            'alt_text' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Reject images whose pixel count exceeds the compressor's decode ceiling. Reads the
     * header only (no canvas allocation), so a decompression-bomb is refused at the gate
     * with a clear error rather than failing opaquely when a platform later rejects it.
     */
    private function withinPixelCeiling(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            return;
        }

        $ceiling = (int) config('media.max_image_pixels', ImageCompressor::DEFAULT_MAX_PIXELS);

        $info = @getimagesize($value->getRealPath());

        if (is_array($info) && ($info[0] * $info[1]) > $ceiling) {
            $fail('The image resolution is too large.');
        }
    }
}
