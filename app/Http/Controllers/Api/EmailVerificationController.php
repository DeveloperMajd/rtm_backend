<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class EmailVerificationController extends Controller
{
    /**
     * The emailed link, by way of the frontend's /verify-email page. The
     * signature says the link is ours and hasn't expired; the id and hash,
     * that it was sent to the account signed in here, at the address it
     * still has.
     *
     * Being signed in is part of the proof. Without it, someone who signed
     * up with another person's address could have that person verify the
     * account just by opening the email, and a later Google sign-in would
     * then link to it with the stranger's password still on it.
     */
    public function verify(Request $request, string $id, string $hash): JsonResponse
    {
        $user = $request->user();

        if ($user->getKey() !== $id || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return response()->json(['data' => ['message' => 'This link is for another account.']], 403);
        }

        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return response()->json(['data' => ['message' => 'Email verified.']]);
    }

    /**
     * Another link, for the "Check your email" screen.
     */
    public function sendLink(Request $request): JsonResponse|Response
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->noContent();
        }

        $user->sendEmailVerificationNotification();

        return response()->json(['data' => ['message' => 'Verification link sent.']], 202);
    }
}
