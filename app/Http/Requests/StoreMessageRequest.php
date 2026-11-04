<?php

namespace App\Http\Requests;

use App\Models\Conversation;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StoreMessageRequest extends FormRequest
{
    /**
     * Only a current member writes (ConversationPolicy::participate). The
     * conversation is in the body, not the route, so it's looked up here:
     * deleted groups included, for the 404. An id that's nobody's
     * conversation is refused like one that isn't theirs.
     */
    public function authorize(): Response|bool
    {
        $conversation = Conversation::withTrashed()->find($this->input('conversation_id'));

        if ($conversation === null) {
            return false;
        }

        return Gate::forUser($this->user())->inspect('participate', $conversation);
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
