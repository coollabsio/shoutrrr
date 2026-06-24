<?php

declare(strict_types=1);

namespace App\Http\Requests\Workspace;

use App\Support\InstanceSettings;
use Illuminate\Foundation\Http\FormRequest;

class StoreWorkspaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) config('kit.workspaces.enabled')
            && app(InstanceSettings::class)->workspaceCreationEnabled();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
