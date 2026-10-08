<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SharedAttachmentsRequest extends FormRequest
{
    /**
     * Participation is checked by the controller, which answers 403 in the
     * same shape as the rest of the conversation endpoints.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `kind` is the photos (`media`) or everything else (`files`), listed
     * separately as the info panel shows them. A page is read from one
     * cursor, older than `before_id` (an attachment's id), checked as a
     * uuid like the history's cursors. `limit` is clamped by the controller.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(['media', 'files'])],
            'before_id' => ['nullable', 'uuid'],
        ];
    }

    public function wantsMedia(): bool
    {
        return $this->validated('kind') === 'media';
    }
}
