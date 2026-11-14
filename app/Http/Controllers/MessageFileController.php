<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesMessageActor;
use App\Models\MessageAttachment;
use App\Support\Messaging;
use App\Support\MessageFiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/** Files in chats: upload one file, show or download a file, drop a file that was not sent. */
class MessageFileController extends Controller
{
    use ResolvesMessageActor;

    /** One file per request: it is kept until the message is sent (then it belongs to the message). */
    public function upload(Request $request, int $conversation): JsonResponse
    {
        $me = $this->me($request);
        $chat = $this->chat($me, $conversation);
        abort_unless(Messaging::canSend($chat, $me), 403, 'You cannot send files in this chat.');

        $maxKb = (int) floor(MessageFiles::maxBytes() / 1024);
        $maxMb = rtrim(rtrim(number_format((float) config('messaging.max_file_mb'), 1), '0'), '.');

        $request->validate(
            ['file' => ['required', 'file', 'max:' . $maxKb]],
            [
                'file.required' => 'Choose a file to send.',
                'file.max'      => "This file is bigger than the limit of {$maxMb} MB.",
                'file.uploaded' => "The file could not be uploaded. It may be bigger than this server allows (the limit is {$maxMb} MB, and PHP must be set to allow it).",
                'file.file'     => 'The file could not be uploaded.',
            ],
        );

        $file = $request->file('file');
        $extension = (string) $file->getClientOriginalExtension();
        if (MessageFiles::isBlocked($extension)) {
            throw ValidationException::withMessages(['file' => ["Files of type .{$extension} cannot be sent, because they can run on the computer of whoever opens them."]]);
        }

        $waiting = MessageAttachment::query()->where('conversation_id', $chat->id)
            ->where('uploader_type', $me->type)->where('uploader_id', $me->id)->whereNull('message_id')->count();
        if ($waiting >= MessageFiles::maxFiles()) {
            throw ValidationException::withMessages(['file' => ['You can attach at most ' . MessageFiles::maxFiles() . ' files to one message.']]);
        }

        return response()->json(['file' => MessageFiles::present(MessageFiles::store($file, $chat, $me), $me)], 201);
    }

    /** The file itself: a photo or video is shown, anything else is downloaded. Only for the people of that chat. */
    public function show(Request $request, int $attachment): Response
    {
        $me = $this->me($request);
        $file = MessageAttachment::query()->find($attachment);
        abort_unless($file && MessageFiles::visibleTo($file, $me), 404);

        return MessageFiles::response($file, $request->boolean('download'));
    }

    /** Takes back a file that was uploaded but not sent. */
    public function discard(Request $request, int $attachment): JsonResponse
    {
        $me = $this->me($request);
        $file = MessageAttachment::query()->find($attachment);
        abort_unless($file && $file->message_id === null && $file->uploader_type === $me->type && (int) $file->uploader_id === $me->id, 404);

        MessageFiles::purge([$file]);

        return response()->json(['ok' => true]);
    }
}
