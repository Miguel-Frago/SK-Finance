<?php

namespace App\Support;

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Group chats.
 *  - The Admin can make a group of anyone in the system.
 *  - A Chairman can make a group inside their own barangay (Secretary, Treasurer, Kagawads, ...).
 *  - Secretary, Treasurer and Kagawads take part in groups; they do not create them.
 *  - Whoever may manage a group (the Admin for the Admin's groups; the Chairman of the barangay for a barangay group,
 *    so it passes on to the next Chairman by itself) can rename it, add and remove people, and delete it.
 *  - Anyone can leave a group. People added later only see the messages from the day they were added.
 */
final class MessageGroups
{
    public const TITLE_MIN = 2;
    public const TITLE_MAX = 60;

    public static function canCreate(MessageActor $me): bool
    {
        return $me->isAdmin() || $me->role === User::ROLE_CHAIRMAN;
    }

    public static function canManage(Conversation $group, MessageActor $me): bool
    {
        if (! $group->isGroup()) {
            return false;
        }
        if ($me->isAdmin()) {
            return $group->created_by_type === MessageActor::ADMIN;
        }

        return $me->role === User::ROLE_CHAIRMAN
            && $group->barangay_id !== null
            && (int) $group->barangay_id === $me->barangayId;
    }

    /**
     * May this account be put in the group? With no group yet ($group = null) the rules of whoever is creating one apply:
     * the Admin may pick anyone, a Chairman only people of their own barangay.
     */
    public static function eligible(?Conversation $group, MessageActor $me, User $user): bool
    {
        if (! $user->canSignIn()) {
            return false;
        }
        if ($group === null ? ! self::canCreate($me) : ! self::canManage($group, $me)) {
            return false;
        }

        $barangay = $group !== null
            ? ($group->barangay_id !== null ? (int) $group->barangay_id : null)
            : ($me->isAdmin() ? null : $me->barangayId);

        return $barangay === null || $user->portalId() === $barangay;
    }

    public static function cleanTitle(string $title): string
    {
        $title = Messaging::clean($title);

        return trim(preg_replace('/\s+/u', ' ', str_replace("\n", ' ', $title)) ?? '');
    }

    /** @param  array<int,int>  $userIds */
    public static function create(MessageActor $me, string $title, array $userIds): Conversation
    {
        $title = self::cleanTitle($title);
        if (mb_strlen($title) < self::TITLE_MIN || mb_strlen($title) > self::TITLE_MAX) {
            throw ValidationException::withMessages(['title' => ['Give the group a name of ' . self::TITLE_MIN . ' to ' . self::TITLE_MAX . ' letters.']]);
        }

        $users = User::query()->with('barangay')->whereIn('id', array_map('intval', $userIds))->get()
            ->reject(fn (User $u) => $me->is(MessageActor::USER, (int) $u->id))
            ->filter(fn (User $u) => self::eligible(null, $me, $u));

        if ($users->isEmpty()) {
            throw ValidationException::withMessages(['members' => ['Choose at least one person you are allowed to add.']]);
        }
        if ($users->count() + 1 > (int) config('messaging.max_group_members')) {
            throw ValidationException::withMessages(['members' => ['A group can have at most ' . config('messaging.max_group_members') . ' people.']]);
        }

        return DB::transaction(function () use ($me, $title, $users) {
            $group = Conversation::query()->create([
                'type'            => 'group',
                'title'           => $title,
                'barangay_id'     => $me->isAdmin() ? null : $me->barangayId,
                'created_by_type' => $me->type,
                'created_by_id'   => $me->id,
            ]);

            ConversationParticipant::query()->create(['conversation_id' => $group->id, 'participant_type' => $me->type, 'participant_id' => $me->id]);
            foreach ($users as $user) {
                ConversationParticipant::query()->create(['conversation_id' => $group->id, 'participant_type' => MessageActor::USER, 'participant_id' => $user->id]);
            }

            return $group;
        });
    }

    /** @return array<int,array{type:string,id:int,name:string,role:string,place:string,is_creator:bool}> */
    public static function members(Conversation $group): array
    {
        $rows = ConversationParticipant::query()->where('conversation_id', $group->id)->get();

        $users = User::query()->with('barangay')
            ->whereIn('id', $rows->where('participant_type', MessageActor::USER)->pluck('participant_id'))->get()->keyBy('id');

        $out = [];
        foreach ($rows as $row) {
            $actor = $row->participant_type === MessageActor::ADMIN
                ? Messaging::describe(MessageActor::ADMIN, (int) $row->participant_id)
                : ($users->get($row->participant_id) ? MessageActor::fromUser($users->get($row->participant_id)) : null);

            $out[] = ($actor ? $actor->toArray() : ['type' => $row->participant_type, 'id' => (int) $row->participant_id, 'name' => 'Former account', 'role' => '', 'place' => ''])
                + ['is_creator' => $row->participant_type === $group->created_by_type && (int) $row->participant_id === (int) $group->created_by_id];
        }

        usort($out, fn ($a, $b) => [$b['is_creator'], mb_strtolower($a['name'])] <=> [$a['is_creator'], mb_strtolower($b['name'])]);

        return $out;
    }

    /** @param  array<int,int>  $userIds  @return int how many were added */
    public static function add(Conversation $group, MessageActor $me, array $userIds): int
    {
        $floor = (int) $group->last_message_id;        // a newcomer sees the group from here on, not its earlier history
        $users = User::query()->with('barangay')->whereIn('id', array_map('intval', $userIds))->get()
            ->filter(fn (User $u) => self::eligible($group, $me, $u));

        $present = ConversationParticipant::query()->where('conversation_id', $group->id)->count();
        if ($present + $users->count() > (int) config('messaging.max_group_members')) {
            throw ValidationException::withMessages(['members' => ['A group can have at most ' . config('messaging.max_group_members') . ' people.']]);
        }

        $added = 0;
        foreach ($users as $user) {
            $row = ConversationParticipant::query()->firstOrCreate(
                ['conversation_id' => $group->id, 'participant_type' => MessageActor::USER, 'participant_id' => $user->id],
                ['joined_after_message_id' => $floor],
            );
            $added += $row->wasRecentlyCreated ? 1 : 0;
        }

        return $added;
    }

    /** Takes another person out of the group (use leave() for yourself). */
    public static function remove(Conversation $group, MessageActor $me, int $userId): bool
    {
        if ($me->is(MessageActor::USER, $userId)) {
            return false;
        }

        return ConversationParticipant::query()
            ->where('conversation_id', $group->id)->where('participant_type', MessageActor::USER)->where('participant_id', $userId)
            ->delete() > 0;
    }

    public static function rename(Conversation $group, string $title): void
    {
        $title = self::cleanTitle($title);
        if (mb_strlen($title) < self::TITLE_MIN || mb_strlen($title) > self::TITLE_MAX) {
            throw ValidationException::withMessages(['title' => ['Give the group a name of ' . self::TITLE_MIN . ' to ' . self::TITLE_MAX . ' letters.']]);
        }

        $group->forceFill(['title' => $title])->save();
    }

    /** Leaves the group. The last person leaving removes it. */
    public static function leave(Conversation $group, MessageActor $me): void
    {
        ConversationParticipant::query()
            ->where('conversation_id', $group->id)->where('participant_type', $me->type)->where('participant_id', $me->id)->delete();

        if (! ConversationParticipant::query()->where('conversation_id', $group->id)->exists()) {
            self::destroy($group);
        }
    }

    /** Deletes the group with all its messages and files. */
    public static function destroy(Conversation $group): void
    {
        MessageFiles::purgeConversation($group);
        $group->delete();                               // the members, messages and file records go with it
    }
}
