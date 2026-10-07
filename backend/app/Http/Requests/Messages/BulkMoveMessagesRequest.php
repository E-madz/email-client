<?php

declare(strict_types=1);

namespace App\Http\Requests\Messages;

class BulkMoveMessagesRequest extends BulkMessagesRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'folder_id' => ['required', 'integer', 'exists:folders,id'],
        ]);
    }
}
