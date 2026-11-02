<?php

namespace App\Http\Controllers;

use App\Enums\EmailType;
use App\Http\Requests\UpdateEmailPreferencesRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** The signed-in bidder's email settings, and the unsubscribe links in every email. */
class EmailPreferenceController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->emailPreferences()]);
    }

    public function update(UpdateEmailPreferencesRequest $request): JsonResponse
    {
        $request->user()->updateEmailPreferences($request->changes());

        return response()->json(['data' => $request->user()->emailPreferences()]);
    }

    /**
     * Opened from the link in an email: no login needed, because the link is signed
     * (it cannot be made for another user or type). Then shows a confirmation page.
     */
    public function unsubscribe(User $user, EmailType $type): RedirectResponse
    {
        $user->updateEmailPreferences([$type->value => false]);

        return redirect()->away(config('app.frontend_url').'/unsubscribed?type='.$type->value);
    }

    /** The mail app's own one-click unsubscribe button (RFC 8058) posts to the same signed link. */
    public function unsubscribeOneClick(User $user, EmailType $type): Response
    {
        $user->updateEmailPreferences([$type->value => false]);

        return response()->noContent();
    }
}
