<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class StoreAvatarRequest extends FormRequest
{
    /** @var list<string> */
    public const ALLOWED_TYPES = ['jpg', 'jpeg', 'png', 'webp'];

    public const MAX_SIZE_KB = 5 * 1024;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'avatar' => [
                'required',
                'image',
                'dimensions:min_width=48,min_height=48',
                File::types(self::ALLOWED_TYPES)->max(self::MAX_SIZE_KB),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'avatar.required' => 'An image file is required.',
        ];
    }
}
