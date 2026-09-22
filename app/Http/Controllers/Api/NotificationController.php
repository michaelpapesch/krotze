<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatUser;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /** Mark one or all notifications as read. */
    public function markRead(Request $request)
    {
        /** @var ChatUser $user */
        $user = $request->attributes->get('chatUser');

        $query = $user->notifications()->whereNull('read_at');
        if ($id = $request->input('id')) {
            $query->where('id', $id);
        }
        $query->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }
}
