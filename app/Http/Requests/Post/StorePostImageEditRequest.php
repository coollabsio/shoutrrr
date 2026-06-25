<?php

declare(strict_types=1);

namespace App\Http\Requests\Post;

class StorePostImageEditRequest extends AbstractImageEditRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->baseRules(),
            'source' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp,image/gif', 'max:8192'],
        ];
    }
}
