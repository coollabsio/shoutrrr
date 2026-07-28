<?php

declare(strict_types=1);

namespace App\Http\Requests\ConnectedAccount;

use App\Models\ConnectedAccount;
use Illuminate\Foundation\Http\FormRequest;

class ConnectBlueskyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', ConnectedAccount::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'identifier' => ['required', 'string', 'max:255'],
            'app_password' => ['required', 'string', 'max:255'],
            'pds_url' => ['nullable', 'url', 'max:255'],
            // "This app password has DM access" — Bluesky has no OAuth scopes to
            // inspect, so the operator self-declares it at connect time instead.
            'dm_access' => ['sometimes', 'boolean'],
        ];
    }
}
