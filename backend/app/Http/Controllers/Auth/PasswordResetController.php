<?php

namespace App\Http\Controllers\Auth;

use App\Enums\AccountChange;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use App\Notifications\AccountSecurityEmail;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

/**
 * "Forgot password?": email a single-use link, then set a new password with it.
 * Uses Laravel's password broker: the token is stored hashed and expires.
 */
class PasswordResetController extends Controller
{
    public function forgot(ForgotPasswordRequest $request): Response
    {
        // The same answer whether or not the account exists (or was just sent a link),
        // so this cannot be used to find out who has an account. A disabled account gets
        // no link either: a new password would not let it in, so it would only mislead.
        $email = $request->validated('email');

        if (User::where('email', $email)->first()?->isDisabled() !== true) {
            Password::broker()->sendResetLink(['email' => $email]);
        }

        return response()->noContent(202);
    }

    public function reset(ResetPasswordRequest $request): Response
    {
        // A link sent before the account was disabled no longer works.
        if (User::where('email', $request->validated('email'))->first()?->isDisabled()) {
            $this->rejectLink();
        }

        $status = Password::broker()->reset(
            $request->safe()->only(['email', 'password', 'token']),
            function (User $user, string $password) {
                $user->forceFill(['password' => $password])->save();
                // Whoever might know the old password is signed out everywhere.
                $user->signOutOtherSessions();
                $user->notify(new AccountSecurityEmail(AccountChange::PasswordReset, $user->first_name ?: $user->name));
                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            $this->rejectLink();
        }

        return response()->noContent();
    }

    /** An unknown email, a disabled account and a bad token all read the same: nothing to enumerate. */
    private function rejectLink(): never
    {
        throw ValidationException::withMessages([
            'token' => 'This reset link is invalid or has expired. Ask for a new one.',
        ]);
    }
}
