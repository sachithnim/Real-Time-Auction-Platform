<?php

namespace App\Http\Controllers;

use App\Http\Resources\NoticeResource;
use App\Support\ServerTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * The signed-in bidder's notification inbox (the bell). Only their own entries
 * are ever read or changed: every query goes through $user->notifications().
 */
class NotificationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['page' => ['nullable', 'integer', 'min:1']]);
        $user = $request->user();

        return NoticeResource::collection($user->notifications()->paginate(15))->additional([
            'meta' => ['server_time' => ServerTime::now(), 'unread_count' => $user->unreadNotifications()->count()],
        ]);
    }

    public function markRead(Request $request, string $notice): Response
    {
        $request->user()->notifications()->whereKey($notice)->firstOrFail()->markAsRead();

        return response()->noContent();
    }

    public function markAllRead(Request $request): Response
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->noContent();
    }
}
