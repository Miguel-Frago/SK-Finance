<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesMessageActor;
use App\Models\Conversation;
use App\Support\MessageActor;
use App\Support\MessageGroups;
use App\Support\Messaging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Group chats: make one, see who is in it, add and remove people, rename, leave, delete. */
class MessageGroupController extends Controller
{
    use ResolvesMessageActor;

    public function create(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title'     => ['required', 'string', 'max:120'],
            'members'   => ['required', 'array', 'min:1', 'max:' . (int) config('messaging.max_group_members')],
            'members.*' => ['integer', 'min:1'],
        ]);

        $me = $this->me($request);
        abort_unless(MessageGroups::canCreate($me), 403, 'You cannot create group chats.');

        $group = MessageGroups::create($me, $data['title'], $data['members']);

        return response()->json(['id' => $group->id], 201);
    }

    /** Who is in the group (everybody in it can see this). */
    public function members(Request $request, int $conversation): JsonResponse
    {
        $me = $this->me($request);
        $group = $this->group($me, $conversation);

        return response()->json([
            'members'    => MessageGroups::members($group),
            'can_manage' => MessageGroups::canManage($group, $me),
            'title'      => $group->title,
        ]);
    }

    public function add(Request $request, int $conversation): JsonResponse
    {
        $data = $request->validate(['members' => ['required', 'array', 'min:1', 'max:200'], 'members.*' => ['integer', 'min:1']]);

        $me = $this->me($request);
        $group = $this->group($me, $conversation);
        abort_unless(MessageGroups::canManage($group, $me), 403, 'You cannot change this group.');

        $added = MessageGroups::add($group, $me, $data['members']);

        return response()->json(['added' => $added, 'members' => MessageGroups::members($group)]);
    }

    public function remove(Request $request, int $conversation): JsonResponse
    {
        $data = $request->validate(['id' => ['required', 'integer', 'min:1']]);

        $me = $this->me($request);
        $group = $this->group($me, $conversation);
        abort_unless(MessageGroups::canManage($group, $me), 403, 'You cannot change this group.');

        MessageGroups::remove($group, $me, (int) $data['id']);

        return response()->json(['members' => MessageGroups::members($group)]);
    }

    public function rename(Request $request, int $conversation): JsonResponse
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:120']]);

        $me = $this->me($request);
        $group = $this->group($me, $conversation);
        abort_unless(MessageGroups::canManage($group, $me), 403, 'You cannot change this group.');

        MessageGroups::rename($group, $data['title']);

        return response()->json(['title' => $group->fresh()->title]);
    }

    public function leave(Request $request, int $conversation): JsonResponse
    {
        $me = $this->me($request);
        MessageGroups::leave($this->group($me, $conversation), $me);

        return response()->json(['ok' => true]);
    }

    /** Deletes the whole group, its messages and its files. */
    public function destroy(Request $request, int $conversation): JsonResponse
    {
        $me = $this->me($request);
        $group = $this->group($me, $conversation);
        abort_unless(MessageGroups::canManage($group, $me), 403, 'You cannot delete this group.');

        MessageGroups::destroy($group);

        return response()->json(['ok' => true]);
    }

    /** A group this person is in. A direct chat or the announcements are "not found" here. */
    private function group(MessageActor $me, int $conversationId): Conversation
    {
        $chat = $this->chat($me, $conversationId);
        abort_unless($chat->isGroup(), 404);

        return $chat;
    }
}
