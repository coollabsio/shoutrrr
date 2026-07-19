<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class UpdateWorkspaceXBudgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var User|null $user */
        $user = $this->user();

        return (bool) $user?->isInstanceOwner();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'unlimited' => ['required', 'boolean'],
            'dollars' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ];
    }

    /**
     * Resolve the request into the value stored by InstanceSettings::setXWorkspaceBudget:
     * "unlimited", an integer number of cents, or null to clear the override.
     */
    public function budgetValue(): int|string|null
    {
        if ($this->boolean('unlimited')) {
            return 'unlimited';
        }

        $dollars = $this->input('dollars');

        if ($dollars === null || $dollars === '') {
            return null;
        }

        return (int) round(((float) $dollars) * 100);
    }
}
