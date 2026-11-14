<?php

use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 4C: edit the latest message, delete your own messages
|--------------------------------------------------------------------------
*/

function edtChat(): array
{
    $a = msgUser('treasurer');
    $b = msgUser('chairman');
    msgAsUser($a);

    return [$a, $b, msgOpen('user', $b->id)];
}

function edtSay(int $chat, string $body): int
{
    return (int) test()->postJson("/messages/{$chat}/send", ['body' => $body])->assertCreated()->json('message.id');
}

// ---------------------------------------------------------------- editing

it('lets you edit your latest message, and marks it as edited', function () {
    [$a, $b, $chat] = edtChat();
    edtSay($chat, 'First');
    $last = edtSay($chat, 'Seeting at 9am');

    $this->patchJson("/messages/{$chat}/messages/{$last}", ['body' => 'Meeting at 9am'])->assertOk()
        ->assertJsonPath('message.body', 'Meeting at 9am')->assertJsonPath('message.edited', true)->assertJsonPath('latest_id', $last);

    msgAsUser($b);
    $thread = $this->getJson("/messages/{$chat}")->json();
    expect(collect($thread['messages'])->pluck('body')->all())->toBe(['First', 'Meeting at 9am'])
        ->and($thread['messages'][1]['edited'])->toBeTrue()->and($thread['messages'][0]['edited'])->toBeFalse()
        ->and($thread['latest_id'])->toBe($last);
});

it('only lets you edit the latest message of the chat', function () {
    [$a, $b, $chat] = edtChat();
    $first = edtSay($chat, 'First');
    $second = edtSay($chat, 'Second');

    // an older one of yours
    $this->patchJson("/messages/{$chat}/messages/{$first}", ['body' => 'Changed'])->assertStatus(422);

    // once the other person has replied, yours is no longer the latest
    msgAsUser($b);
    $reply = edtSay($chat, 'Reply');
    msgAsUser($a);
    $this->patchJson("/messages/{$chat}/messages/{$second}", ['body' => 'Changed'])->assertStatus(422);

    // and you can never edit what somebody else wrote
    $this->patchJson("/messages/{$chat}/messages/{$reply}", ['body' => 'Changed'])->assertForbidden();
    expect(Message::query()->find($second)->body)->toBe('Second')->and(Message::query()->find($reply)->body)->toBe('Reply');
});

it('makes the message before editable again when the latest one is deleted', function () {
    [$a, , $chat] = edtChat();
    $first = edtSay($chat, 'First');
    $second = edtSay($chat, 'Second');

    $this->patchJson("/messages/{$chat}/messages/{$first}", ['body' => 'x'])->assertStatus(422);
    $this->deleteJson("/messages/{$chat}/messages/{$second}")->assertOk()->assertJsonPath('latest_id', $first);
    $this->patchJson("/messages/{$chat}/messages/{$first}", ['body' => 'First, corrected'])->assertOk();
});

it('checks an edited text like a new one', function () {
    [$a, , $chat] = edtChat();
    $id = edtSay($chat, 'Hello');

    $this->patchJson("/messages/{$chat}/messages/{$id}", ['body' => ''])->assertStatus(422);
    $this->patchJson("/messages/{$chat}/messages/{$id}", ['body' => '   '])->assertStatus(422);
    $this->patchJson("/messages/{$chat}/messages/{$id}", ['body' => str_repeat('a', 2001)])->assertStatus(422);
    $this->patchJson("/messages/{$chat}/messages/{$id}", ['body' => "Clean\u{202E}\u{0007} text"])->assertOk()->assertJsonPath('message.body', 'Clean text');
    $this->patchJson("/messages/{$chat}/messages/99999", ['body' => 'x'])->assertNotFound();
});

it('does not let anyone edit a message in a chat they are not in', function () {
    [, , $chat] = edtChat();
    $id = edtSay($chat, 'Mine');

    msgAsUser(msgUser('secretary'));
    $this->patchJson("/messages/{$chat}/messages/{$id}", ['body' => 'Hacked'])->assertNotFound();
    $this->deleteJson("/messages/{$chat}/messages/{$id}")->assertNotFound();
    expect(Message::query()->find($id)->body)->toBe('Mine');
});

// ---------------------------------------------------------------- deleting

it('lets you delete your own message and leaves an empty "deleted" line for both', function () {
    [$a, $b, $chat] = edtChat();
    edtSay($chat, 'Keep this');
    $id = edtSay($chat, 'Oops, wrong chat');

    $this->deleteJson("/messages/{$chat}/messages/{$id}")->assertOk()
        ->assertJsonPath('message.deleted', true)->assertJsonPath('message.body', '');
    // deleting twice changes nothing
    $this->deleteJson("/messages/{$chat}/messages/{$id}")->assertOk()->assertJsonPath('message.deleted', true);

    foreach ([$a, $b] as $person) {
        msgAsUser($person);
        $messages = $this->getJson("/messages/{$chat}")->json('messages');
        expect($messages)->toHaveCount(2)->and($messages[1]['deleted'])->toBeTrue()->and($messages[1]['body'])->toBe('');
    }

    // the words are gone from the database, not just hidden
    expect(Message::query()->find($id)->body)->toBe('')->and(Message::query()->find($id)->deleted_at)->not->toBeNull();
});

it('does not let you delete what somebody else wrote', function () {
    [$a, $b, $chat] = edtChat();
    $id = edtSay($chat, 'Mine');

    msgAsUser($b);
    $this->deleteJson("/messages/{$chat}/messages/{$id}")->assertForbidden();
    expect(Message::query()->find($id)->deleted_at)->toBeNull();
});

it('does not count a deleted message as unread, and says so in the list', function () {
    [$a, $b, $chat] = edtChat();
    $id = edtSay($chat, 'Sent by mistake');

    msgAsUser($b);
    $this->getJson('/messages/state')->assertJsonPath('unread', 1);

    msgAsUser($a);
    $this->deleteJson("/messages/{$chat}/messages/{$id}")->assertOk();

    msgAsUser($b);
    $state = $this->getJson('/messages/state')->assertJsonPath('unread', 0)->json();
    expect(collect($state['conversations'])->firstWhere('id', $chat)['preview'])->toBe('Message deleted');
});

it('still lets you delete your own message after the other account was switched off', function () {
    [$a, $b, $chat] = edtChat();
    $id = edtSay($chat, 'Before');
    $b->forceFill(['is_active' => false])->save();

    $this->patchJson("/messages/{$chat}/messages/{$id}", ['body' => 'Edit'])->assertStatus(422);   // can't write there any more
    $this->deleteJson("/messages/{$chat}/messages/{$id}")->assertOk();
});

// ---------------------------------------------------------------- an open chat catches up

it('tells an open chat about edits and deletes made since it last looked', function () {
    [$a, $b, $chat] = edtChat();
    $one = edtSay($chat, 'One');
    $two = edtSay($chat, 'Two');

    msgAsUser($b);
    $first = $this->getJson("/messages/{$chat}")->json();
    $since = $first['server_time'];
    $lastId = collect($first['messages'])->last()['id'];

    // nothing happened yet
    $this->getJson("/messages/{$chat}?after={$lastId}&since=" . urlencode($since))->assertOk()
        ->assertJsonCount(0, 'messages')->assertJsonCount(0, 'changed');

    msgAsUser($a);
    $this->patchJson("/messages/{$chat}/messages/{$two}", ['body' => 'Two (edited)'])->assertOk();

    msgAsUser($b);
    $poll = $this->getJson("/messages/{$chat}?after={$lastId}&since=" . urlencode($since))->assertOk()->json();
    expect($poll['changed'])->toHaveCount(1)->and($poll['changed'][0]['body'])->toBe('Two (edited)')->and($poll['changed'][0]['edited'])->toBeTrue();

    msgAsUser($a);
    $this->deleteJson("/messages/{$chat}/messages/{$one}")->assertOk();

    msgAsUser($b);
    $poll = $this->getJson("/messages/{$chat}?after={$lastId}&since=" . urlencode($since))->assertOk()->json();
    expect(collect($poll['changed'])->firstWhere('id', $one)['deleted'])->toBeTrue();

    // a bad date is just ignored
    $this->getJson("/messages/{$chat}?after={$lastId}&since=not-a-date")->assertOk()->assertJsonCount(0, 'changed');
});

// ---------------------------------------------------------------- groups and announcements

it('lets members edit and delete their own messages in a group', function () {
    $chair = msgUser('chairman');
    $a = msgUser('treasurer');

    msgAsUser($chair);
    $group = grpMake([$a]);
    $id = (int) grpSay($group, 'Meeting at 9')->json('message.id');

    msgAsUser($a);
    $this->patchJson("/messages/{$group}/messages/{$id}", ['body' => 'Hacked'])->assertForbidden();
    $mine = (int) grpSay($group, 'Seen')->json('message.id');
    $this->patchJson("/messages/{$group}/messages/{$mine}", ['body' => 'Seen it'])->assertOk();
    $this->deleteJson("/messages/{$group}/messages/{$mine}")->assertOk();
});

it('lets only the Admin edit or delete an announcement', function () {
    msgAdmin();
    $user = msgUser('treasurer');

    Auth::shouldUse('admin');
    $channel = (int) $this->getJson('/admin/messages/state')->json('conversations.0.id');
    $id = (int) $this->postJson("/admin/messages/{$channel}/send", ['body' => 'Due Friday'])->assertCreated()->json('message.id');
    $this->patchJson("/admin/messages/{$channel}/messages/{$id}", ['body' => 'Due Monday'])->assertOk()->assertJsonPath('message.edited', true);

    msgAsUser($user);
    $this->patchJson("/messages/{$channel}/messages/{$id}", ['body' => 'Due never'])->assertForbidden();
    $this->deleteJson("/messages/{$channel}/messages/{$id}")->assertForbidden();
    $this->getJson("/messages/{$channel}")->assertJsonPath('messages.0.body', 'Due Monday');

    Auth::shouldUse('admin');
    $this->deleteJson("/admin/messages/{$channel}/messages/{$id}")->assertOk();
    msgAsUser($user);
    $this->getJson('/messages/state')->assertJsonPath('unread', 0);
});
