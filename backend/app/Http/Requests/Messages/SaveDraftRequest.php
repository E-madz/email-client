<?php

declare(strict_types=1);

namespace App\Http\Requests\Messages;

use Illuminate\Contracts\Validation\Validator;
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
            // Which of the draft's *already-saved* attachments to keep on an
            // update — anything not listed here gets deleted (§8 item 62).
            // Meaningless on a new draft (store()), since it has none yet.
            'keep_attachment_ids' => ['sometimes', 'array'],
            'keep_attachment_ids.*' => ['integer', 'exists:message_attachments,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->has('keep_attachment_ids')) {
                return;
            }

            $draft = $this->route('message');

            if ($draft === null) {
                return;
            }

            $ownAttachmentIds = $draft->attachments()->pluck('id')->all();

            foreach ($this->input('keep_attachment_ids', []) as $id) {
                if (! in_array((int) $id, $ownAttachmentIds, true)) {
                    $validator->errors()->add('keep_attachment_ids', 'One or more attachments do not belong to this draft.');
                    break;
                }
            }
        });
    }
}
