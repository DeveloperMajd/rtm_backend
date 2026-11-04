<?php

namespace App\Http\Requests;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UpdateConversationParticipantRoleRequest extends FormRequest
{
    /**
     * Here rather than on the route: whether the change is allowed depends
     * on the role asked for (ConversationPolicy::updateParticipantRole). A
     * deleted group is 404, as everywhere.
     */
    public function authorize(): Response
    {
        /** @var Conversation $conversation */
        $conversation = $this->route('conversation');
        /** @var User $target */
        $target = $this->route('user');

        return Gate::forUser($this->user())->inspect('updateParticipantRole', [$conversation, $target, (string) $this->input('role')]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', Rule::in(['admin', 'participant'])],
        ];
    }
}
