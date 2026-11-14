<?php

use App\Models\MessageAttachment;
use App\Support\MessageFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 4C: files in chats (up to 50 MB each, several per message), shown inside the chat or downloaded
|--------------------------------------------------------------------------
*/

const FIL_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

function filPng(string $name = 'photo.png'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, base64_decode(FIL_PNG));
}

function filMp4(string $name = 'clip.mp4'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom" . str_repeat("\x00", 300));
}

function filPdf(string $name = 'report.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");
}

/** Two people of one barangay with a chat between them. */
function filChat(): array
{
    $a = msgUser('treasurer');
    $b = msgUser('chairman');
    msgAsUser($a);

    return [$a, $b, msgOpen('user', $b->id)];
}

function filUpload(int $chat, UploadedFile $file, string $prefix = '/messages')
{
    return test()->postJson("{$prefix}/{$chat}/files", ['file' => $file]);
}

beforeEach(fn () => Storage::fake('local'));

// ---------------------------------------------------------------- uploading

it('uploads one file, works out what it is from the file itself, and keeps it on the private disk', function () {
    [$a, $b, $chat] = filChat();

    $png = filUpload($chat, filPng())->assertCreated()->json('file');
    $mp4 = filUpload($chat, filMp4())->assertCreated()->json('file');
    $pdf = filUpload($chat, filPdf())->assertCreated()->json('file');

    expect($png['kind'])->toBe('image')->and($mp4['kind'])->toBe('video')->and($pdf['kind'])->toBe('file')
        ->and($png['name'])->toBe('photo.png')->and($pdf['mime'])->toBe('application/octet-stream')
        ->and($png['download_url'])->toEndWith('?download=1');

    $row = MessageAttachment::query()->findOrFail($png['id']);
    expect($row->message_id)->toBeNull()
        ->and($row->path)->toStartWith('messages/b' . $a->barangay_id . '/u' . $a->id . '/')
        ->and($row->path)->not->toContain('photo');                  // stored under a random name
    Storage::disk('local')->assertExists($row->path);
});

it('does not trust the name: a .png that is not a picture is only a download', function () {
    [, , $chat] = filChat();

    $fake = UploadedFile::fake()->createWithContent('holiday.png', '<html><script>alert(1)</script></html>');
    expect(filUpload($chat, $fake)->assertCreated()->json('file.kind'))->toBe('file');

    $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
    expect(filUpload($chat, $svg)->assertCreated()->json('file.kind'))->toBe('file');
});

it('refuses programs and scripts', function () {
    [, , $chat] = filChat();

    foreach (['setup.exe', 'run.BAT', 'x.js', 'shell.php', 'a.ps1', 'tool.jar'] as $name) {
        filUpload($chat, UploadedFile::fake()->createWithContent($name, 'x'))->assertStatus(422)->assertJsonValidationErrors('file');
    }
    expect(MessageAttachment::query()->count())->toBe(0);
});

it('allows 50 MB per file and refuses more', function () {
    expect(config('messaging.max_file_mb'))->toBe(50.0)->and(MessageFiles::maxBytes())->toBe(50 * 1024 * 1024);

    [, , $chat] = filChat();
    config(['messaging.max_file_mb' => 1]);                          // the same rule with a small number, so the test is quick

    filUpload($chat, UploadedFile::fake()->create('fits.pdf', 1024))->assertCreated();
    filUpload($chat, UploadedFile::fake()->create('big.pdf', 1025))->assertStatus(422)
        ->assertJsonPath('errors.file.0', 'This file is bigger than the limit of 1 MB.');
});

it('limits how many files wait for one message, and how many go in one message', function () {
    [, , $chat] = filChat();
    config(['messaging.max_files' => 3]);

    foreach (range(1, 3) as $i) {
        filUpload($chat, filPdf("doc{$i}.pdf"))->assertCreated();
    }
    filUpload($chat, filPdf('one-more.pdf'))->assertStatus(422);

    $this->postJson("/messages/{$chat}/send", ['body' => 'x', 'attachments' => [1, 2, 3, 4]])->assertStatus(422);
});

it('only lets people who can write in the chat upload to it', function () {
    msgAdmin();
    $user = msgUser('treasurer');
    msgAsUser($user);
    $announcements = $this->getJson('/messages/state')->json('conversations.0.id');

    filUpload($announcements, filPdf())->assertForbidden();                           // announcements: only the Admin
    Auth::shouldUse('admin');
    $file = filUpload($announcements, filPdf(), '/admin/messages')->assertCreated()->json('file');
    expect(MessageAttachment::query()->findOrFail($file['id'])->path)->toStartWith('messages/admin/a');

    $stranger = msgUser('treasurer', 1);
    msgAsUser($stranger);
    $private = msgUser('chairman', 1);
    $this->postJson("/messages/{$announcements}/send", ['body' => 'x'])->assertForbidden();
});

// ---------------------------------------------------------------- sending files

it('sends several files in one message, with or without text', function () {
    [$a, $b, $chat] = filChat();

    $ids = collect([filPng(), filMp4(), filPdf()])->map(fn ($f) => filUpload($chat, $f)->assertCreated()->json('file.id'))->all();
    $sent = $this->postJson("/messages/{$chat}/send", ['body' => '', 'attachments' => $ids])->assertCreated()->json('message');

    expect($sent['body'])->toBe('')->and($sent['attachments'])->toHaveCount(3)
        ->and(collect($sent['attachments'])->pluck('kind')->all())->toBe(['image', 'video', 'file']);

    // the preview says what it is
    msgAsUser($b);
    $item = collect($this->getJson('/messages/state')->json('conversations'))->firstWhere('id', $chat);
    expect($item['preview'])->toBe('3 files')->and($item['unread'])->toBe(1);

    $thread = $this->getJson("/messages/{$chat}")->assertOk()->json('messages');
    expect($thread[0]['attachments'])->toHaveCount(3);

    // nothing to send: no text and no files
    msgAsUser($a);
    $this->postJson("/messages/{$chat}/send", ['body' => ''])->assertStatus(422);
});

it('does not let a message claim somebody else\'s file, a file of another chat, or a file twice', function () {
    [$a, $b, $chat] = filChat();
    $mine = filUpload($chat, filPdf())->json('file.id');

    msgAsUser($b);
    $this->postJson("/messages/{$chat}/send", ['body' => 'stealing', 'attachments' => [$mine]])->assertStatus(422);

    msgAsUser($a);
    $c = msgUser('secretary');
    $other = msgOpen('user', $c->id);
    $this->postJson("/messages/{$other}/send", ['body' => 'wrong chat', 'attachments' => [$mine]])->assertStatus(422);

    $this->postJson("/messages/{$chat}/send", ['body' => 'ok', 'attachments' => [$mine]])->assertCreated();
    $this->postJson("/messages/{$chat}/send", ['body' => 'again', 'attachments' => [$mine]])->assertStatus(422);
    expect(MessageAttachment::query()->whereNotNull('message_id')->count())->toBe(1);
});

// ---------------------------------------------------------------- opening a file

it('shows photos and videos in the page, and makes everything else a download', function () {
    [$a, $b, $chat] = filChat();
    $ids = [
        'png'  => filUpload($chat, filPng())->json('file.id'),
        'mp4'  => filUpload($chat, filMp4())->json('file.id'),
        'pdf'  => filUpload($chat, filPdf('Q3 report.pdf'))->json('file.id'),
        'html' => filUpload($chat, UploadedFile::fake()->createWithContent('page.html', '<script>alert(1)</script>'))->json('file.id'),
    ];
    $this->postJson("/messages/{$chat}/send", ['body' => 'files', 'attachments' => array_values($ids)])->assertCreated();

    msgAsUser($b);

    $png = $this->get("/messages/files/{$ids['png']}")->assertOk();
    expect($png->headers->get('Content-Type'))->toBe('image/png')
        ->and($png->headers->get('Content-Disposition'))->toStartWith('inline')
        ->and($png->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($png->headers->get('Content-Security-Policy'))->toContain('sandbox');

    $mp4 = $this->get("/messages/files/{$ids['mp4']}")->assertOk();
    expect($mp4->headers->get('Content-Type'))->toBe('video/mp4')->and($mp4->headers->get('Content-Disposition'))->toStartWith('inline');

    foreach (['pdf', 'html'] as $key) {
        $res = $this->get("/messages/files/{$ids[$key]}")->assertOk();
        expect($res->headers->get('Content-Disposition'))->toStartWith('attachment')
            ->and($res->headers->get('Content-Type'))->toBe('application/octet-stream')
            ->and($res->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    }
    expect($this->get("/messages/files/{$ids['pdf']}")->headers->get('Content-Disposition'))->toContain('Q3 report.pdf');

    // "?download=1" turns even a photo into a download
    expect($this->get("/messages/files/{$ids['png']}?download=1")->headers->get('Content-Disposition'))->toStartWith('attachment');
});

it('lets a video be skipped through (range requests)', function () {
    [, $b, $chat] = filChat();
    $id = filUpload($chat, filMp4())->json('file.id');
    $this->postJson("/messages/{$chat}/send", ['body' => '', 'attachments' => [$id]])->assertCreated();

    msgAsUser($b);
    $this->get("/messages/files/{$id}", ['Range' => 'bytes=0-9'])->assertStatus(206);
});

it('hands a file only to the people of that chat', function () {
    [$a, $b, $chat] = filChat();
    $id = filUpload($chat, filPdf())->json('file.id');

    // not sent yet: only the person who uploaded it
    $this->get("/messages/files/{$id}")->assertOk();
    msgAsUser($b);
    $this->get("/messages/files/{$id}")->assertNotFound();

    msgAsUser($a);
    $this->postJson("/messages/{$chat}/send", ['body' => '', 'attachments' => [$id]])->assertCreated();

    msgAsUser($b);
    $this->get("/messages/files/{$id}")->assertOk();

    msgAsUser(msgUser('secretary'));                       // another person of the barangay, not in this chat
    $this->get("/messages/files/{$id}")->assertNotFound();

    // not even the Admin, who is not in the chat
    msgAdmin();
    Auth::shouldUse('admin');
    $this->get("/admin/messages/files/{$id}")->assertNotFound();
    expect(MessageAttachment::query()->count())->toBe(1);
});

it('needs a signed-in person to get any file', function () {
    $this->get('/messages/files/1')->assertRedirect();
    $this->getJson('/messages/files/1')->assertUnauthorized();
});

it('gives group files only to the members, and newcomers only the files sent after they joined', function () {
    $chair = msgUser('chairman');
    $a = msgUser('treasurer');
    $late = msgUser('kagawad');

    msgAsUser($chair);
    $group = grpMake([$a]);
    $before = filUpload($group, filPdf('old.pdf'))->json('file.id');
    $this->postJson("/messages/{$group}/send", ['body' => '', 'attachments' => [$before]])->assertCreated();
    $this->postJson("/messages/{$group}/members", ['members' => [$late->id]])->assertOk();
    $after = filUpload($group, filPdf('new.pdf'))->json('file.id');
    $this->postJson("/messages/{$group}/send", ['body' => '', 'attachments' => [$after]])->assertCreated();

    msgAsUser($late);
    $this->get("/messages/files/{$after}")->assertOk();
    $this->get("/messages/files/{$before}")->assertNotFound();
});

// ---------------------------------------------------------------- taking files away

it('removes the files from the disk when the message is deleted', function () {
    [$a, $b, $chat] = filChat();
    $id = filUpload($chat, filPdf())->json('file.id');
    $path = MessageAttachment::query()->findOrFail($id)->path;
    $mid = $this->postJson("/messages/{$chat}/send", ['body' => 'with file', 'attachments' => [$id]])->json('message.id');

    Storage::disk('local')->assertExists($path);
    $this->deleteJson("/messages/{$chat}/messages/{$mid}")->assertOk()->assertJsonPath('message.deleted', true)->assertJsonPath('message.attachments', []);

    Storage::disk('local')->assertMissing($path);
    expect(MessageAttachment::query()->count())->toBe(0);
    msgAsUser($b);
    $this->get("/messages/files/{$id}")->assertNotFound();
});

it('lets a person take back a file they have not sent', function () {
    [$a, $b, $chat] = filChat();
    $id = filUpload($chat, filPdf())->json('file.id');
    $path = MessageAttachment::query()->findOrFail($id)->path;

    msgAsUser($b);
    $this->deleteJson("/messages/files/{$id}")->assertNotFound();           // not theirs

    msgAsUser($a);
    $this->deleteJson("/messages/files/{$id}")->assertOk();
    Storage::disk('local')->assertMissing($path);
});

it('removes the files of a deleted group', function () {
    $chair = msgUser('chairman');
    $a = msgUser('treasurer');
    msgAsUser($chair);
    $group = grpMake([$a]);
    $id = filUpload($group, filPdf())->json('file.id');
    $path = MessageAttachment::query()->findOrFail($id)->path;
    $this->postJson("/messages/{$group}/send", ['body' => '', 'attachments' => [$id]])->assertCreated();

    $this->deleteJson("/messages/{$group}")->assertOk();
    Storage::disk('local')->assertMissing($path);
    expect(MessageAttachment::query()->count())->toBe(0);
});

it('cleans up files that were uploaded but never sent', function () {
    [, , $chat] = filChat();
    $old = filUpload($chat, filPdf('old.pdf'))->json('file.id');
    $fresh = filUpload($chat, filPdf('fresh.pdf'))->json('file.id');
    MessageAttachment::query()->whereKey($old)->update(['created_at' => now()->subHours(30)]);
    $oldPath = MessageAttachment::query()->findOrFail($old)->path;

    $this->artisan('messages:purge-pending')->assertSuccessful();

    expect(MessageAttachment::query()->whereKey($old)->exists())->toBeFalse()
        ->and(MessageAttachment::query()->whereKey($fresh)->exists())->toBeTrue();
    Storage::disk('local')->assertMissing($oldPath);
});

it('sanitises file names', function () {
    expect(MessageFiles::cleanName("../../etc/passwd"))->toBe('etcpasswd')
        ->and(MessageFiles::cleanName("a\u{202E}fdp.exe"))->toBe('afdp.exe')
        ->and(MessageFiles::cleanName('   '))->toBe('file')
        ->and(mb_strlen(MessageFiles::cleanName(str_repeat('x', 400))))->toBe(150);
});
