<?php

use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\MessageEdit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| "Seen" like Messenger, announcements that clear when read, and the history of an edited message
| (helpers msgUser, msgAsUser, msgAdmin, msgOpen come from MessagingTest.php; grpMake, grpSay from MessagingGroupsTest.php)
|--------------------------------------------------------------------------
*/

function seenChat(): array
{
    $a = msgUser('treasurer', 0, 'Ana Treasurer');
    $b = msgUser('chairman', 0, 'Ben Chairman');
    msgAsUser($a);

    return [$a, $b, msgOpen('user', $b->id)];
}

// ---------------------------------------------------------------- announcements that stay unread

it('marks an announcement as read by the same request that opens it (no second request needed)', function () {
    msgAdmin();
    $user = msgUser('treasurer');

    Auth::shouldUse('admin');
    $channel = (int) $this->getJson('/admin/messages/state')->json('conversations.0.id');
    $this->postJson("/admin/messages/{$channel}/send", ['body' => 'First'])->assertCreated();
    $this->postJson("/admin/messages/{$channel}/send", ['body' => 'Second'])->assertCreated();

    msgAsUser($user);
    $this->getJson('/messages/state')->assertJsonPath('unread', 2);

    // opening it WITHOUT saying it is on screen changes nothing (a background refresh must not mark things read)
    $this->getJson("/messages/{$channel}")->assertOk();
    $this->getJson('/messages/state')->assertJsonPath('unread', 2);

    // opening it with seen=1 is enough: the person can leave the page the next instant and it is still read
    $this->getJson("/messages/{$channel}?seen=1")->assertOk()->assertJsonCount(2, 'messages');
    $this->getJson('/messages/state')->assertJsonPath('unread', 0)->assertJsonPath('conversations.0.unread', 0);

    // and a later announcement is unread again, then read by the poll that carries seen=1
    Auth::shouldUse('admin');
    $third = (int) $this->postJson("/admin/messages/{$channel}/send", ['body' => 'Third'])->json('message.id');
    msgAsUser($user);
    $this->getJson('/messages/state')->assertJsonPath('unread', 1);
    $this->getJson("/messages/{$channel}?after=" . ($third - 1) . '&seen=1')->assertOk()->assertJsonCount(1, 'messages');
    $this->getJson('/messages/state')->assertJsonPath('unread', 0);
});

it('does not mark anything read when older messages are loaded', function () {
    [$a, $b, $chat] = seenChat();
    $one = (int) $this->postJson("/messages/{$chat}/send", ['body' => 'One'])->json('message.id');
    $this->postJson("/messages/{$chat}/send", ['body' => 'Two'])->assertCreated();

    msgAsUser($b);
    $this->getJson("/messages/{$chat}?before=" . ($one + 1) . '&seen=1')->assertOk();   // "earlier messages" button
    $this->getJson('/messages/state')->assertJsonPath('unread', 2);
});

it('still marks a chat read with the separate read request', function () {
    [$a, $b, $chat] = seenChat();
    $id = (int) $this->postJson("/messages/{$chat}/send", ['body' => 'Hello'])->json('message.id');

    msgAsUser($b);
    $this->postJson("/messages/{$chat}/read", ['message_id' => $id])->assertOk();
    $this->getJson('/messages/state')->assertJsonPath('unread', 0);
});

// ---------------------------------------------------------------- seen, in a private chat

it('shows the sender "Seen" under the last message the other person looked at, and only to the sender', function () {
    [$a, $b, $chat] = seenChat();
    $one = (int) $this->postJson("/messages/{$chat}/send", ['body' => 'One'])->json('message.id');
    $two = (int) $this->postJson("/messages/{$chat}/send", ['body' => 'Two'])->json('message.id');

    // nobody has looked yet
    $this->getJson("/messages/{$chat}")->assertJsonPath('seen', []);

    // Ben opens the chat
    msgAsUser($b);
    $mine = $this->getJson("/messages/{$chat}?seen=1")->assertOk()->json();
    expect($mine['seen'])->toBe([]);                                  // he has sent nothing, so there is nothing to show him

    msgAsUser($a);
    $seen = $this->getJson("/messages/{$chat}")->assertOk()->json('seen');
    expect($seen)->toHaveCount(1)->and($seen[0]['message_id'])->toBe($two)->and($seen[0]['label'])->toStartWith('Seen ');

    // a new message is not seen until he looks again, and the "Seen" stays under the one he did see
    $three = (int) $this->postJson("/messages/{$chat}/send", ['body' => 'Three'])->json('message.id');
    expect($this->getJson("/messages/{$chat}")->json('seen.0.message_id'))->toBe($two);

    msgAsUser($b);
    $this->getJson("/messages/{$chat}?after={$two}&seen=1")->assertOk();
    msgAsUser($a);
    expect($this->getJson("/messages/{$chat}")->json('seen.0.message_id'))->toBe($three);
});

it('does not tell the sender "Seen" just because the other person hid the chat or has not opened it', function () {
    [$a, $b, $chat] = seenChat();
    $this->postJson("/messages/{$chat}/send", ['body' => 'Hello'])->assertCreated();

    msgAsUser($b);
    $this->getJson("/messages/{$chat}")->assertOk();                  // loaded in the background, not on screen
    $this->postJson("/messages/{$chat}/hide")->assertOk();           // hiding clears the unread count but is not "seen"

    msgAsUser($a);
    $this->getJson("/messages/{$chat}")->assertJsonPath('seen', []);
});

it('does not count a deleted message as seen, and never shows receipts of other people\'s messages', function () {
    [$a, $b, $chat] = seenChat();
    $mine = (int) $this->postJson("/messages/{$chat}/send", ['body' => 'Mine'])->json('message.id');

    msgAsUser($b);
    $theirs = (int) $this->postJson("/messages/{$chat}/send", ['body' => 'Reply'])->json('message.id');
    $this->getJson("/messages/{$chat}?seen=1")->assertOk();

    msgAsUser($a);
    $this->getJson("/messages/{$chat}?seen=1")->assertOk();           // Ana sees Ben's reply
    msgAsUser($b);
    $seenByAna = collect($this->getJson("/messages/{$chat}")->json('seen'));
    expect($seenByAna->pluck('message_id')->all())->toBe([$theirs]);   // Ben only sees receipts for HIS message

    msgAsUser($a);
    $this->deleteJson("/messages/{$chat}/messages/{$mine}")->assertOk();
    expect(collect($this->getJson("/messages/{$chat}")->json('seen'))->pluck('message_id')->all())->not->toContain($mine);
});

// ---------------------------------------------------------------- seen, in a group

it('shows who has seen your message in a group, under the last message each person looked at', function () {
    $chair = msgUser('chairman');
    $ana   = msgUser('treasurer', 0, 'Ana Treasurer');
    $kim   = msgUser('kagawad', 0, 'Kim Kagawad');
    $sam   = msgUser('secretary', 0, 'Sam Secretary');
    $tom   = msgUser('kagawad', 0, 'Tom Kagawad');

    msgAsUser($chair);
    $group = grpMake([$ana, $kim, $sam, $tom]);
    $first = (int) grpSay($group, 'First')->json('message.id');
    $second = (int) grpSay($group, 'Second')->json('message.id');

    msgAsUser($ana);
    $this->getJson("/messages/{$group}?seen=1")->assertOk();         // Ana saw both
    msgAsUser($kim);
    $this->postJson("/messages/{$group}/read", ['message_id' => $first])->assertOk();   // Kim saw only the first
    msgAsUser($sam);
    $this->getJson("/messages/{$group}?seen=1")->assertOk();
    msgAsUser($tom);
    $this->getJson("/messages/{$group}?seen=1")->assertOk();

    msgAsUser($chair);
    $seen = collect($this->getJson("/messages/{$group}")->json('seen'))->keyBy('message_id');
    expect($seen[$second]['label'])->toBe('Seen by Ana, Sam, Tom')
        ->and($seen[$second]['names'])->toBe(['Ana Treasurer', 'Sam Secretary', 'Tom Kagawad'])
        ->and($seen[$first]['label'])->toBe('Seen by Kim');

});

it('shortens a long list of people who have seen a message', function () {
    $chair = msgUser('chairman');
    $people = collect(['Ana', 'Ben', 'Cal', 'Dee', 'Eli'])->map(fn ($n) => msgUser('kagawad', 0, "{$n} Kagawad"));

    msgAsUser($chair);
    $group = grpMake($people->all());
    grpSay($group, 'Hello all')->assertCreated();

    foreach ($people as $person) {
        msgAsUser($person);
        $this->getJson("/messages/{$group}?seen=1")->assertOk();
    }

    msgAsUser($chair);
    expect($this->getJson("/messages/{$group}")->json('seen.0.label'))->toBe('Seen by Ana, Ben, Cal and 2 others');
});

it('shows the Admin how many people have seen each announcement, and nobody else', function () {
    msgAdmin();
    $a = msgUser('treasurer');
    $b = msgUser('kagawad', 1);
    $c = msgUser('chairman', 2);

    Auth::shouldUse('admin');
    $channel = (int) $this->getJson('/admin/messages/state')->json('conversations.0.id');
    $first = (int) $this->postJson("/admin/messages/{$channel}/send", ['body' => 'First'])->json('message.id');
    $second = (int) $this->postJson("/admin/messages/{$channel}/send", ['body' => 'Second'])->json('message.id');

    msgAsUser($a);
    $this->getJson("/messages/{$channel}?seen=1")->assertOk()->assertJsonPath('seen', []);   // a member sees no receipts
    msgAsUser($b);
    $this->postJson("/messages/{$channel}/read", ['message_id' => $first])->assertOk();

    Auth::shouldUse('admin');
    $seen = collect($this->getJson("/admin/messages/{$channel}")->json('seen'))->keyBy('message_id');
    expect($seen[$first]['label'])->toBe('Seen by 2 of 3')->and($seen[$second]['label'])->toBe('Seen by 1 of 3');
});

// ---------------------------------------------------------------- edited, like Messenger

it('keeps every earlier version of an edited message and lets the people in the chat open them', function () {
    [$a, $b, $chat] = seenChat();
    $id = (int) $this->postJson("/messages/{$chat}/send", ['body' => 'Meting at 9'])->json('message.id');

    // not edited: nothing to show
    $this->getJson("/messages/{$chat}/messages/{$id}/edits")->assertOk()->assertJsonPath('edits', []);

    $this->patchJson("/messages/{$chat}/messages/{$id}", ['body' => 'Meeting at 9'])->assertOk()
        ->assertJsonPath('message.edited', true)->assertJsonPath('message.edits', 1);
    $this->patchJson("/messages/{$chat}/messages/{$id}", ['body' => 'Meeting at 10'])->assertOk()->assertJsonPath('message.edits', 2);

    msgAsUser($b);
    $thread = $this->getJson("/messages/{$chat}")->json('messages.0');
    expect($thread['body'])->toBe('Meeting at 10')->and($thread['edited'])->toBeTrue()->and($thread['edits'])->toBe(2);

    $history = $this->getJson("/messages/{$chat}/messages/{$id}/edits")->assertOk()->json();
    expect(collect($history['edits'])->pluck('body')->all())->toBe(['Meting at 9', 'Meeting at 9'])
        ->and($history['current'])->toBe('Meeting at 10')
        ->and($history['edits'][0]['at'])->not->toBe('');
});

it('does not count a save without changes as an edit', function () {
    [, , $chat] = seenChat();
    $id = (int) $this->postJson("/messages/{$chat}/send", ['body' => 'Same'])->json('message.id');

    $this->patchJson("/messages/{$chat}/messages/{$id}", ['body' => 'Same'])->assertOk()->assertJsonPath('message.edited', false)->assertJsonPath('message.edits', 0);
    expect(MessageEdit::query()->count())->toBe(0);
});

it('only shows the history to the people of the chat, and not for a deleted message', function () {
    [$a, $b, $chat] = seenChat();
    $id = (int) $this->postJson("/messages/{$chat}/send", ['body' => 'Before'])->json('message.id');
    $this->patchJson("/messages/{$chat}/messages/{$id}", ['body' => 'After'])->assertOk();

    msgAsUser(msgUser('secretary'));
    $this->getJson("/messages/{$chat}/messages/{$id}/edits")->assertNotFound();

    // not even the Admin, who is not in the chat
    msgAdmin();
    Auth::shouldUse('admin');
    $this->getJson("/admin/messages/{$chat}/messages/{$id}/edits")->assertNotFound();

    // deleting the message removes the earlier versions for good
    msgAsUser($a);
    $this->deleteJson("/messages/{$chat}/messages/{$id}")->assertOk();
    expect(MessageEdit::query()->count())->toBe(0);
    $this->getJson("/messages/{$chat}/messages/{$id}/edits")->assertNotFound();
});

it('does not give a newcomer to a group the history of messages from before they joined', function () {
    $chair = msgUser('chairman');
    $a = msgUser('treasurer');
    $late = msgUser('kagawad');

    msgAsUser($chair);
    $group = grpMake([$a]);
    $old = (int) grpSay($group, 'Old text')->json('message.id');
    $this->patchJson("/messages/{$group}/messages/{$old}", ['body' => 'Old text, corrected'])->assertOk();
    $this->postJson("/messages/{$group}/members", ['members' => [$late->id]])->assertOk();

    msgAsUser($late);
    $this->getJson("/messages/{$group}/messages/{$old}/edits")->assertNotFound();
});

it('tells the other person about an edit through the poll, with the new edit count', function () {
    [$a, $b, $chat] = seenChat();
    $id = (int) $this->postJson("/messages/{$chat}/send", ['body' => 'Seeting'])->json('message.id');

    msgAsUser($b);
    $first = $this->getJson("/messages/{$chat}?seen=1")->json();

    msgAsUser($a);
    $this->patchJson("/messages/{$chat}/messages/{$id}", ['body' => 'Meeting'])->assertOk();

    msgAsUser($b);
    $poll = $this->getJson("/messages/{$chat}?after={$id}&since=" . urlencode($first['server_time']))->assertOk()->json();
    expect($poll['changed'])->toHaveCount(1)
        ->and($poll['changed'][0]['body'])->toBe('Meeting')
        ->and($poll['changed'][0]['edited'])->toBeTrue()
        ->and($poll['changed'][0]['edits'])->toBe(1);
});
