<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Conversation;
use App\Support\MessageActor;
use App\Support\Messaging;
use Illuminate\Http\Request;

/** Who is asking, and which chat they may open. The sign-in system comes from the route name, never from the request data. */
trait ResolvesMessageActor
{
    protected function me(Request $request): MessageActor
    {
        $actor = Messaging::actor($request->routeIs('admin.*') ? 'admin' : 'web');
        abort_unless($actor, 403);

        return $actor;
    }

    /** A chat this person is in (or the announcements). Anyone else gets "not found". */
    protected function chat(MessageActor $me, int $conversationId): Conversation
    {
        $conversation = Messaging::conversationFor($me, $conversationId);
        abort_unless($conversation, 404);

        return $conversation;
    }
}
