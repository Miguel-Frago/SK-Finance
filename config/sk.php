<?php

return [

    /*
    | The SK Federation (Admin). Approval and secret-code emails are sent from the mail account set in .env
    | (MAIL_FROM_ADDRESS); a person who replies to one of them writes to SK_ADMIN_REPLY_TO.
    | Set it in .env:   SK_ADMIN_REPLY_TO=federation@example.com
    */
    'admin_name'     => 'SK Federation of Luna',   // the sender name on emails
    'admin_reply_to' => env('SK_ADMIN_REPLY_TO'),

    /*
    | Only used ONCE, by AdminSeeder, to create the very first Admin (who must then choose their own password).
    | After that the Admin account is managed inside the app, so these can be emptied.
    */
    'admin_seed' => [
        'name'     => env('SK_ADMIN_NAME'),
        'email'    => env('SK_ADMIN_EMAIL'),
        'password' => env('SK_ADMIN_PASSWORD'),
    ],

    /*
    | How many days a secret code from the Admin works. It also works only once.
    */
    'code_days' => (int) env('SK_CODE_DAYS', 7),

    /*
    | Address the PHONE uses to reach this app when it scans the QR code on the Access ID.
    | "sk-finance.test" only works on the PC itself, so use the PC's Wi-Fi address instead, e.g.
    |   SK_QR_BASE_URL=http://192.168.1.10:8000
    | Leave it empty to fall back to APP_URL.
    */
    'qr_base_url' => env('SK_QR_BASE_URL'),

    /*
    | Receipts (attachments) on one expense. An expense can have several; these are the limits.
    | The PHP settings upload_max_filesize and post_max_size must be at least as large as these
    | (post_max_size must cover all the files sent together), or PHP refuses the upload first.
    |   SK_ATTACH_MAX_FILES=5    files per expense
    |   SK_ATTACH_MAX_MB=5       size of ONE file, in MB
    |   SK_ATTACH_TOTAL_MB=15    all the files of one expense together, in MB
    */
    'attachments' => [
        'max_files'    => (int) env('SK_ATTACH_MAX_FILES', 5),
        'max_file_mb'  => (float) env('SK_ATTACH_MAX_MB', 5),
        'max_total_mb' => (float) env('SK_ATTACH_TOTAL_MB', 15),
    ],

];
