<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreConversationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => ['sometimes', 'string', 'in:direct,group'],
            'title' => ['nullable', 'string', 'max:255'],
            'participant_ids' => [
                'array',
                Rule::when(
                    fn ($input) => ($input->type ?? 'direct') === 'direct',
                    ['required', 'size:1'],
                    ['nullable'],
                ),
            ],
            'participant_ids.*' => ['uuid', 'exists:users,id'],
        ];
    }
}
