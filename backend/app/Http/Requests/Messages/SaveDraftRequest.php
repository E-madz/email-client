<?php

declare(strict_types=1);

namespace App\Http\Requests\Messages;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Unlike SendMessageRequest, every field is optional — a draft is allowed to
 * be incomplete (no recipient yet, empty subject/body), which is exactly why
 * it's a draft and not a send.
 */
class SaveDraftRequest extends FormRequest
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
            'to' => ['sometimes', 'array'],
            'to.*' => ['email'],
            'cc' => ['sometimes', 'array'],
            'cc.*' => ['email'],
            'bcc' => ['sometimes', 'array'],
            'bcc.*' => ['email'],
            'subject' => ['sometimes', 'nullable', 'string', 'max:500'],
            'body_html' => ['sometimes', 'nullable', 'string'],
            'attachments' => ['sometimes', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:10240'],
        ];
    }
}
