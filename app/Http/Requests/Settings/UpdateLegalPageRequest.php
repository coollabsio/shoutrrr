<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateLegalPageRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var User|null $user */
        $user = $this->user();

        return $user !== null
            && $user->hasAllPermissions(['workspace.settings.manage'], Context::get('workspace_id'));
    }

    /**
     * Normalise the slug before validation so casing/whitespace never causes a
     * spurious uniqueness or format failure.
     */
    protected function prepareForValidation(): void
    {
        $slug = $this->input('slug');

        if (is_string($slug)) {
            $this->merge(['slug' => Str::lower(trim($slug))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $workspaceId = Context::get('workspace_id');
        $maxBody = (int) config('kit.legal.max_body_length');

        return [
            'slug' => [
                'required', 'string', 'min:3', 'max:63',
                // Lowercase alphanumerics separated by single hyphens; no
                // leading, trailing, or consecutive hyphens.
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                // Reserved words that would collide with, or impersonate,
                // first-party routes (see config/kit.php).
                Rule::notIn((array) config('kit.legal.reserved_slugs')),
                // Globally unique, ignoring this workspace's own existing row.
                Rule::unique('legal_pages', 'slug')->ignore($workspaceId, 'workspace_id'),
            ],
            'terms_body' => ['nullable', 'string', "max:{$maxBody}"],
            'privacy_body' => ['nullable', 'string', "max:{$maxBody}"],
            'terms_published' => ['boolean'],
            'privacy_published' => ['boolean'],
        ];
    }

    /**
     * A document can only be published once it actually has content.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->boolean('terms_published') && blank($this->input('terms_body'))) {
                $validator->errors()->add('terms_body', 'Add terms content before publishing this page.');
            }

            if ($this->boolean('privacy_published') && blank($this->input('privacy_body'))) {
                $validator->errors()->add('privacy_body', 'Add privacy content before publishing this page.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.regex' => 'The slug may only contain lowercase letters, numbers, and single hyphens.',
            'slug.not_in' => 'That slug is reserved. Please choose another.',
            'slug.unique' => 'That slug is already in use. Please choose another.',
        ];
    }
}
