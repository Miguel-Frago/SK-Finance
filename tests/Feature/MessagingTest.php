<?php

use App\Models\Admin;
use App\Models\Barangay;
use App\Models\Message;
use App\Models\User;
use App\Support\MessageActor;
use App\Support\Messaging;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 4B: in-app messaging (private one-to-one chats)
|--------------------------------------------------------------------------
*/

function msgBarangay(int $nth = 0): int
{
    // 0 = the default barangay (Cantoria No. 4), 1 = some other barangay, 2 = yet another one
    if ($nth === 0) {
        return Barangay::defaultId();
    }

    return (int) Barangay::query()->where('id', '!=', Barangay::defaultId())->orderBy('id')->skip($nth - 1)->value('id');
}

function msgUser(string $role, int $barangay = 0, ?string $name = null, bool $active = true): User
{
    $user = User::factory()->create([
        'name'  => $name ?? ucfirst($role) . ' ' . Str::random(5),
        'email' => Str::lower(Str::random(10)) . '@example.com',
    ]);
    $user->forceFill([
        'role'        => $role,
        'barangay_id' => msgBarangay($barangay),
        'status'      => User::STATUS_APPROVED,
        'is_active'   => $active,
    ])->save();

    return $user->fresh();
}

function msgAsUser(User $user): void
{
    Auth::shouldUse('web');
    test()->actingAs($user);
}

function msgAdmin(): Admin
{
    $admin = Admin::create(['name' => 'Federation President', 'email' => 'fed@example.com', 'password' => 'Passw0rd123']);
    test()->post('/admin/login', ['email' => 'fed@example.com', 'password' => 'Passw0rd123']);

    return $admin;
}

function msgOpen(string $type, int $id, string $prefix = '/messages'): int
{
    return (int) test()->postJson($prefix . '/direct', ['type' => $type, 'id' => $id])->assertOk()->json('id');
}

// ---------------------------------------------------------------- the happy path

it('lets a chairman find a chairman of another barangay, message them, and be read', function () {
    $a = msgUser('chairman');
    $b = msgUser('chairman', 1, 'Chairman Dela Cruz');

    msgAsUser($a);
    $found = collect($this->getJson('/messages/people?q=Dela Cruz')->assertOk()->json('people'));
    expect($found->pluck('id')->all())->toContain($b->id);
    expect($found->first())->toHaveKeys(['type', 'id', 'name', 'role', 'place'])->not->toHaveKey('email');

    $id = msgOpen('user', $b->id);
    $this->postJson("/messages/{$id}/send", ['body' => 'Good morning, Chairman'])
        ->assertCreated()->assertJsonPath('message.mine', true)->assertJsonPath('message.body', 'Good morning, Chairman');

    // the sender's own message is not "unread" for them
    $this->getJson('/messages/state')->assertJsonPath('unread', 0);

    msgAsUser($b);
    $this->getJson('/messages/state')
        ->assertJsonPath('unread', 1)
        ->assertJsonPath('conversations.0.kind', 'announcement')
        ->assertJsonPath('conversations.1.unread', 1)
        ->assertJsonPath('conversations.1.with.name', $a->name)
        ->assertJsonPath('conversations.1.preview', 'Good morning, Chairman');

    $thread = $this->getJson("/messages/{$id}")->assertOk()
        ->assertJsonPath('messages.0.mine', false)
        ->assertJsonPath('conversation.can_send', true)
        ->json();

    $this->postJson("/messages/{$id}/read", ['message_id' => $thread['messages'][0]['id']])->assertOk();
    $this->getJson('/messages/state')->assertJsonPath('unread', 0);
});

it('opens the same chat again instead of making a second one', function () {
    $a = msgUser('chairman');
    $b = msgUser('chairman', 1);

    msgAsUser($a);
    $first = msgOpen('user', $b->id);

    msgAsUser($b);
    $second = msgOpen('user', $a->id);

    expect($second)->toBe($first);
});

// ---------------------------------------------------------------- who can talk to whom

it('keeps Treasurer, Secretary and Kagawad inside their own barangay, and lets only Chairmen reach other barangays', function () {
    $kagawad    = msgUser('kagawad');                 // barangay 0
    $colleague  = msgUser('chairman');                // same barangay
    $farTreas   = msgUser('treasurer', 1);            // another barangay
    $farChair   = msgUser('chairman', 1);
    $farKagawad = msgUser('kagawad', 1);

    foreach (['treasurer', 'secretary', 'kagawad'] as $role) {
        $mine = msgUser($role, 0, ucfirst($role) . ' Inside');

        msgAsUser($mine);
        // inside the barangay: everyone, including the Chairman
        $this->postJson('/messages/direct', ['type' => 'user', 'id' => $colleague->id])->assertOk();
        // outside it: nobody, not even a Chairman
        foreach ([$farTreas, $farChair, $farKagawad] as $outsider) {
            $this->postJson('/messages/direct', ['type' => 'user', 'id' => $outsider->id])->assertForbidden();
            expect(collect($this->getJson('/messages/people?q=' . $outsider->name)->json('people'))->where('type', 'user')->pluck('id')->all())
                ->not->toContain($outsider->id);
        }
    }

    // a Chairman reaches the other Chairmen of the municipality, but not their Treasurers or Kagawads
    msgAsUser($colleague);
    $this->postJson('/messages/direct', ['type' => 'user', 'id' => $farChair->id])->assertOk();
    $this->postJson('/messages/direct', ['type' => 'user', 'id' => $farTreas->id])->assertForbidden();
    $this->postJson('/messages/direct', ['type' => 'user', 'id' => $farKagawad->id])->assertForbidden();
    // ... and nobody outside the barangay can start a chat with the Kagawad
    msgAsUser($farChair);
    $this->postJson('/messages/direct', ['type' => 'user', 'id' => $kagawad->id])->assertForbidden();
    $this->postJson('/messages/direct', ['type' => 'user', 'id' => $colleague->id])->assertOk();
});

it('shows the Admin and your own barangay first, and the other Chairmen of the municipality when a Chairman searches', function () {
    msgAdmin();
    $me    = msgUser('chairman');
    $mate  = msgUser('treasurer', 0, 'Mate Treasurer');
    $far   = msgUser('chairman', 1, 'Faraway Chairman');
    $farSecretary = msgUser('secretary', 1, 'Faraway Secretary');

    msgAsUser($me);
    $default = collect($this->getJson('/messages/people')->assertOk()->json('people'));
    $defaultUsers = $default->where('type', 'user')->pluck('id')->all();   // the Admin has an id too, so compare people only
    expect($default->first()['type'])->toBe('admin')
        ->and($defaultUsers)->toContain($mate->id)
        ->and($defaultUsers)->not->toContain($far->id)
        ->and($defaultUsers)->not->toContain($me->id);

    $search = collect($this->getJson('/messages/people?q=Faraway')->json('people'))->where('type', 'user');
    expect($search->pluck('id')->all())->toContain($far->id)->not->toContain($farSecretary->id);
});

it('does not list or allow switched-off or unapproved accounts, and stops sending to an account switched off later', function () {
    $me  = msgUser('treasurer');
    $off = msgUser('chairman', 0, 'Switched Off Person', false);
    $ok  = msgUser('chairman', 0, 'Working Person');

    msgAsUser($me);
    expect(collect($this->getJson('/messages/people?q=Person')->json('people'))->pluck('id')->all())
        ->toContain($ok->id)->not->toContain($off->id);
    $this->postJson('/messages/direct', ['type' => 'user', 'id' => $off->id])->assertForbidden();

    // a chat that already exists can still be read after the other account is switched off, but not added to
    $id = msgOpen('user', $ok->id);
    $this->postJson("/messages/{$id}/send", ['body' => 'Before'])->assertCreated();
    $ok->forceFill(['is_active' => false])->save();

    $this->getJson("/messages/{$id}")->assertOk()->assertJsonPath('conversation.can_send', false)->assertJsonCount(1, 'messages');
    $this->postJson("/messages/{$id}/send", ['body' => 'After'])->assertForbidden();
});

it('does not allow a chat with yourself or with a person that does not exist', function () {
    $me = msgUser('treasurer');
    msgAsUser($me);

    $this->postJson('/messages/direct', ['type' => 'user', 'id' => $me->id])->assertForbidden();
    $this->postJson('/messages/direct', ['type' => 'user', 'id' => 999999])->assertForbidden();
    $this->postJson('/messages/direct', ['type' => 'group', 'id' => 1])->assertStatus(422);
});

// ---------------------------------------------------------------- the Admin

it('lets everybody message the Admin and the Admin message anybody, with no reading of other chats', function () {
    $admin = msgAdmin();
    $user  = msgUser('kagawad');
    $other = msgUser('treasurer', 1);

    // the Admin has to type to find people, and can reach a Kagawad of any barangay
    expect($this->getJson('/admin/messages/people')->assertOk()->json('people'))->toBe([]);
    $found = collect($this->getJson('/admin/messages/people?q=' . $user->name)->json('people'))->pluck('id');
    expect($found->all())->toContain($user->id);

    $adminChat = msgOpen('user', $user->id, '/admin/messages');
    $this->postJson("/admin/messages/{$adminChat}/send", ['body' => 'From the Federation'])->assertCreated();

    // the Kagawad sees it, and can write back to the Admin even though the rest of the municipality is closed to them
    msgAsUser($user);
    $this->getJson('/messages/state')->assertJsonPath('unread', 1)->assertJsonPath('conversations.1.with.type', 'admin');
    $this->postJson("/messages/{$adminChat}/send", ['body' => 'Received, thank you'])->assertCreated();

    // a chat between two other people is invisible to the Admin
    msgAsUser($other);
    $mate = msgUser('chairman', 1);
    $private = msgOpen('user', $mate->id);
    $this->postJson("/messages/{$private}/send", ['body' => 'Private words'])->assertCreated();

    Auth::shouldUse('admin');
    $this->getJson("/admin/messages/{$private}")->assertNotFound();
    $this->postJson("/admin/messages/{$private}/send", ['body' => 'x'])->assertNotFound();
    expect(collect($this->getJson('/admin/messages/state')->json('conversations'))->pluck('id')->all())->not->toContain($private);

    // the Admin cannot "message the Admin"
    $this->postJson('/admin/messages/direct', ['type' => 'admin', 'id' => $admin->id])->assertForbidden();
});

// ---------------------------------------------------------------- privacy

it('answers "not found" to anybody who is not in the chat', function () {
    $a = msgUser('chairman');
    $b = msgUser('chairman', 1);
    $c = msgUser('secretary', 2);

    msgAsUser($a);
    $id = msgOpen('user', $b->id);
    $this->postJson("/messages/{$id}/send", ['body' => 'Just us'])->assertCreated();

    msgAsUser($c);
    $this->getJson("/messages/{$id}")->assertNotFound();
    $this->postJson("/messages/{$id}/send", ['body' => 'Let me in'])->assertNotFound();
    $this->postJson("/messages/{$id}/read", ['message_id' => 1])->assertNotFound();
    $this->postJson("/messages/{$id}/hide")->assertNotFound();
    $this->getJson('/messages/state')->assertJsonCount(1, 'conversations')->assertJsonPath('conversations.0.kind', 'announcement')->assertJsonPath('unread', 0);
});

it('requires a signed-in person for every messaging route', function () {
    $this->getJson('/messages/state')->assertUnauthorized();
    $this->getJson('/messages/people')->assertUnauthorized();
    $this->postJson('/messages/direct', ['type' => 'user', 'id' => 1])->assertUnauthorized();
    $this->getJson('/messages/1')->assertUnauthorized();
    $this->postJson('/messages/1/send', ['body' => 'hi'])->assertUnauthorized();
    $this->getJson('/admin/messages/state')->assertUnauthorized();
});

// ---------------------------------------------------------------- the message itself

it('rejects empty, blank and too long messages, and cleans hidden control characters', function () {
    $a = msgUser('treasurer');
    $b = msgUser('chairman', 0);

    msgAsUser($a);
    $id = msgOpen('user', $b->id);

    $this->postJson("/messages/{$id}/send", ['body' => ''])->assertStatus(422);
    $this->postJson("/messages/{$id}/send", ['body' => "   \n  "])->assertStatus(422);
    $this->postJson("/messages/{$id}/send", ['body' => str_repeat('a', Messaging::MAX_BODY + 1)])->assertStatus(422);
    $this->postJson("/messages/{$id}/send", ['body' => str_repeat('a', Messaging::MAX_BODY)])->assertCreated();

    // text-direction tricks and control characters are removed; line breaks and emoji stay
    $this->postJson("/messages/{$id}/send", ['body' => "Line one\u{202E}\u{0007}\nLine two 👍"])
        ->assertCreated()->assertJsonPath('message.body', "Line one\nLine two 👍");

    expect(Message::query()->count())->toBe(2);
});

it('stores a message as plain text exactly as written (the screen escapes it)', function () {
    $a = msgUser('treasurer');
    $b = msgUser('chairman', 0);

    msgAsUser($a);
    $id = msgOpen('user', $b->id);
    $this->postJson("/messages/{$id}/send", ['body' => '<script>alert(1)</script> https://example.com/event'])
        ->assertCreated()->assertJsonPath('message.body', '<script>alert(1)</script> https://example.com/event');
});

// ---------------------------------------------------------------- hiding, polling, paging

it('hides a chat for one person only, and a new message brings it back', function () {
    $a = msgUser('treasurer');
    $b = msgUser('chairman', 0);

    msgAsUser($a);
    $id = msgOpen('user', $b->id);
    $this->postJson("/messages/{$id}/send", ['body' => 'First'])->assertCreated();

    msgAsUser($b);
    $this->postJson("/messages/{$id}/hide")->assertOk();
    $this->getJson('/messages/state')->assertJsonCount(1, 'conversations')->assertJsonPath('conversations.0.kind', 'announcement')->assertJsonPath('unread', 0);

    // the other person still has it, and nothing was deleted
    msgAsUser($a);
    $this->getJson('/messages/state')->assertJsonCount(2, 'conversations');
    expect(Message::query()->count())->toBe(1);
    $this->postJson("/messages/{$id}/send", ['body' => 'Second'])->assertCreated();

    msgAsUser($b);
    $this->getJson('/messages/state')->assertJsonCount(2, 'conversations')->assertJsonPath('unread', 1);
    $this->getJson("/messages/{$id}")->assertJsonCount(2, 'messages');
});

it('returns only newer messages when asked for them (the poll)', function () {
    $a = msgUser('treasurer');
    $b = msgUser('chairman', 0);

    msgAsUser($a);
    $id = msgOpen('user', $b->id);
    $ids = [];
    foreach (['One', 'Two', 'Three'] as $text) {
        $ids[] = $this->postJson("/messages/{$id}/send", ['body' => $text])->json('message.id');
    }

    $this->getJson("/messages/{$id}?after={$ids[0]}")->assertOk()
        ->assertJsonCount(2, 'messages')->assertJsonPath('messages.0.body', 'Two')->assertJsonPath('has_more', false);
    $this->getJson("/messages/{$id}?after={$ids[2]}")->assertOk()->assertJsonCount(0, 'messages');
});

it('loads the latest page first and older messages on request', function () {
    $a = msgUser('treasurer');
    $b = msgUser('chairman', 0);

    msgAsUser($a);
    $id = msgOpen('user', $b->id);
    $conversation = \App\Models\Conversation::query()->findOrFail($id);
    $me = MessageActor::fromUser($a);
    foreach (range(1, 55) as $n) {
        Messaging::send($conversation, $me, "Message {$n}");
    }

    $latest = $this->getJson("/messages/{$id}")->assertOk()->assertJsonCount(Messaging::PAGE, 'messages')->assertJsonPath('has_more', true)->json();
    expect($latest['messages'][0]['body'])->toBe('Message 6')
        ->and($latest['messages'][Messaging::PAGE - 1]['body'])->toBe('Message 55');

    $older = $this->getJson("/messages/{$id}?before={$latest['messages'][0]['id']}")->assertOk()
        ->assertJsonCount(5, 'messages')->assertJsonPath('has_more', false)->json();
    expect($older['messages'][0]['body'])->toBe('Message 1');
});

it('counts unread messages per chat and never counts your own', function () {
    $a = msgUser('treasurer');
    $b = msgUser('chairman');
    $c = msgUser('secretary');

    msgAsUser($b);
    $withA = msgOpen('user', $a->id);
    foreach (['1', '2', '3'] as $t) {
        $this->postJson("/messages/{$withA}/send", ['body' => $t])->assertCreated();
    }
    msgAsUser($c);
    $withB = msgOpen('user', $a->id);
    $this->postJson("/messages/{$withB}/send", ['body' => 'hello'])->assertCreated();

    msgAsUser($a);
    $state = $this->getJson('/messages/state')->assertOk()->json();
    expect($state['unread'])->toBe(4)->and(collect($state['conversations'])->where('kind', '!=', 'announcement')->pluck('unread')->sort()->values()->all())->toBe([1, 3]);
});

// ---------------------------------------------------------------- rate limits

it('counts polling and sending separately, so regular polling never blocks a message', function () {
    $a = msgUser('treasurer');
    $b = msgUser('chairman');

    msgAsUser($a);
    $id = msgOpen('user', $b->id);

    foreach (range(1, 80) as $i) {           // far more than any shared per-minute count of the sending limit
        $this->getJson('/messages/state')->assertOk();
    }

    $this->postJson("/messages/{$id}/send", ['body' => 'Still works'])->assertCreated();
});

it('limits writing to 40 a minute for each person', function () {
    $a = msgUser('treasurer');
    $b = msgUser('chairman');

    msgAsUser($a);
    $id = msgOpen('user', $b->id);                                   // counts as 1

    foreach (range(1, 39) as $i) {
        $this->postJson("/messages/{$id}/send", ['body' => "m{$i}"])->assertCreated();
    }
    $this->postJson("/messages/{$id}/send", ['body' => 'one too many'])->assertStatus(429);

    // somebody else is not affected
    msgAsUser($b);
    $this->postJson("/messages/{$id}/send", ['body' => 'My turn'])->assertCreated();
});

// ---------------------------------------------------------------- the box on the page

it('gives every page the Messages box wired to the right address', function () {
    msgAsUser(msgUser('treasurer'));
    $this->get(route('dashboard'))->assertOk()
        ->assertSee('id="msgPanel"', false)
        ->assertSee('data-base="' . url('/messages') . '"', false)
        ->assertSee('js/messages-app.js', false);

    msgAdmin();
    Auth::shouldUse('admin');
    $this->get(route('admin.dashboard'))->assertOk()
        ->assertSee('data-base="' . url('/admin/messages') . '"', false)
        ->assertSee('js/messages-app.js', false);
});

it('gives the Messages box the file limits it checks before uploading', function () {
    msgAsUser(msgUser('treasurer'));
    $this->get(route('dashboard'))->assertOk()
        ->assertSee('data-max-mb="50"', false)
        ->assertSee('data-max-files="10"', false)
        ->assertSee('data-blocked="[&quot;exe&quot;', false)
        ->assertSee('id="msgAttach"', false)
        ->assertSee('id="msgGroupForm"', false)
        ->assertSee('id="msgMembersBody"', false);
});
