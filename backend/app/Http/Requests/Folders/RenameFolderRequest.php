<?php

declare(strict_types=1);

namespace App\Http\Requests\Folders;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RenameFolderRequest extends FormRequest
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
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('folders', 'name')
                    ->where('email_account_id', $this->route('folder')?->email_account_id)
                    ->ignore($this->route('folder')?->id),
            ],
        ];
    }
}
