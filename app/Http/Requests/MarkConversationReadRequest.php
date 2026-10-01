<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class MarkConversationReadRequest extends FormRequest
{
    /**
     * Participation is checked by the controller (403, 404).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `message_id`: how far the viewer has read. Without it, up to the
     * conversation's newest message, as before. That it belongs to this
     * conversation is checked by the controller.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'message_id' => ['nullable', 'uuid'],
        ];
    }
}
