<?php

namespace App\Support;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Files in chats. A file is uploaded on its own first (one request per file, so each one can be as big as the limit and
 * show its own progress) and is attached to the message when the message is sent.
 *
 * Safety:
 *  - files are kept on the private disk, in a folder per barangay and per person, never in public/
 *  - a file is only handed out to the people of that chat, never by a public address
 *  - what a file "is" comes from the file itself, not from the name the sender gave it
 *  - only photos and videos of known types are shown inside the chat; everything else (PDF, Word, SVG, HTML ...) is
 *    sent as a download with "nosniff", so it can never run inside the page
 *  - programs and scripts (.exe, .bat, .js, .php ...) cannot be sent at all
 */
final class MessageFiles
{
    public static function maxBytes(): int
    {
        return (int) round(((float) config('messaging.max_file_mb')) * 1024 * 1024);
    }

    public static function maxFiles(): int
    {
        return max(1, (int) config('messaging.max_files'));
    }

    public static function isBlocked(string $extension): bool
    {
        return in_array(strtolower($extension), (array) config('messaging.blocked_extensions'), true);
    }

    /** A name that is safe to show and to hand back as a download name. */
    public static function cleanName(string $name): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F\/\\\\\x{202A}-\x{202E}\x{2066}-\x{2069}]+/u', '', $name) ?? '';
        $name = trim(Str::limit($name, 150, ''), " .\t");

        return $name !== '' ? $name : 'file';
    }

    /** image | video | file. Both the extension and the detected type must agree. */
    public static function kind(string $mime, string $extension): string
    {
        $ext = strtolower($extension);

        if (($images = config('messaging.inline_images'))[$ext] ?? null) {
            return $images[$ext] === strtolower($mime) ? 'image' : 'file';
        }
        if (($videos = config('messaging.inline_videos'))[$ext] ?? null) {
            // .mov is reported as video/quicktime, .mp4 sometimes as video/mp4 or video/x-m4v
            return in_array(strtolower($mime), [$videos[$ext], 'video/mp4', 'video/x-m4v', 'video/quicktime'], true) ? 'video' : 'file';
        }

        return 'file';
    }

    /** The type of the file from its own bytes, never from the name or the type the browser claimed. */
    public static function detectMime(UploadedFile $file): string
    {
        $path = $file->getRealPath();

        if ($path && is_file($path) && class_exists(\finfo::class)) {
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
            if (is_string($mime) && $mime !== '') {
                return strtolower($mime);
            }
        }

        return 'application/octet-stream';
    }

    public static function store(UploadedFile $file, Conversation $conversation, MessageActor $me): MessageAttachment
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $mime = self::detectMime($file);
        $size = (int) $file->getSize();
        $name = self::cleanName((string) $file->getClientOriginalName());

        $folder = $me->isAdmin() ? 'messages/admin/a' . $me->id : 'messages/b' . ($me->barangayId ?? 0) . '/u' . $me->id;
        $stored = Str::lower((string) Str::ulid()) . (preg_match('/^[a-z0-9]{1,8}$/', $extension) ? '.' . $extension : '');
        $path = $file->storeAs($folder . '/' . now()->format('Y-m'), $stored, 'local');

        return MessageAttachment::query()->create([
            'conversation_id' => $conversation->id,
            'message_id'      => null,
            'uploader_type'   => $me->type,
            'uploader_id'     => $me->id,
            'path'            => $path,
            'original_name'   => $name,
            'mime'            => $mime,
            'size'            => $size,
            'kind'            => self::kind($mime, $extension),
        ]);
    }

    public static function url(MessageAttachment $attachment, MessageActor $me, bool $download = false): string
    {
        $url = url(($me->isAdmin() ? '/admin/messages/files/' : '/messages/files/') . $attachment->id);

        return $download ? $url . '?download=1' : $url;
    }

    public static function present(MessageAttachment $attachment, MessageActor $me): array
    {
        return [
            'id'           => $attachment->id,
            'name'         => $attachment->original_name,
            'size'         => (int) $attachment->size,
            'kind'         => $attachment->kind,
            'mime'         => $attachment->kind === 'file' ? 'application/octet-stream' : $attachment->mime,
            'url'          => self::url($attachment, $me),
            'download_url' => self::url($attachment, $me, true),
        ];
    }

    /** May this person get this file? Only people of that chat, and nothing of a deleted message. */
    public static function visibleTo(MessageAttachment $attachment, MessageActor $me): bool
    {
        if ($attachment->message_id === null) {
            return $attachment->uploader_type === $me->type && (int) $attachment->uploader_id === $me->id;   // not sent yet: only its uploader
        }

        $message = Message::query()->find($attachment->message_id);
        if (! $message || $message->deleted_at !== null) {
            return false;
        }

        $conversation = Messaging::conversationFor($me, (int) $message->conversation_id);

        return $conversation !== null && (int) $message->id > Messaging::floor($conversation, $me);
    }

    /** Photos and videos are shown in the page; everything else is a download. */
    public static function response(MessageAttachment $attachment, bool $download): Response
    {
        $path = Storage::disk('local')->path($attachment->path);
        abort_unless(is_file($path), 404);

        $inline = ! $download && in_array($attachment->kind, ['image', 'video'], true);
        $fallback = Str::ascii($attachment->original_name) ?: 'file';

        $headers = [
            'Content-Type'            => $inline ? $attachment->mime : 'application/octet-stream',
            'Content-Disposition'     => HeaderUtils::makeDisposition($inline ? 'inline' : 'attachment', $attachment->original_name, str_replace(['%', '/', '\\'], '_', $fallback)),
            'X-Content-Type-Options'  => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control'           => 'private, max-age=3600',
        ];

        // a file response understands "Range" requests, so a video can be skipped through
        return response()->file($path, $headers);
    }

    /** Removes the files (from the disk and the database). */
    public static function purge(iterable $attachments): void
    {
        $ids = [];
        foreach ($attachments as $attachment) {
            Storage::disk('local')->delete($attachment->path);
            $ids[] = $attachment->id;
        }
        if ($ids) {
            MessageAttachment::query()->whereIn('id', $ids)->delete();
        }
    }

    public static function purgeConversation(Conversation $conversation): void
    {
        self::purge(MessageAttachment::query()->where('conversation_id', $conversation->id)->get());
    }

    /** Files that were uploaded but never sent (the person closed the box, or removed the chip). */
    public static function purgePending(?int $hours = null): int
    {
        $old = MessageAttachment::query()->whereNull('message_id')
            ->where('created_at', '<', now()->subHours($hours ?? (int) config('messaging.pending_hours')))->get();

        self::purge($old);

        return $old->count();
    }
}
