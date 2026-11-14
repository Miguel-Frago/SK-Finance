<?php

namespace App\Support;

use App\Models\Admin;
use App\Models\Barangay;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\MessageEdit;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Everything about in-app messaging that is not a screen: who may talk to whom, the people directory, chats, the Admin's
 * announcements, sending, editing, deleting, reading, and the data the Messages box polls for.
 * Group chats are in MessageGroups, files in MessageFiles.
 *
 * Rules (decided with the owner):
 *  - The Admin can chat with anyone, and everybody can chat with the Admin.
 *  - People of the same barangay can chat with each other (Chairman, Secretary, Treasurer, Kagawads), privately or in a group.
 *  - Outside their own barangay, only a Chairman can reach somebody, and only another Chairman.
 *  - Only approved, switched-on accounts can be messaged.
 *  - Chats are private to the people in them. Nobody else, the Admin included, can read them.
 *  - The Admin's announcements go to every member of the system; only the Admin can post there.
 *  - A person can delete their own messages, and edit their own message while it is the latest one in the chat.
 */
final class Messaging
{
    public const ADMIN_LABEL  = 'SK Federation President';
    public const ADMIN_PLACE  = 'Municipality of Luna';
    public const MAX_BODY     = 2000;
    public const PAGE         = 50;     // messages loaded at a time
    public const LIST_LIMIT   = 80;     // chats shown in the list
    public const PEOPLE_LIMIT = 20;     // people shown in a search
    public const GROUP_PICK   = 100;    // people shown when choosing members of a group
    public const ANNOUNCEMENT_KEY = 'announcement';

    // ------------------------------------------------------------------ who is asking

    /** The signed-in person of one guard ("web" = barangay account, "admin" = the Admin), or null. */
    public static function actor(string $guard): ?MessageActor
    {
        $person = Auth::guard($guard)->user();

        if ($guard === 'admin') {
            return $person instanceof Admin ? MessageActor::fromAdmin($person) : null;
        }

        return $person instanceof User ? MessageActor::fromUser($person) : null;
    }

    // ------------------------------------------------------------------ who may talk to whom

    public static function canMessageUser(MessageActor $from, User $to): bool
    {
        if (! $to->canSignIn()) {
            return false;                                   // switched off or not approved yet
        }
        if ($from->is(MessageActor::USER, (int) $to->getKey())) {
            return false;                                   // not with yourself
        }
        if ($from->isAdmin()) {
            return true;                                    // the Admin can chat with anyone
        }
        if (self::sameBarangay($from, $to)) {
            return true;                                    // everybody inside their own barangay
        }

        // outside the barangay: a Chairman can reach another Chairman, nobody else
        return $from->role === User::ROLE_CHAIRMAN && ($to->role ?? User::ROLE_TREASURER) === User::ROLE_CHAIRMAN;
    }

    public static function canMessageAdmin(MessageActor $from): bool
    {
        return ! $from->isAdmin();
    }

    /** Looks the other person up and applies the rules above. */
    public static function canMessage(MessageActor $from, string $type, int $id): bool
    {
        if ($type === MessageActor::ADMIN) {
            return self::canMessageAdmin($from) && Admin::query()->whereKey($id)->exists();
        }
        if ($type === MessageActor::USER) {
            $user = User::query()->find($id);

            return $user !== null && self::canMessageUser($from, $user);
        }

        return false;
    }

    private static function sameBarangay(MessageActor $from, User $to): bool
    {
        return $from->barangayId !== null && $from->barangayId === $to->portalId();
    }

    // ------------------------------------------------------------------ the people directory

    /**
     * People this person is allowed to start a chat with (or, with $forGroup, to put into a group they create).
     * Only name, position and barangay are shown (no emails).
     * With no search text: the Admin and the people of your own barangay. The Admin has to type something.
     *
     * @return array<int, array{type:string,id:int,name:string,role:string,place:string}>
     */
    public static function people(MessageActor $me, string $search = '', bool $forGroup = false): array
    {
        $q = trim(preg_replace('/[%_\\\\]/', '', mb_substr($search, 0, 60)) ?? '');
        $out = [];
        $limit = $forGroup ? self::GROUP_PICK : self::PEOPLE_LIMIT;

        if ($forGroup && ! MessageGroups::canCreate($me)) {
            return [];
        }

        if (! $me->isAdmin() && ! $forGroup) {
            $admin = Admin::query()->orderBy('id')->first();
            if ($admin && ($q === '' || self::matches($q, [$admin->name, self::ADMIN_LABEL, self::ADMIN_PLACE, 'admin']))) {
                $out[] = MessageActor::fromAdmin($admin)->toArray();
            }
        }

        if ($q === '' && $me->isAdmin()) {
            return $out;                                    // the Admin searches by typing
        }

        $users = User::query()->with('barangay')
            ->where(fn ($w) => $w->where('status', User::STATUS_APPROVED)->orWhereNull('status'))
            ->where(fn ($w) => $w->where('is_active', true)->orWhereNull('is_active'));

        if (! $me->isAdmin()) {
            $users->where('id', '!=', $me->id);

            $bid = $me->barangayId;
            $mine = function ($w) use ($bid) {
                $w->where('barangay_id', $bid);
                if ($bid === Barangay::defaultId()) {
                    $w->orWhereNull('barangay_id');
                }
            };

            $reachChairmen = $me->role === User::ROLE_CHAIRMAN && $q !== '' && ! $forGroup;
            if ($reachChairmen) {
                // own barangay, plus the Chairmen of the other barangays
                $users->where(fn ($w) => $w->where($mine)->orWhere('role', User::ROLE_CHAIRMAN));
            } else {
                $users->where($mine);
            }
        }

        if ($q !== '') {
            $like = '%' . $q . '%';
            $roleKeys = collect(User::ROLES)->filter(fn ($label) => mb_stripos($label, $q) !== false)->keys()->all();

            $users->where(function ($w) use ($like, $roleKeys) {
                $w->where('name', 'like', $like)
                    ->orWhereHas('barangay', fn ($b) => $b->where('name', 'like', $like));
                if ($roleKeys) {
                    $w->orWhereIn('role', $roleKeys);
                }
            });
        }

        foreach ($users->orderBy('name')->limit($limit * 3)->get() as $user) {
            if (count($out) >= $limit) {
                break;
            }
            // the rules are checked again on every result; for a group the creator's own scope applies instead
            $ok = $forGroup ? MessageGroups::eligible(null, $me, $user) : self::canMessageUser($me, $user);
            if ($ok) {
                $out[] = MessageActor::fromUser($user)->toArray();
            }
        }

        return $out;
    }

    private static function matches(string $q, array $haystack): bool
    {
        foreach ($haystack as $text) {
            if (mb_stripos((string) $text, $q) !== false) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------ chats

    public static function directKey(string $typeA, int $idA, string $typeB, int $idB): string
    {
        $a = $typeA . ':' . $idA;
        $b = $typeB . ':' . $idB;

        return $a < $b ? $a . '|' . $b : $b . '|' . $a;
    }

    /** Opens (or creates) the one chat between this person and another. Null when the rules do not allow it. */
    public static function openDirect(MessageActor $me, string $type, int $id): ?Conversation
    {
        if (! self::canMessage($me, $type, $id)) {
            return null;
        }

        return DB::transaction(function () use ($me, $type, $id) {
            $conversation = Conversation::query()->firstOrCreate(
                ['direct_key' => self::directKey($me->type, $me->id, $type, $id)],
                ['type' => 'direct'],
            );

            foreach ([[$me->type, $me->id], [$type, $id]] as [$t, $i]) {
                ConversationParticipant::query()->firstOrCreate([
                    'conversation_id' => $conversation->id, 'participant_type' => $t, 'participant_id' => $i,
                ]);
            }

            self::mine($conversation->id, $me)->update(['hidden_at' => null]);

            return $conversation;
        });
    }

    /** The Admin's announcements: one channel for the whole system. Created the first time it is needed. */
    public static function announcementChannel(): Conversation
    {
        return Conversation::query()->firstOrCreate(
            ['direct_key' => self::ANNOUNCEMENT_KEY],
            ['type' => 'announcement', 'title' => 'Announcements'],
        );
    }

    /**
     * A chat this person may open, or null. Anyone else gets "not found", so nothing is revealed.
     * The announcements are open to every signed-in person; every other chat only to the people in it.
     */
    public static function conversationFor(MessageActor $me, int $conversationId): ?Conversation
    {
        $conversation = Conversation::query()->find($conversationId);
        if (! $conversation) {
            return null;
        }
        if ($conversation->isAnnouncement()) {
            return $conversation;
        }

        return self::mine($conversation->id, $me)->exists() ? $conversation : null;
    }

    /** The person's own row in a chat. */
    private static function mine(int $conversationId, MessageActor $me)
    {
        return ConversationParticipant::query()
            ->where('conversation_id', $conversationId)
            ->where('participant_type', $me->type)
            ->where('participant_id', $me->id);
    }

    /** The announcements have no member list (everybody reads them), so a row is made the first time somebody needs one. */
    private static function ensureRow(Conversation $conversation, MessageActor $me): void
    {
        ConversationParticipant::query()->firstOrCreate([
            'conversation_id' => $conversation->id, 'participant_type' => $me->type, 'participant_id' => $me->id,
        ]);
    }

    /** Messages with an id up to this one were sent before the person joined the group, so they are not theirs to see. */
    public static function floor(Conversation $conversation, MessageActor $me): int
    {
        if (! $conversation->isGroup()) {
            return 0;
        }

        return (int) self::mine($conversation->id, $me)->value('joined_after_message_id');
    }

    /** The other person of a direct chat (null when that account no longer exists). */
    public static function otherParty(Conversation $conversation, MessageActor $me): ?MessageActor
    {
        $row = self::otherRow($conversation, $me);

        return $row ? self::describe($row->participant_type, (int) $row->participant_id) : null;
    }

    private static function otherRow(Conversation $conversation, MessageActor $me): ?ConversationParticipant
    {
        return ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where(fn ($w) => $w->where('participant_type', '!=', $me->type)->orWhere('participant_id', '!=', $me->id))
            ->first();
    }

    public static function describe(string $type, int $id): ?MessageActor
    {
        if ($type === MessageActor::ADMIN) {
            $admin = Admin::query()->find($id);

            return $admin ? MessageActor::fromAdmin($admin) : null;
        }

        $user = User::query()->with('barangay')->find($id);

        return $user ? MessageActor::fromUser($user) : null;
    }

    /**
     * Can this person write in this chat right now?
     * Direct: false when the other account was switched off (the chat stays readable). Group: yes while they are a member.
     * Announcements: only the Admin.
     */
    public static function canSend(Conversation $conversation, MessageActor $me): bool
    {
        if ($conversation->isAnnouncement()) {
            return $me->isAdmin();
        }
        if ($conversation->isGroup()) {
            return self::mine($conversation->id, $me)->exists();
        }

        $row = self::otherRow($conversation, $me);

        return $row !== null && self::canMessage($me, $row->participant_type, (int) $row->participant_id);
    }

    /** How the chat is described to the screen: who or what it is, and what this person may do in it. */
    public static function summary(Conversation $conversation, MessageActor $me): array
    {
        if ($conversation->isAnnouncement()) {
            return [
                'id' => $conversation->id, 'kind' => 'announcement', 'with' => self::announcementWith(),
                'can_send' => $me->isAdmin(), 'can_manage' => false, 'members' => 0,
            ];
        }
        if ($conversation->isGroup()) {
            $count = ConversationParticipant::query()->where('conversation_id', $conversation->id)->count();

            return [
                'id' => $conversation->id, 'kind' => 'group', 'with' => self::groupWith($conversation, $count),
                'can_send' => self::canSend($conversation, $me), 'can_manage' => MessageGroups::canManage($conversation, $me),
                'members' => $count,
            ];
        }

        $other = self::otherParty($conversation, $me);

        return [
            'id' => $conversation->id, 'kind' => 'direct', 'with' => $other ? $other->toArray() : self::formerAccount(),
            'can_send' => self::canSend($conversation, $me), 'can_manage' => false, 'members' => 2,
        ];
    }

    private static function announcementWith(): array
    {
        return ['type' => 'announcement', 'id' => 0, 'name' => 'Announcements', 'role' => 'From the ' . self::ADMIN_LABEL, 'place' => ''];
    }

    public static function groupWith(Conversation $conversation, int $members): array
    {
        return [
            'type'  => 'group', 'id' => $conversation->id, 'name' => (string) $conversation->title,
            'role'  => 'Group chat', 'place' => $members . ' ' . ($members === 1 ? 'member' : 'members'),
        ];
    }

    // ------------------------------------------------------------------ writing

    /** Removes control characters and text-direction tricks, and unifies line breaks. Returns '' for invalid text. */
    public static function clean(string $body): string
    {
        if (! mb_check_encoding($body, 'UTF-8')) {
            return '';
        }

        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $body = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $body) ?? '';
        $body = preg_replace("/\n{4,}/", "\n\n\n", $body) ?? '';

        return trim($body);
    }

    /**
     * @param  array<int,int>  $attachmentIds  files already uploaded to this chat by this person (see MessageFiles)
     */
    public static function send(Conversation $conversation, MessageActor $me, string $body, array $attachmentIds = []): Message
    {
        $attachmentIds = array_values(array_unique(array_map('intval', $attachmentIds)));

        return DB::transaction(function () use ($conversation, $me, $body, $attachmentIds) {
            $message = new Message();
            $message->forceFill([
                'conversation_id' => $conversation->id,
                'sender_type'     => $me->type,
                'sender_id'       => $me->id,
                'body'            => $body,
            ])->save();

            if ($attachmentIds) {
                $claimed = MessageAttachment::query()
                    ->whereIn('id', $attachmentIds)
                    ->where('conversation_id', $conversation->id)
                    ->where('uploader_type', $me->type)
                    ->where('uploader_id', $me->id)
                    ->whereNull('message_id')
                    ->update(['message_id' => $message->id]);

                if ($claimed !== count($attachmentIds)) {
                    throw ValidationException::withMessages(['attachments' => ['Some of the files are no longer available. Please attach them again.']]);
                }
            }

            $conversation->forceFill(['last_message_id' => $message->id, 'last_message_at' => now()])->save();

            // a new message brings the chat back for everyone who had hidden it; the sender has read their own message
            ConversationParticipant::query()->where('conversation_id', $conversation->id)->update(['hidden_at' => null]);
            if ($conversation->isAnnouncement()) {
                self::ensureRow($conversation, $me);
            }
            self::mine($conversation->id, $me)->update(['last_read_message_id' => $message->id]);

            return $message;
        });
    }

    /** The newest message of the chat that has not been deleted. Only this one can be edited. */
    public static function latestId(Conversation $conversation): int
    {
        return (int) Message::query()->where('conversation_id', $conversation->id)->whereNull('deleted_at')->max('id');
    }

    public static function isMine(Message $message, MessageActor $me): bool
    {
        return $message->sender_type === $me->type && (int) $message->sender_id === $me->id;
    }

    public static function canEdit(Message $message, Conversation $conversation, MessageActor $me): bool
    {
        return $message->deleted_at === null
            && self::isMine($message, $me)
            && (int) $message->id === self::latestId($conversation)
            && self::canSend($conversation, $me);
    }

    /** Changes the text and keeps the earlier version, so everybody in the chat can open "Edited" and see what it said before. */
    public static function edit(Message $message, string $body): void
    {
        if ($body === $message->body) {
            return;                                         // nothing changed: not an edit
        }

        DB::transaction(function () use ($message, $body) {
            MessageEdit::query()->create(['message_id' => $message->id, 'body' => $message->body, 'created_at' => now()]);
            $message->forceFill(['body' => $body, 'edited_at' => now(), 'changed_at' => now()])->save();
        });
    }

    /** The earlier versions of an edited message, oldest first. @return array<int,array{body:string,at:string}> */
    public static function editsOf(Message $message): array
    {
        return MessageEdit::query()->where('message_id', $message->id)->orderBy('id')->get()
            ->map(fn (MessageEdit $e) => ['body' => (string) $e->body, 'at' => self::stamp($e->created_at, false)])->all();
    }

    /** Deletes one of your own messages: the text and the files are removed for good, an empty "deleted" line stays. */
    public static function delete(Message $message): void
    {
        $files = MessageAttachment::query()->where('message_id', $message->id)->get();

        $message->forceFill(['body' => '', 'deleted_at' => now(), 'changed_at' => now()])->save();
        MessageEdit::query()->where('message_id', $message->id)->delete();      // deleted means deleted: no earlier versions stay behind

        MessageFiles::purge($files);
    }

    // ------------------------------------------------------------------ reading

    /** Marks everything up to this message as read (never moves backwards, never past the last message). */
    public static function markRead(Conversation $conversation, MessageActor $me, int $upTo): void
    {
        $last = (int) Conversation::query()->whereKey($conversation->id)->value('last_message_id');
        $to = min($upTo, $last);

        if ($to <= 0) {
            return;
        }
        if ($conversation->isAnnouncement()) {
            self::ensureRow($conversation, $me);
        }

        self::mine($conversation->id, $me)
            ->where(fn ($w) => $w->whereNull('last_read_message_id')->orWhere('last_read_message_id', '<', $to))
            ->update(['last_read_message_id' => $to]);

        // "seen" is only ever moved by really looking at the chat (hiding a chat moves last_read_message_id, never this)
        self::mine($conversation->id, $me)
            ->where(fn ($w) => $w->whereNull('seen_message_id')->orWhere('seen_message_id', '<', $to))
            ->update(['seen_message_id' => $to, 'seen_at' => now()]);
    }

    /** "Hide this chat": it leaves the list, nothing is deleted, and a new message brings it back. */
    public static function hide(Conversation $conversation, MessageActor $me): void
    {
        $last = (int) Conversation::query()->whereKey($conversation->id)->value('last_message_id');

        self::mine($conversation->id, $me)->update(['hidden_at' => now(), 'last_read_message_id' => $last ?: null]);
    }

    // ------------------------------------------------------------------ what the panel asks for

    /** The chat list and the unread total: the one small request the panel repeats every few seconds. */
    public static function state(MessageActor $me): array
    {
        $unread = self::unreadByConversation($me);

        $rows = Conversation::query()
            ->join('conversation_participants as me', 'me.conversation_id', '=', 'conversations.id')
            ->where('me.participant_type', $me->type)
            ->where('me.participant_id', $me->id)
            ->whereNull('me.hidden_at')
            ->where('conversations.type', '!=', 'announcement')
            ->where(fn ($w) => $w->where('conversations.type', 'group')->orWhereNotNull('conversations.last_message_id'))
            ->orderByRaw('COALESCE(conversations.last_message_at, conversations.created_at) DESC')
            ->orderByDesc('conversations.id')
            ->limit(self::LIST_LIMIT)
            ->get(['conversations.*', 'me.joined_after_message_id as my_floor']);

        $ids = $rows->pluck('id')->all();
        $groupIds = $rows->where('type', 'group')->pluck('id')->all();

        $others = ConversationParticipant::query()->whereIn('conversation_id', array_diff($ids, $groupIds))->get()
            ->reject(fn ($p) => $p->participant_type === $me->type && (int) $p->participant_id === $me->id)
            ->keyBy('conversation_id');

        $counts = $groupIds ? ConversationParticipant::query()->whereIn('conversation_id', $groupIds)
            ->selectRaw('conversation_id, COUNT(*) as n')->groupBy('conversation_id')->pluck('n', 'conversation_id') : collect();

        $users = User::query()->with('barangay')
            ->whereIn('id', $others->where('participant_type', MessageActor::USER)->pluck('participant_id'))->get()->keyBy('id');
        $admins = Admin::query()
            ->whereIn('id', $others->where('participant_type', MessageActor::ADMIN)->pluck('participant_id'))->get()->keyBy('id');

        $announcement = self::announcementChannel();
        $last = Message::query()
            ->whereIn('id', $rows->pluck('last_message_id')->push($announcement->last_message_id)->filter())->get()->keyBy('id');
        $senders = self::senderNames($last->values());

        $list = [];
        foreach ($rows as $conversation) {
            $message = $last->get($conversation->last_message_id);
            if ($message && (int) $message->id <= (int) $conversation->my_floor) {
                $message = null;                            // sent before this person joined the group
            }

            if ($conversation->isGroup()) {
                $with = self::groupWith($conversation, (int) ($counts[$conversation->id] ?? 0));
            } else {
                $other = $others->get($conversation->id);
                $who = null;
                if ($other) {
                    $who = $other->participant_type === MessageActor::ADMIN
                        ? ($admins->get($other->participant_id) ? MessageActor::fromAdmin($admins->get($other->participant_id)) : null)
                        : ($users->get($other->participant_id) ? MessageActor::fromUser($users->get($other->participant_id)) : null);
                }
                $with = $who ? $who->toArray() : self::formerAccount();
            }

            $list[] = [
                'id'      => $conversation->id,
                'kind'    => $conversation->isGroup() ? 'group' : 'direct',
                'with'    => $with,
                'preview' => self::preview($message, $me, $conversation->isGroup(), $senders),
                'at'      => $message ? self::stamp($message->created_at, true) : '',
                'unread'  => (int) ($unread[$conversation->id] ?? 0),
            ];
        }

        // the Admin's announcements are always the first line, for every member of the system
        $announcementUnread = self::announcementUnread($announcement, $me);
        $lastAnnouncement = $last->get($announcement->last_message_id);
        array_unshift($list, [
            'id'      => $announcement->id,
            'kind'    => 'announcement',
            'with'    => self::announcementWith(),
            'preview' => self::preview($lastAnnouncement, $me, true, $senders),
            'at'      => $lastAnnouncement ? self::stamp($lastAnnouncement->created_at, true) : '',
            'unread'  => $announcementUnread,
        ]);

        return [
            'unread'           => array_sum($unread) + $announcementUnread,
            'conversations'    => $list,
            'can_create_group' => MessageGroups::canCreate($me),
        ];
    }

    /** @return array<int,int> conversation id => number of messages from other people not read yet (not counting announcements) */
    private static function unreadByConversation(MessageActor $me): array
    {
        return DB::table('messages')
            ->join('conversations as c', 'c.id', '=', 'messages.conversation_id')
            ->join('conversation_participants as p', function ($join) use ($me) {
                $join->on('p.conversation_id', '=', 'messages.conversation_id')
                    ->where('p.participant_type', $me->type)
                    ->where('p.participant_id', $me->id);
            })
            ->where('c.type', '!=', 'announcement')
            ->whereNull('p.hidden_at')
            ->whereNull('messages.deleted_at')
            ->whereRaw('messages.id > COALESCE(p.last_read_message_id, 0)')
            ->whereRaw('messages.id > p.joined_after_message_id')
            ->whereRaw('NOT (messages.sender_type = ? AND messages.sender_id = ?)', [$me->type, $me->id])
            ->groupBy('messages.conversation_id')
            ->selectRaw('messages.conversation_id as cid, COUNT(*) as n')
            ->pluck('n', 'cid')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    private static function announcementUnread(Conversation $announcement, MessageActor $me): int
    {
        $read = (int) self::mine($announcement->id, $me)->value('last_read_message_id');

        return Message::query()
            ->where('conversation_id', $announcement->id)
            ->whereNull('deleted_at')
            ->where('id', '>', $read)
            ->whereRaw('NOT (sender_type = ? AND sender_id = ?)', [$me->type, $me->id])
            ->count();
    }

    /**
     * The messages of one chat. No parameters = the latest page; "after" = only newer ones (the poll), plus the
     * ones edited or deleted since "since"; "before" = the page of older ones (the "earlier messages" button).
     *
     * @return array{messages: array<int,array>, changed: array<int,array>, has_more: bool, latest_id: int, server_time: string}
     */
    public static function thread(Conversation $conversation, MessageActor $me, ?int $after = null, ?int $before = null, ?string $since = null): array
    {
        $floor = self::floor($conversation, $me);
        $base = fn () => Message::query()->where('conversation_id', $conversation->id)->where('id', '>', $floor);
        $changed = collect();
        $hasMore = false;

        if ($after !== null) {
            $rows = $base()->where('id', '>', $after)->orderBy('id')->limit(200)->get();

            if ($since !== null) {
                try {
                    $moment = Carbon::parse($since)->setTimezone(config('app.timezone'));
                    $changed = $base()->where('id', '<=', $after)->whereNotNull('changed_at')->where('changed_at', '>=', $moment)->get();
                } catch (\Throwable) {
                    $changed = collect();                   // a bad date just means "nothing to catch up on"
                }
            }
        } else {
            $query = $base();
            if ($before !== null) {
                $query->where('id', '<', $before);
            }
            $rows = $query->orderByDesc('id')->limit(self::PAGE)->get()->reverse()->values();
            $hasMore = $rows->isNotEmpty() && $base()->where('id', '<', $rows->first()->id)->exists();
        }

        $present = collect(self::presentMany($rows->concat($changed)->values(), $conversation, $me))->keyBy('id');

        return [
            'messages'    => $rows->map(fn ($m) => $present[$m->id])->values()->all(),
            'changed'     => $changed->map(fn ($m) => $present[$m->id])->values()->all(),
            'has_more'    => $hasMore,
            'latest_id'   => self::latestId($conversation),
            'seen'        => self::seen($conversation, $me),
            'server_time' => now()->toIso8601String(),
        ];
    }

    /**
     * Who has seen the messages YOU sent, like Messenger: a line under the last message each person has looked at.
     *  - a private chat: "Seen 4:27 AM"
     *  - a group: "Seen by Ana, Kim and 3 others"
     *  - the Admin's announcements (shown to the Admin only): "Seen by 12 of 40"
     * You only ever see this for your own messages, and never for anybody else's.
     *
     * @return array<int,array{message_id:int,label:string,names:array<int,string>}>
     */
    public static function seen(Conversation $conversation, MessageActor $me): array
    {
        $mine = Message::query()->where('conversation_id', $conversation->id)->whereNull('deleted_at')
            ->where('sender_type', $me->type)->where('sender_id', $me->id)
            ->orderByDesc('id')->limit(200)->pluck('id')->map(fn ($id) => (int) $id)->all();   // newest first

        if (! $mine) {
            return [];
        }

        if ($conversation->isAnnouncement()) {
            if (! $me->isAdmin()) {
                return [];
            }

            $reads = ConversationParticipant::query()->where('conversation_id', $conversation->id)
                ->where('participant_type', MessageActor::USER)->whereNotNull('seen_message_id')->pluck('seen_message_id');
            $total = User::query()
                ->where(fn ($w) => $w->where('status', User::STATUS_APPROVED)->orWhereNull('status'))
                ->where(fn ($w) => $w->where('is_active', true)->orWhereNull('is_active'))->count();

            return collect(array_slice($mine, 0, self::PAGE))->sort()->values()->map(fn ($id) => [
                'message_id' => $id,
                'label'      => 'Seen by ' . $reads->filter(fn ($read) => (int) $read >= $id)->count() . ' of ' . $total,
                'names'      => [],
            ])->all();
        }

        $readers = ConversationParticipant::query()->where('conversation_id', $conversation->id)->whereNotNull('seen_message_id')
            ->get()->reject(fn ($p) => $p->participant_type === $me->type && (int) $p->participant_id === $me->id);

        // each reader goes under the LAST of my messages they have looked at
        $under = [];
        foreach ($readers as $reader) {
            foreach ($mine as $id) {
                if ($id <= (int) $reader->seen_message_id) {
                    $under[$id][] = $reader;
                    break;
                }
            }
        }
        if (! $under) {
            return [];
        }

        $users = User::query()->whereIn('id', $readers->where('participant_type', MessageActor::USER)->pluck('participant_id'))->get()->keyBy('id');
        $admins = Admin::query()->whereIn('id', $readers->where('participant_type', MessageActor::ADMIN)->pluck('participant_id'))->get()->keyBy('id');
        $nameOf = fn ($r) => (string) ($r->participant_type === MessageActor::ADMIN ? ($admins->get($r->participant_id)->name ?? 'Admin') : ($users->get($r->participant_id)->name ?? 'Someone'));

        ksort($under);
        $out = [];
        foreach ($under as $id => $list) {
            $names = array_map($nameOf, $list);
            sort($names, SORT_NATURAL | SORT_FLAG_CASE);

            if ($conversation->isGroup()) {
                $first = array_map(fn ($n) => strtok($n, ' ') ?: $n, array_slice($names, 0, 3));
                $more = count($names) - count($first);
                $label = 'Seen by ' . implode(', ', $first) . ($more > 0 ? ' and ' . $more . ($more === 1 ? ' other' : ' others') : '');
            } else {
                $label = 'Seen ' . self::stamp($list[0]->seen_at, true);
            }

            $out[] = ['message_id' => $id, 'label' => $label, 'names' => $names];
        }

        return $out;
    }

    /** @return array<int,array> */
    public static function presentMany(Collection $messages, Conversation $conversation, MessageActor $me): array
    {
        $files = MessageAttachment::query()->whereIn('message_id', $messages->pluck('id'))->orderBy('id')->get()->groupBy('message_id');
        $senders = $conversation->isDirect() ? [] : self::senderNames($messages);
        $edits = MessageEdit::query()->whereIn('message_id', $messages->pluck('id'))
            ->selectRaw('message_id, COUNT(*) as n')->groupBy('message_id')->pluck('n', 'message_id');

        return $messages->map(function (Message $m) use ($me, $files, $senders, $conversation, $edits) {
            $mine = self::isMine($m, $me);
            $deleted = $m->deleted_at !== null;

            return [
                'id'          => $m->id,
                'mine'        => $mine,
                'body'        => $deleted ? '' : $m->body,
                'at'          => self::stamp($m->created_at, false),
                'iso'         => $m->created_at->toIso8601String(),
                'edited'      => ! $deleted && $m->edited_at !== null,
                'edits'       => $deleted ? 0 : (int) ($edits[$m->id] ?? 0),
                'deleted'     => $deleted,
                'sender'      => ($mine || $conversation->isDirect()) ? null : ($senders[$m->sender_type . ':' . $m->sender_id] ?? ['name' => 'Former account', 'role' => '']),
                'attachments' => $deleted ? [] : ($files->get($m->id) ?? collect())->map(fn ($a) => MessageFiles::present($a, $me))->all(),
            ];
        })->all();
    }

    public static function present(Message $message, Conversation $conversation, MessageActor $me): array
    {
        return self::presentMany(collect([$message]), $conversation, $me)[0];
    }

    /** @return array<string,array{name:string,role:string}> "user:5" => name and position */
    private static function senderNames(Collection $messages): array
    {
        $userIds = $messages->where('sender_type', MessageActor::USER)->pluck('sender_id')->unique();
        $adminIds = $messages->where('sender_type', MessageActor::ADMIN)->pluck('sender_id')->unique();
        $map = [];

        foreach (User::query()->whereIn('id', $userIds)->get() as $user) {
            $map['user:' . $user->id] = ['name' => (string) $user->name, 'role' => $user->role_label];
        }
        foreach (Admin::query()->whereIn('id', $adminIds)->get() as $admin) {
            $map['admin:' . $admin->id] = ['name' => (string) $admin->name, 'role' => self::ADMIN_LABEL];
        }

        return $map;
    }

    /** One line for the chat list. */
    private static function preview(?Message $message, MessageActor $me, bool $withSender, array $senders): string
    {
        if (! $message) {
            return '';
        }
        if ($message->deleted_at !== null) {
            return 'Message deleted';
        }

        $text = trim(preg_replace('/\s+/u', ' ', (string) $message->body) ?? '');
        if ($text === '') {
            $files = MessageAttachment::query()->where('message_id', $message->id)->get(['kind']);
            $text = match (true) {
                $files->count() > 1                => $files->count() . ' files',
                $files->first()?->kind === 'image' => 'Photo',
                $files->first()?->kind === 'video' => 'Video',
                default                            => 'File',
            };
        }
        $text = mb_strimwidth($text, 0, 90, '…');

        if (self::isMine($message, $me)) {
            return 'You: ' . $text;
        }
        if ($withSender) {
            $name = $senders[$message->sender_type . ':' . $message->sender_id]['name'] ?? '';

            return $name !== '' ? strtok($name, ' ') . ': ' . $text : $text;
        }

        return $text;
    }

    private static function formerAccount(): array
    {
        return ['type' => 'user', 'id' => 0, 'name' => 'Former account', 'role' => '', 'place' => ''];
    }

    /** "10:42 AM" today, "Oct 24" (list) or "Oct 24, 10:42 AM" (message) before, with the year when it is not this year. */
    private static function stamp(?CarbonInterface $time, bool $forList): string
    {
        if (! $time) {
            return '';
        }

        $time = $time->copy()->timezone(config('app.timezone'));

        if ($time->isToday()) {
            return $time->format('g:i A');
        }

        $day = $time->isCurrentYear() ? $time->format('M j') : $time->format('M j, Y');

        return $forList ? $day : $day . ', ' . $time->format('g:i A');
    }
}
