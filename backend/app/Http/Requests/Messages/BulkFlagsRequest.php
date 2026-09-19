<?php

declare(strict_types=1);

namespace App\Http\Requests\Messages;

use Illuminate\Contracts\Validation\Validator;

class BulkFlagsRequest extends BulkMessagesRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'is_read' => ['sometimes', 'boolean'],
            'is_flagged' => ['sometimes', 'boolean'],
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->has('is_read') && ! $this->has('is_flagged')) {
                $validator->errors()->add('is_read', 'At least one of is_read or is_flagged is required.');
            }
        });
    }
}
