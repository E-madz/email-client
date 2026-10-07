<?php

declare(strict_types=1);

namespace App\Http\Requests\Messages;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Base validation shared by every bulk message action — just the id list.
 * Ownership is checked separately in the controller (not here), since it
 * needs to compare against the authenticated user across every message's
 * folder/email account, not a simple per-field rule.
 */
class BulkMessagesRequest extends FormRequest
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
            'message_ids' => ['required', 'array', 'min:1', 'max:200'],
            'message_ids.*' => ['integer', 'distinct', 'exists:messages,id'],
        ];
    }
}
