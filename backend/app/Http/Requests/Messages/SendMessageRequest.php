<?php

declare(strict_types=1);

namespace App\Http\Requests\Messages;

use App\Models\Message;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class SendMessageRequest extends FormRequest
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
            'to' => ['required', 'array', 'min:1'],
            'to.*' => ['required', 'email'],
            'cc' => ['sometimes', 'array'],
            'cc.*' => ['email'],
            'bcc' => ['sometimes', 'array'],
            'bcc.*' => ['email'],
            'subject' => ['required', 'string', 'max:500'],
            'body_html' => ['required', 'string'],
            'attachments' => ['sometimes', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:10240'],
            // Reply/Forward threading (Phase 4b) — the message this send is
            // in reply to, so SmtpMailService can set In-Reply-To/References.
            'in_reply_to_message_id' => ['nullable', 'integer', 'exists:messages,id'],
            // A draft being sent (Phase 4b's compose-from-draft flow, §8 item
            // 65) — once the send succeeds, the draft is permanently removed
            // rather than left behind or moved to Trash, since the real Sent
            // copy already supersedes it.
            'draft_message_id' => ['nullable', 'integer', 'exists:messages,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $emailAccount = $this->route('emailAccount');

            if (! $validator->errors()->has('in_reply_to_message_id') && $this->input('in_reply_to_message_id') !== null) {
                $original = Message::find($this->input('in_reply_to_message_id'));

                if ($original !== null && $original->folder->email_account_id !== $emailAccount->id) {
                    $validator->errors()->add('in_reply_to_message_id', 'The referenced message must belong to the same email account.');
                }
            }

            if (! $validator->errors()->has('draft_message_id') && $this->input('draft_message_id') !== null) {
                $draft = Message::find($this->input('draft_message_id'));

                if ($draft === null || $draft->folder->email_account_id !== $emailAccount->id) {
                    $validator->errors()->add('draft_message_id', 'The draft must belong to the same email account.');
                } elseif ($draft->folder->type !== 'drafts') {
                    $validator->errors()->add('draft_message_id', 'The referenced message is not a draft.');
                }
            }
        });
    }
}
