<?php

declare(strict_types=1);

namespace App\Http\Requests\EmailAccounts;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEmailAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string'],
        ];
    }
}
