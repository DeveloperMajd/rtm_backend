<?php

namespace App\Http\Requests;

use App\Models\Conversation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMessageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $conversation = Conversation::find($this->input('conversation_id'));

        if (! $conversation) {
            return false;
        }

        return $conversation->participants()
            ->where('user_id', $this->user()->id)
            ->exists();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'conversation_id' => ['required', 'exists:conversations,id'],
            'body' => ['nullable', 'string', 'required_without:attachment_ids'],
            'reply_to_message_id' => [
                'nullable',
                'uuid',
                Rule::exists('messages', 'id')->where('conversation_id', $this->input('conversation_id')),
            ],
            'attachment_ids' => ['sometimes', 'array', 'min:1', 'max:10'],
            'attachment_ids.*' => [
                'uuid',
                Rule::exists('attachments', 'id')
                    ->where('uploaded_by_user_id', $this->user()->id)
                    ->whereNull('message_id'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'conversation_id.required' => 'The conversation ID is required.',
            'conversation_id.exists' => 'The specified conversation does not exist.',
            'body.required_without' => 'The message body is required unless a file is attached.',
            'body.string' => 'The message body must be a string.',
            'attachment_ids.*.exists' => 'One or more attachments are invalid or already sent.',
        ];
    }
}
