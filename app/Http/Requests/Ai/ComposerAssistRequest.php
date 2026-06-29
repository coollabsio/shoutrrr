<?php

declare(strict_types=1);

namespace App\Http\Requests\Ai;

use App\Services\Ai\Prompts;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ComposerAssistRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'text' => ['nullable', 'string', 'max:20000'],
            'instruction' => ['nullable', 'string', 'max:2000'],
            'platform' => ['nullable', 'string', Rule::in(['x', 'bluesky', 'linkedin'])],
            'limit' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'action' => ['nullable', 'string', Rule::in(array_keys(Prompts::PRESETS))],
        ];
    }

    public function platform(): ?string
    {
        $value = $this->string('platform')->toString();

        return $value === '' ? null : $value;
    }

    public function limit(): int
    {
        return (int) $this->integer('limit');
    }
}
