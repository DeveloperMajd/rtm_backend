<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateConversationPreferencesRequest extends FormRequest
{
    /**
     * Participation is checked by the controller (403, 404).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Any of the three, switched on or off; at least one of them.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'pinned' => ['required_without_all:muted,archived', 'boolean'],
            'muted' => ['sometimes', 'boolean'],
            'archived' => ['sometimes', 'boolean'],
        ];
    }
}
