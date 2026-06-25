<?php

declare(strict_types=1);

namespace App\Http\Requests\Post;

class UpdatePostImageEditRequest extends AbstractImageEditRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->baseRules();
    }
}
