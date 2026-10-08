<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PresenceHeartbeatRequest extends FormRequest
{
    /**
     * A heartbeat is the signed-in person's own: nothing else to check.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `state` says whether the person is using the app (`active`, the
     * default, which is what a client from before away presence sends by
     * leaving it out) or has left it idle (`away`).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'state' => ['sometimes', 'string', Rule::in(['active', 'away'])],
        ];
    }

    public function isAway(): bool
    {
        return $this->validated('state') === 'away';
    }
}
