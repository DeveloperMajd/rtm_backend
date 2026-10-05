<?php

namespace App\Http\Requests;

use App\Models\UserSettings;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSettingsRequest extends FormRequest
{
    /**
     * Everyone may change their own settings.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Any of the settings, the rest left as they are; at least one.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $fields = array_keys(UserSettings::DEFAULTS);

        return [
            'read_receipts' => ['required_without_all:'.implode(',', array_diff($fields, ['read_receipts'])), 'boolean'],
            'last_seen_visibility' => ['sometimes', Rule::in(UserSettings::LAST_SEEN_VISIBILITIES)],
            'typing_indicators' => ['sometimes', 'boolean'],
            'message_sounds' => ['sometimes', 'boolean'],
            'desktop_notifications' => ['sometimes', 'boolean'],
        ];
    }
}
