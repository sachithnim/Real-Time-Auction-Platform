<?php

namespace App\Http\Controllers\Account;

use App\Enums\AccountChange;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePasswordRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Notifications\AccountSecurityEmail;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Notification;

/**
 * The signed-in user's own name, email and password (admins and bidders alike).
 */
class AccountController extends Controller
{
    public function updateProfile(UpdateProfileRequest $request): UserResource
    {
        $user = $request->user();
        $oldEmail = $user->email;
        // Asked before saving: afterwards the user already has the new address.
        $emailChanged = $request->changesEmail();
        $data = $request->safe()->only(['first_name', 'last_name', 'email']);

        $user->update([...$data, 'name' => User::fullName($data['first_name'], $data['last_name'])]);

        if ($emailChanged) {
            // To the old address: if someone else made the change, the owner still hears of it.
            Notification::route('mail', $oldEmail)->notify(
                new AccountSecurityEmail(AccountChange::EmailChanged, $user->first_name, $user->email),
            );
        }

        return new UserResource($user);
    }

    public function updatePassword(UpdatePasswordRequest $request): Response
    {
        $user = $request->user();
        $user->forceFill(['password' => $request->validated('password')])->save();

        // This browser stays signed in; every other session and remember-me cookie ends.
        $user->signOutOtherSessions($request->hasSession() ? $request->session()->getId() : null);
        $user->notify(new AccountSecurityEmail(AccountChange::PasswordChanged, $user->first_name ?: $user->name));

        return response()->noContent();
    }
}
