<?php

declare(strict_types=1);

namespace App\Http\Requests\Messages;

use App\Models\Folder;
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

    /**
     * Folder types a message can never be moved *into* through this generic
     * action, since their contents mean something specific that an arbitrary
     * moved message would misrepresent (§8 item 66):
     *  - `drafts`: only ever populated by the compose/save-draft flow
     *    (`DraftService`) — a moved-in message isn't an editable draft and
     *    has none of the delete-then-append semantics that flow depends on.
     *  - `sent`: only ever populated by `SmtpMailService` appending a
     *    message the account itself actually sent over SMTP — moving an
     *    arbitrary (possibly received) message in would misrepresent it as
     *    something the user sent.
     */
    private const UNMOVABLE_TARGET_TYPES = ['drafts', 'sent'];

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

            if ($targetFolder->email_account_id !== $message->folder->email_account_id) {
                $validator->errors()->add('folder_id', 'The target folder must belong to the same email account.');
            } elseif ($targetFolder->id === $message->folder_id) {
                $validator->errors()->add('folder_id', 'The message is already in that folder.');
            } elseif ($message->folder->type === 'drafts') {
                // A draft leaves Drafts only by being sent (which deletes it,
                // §8 item 65) or deleted (moved to Trash via the dedicated
                // delete endpoint) — not by an arbitrary generic move, which
                // would leave a half-composed message stranded somewhere
                // with none of its editing lifecycle intact.
                $validator->errors()->add('folder_id', 'A draft cannot be moved directly — send or delete it instead.');
            } elseif (in_array($targetFolder->type, self::UNMOVABLE_TARGET_TYPES, true)) {
                $validator->errors()->add('folder_id', "Messages can't be moved into the {$targetFolder->type} folder.");
            }
        });
    }
}
