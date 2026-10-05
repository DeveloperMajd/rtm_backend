<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class MessageHistoryRequest extends FormRequest
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
     * A page of history is read from one cursor, in one direction: older
     * than `before_id`, or newer than `after_id`. Both are message ids,
     * checked as uuids because Postgres rejects anything else in a uuid
     * comparison (a malformed cursor used to surface as a 500). `limit` is
     * not validated but clamped by the controller, as it always has been.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'before_id' => ['nullable', 'uuid'],
            'after_id' => ['nullable', 'uuid', 'prohibits:before_id'],
        ];
    }
}
