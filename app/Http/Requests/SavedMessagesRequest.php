<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SavedMessagesRequest extends FormRequest
{
    /**
     * Anyone signed in has a saved list: their own, which the controller
     * reads by who's asking.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * A page of the list is read from one cursor: older saves than
     * `before_id`, a save's id, as the history endpoint pages by message id
     * (and checked as a uuid for the same reason: Postgres rejects anything
     * else in a uuid comparison). `limit` is clamped by the controller.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'before_id' => ['nullable', 'uuid'],
        ];
    }
}
