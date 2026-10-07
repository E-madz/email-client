<?php

declare(strict_types=1);

namespace App\Http\Requests\Messages;

use App\Models\Folder;
use App\Support\MessageMoveEligibility;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class MoveMessageRequest extends FormRequest
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
            'folder_id' => ['required', 'integer', 'exists:folders,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->has('folder_id')) {
                return;
            }

            $message = $this->route('message');
            $targetFolder = Folder::find($this->input('folder_id'));

            if ($targetFolder === null) {
                return;
            }

            $violation = MessageMoveEligibility::violation($message->folder, $targetFolder);

            if ($violation !== null) {
                $validator->errors()->add('folder_id', $violation);
            }
        });
    }
}
