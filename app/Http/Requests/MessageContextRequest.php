<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class MessageContextRequest extends FormRequest
{
    /**
     * Participation and visibility are checked by the controller (403 and
     * 404 respectively).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * How many messages to return either side of the target. Clamped to
     * 0–50 by the controller.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'before' => ['nullable', 'integer'],
            'after' => ['nullable', 'integer'],
        ];
    }
}
