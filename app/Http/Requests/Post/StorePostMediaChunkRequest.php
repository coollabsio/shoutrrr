<?php

declare(strict_types=1);

namespace App\Http\Requests\Post;

use Illuminate\Foundation\Http\FormRequest;

class StorePostMediaChunkRequest extends FormRequest
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
            'upload_id' => ['required', 'uuid'],
            'index' => ['required', 'integer', 'min:0'],
            'total' => ['required', 'integer', 'min:1'],
            'mime' => ['required', 'string', 'in:video/mp4'],
            'chunk' => ['required', 'file', 'max:6144'], // KiB; client slices at 5 MiB
            'duration_seconds' => [$this->isFinalChunk() ? 'required' : 'nullable', 'integer', 'min:1'],
            'width' => [$this->isFinalChunk() ? 'required' : 'nullable', 'integer', 'min:1'],
            'height' => [$this->isFinalChunk() ? 'required' : 'nullable', 'integer', 'min:1'],
            'alt_text' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function isFinalChunk(): bool
    {
        return (int) $this->input('index') === (int) $this->input('total') - 1;
    }
}
