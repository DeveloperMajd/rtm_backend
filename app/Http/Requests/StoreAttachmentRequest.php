<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class StoreAttachmentRequest extends FormRequest
{
    /**
     * Allowed extensions for message attachments. Kept in sync with the
     * frontend picker's `accept` attribute.
     *
     * @var list<string>
     */
    public const ALLOWED_TYPES = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];

    public const MAX_SIZE_KB = 15 * 1024;

    /**
     * Determine if the user is authorized to make this request.
     *
     * Any authenticated user may upload; the file only becomes visible once
     * it is linked to a message in a conversation they participate in
     * (enforced in StoreMessageRequest).
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                File::types(self::ALLOWED_TYPES)->max(self::MAX_SIZE_KB),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'A file is required.',
        ];
    }
}
