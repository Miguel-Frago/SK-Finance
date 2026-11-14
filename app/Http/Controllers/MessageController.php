<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesMessageActor;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Support\Messaging;
use App\Support\MessageFiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The JSON side of in-app messaging. One controller serves both sign-in systems: the barangay routes (/messages/*,
 * guard "web") and the Admin routes (/admin/messages/*, guard "admin"). Every chat is checked against its people, so
 * another person's chat is "not found". Groups are in MessageGroupController, files in MessageFileController.
 */
class MessageController extends Controller
{
    use ResolvesMessageActor;

    /** The chat list and the unread count (this is what the panel polls). */
    public function state(Request $request): JsonResponse
    {
        return response()->json(Messaging::state($this->me($request)));
    }

    /** People this person may start a chat with (or, with for=group, put into a group). */
    public function people(Request $request): JsonResponse
    {
        $request->validate(['q' => ['nullable', 'string', 'max:60'], 'for' => ['nullable', 'in:group']]);

        return response()->json([
            'people' => Messaging::people($this->me($request), (string) $request->query('q', ''), $request->query('for') === 'group'),
        ]);
    }

    /** Opens (or creates) the chat with one person. */
    public function open(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:user,admin'],
            'id'   => ['required', 'integer', 'min:1'],
        ]);

        $conversation = Messaging::openDirect($this->me($request), $data['type'], (int) $data['id']);

        if (! $conversation) {
            return response()->json(['message' => 'You cannot message this person.'], 403);
        }

        return response()->json(['id' => $conversation->id]);
    }

    /**
     * One chat: the latest page, or only newer messages (?after=, with ?since= for edits and deletes), or older ones (?before=).
     * With ?seen=1 the screen says "this chat is open in front of the person": what it returns counts as read, in the same
     * request, so it cannot get lost between two requests (for example when the person opens another page right away).
     */
    public function show(Request $request, int $conversation): JsonResponse
    {
        $request->validate([
            'after'  => ['nullable', 'integer', 'min:0'],
            'before' => ['nullable', 'integer', 'min:1'],
            'since'  => ['nullable', 'string', 'max:40'],
            'seen'   => ['nullable', 'boolean'],
        ]);

        $me = $this->me($request);
        $chat = $this->chat($me, $conversation);

        $thread = Messaging::thread(
            $chat,
            $me,
            $request->filled('after') ? (int) $request->query('after') : null,
            $request->filled('before') ? (int) $request->query('before') : null,
            $request->filled('since') ? (string) $request->query('since') : null,
        );

        if ($request->boolean('seen') && ! $request->filled('before')) {
            $newest = collect($thread['messages'])->pluck('id')->max();
            if ($newest) {
                Messaging::markRead($chat, $me, (int) $newest);
            }
        }

        return response()->json($thread + ['conversation' => Messaging::summary($chat, $me)]);
    }

    /** The earlier versions of an edited message (anybody in the chat can open them, like Messenger's "Edited"). */
    public function edits(Request $request, int $conversation, int $message): JsonResponse
    {
        $me = $this->me($request);
        $chat = $this->chat($me, $conversation);
        $row = $this->messageOf($chat, $me, $message);
        abort_if($row->deleted_at !== null, 404);

        return response()->json(['edits' => Messaging::editsOf($row), 'current' => $row->body]);
    }

    public function send(Request $request, int $conversation): JsonResponse
    {
        $data = $request->validate([
            'body'          => ['nullable', 'string', 'max:' . Messaging::MAX_BODY],
            'attachments'   => ['nullable', 'array', 'max:' . MessageFiles::maxFiles()],
            'attachments.*' => ['integer', 'min:1'],
        ]);

        $me = $this->me($request);
        $chat = $this->chat($me, $conversation);

        $body = Messaging::clean((string) ($data['body'] ?? ''));
        $files = (array) ($data['attachments'] ?? []);
        if ($body === '' && ! $files) {
            return response()->json(['message' => 'Write a message or attach a file first.', 'errors' => ['body' => ['Write a message or attach a file first.']]], 422);
        }

        if (! Messaging::canSend($chat, $me)) {
            return response()->json(['message' => $chat->isAnnouncement()
                ? 'Only the Admin can post announcements.'
                : 'This account is no longer active, so you cannot send a message.'], 403);
        }

        $message = Messaging::send($chat, $me, $body, $files);

        return response()->json(['message' => Messaging::present($message, $chat, $me)], 201);
    }

    /** Changes the text of your own latest message. */
    public function edit(Request $request, int $conversation, int $message): JsonResponse
    {
        $data = $request->validate(['body' => ['nullable', 'string', 'max:' . Messaging::MAX_BODY]]);

        $me = $this->me($request);
        $chat = $this->chat($me, $conversation);
        $row = $this->messageOf($chat, $me, $message);

        abort_unless(Messaging::isMine($row, $me), 403, 'You can only change your own messages.');

        if (! Messaging::canEdit($row, $chat, $me)) {
            return response()->json(['message' => 'Only your latest message in this chat can be edited.'], 422);
        }

        $body = Messaging::clean((string) ($data['body'] ?? ''));
        if ($body === '' && ! MessageAttachment::query()->where('message_id', $row->id)->exists()) {
            return response()->json(['message' => 'A message cannot be empty. Delete it instead.', 'errors' => ['body' => ['A message cannot be empty. Delete it instead.']]], 422);
        }

        Messaging::edit($row, $body);

        return response()->json(['message' => Messaging::present($row->fresh(), $chat, $me), 'latest_id' => Messaging::latestId($chat)]);
    }

    /** Deletes your own message (and its files). An empty "deleted" line stays in the chat. */
    public function delete(Request $request, int $conversation, int $message): JsonResponse
    {
        $me = $this->me($request);
        $chat = $this->chat($me, $conversation);
        $row = $this->messageOf($chat, $me, $message);

        abort_unless(Messaging::isMine($row, $me), 403, 'You can only delete your own messages.');

        if ($row->deleted_at === null) {
            Messaging::delete($row);
        }

        return response()->json(['message' => Messaging::present($row->fresh(), $chat, $me), 'latest_id' => Messaging::latestId($chat)]);
    }

    /** Everything up to this message has been seen. */
    public function read(Request $request, int $conversation): JsonResponse
    {
        $data = $request->validate(['message_id' => ['required', 'integer', 'min:1']]);

        $me = $this->me($request);
        Messaging::markRead($this->chat($me, $conversation), $me, (int) $data['message_id']);

        return response()->json(['ok' => true]);
    }

    /** Hide this chat from my own list (nothing is deleted; a new message brings it back). */
    public function hide(Request $request, int $conversation): JsonResponse
    {
        $me = $this->me($request);
        $chat = $this->chat($me, $conversation);
        abort_if($chat->isAnnouncement(), 422, 'The announcements cannot be hidden.');

        Messaging::hide($chat, $me);

        return response()->json(['ok' => true]);
    }

    /** A message of this chat that this person is allowed to see (a newcomer to a group cannot reach the earlier ones). */
    private function messageOf($chat, $me, int $messageId): Message
    {
        $row = Message::query()->where('conversation_id', $chat->id)->where('id', '>', Messaging::floor($chat, $me))->find($messageId);
        abort_unless($row, 404);

        return $row;
    }
}
