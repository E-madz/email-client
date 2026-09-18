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
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->has('in_reply_to_message_id') || $this->input('in_reply_to_message_id') === null) {
                return;
            }

            $original = Message::find($this->input('in_reply_to_message_id'));
            $emailAccount = $this->route('emailAccount');

            if ($original !== null && $original->folder->email_account_id !== $emailAccount->id) {
                $validator->errors()->add('in_reply_to_message_id', 'The referenced message must belong to the same email account.');
            }
        });
    }
}
