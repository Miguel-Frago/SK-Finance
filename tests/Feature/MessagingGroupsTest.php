<?php

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 4C: group chats, and the Admin's announcements to the whole system
|--------------------------------------------------------------------------
| (helpers msgUser, msgAsUser, msgAdmin, msgOpen come from MessagingTest.php)
*/

function grpMake(array $members, string $title = 'Quarterly planning', string $prefix = '/messages'): int
{
    return (int) test()->postJson($prefix . '/group', ['title' => $title, 'members' => collect($members)->map(fn ($u) => $u->id)->all()])
        ->assertCreated()->json('id');
}

function grpSay(int $id, string $body, string $prefix = '/messages')
{
    return test()->postJson("{$prefix}/{$id}/send", ['body' => $body]);
}

// ---------------------------------------------------------------- who may create a group

it('lets the Admin make a group of anyone in the system', function () {
    msgAdmin();
    $a = msgUser('treasurer');
    $b = msgUser('kagawad', 1);
    $c = msgUser('chairman', 2);

    $id = grpMake([$a, $b, $c], 'All officials', '/admin/messages');
    grpSay($id, 'Welcome, everybody', '/admin/messages')->assertCreated();

    foreach ([$a, $b, $c] as $member) {
        msgAsUser($member);
        $state = $this->getJson('/messages/state')->assertOk()->json();
        $group = collect($state['conversations'])->firstWhere('id', $id);
        expect($group['kind'])->toBe('group')
            ->and($group['with']['name'])->toBe('All officials')
            ->and($group['with']['place'])->toBe('4 members')
            ->and($group['unread'])->toBe(1)
            ->and($group['preview'])->toContain('Welcome, everybody');
        $this->getJson("/messages/{$id}")->assertOk()->assertJsonPath('conversation.kind', 'group')->assertJsonPath('conversation.can_send', true);
    }
});

it('lets a Chairman make a group inside their own barangay only', function () {
    $chair  = msgUser('chairman');
    $treas  = msgUser('treasurer');
    $kag    = msgUser('kagawad');
    $far    = msgUser('treasurer', 1);

    msgAsUser($chair);
    // the picker for a group only offers the barangay's own people
    $pick = collect($this->getJson('/messages/people?for=group&q=' . $far->name)->assertOk()->json('people'))->pluck('id')->all();
    expect($pick)->not->toContain($far->id);
    expect(collect($this->getJson('/messages/people?for=group')->json('people'))->pluck('id')->all())->toContain($treas->id, $kag->id);

    // somebody from another barangay is simply left out
    $id = grpMake([$treas, $kag, $far]);
    expect(ConversationParticipant::query()->where('conversation_id', $id)->count())->toBe(3);   // chairman + treasurer + kagawad

    // only an outsider: nothing to make a group of
    $this->postJson('/messages/group', ['title' => 'Nobody', 'members' => [$far->id]])->assertStatus(422);
});

it('does not let Secretary, Treasurer or Kagawad create groups', function () {
    $mate = msgUser('chairman');
    foreach (['secretary', 'treasurer', 'kagawad'] as $role) {
        msgAsUser(msgUser($role));
        $this->postJson('/messages/group', ['title' => 'My group', 'members' => [$mate->id]])->assertForbidden();
        expect($this->getJson('/messages/people?for=group')->json('people'))->toBe([]);
        expect($this->getJson('/messages/state')->json('can_create_group'))->toBeFalse();
    }
});

it('checks the name and the members of a new group', function () {
    $chair = msgUser('chairman');
    $mate = msgUser('treasurer');
    msgAsUser($chair);

    $this->postJson('/messages/group', ['title' => '', 'members' => [$mate->id]])->assertStatus(422);
    $this->postJson('/messages/group', ['title' => 'A', 'members' => [$mate->id]])->assertStatus(422);
    $this->postJson('/messages/group', ['title' => str_repeat('x', 61), 'members' => [$mate->id]])->assertStatus(422);
    $this->postJson('/messages/group', ['title' => 'Fine name', 'members' => []])->assertStatus(422);
    $this->postJson('/messages/group', ['title' => 'Fine name', 'members' => [$chair->id]])->assertStatus(422);   // only yourself
    expect(Conversation::query()->where('type', 'group')->count())->toBe(0);
});

// ---------------------------------------------------------------- talking in a group

it('shows who said what in a group, and everybody inside can reply', function () {
    $chair = msgUser('chairman');
    $treas = msgUser('treasurer', 0, 'Tess Treasurer');
    $kag   = msgUser('kagawad');

    msgAsUser($chair);
    $id = grpMake([$treas, $kag]);
    grpSay($id, 'Meeting on Friday')->assertCreated();

    msgAsUser($treas);
    grpSay($id, 'Noted, I will bring the report')->assertCreated();

    msgAsUser($kag);
    $thread = $this->getJson("/messages/{$id}")->assertOk()->json('messages');
    expect($thread)->toHaveCount(2)
        ->and($thread[0]['sender']['name'])->toBe($chair->name)
        ->and($thread[1]['sender']['name'])->toBe('Tess Treasurer')
        ->and($thread[1]['sender']['role'])->toBe('SK Treasurer');

    // the chat list shows who wrote the last line
    $item = collect($this->getJson('/messages/state')->json('conversations'))->firstWhere('id', $id);
    expect($item['preview'])->toBe('Tess: Noted, I will bring the report')->and($item['unread'])->toBe(2);
});

it('keeps a group private to its members', function () {
    $chair = msgUser('chairman');
    $mate  = msgUser('treasurer');
    $out   = msgUser('treasurer', 1);

    msgAsUser($chair);
    $id = grpMake([$mate]);
    grpSay($id, 'Private to the barangay')->assertCreated();

    msgAsUser($out);
    $this->getJson("/messages/{$id}")->assertNotFound();
    $this->getJson("/messages/{$id}/members")->assertNotFound();
    grpSay($id, 'Let me in')->assertNotFound();
    expect(collect($this->getJson('/messages/state')->json('conversations'))->pluck('id')->all())->not->toContain($id);

    // the Admin is not a member either, so the Admin cannot read it
    msgAdmin();
    Auth::shouldUse('admin');
    $this->getJson("/admin/messages/{$id}")->assertNotFound();
    $this->getJson("/admin/messages/{$id}/members")->assertNotFound();
});

// ---------------------------------------------------------------- managing a group

it('lets the Chairman of the barangay add, remove, rename and delete the group, and nobody else', function () {
    $chair = msgUser('chairman');
    $a     = msgUser('treasurer');
    $b     = msgUser('secretary');
    $c     = msgUser('kagawad');
    $far   = msgUser('treasurer', 1);

    msgAsUser($chair);
    $id = grpMake([$a, $b]);
    $this->getJson("/messages/{$id}/members")->assertOk()->assertJsonPath('can_manage', true)->assertJsonCount(3, 'members');

    $this->postJson("/messages/{$id}/members", ['members' => [$c->id, $far->id]])->assertOk()->assertJsonPath('added', 1)->assertJsonCount(4, 'members');
    $this->postJson("/messages/{$id}/members/remove", ['id' => $b->id])->assertOk()->assertJsonCount(3, 'members');
    $this->postJson("/messages/{$id}/rename", ['title' => 'Barangay council'])->assertOk()->assertJsonPath('title', 'Barangay council');
    $this->postJson("/messages/{$id}/rename", ['title' => 'x'])->assertStatus(422);

    // an ordinary member may look, not change
    msgAsUser($a);
    $this->getJson("/messages/{$id}/members")->assertOk()->assertJsonPath('can_manage', false);
    $this->postJson("/messages/{$id}/members", ['members' => [$b->id]])->assertForbidden();
    $this->postJson("/messages/{$id}/members/remove", ['id' => $c->id])->assertForbidden();
    $this->postJson("/messages/{$id}/rename", ['title' => 'Mine now'])->assertForbidden();
    $this->deleteJson("/messages/{$id}")->assertForbidden();

    // the Chairman of another barangay is not a member and cannot manage it
    msgAsUser(msgUser('chairman', 1));
    $this->postJson("/messages/{$id}/rename", ['title' => 'Hijacked'])->assertNotFound();

    // the Admin cannot manage a barangay group either
    msgAdmin();
    Auth::shouldUse('admin');
    $this->postJson("/admin/messages/{$id}/rename", ['title' => 'Hijacked'])->assertNotFound();

    msgAsUser($chair);
    $this->deleteJson("/messages/{$id}")->assertOk();
    expect(Conversation::query()->whereKey($id)->exists())->toBeFalse()
        ->and(ConversationParticipant::query()->where('conversation_id', $id)->count())->toBe(0);
});

it('lets the next Chairman take over a barangay group by themselves', function () {
    $old = msgUser('chairman');
    $a = msgUser('treasurer');

    msgAsUser($old);
    $id = grpMake([$a]);
    $old->forceFill(['is_active' => false])->save();

    $new = msgUser('chairman');
    msgAsUser($new);
    // not a member yet, so nothing to read, but they can manage the group of their barangay, including adding themselves
    $this->getJson("/messages/{$id}")->assertNotFound();
    $group = Conversation::query()->findOrFail($id);
    expect(\App\Support\MessageGroups::canManage($group, \App\Support\MessageActor::fromUser($new)))->toBeTrue();
});

it('lets only the Admin manage the Admin\'s groups', function () {
    msgAdmin();
    $a = msgUser('treasurer');
    $b = msgUser('chairman', 1);
    $c = msgUser('kagawad', 2);

    $id = grpMake([$a, $b], 'Federation team', '/admin/messages');
    $this->postJson("/admin/messages/{$id}/members", ['members' => [$c->id]])->assertOk()->assertJsonPath('added', 1);
    $this->postJson("/admin/messages/{$id}/members/remove", ['id' => $a->id])->assertOk();

    // a Chairman who is in it cannot manage it
    msgAsUser($b);
    $this->postJson("/messages/{$id}/members", ['members' => [$a->id]])->assertForbidden();
    $this->postJson("/messages/{$id}/rename", ['title' => 'Nope'])->assertForbidden();
});

it('shows a newcomer only the messages from the day they were added', function () {
    $chair = msgUser('chairman');
    $a     = msgUser('treasurer');
    $late  = msgUser('kagawad');

    msgAsUser($chair);
    $id = grpMake([$a]);
    $old = grpSay($id, 'Before you joined')->json('message.id');
    $this->postJson("/messages/{$id}/members", ['members' => [$late->id]])->assertOk();
    grpSay($id, 'After you joined')->assertCreated();

    msgAsUser($late);
    $bodies = collect($this->getJson("/messages/{$id}")->assertOk()->json('messages'))->pluck('body')->all();
    expect($bodies)->toBe(['After you joined']);
    $item = collect($this->getJson('/messages/state')->json('conversations'))->firstWhere('id', $id);
    expect($item['unread'])->toBe(1);

    // the earlier message cannot be reached by guessing its number
    $this->deleteJson("/messages/{$id}/messages/{$old}")->assertNotFound();
});

it('lets a person leave a group, and the last one leaving removes it', function () {
    $chair = msgUser('chairman');
    $a = msgUser('treasurer');

    msgAsUser($chair);
    $id = grpMake([$a]);
    grpSay($id, 'Hello')->assertCreated();

    msgAsUser($a);
    $this->postJson("/messages/{$id}/leave")->assertOk();
    $this->getJson("/messages/{$id}")->assertNotFound();

    msgAsUser($chair);
    $this->getJson("/messages/{$id}")->assertOk();                      // the others still have it
    $this->postJson("/messages/{$id}/leave")->assertOk();
    expect(Conversation::query()->whereKey($id)->exists())->toBeFalse() // nobody left: it is gone
        ->and(Message::query()->where('conversation_id', $id)->count())->toBe(0);
});

it('does not take the managing person out when "remove" names themselves', function () {
    $chair = msgUser('chairman');
    $a = msgUser('treasurer');

    msgAsUser($chair);
    $id = grpMake([$a]);
    $this->postJson("/messages/{$id}/members/remove", ['id' => $chair->id])->assertOk()->assertJsonCount(2, 'members');
});

// ---------------------------------------------------------------- the Admin's announcements

it('puts the Admin\'s announcement first in everybody\'s list, and nobody else can post there', function () {
    msgAdmin();
    $users = [msgUser('treasurer'), msgUser('kagawad', 1), msgUser('chairman', 2), msgUser('secretary')];

    // before anything is posted, every member still has the channel, empty
    msgAsUser($users[0]);
    $first = $this->getJson('/messages/state')->assertOk()->json();
    expect($first['conversations'][0]['kind'])->toBe('announcement')->and($first['unread'])->toBe(0);
    $channel = $first['conversations'][0]['id'];

    Auth::shouldUse('admin');
    $this->postJson("/admin/messages/{$channel}/send", ['body' => 'Quarterly reports are due on Friday.'])->assertCreated();

    foreach ($users as $user) {
        msgAsUser($user);
        $state = $this->getJson('/messages/state')->assertOk()->json();
        expect($state['unread'])->toBe(1)
            ->and($state['conversations'][0]['kind'])->toBe('announcement')
            ->and($state['conversations'][0]['unread'])->toBe(1)
            ->and($state['conversations'][0]['preview'])->toStartWith('Federation: Quarterly');

        $this->getJson("/messages/{$channel}")->assertOk()
            ->assertJsonPath('conversation.can_send', false)->assertJsonPath('messages.0.body', 'Quarterly reports are due on Friday.');
        grpSay($channel, 'Reply to everybody')->assertForbidden();
        $this->postJson("/messages/{$channel}/hide")->assertStatus(422);
    }

    // every person has their own read state
    msgAsUser($users[0]);
    $last = $this->getJson("/messages/{$channel}")->json('messages.0.id');
    $this->postJson("/messages/{$channel}/read", ['message_id' => $last])->assertOk();
    $this->getJson('/messages/state')->assertJsonPath('unread', 0);
    msgAsUser($users[1]);
    $this->getJson('/messages/state')->assertJsonPath('unread', 1);

    // the Admin does not count their own announcement as unread
    Auth::shouldUse('admin');
    $this->getJson('/admin/messages/state')->assertJsonPath('unread', 0)->assertJsonPath('conversations.0.kind', 'announcement');
    $this->getJson("/admin/messages/{$channel}")->assertJsonPath('conversation.can_send', true);
});
