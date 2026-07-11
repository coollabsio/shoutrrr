<?php

declare(strict_types=1);

namespace App\Http\Requests\ConnectedAccount;

use App\Models\ConnectedAccount;
use Illuminate\Foundation\Http\FormRequest;

class ConnectDiscordRequest extends FormRequest
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
            'webhook_url' => ['required', 'string', 'url', 'max:2048'],
        ];
    }
}
