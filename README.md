# SK Finance

A web system for the **Sangguniang Kabataan (SK) of every barangay of the Municipality of Luna, La Union** (40 barangays). Each barangay keeps its budget, expenses, fund movements, journal, ledger, reports and minutes of meetings. The **SK Federation President (the Admin)** verifies who may use the system and can see what is happening, **without access to any barangay's money**. People also talk to each other inside the system.

Laravel 12 · SQLite (MySQL is possible later) · Blade · plain CSS (no frameworks, no CDNs) · Pest tests · built to run on Laravel Herd (`http://sk-finance.test`).

---

## Who can do what

| Role | Places per barangay | Finance | Reports and signatures | Minutes of meetings |
|---|---|---|---|---|
| **Admin** (SK Federation President) | none (municipality level) | **no access** | n/a | n/a |
| **SK Treasurer** | 1 | add, edit, void everything; asks for quarter locks; bank reconciliation | yes | read |
| **SK Chairman** | 1 | view; edit only while the Treasurer's switch is on; approves quarter locks | yes | read, print |
| **SK Secretary** | 1 | view only | no | **write, edit, print** |
| **SK Kagawad** | up to 7 | view only | no | read |

People join by **asking for access** (name, Gmail, barangay, position). The Admin approves, a one-time secret code is emailed (valid 7 days), the person creates the account with it, and the Admin approves once more. At the end of a term the Admin switches the old account off (its records stay) and approves the new person.

## Keeping the money records honest

- **Nothing is deleted.** An expense or a fund movement is **voided** with a reason (at least 10 letters). It stays in the books, marked and struck through (use *Show voided* on the list), with who voided it and when. It leaves every balance, ledger, journal, report and print-out automatically. Its receipts stay.
- **Quarter lock.** When a quarter has ended, the **Treasurer asks** for it to be locked and the **Chairman approves**. While locked, nothing dated in that quarter can be added, changed, moved or voided, by anyone, on any page. To open it again the Treasurer or the Chairman asks with a reason, and the **Admin decides** (*Admin → Unlock requests*). The Admin sees only the barangay, the quarter and the reason, never any money. Every step is in the activity log.
- **Bank reconciliation.** The Treasurer compares the bank statement with the books (with checks not yet cleared and deposits not yet shown). Each result is saved and cannot be changed, so the history is the evidence.

## Lost passwords

- **Forgot it yourself:** *Forgot your password?* on the sign-in page emails a link (works once, expires in one hour). The answer is the same for every address, so nobody can find out which emails have an account, and each address gets at most 3 links an hour.
- **No email access:** the **Admin** opens the person's account (*Admin → Accounts*) and gives a **temporary password**. It is shown once on screen (and can be emailed). The person is signed out everywhere and must **choose their own password** at the next sign-in; until then nothing else in the system opens.
- The Admin's own password is reset on the server: `php artisan sk:reset-admin`.

## Messaging

The **Messages** icon in the top bar opens the Messages box (private chats, group chats, announcements, files).

| Who | Can chat with |
|---|---|
| **Admin** | anyone, privately or in groups; posts announcements to everybody |
| **Chairman** | the Admin, the other Chairmen of the municipality, and the people of their own barangay; can create groups for their own barangay |
| **Secretary, Treasurer, Kagawad** | each other and their Chairman inside their own barangay (privately or in groups), and the Admin |

- **Chats are private** to the people in them. Nobody else can read them, **the Admin included**. This is why backups are never offered for download inside the app (see below).
- **Announcements:** one channel for the whole system. Only the Admin writes there; everybody reads. The Admin sees "Seen by N of M".
- **Groups:** the Admin (anyone) and each Chairman (own barangay) create them. People added later see messages only from the day they were added. Anyone can leave; the Admin, or the barangay's Chairman, manages the group.
- **Files:** up to **50 MB each**, 10 per message, any type except programs and scripts. Photos and videos show in the chat; everything else is a download. Files are kept privately and handed only to the people of that chat.
- **Messages:** you can delete your own messages and edit your own **latest** message. Edited messages show "Edited"; tap it to see the earlier versions. Senders see "Seen" when the other person has looked at a message.
- Settings are in `config/messaging.php` (and `SK_MSG_*` in `.env`).

> **For 50 MB files PHP must allow it.** In `php.ini` set `upload_max_filesize = 64M` and `post_max_size = 64M` (Laravel Herd: *Herd → PHP settings*). Check with **Admin → System check** (it reads the web server's own settings) or `php artisan sk:check`.

## Setting up

```
composer install
copy .env.example .env          (then fill in the values; keep your existing APP_KEY if you have one)
php artisan key:generate        (only for a brand-new .env)
php artisan migrate
php artisan storage:link
```

Create the first Admin: set `SK_ADMIN_NAME`, `SK_ADMIN_EMAIL` and `SK_ADMIN_PASSWORD` in `.env`, then run `php artisan db:seed --class=AdminSeeder`. The Admin chooses their own password at the first sign-in. If nobody can sign in: `php artisan sk:reset-admin`.

Open `http://sk-finance.test` (barangay sign-in), `/request-access` (ask to join) and `/admin/login` (Admin). With `MAIL_MAILER=log`, emails (including secret codes) are written to `storage/logs/laravel.log`. For real emails see the Gmail notes in `.env.example`.

### The scheduler (important)

These run by themselves **only while the scheduler runs** (`php artisan schedule:work` while developing; one cron line `* * * * * php artisan schedule:run` on a server):

| What | When |
|---|---|
| `sk:backup` (database and all uploaded files) | every night 02:00 |
| `messages:purge-pending` (files uploaded but never sent) | every hour |
| `activity:archive` (saves the finished month of the activity log) | the 1st of each month |

## Backups

```
php artisan sk:backup            make a backup now
php artisan sk:backup --list     show the backups
```

One zip in `storage/app/backups/` holding a **checked copy of the database** and **all uploaded files** (receipts, chat files, profile photos). The newest 14 are kept (`SK_BACKUP_KEEP`). `.env` (passwords) is never included.

- **Copy the zips to another computer or drive regularly.** A backup on the same disk is lost with that disk.
- They are **not downloadable from inside the app on purpose**: a backup contains every chat, and chats are private even from the Admin. Whoever has access to the server's disk can read them, so protect the server.

**Restoring** (the zip's `MANIFEST.txt` repeats this): stop the app; unzip; put `database.sqlite` at `database/database.sqlite`; copy `files/receipts-and-public` into `storage/app/public`, `files/chat-files` into `storage/app/private/messages`, `files/profile-photos` into `public/uploads`; run `php artisan migrate`; start the app. Try a restore on another computer before you need it.

## Before other people use it (checklist)

Run `php artisan sk:check` and open **Admin → System check**. Both list what to fix. In short:

- `.env`: `APP_ENV=production`, `APP_DEBUG=false`, `APP_TIMEZONE=Asia/Manila`, a real mail setup, the right `APP_URL`.
- PHP upload limits of 64M (see above), the `zip` and `fileinfo` extensions on.
- `php artisan storage:link` done; the scheduler running; at least one backup made and a copy kept elsewhere.
- No file with passwords inside the project folder (the check warns about this), and never zip or share `.env` or `database/database.sqlite` with anybody.
- HTTPS if the system is reachable beyond your own network.

## Tests

```
php artisan test
```

The suite covers finance, isolation between barangays, the Admin, access requests, seats, minutes, messaging (rules, privacy, groups, files, editing, "seen"), backups and the system check. Run it before every demo and after every update. Never run `migrate:fresh` on the real database.

## Where things are

| | |
|---|---|
| Routes | `routes/web.php`, scheduler in `routes/console.php`, messaging addresses in `app/Support/MessageRoutes.php` |
| Barangay side | `app/Http/Controllers/*` and `resources/views/*` (layout: `layouts/app.blade.php`) |
| Admin side | `app/Http/Controllers/Admin/*` and `resources/views/admin/*` (layout: `layouts/admin.blade.php`) |
| Messaging | `app/Support/Messaging.php` (rules), `MessageGroups.php`, `MessageFiles.php`, `app/Http/Controllers/Message*Controller.php`, `public/js/messages-app.js` |
| Backups and checks | `app/Console/Commands/BackupSystem.php`, `app/Support/SystemCheck.php` |
| Styles | `public/css/custom.css` (everything), `admin.css`, `print-report.css` |
| Tests | `tests/Feature/*` |

More notes (phones on the same Wi-Fi, QR sign-in): `SK-Finance-Network-Notes.md` (the parts about a "Viewer" are out of date: that role was replaced by Secretary and Kagawad).
