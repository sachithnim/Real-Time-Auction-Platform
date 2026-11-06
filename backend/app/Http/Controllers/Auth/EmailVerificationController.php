<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Confirming the email address. The link in the email opens verify() directly
 * (no login needed), which then sends the browser to the web app with the outcome.
 */
class EmailVerificationController extends Controller
{
    public function verify(Request $request, User $user, string $hash): RedirectResponse
    {
        // A tampered or expired link, or one for an address the account no longer uses.
        if (! $request->hasValidSignature() || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return $this->toApp('invalid');
        }

        if ($user->hasVerifiedEmail()) {
            return $this->toApp('already');
        }

        $user->markEmailAsVerified();
        event(new Verified($user));

        return $this->toApp('verified');
    }

    /** "Send it again" from the banner. Nothing to do once confirmed. */
    public function resend(Request $request): Response
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->noContent();
        }

        $user->sendEmailVerificationNotification();

        return response()->noContent(202);
    }

    private function toApp(string $status): RedirectResponse
    {
        return redirect()->away(config('app.frontend_url').'/email-verified?status='.$status);
    }
}
