<?php

namespace App\Http\Requests;

use App\Models\Message;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateMessageRequest extends FormRequest
{
    /**
     * Authorized on the route (MessagePolicy::update).
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
            'body' => ['required', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var Message $message */
            $message = $this->route('message');

            if ($message->deleted_at !== null) {
                $validator->errors()->add('body', 'Cannot edit a deleted message.');
            }
        });
    }
}
