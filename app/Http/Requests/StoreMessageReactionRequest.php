<?php

namespace App\Http\Requests;

use App\Enums\MessageReactionType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMessageReactionRequest extends FormRequest
{
    /**
     * Authorized on the route (MessagePolicy::react).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'reaction' => ['required', Rule::enum(MessageReactionType::class)],
        ];
    }
}
