<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SearchMessagesRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `conversation_id` narrows the search to one conversation (the in-chat
     * search bar, or the palette's "This conversation" scope); whether the
     * viewer may search it is the controller's call (403/404). `sort` is
     * best-match first by default, or newest first. `limit` is clamped by
     * the controller, not validated.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:2', 'max:200'],
            'conversation_id' => ['nullable', 'uuid'],
            'sort' => ['nullable', 'in:relevance,recent'],
        ];
    }
}
