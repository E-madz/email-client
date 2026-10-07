<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\Mail\ImapAuthenticationException;
use App\Exceptions\Mail\ImapConnectionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\EmailAccounts\StoreEmailAccountRequest;
use App\Http\Requests\EmailAccounts\UpdateEmailAccountRequest;
use App\Http\Resources\EmailAccountResource;
use App\Models\EmailAccount;
use App\Models\MailServer;
use App\Services\Mail\EmailAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EmailAccountController extends Controller
{
    public function __construct(private readonly EmailAuthService $emailAuthService)
    {
        //
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $emailAccounts = $request->user()
            ->emailAccounts()
            ->with('mailServer')
            ->latest()
            ->get();

        return EmailAccountResource::collection($emailAccounts);
    }

    public function store(StoreEmailAccountRequest $request): JsonResponse
    {
        $mailServer = MailServer::query()
            ->where('is_active', true)
            ->where('is_default', true)
            ->firstOrFail();

        try {
            $this->emailAuthService->testImapConnection(
                $mailServer,
                $request->validated('email'),
                $request->validated('password'),
            );
        } catch (ImapAuthenticationException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (ImapConnectionException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        $emailAccount = $request->user()->emailAccounts()->create([
            'mail_server_id' => $mailServer->id,
            'email' => $request->validated('email'),
            'password' => $request->validated('password'),
            'status' => 'active',
            'last_verified_at' => now(),
        ]);

        return (new EmailAccountResource($emailAccount->load('mailServer')))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateEmailAccountRequest $request, EmailAccount $emailAccount): JsonResponse
    {
        abort_unless($emailAccount->user_id === $request->user()->id, 403);

        try {
            $this->emailAuthService->testImapConnection(
                $emailAccount->mailServer,
                $emailAccount->email,
                $request->validated('password'),
            );
        } catch (ImapAuthenticationException $e) {
            $emailAccount->markNeedsReauth();

            return response()->json(['message' => $e->getMessage()], 422);
        } catch (ImapConnectionException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        $emailAccount->password = $request->validated('password');
        $emailAccount->save();
        $emailAccount->markVerified();

        return (new EmailAccountResource($emailAccount->load('mailServer')))->response();
    }

    public function destroy(Request $request, EmailAccount $emailAccount): JsonResponse
    {
        abort_unless($emailAccount->user_id === $request->user()->id, 403);

        $emailAccount->delete();

        return response()->json(status: 204);
    }
}
