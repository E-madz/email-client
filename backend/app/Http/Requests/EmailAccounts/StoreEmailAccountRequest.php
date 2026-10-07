<?php

declare(strict_types=1);

namespace App\Http\Requests\EmailAccounts;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmailAccountRequest extends FormRequest
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
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('email_accounts', 'email')->where('user_id', $this->user()?->id),
            ],
            'password' => ['required', 'string'],
        ];
    }
}
