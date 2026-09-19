<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\UpdatePasswordRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use Illuminate\Http\JsonResponse;

class ProfileController extends Controller
{
    /**
     * Update the authenticated user's name/username (CLAUDE.md §4.1 —
     * email/password stay separate concerns, not editable here).
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();

        $user->update([
            'name' => $request->validated('name'),
            'username' => $request->validated('username'),
        ]);

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
        ]);
    }

    /**
     * Change the authenticated user's password — requires the current
     * password to be re-entered correctly (verified in UpdatePasswordRequest),
     * same discipline as any other credential change in this app.
     */
    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        $user->update([
            'password' => $request->validated('password'),
        ]);

        return response()->json(status: 204);
    }
}
