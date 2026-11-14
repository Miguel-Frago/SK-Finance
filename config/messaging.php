<?php

/*
|--------------------------------------------------------------------------
| In-app messaging: files and groups
|--------------------------------------------------------------------------
|
| Set in .env if you need other numbers:
|   SK_MSG_MAX_FILE_MB=50      size of ONE file, in MB
|   SK_MSG_MAX_FILES=10        files in ONE message
|   SK_MSG_MAX_GROUP=500       people in one group
|   SK_MSG_PENDING_HOURS=24    a file uploaded but never sent is removed after this many hours
|
| IMPORTANT for 50 MB files: PHP itself must allow it. In php.ini (Laravel Herd: Herd > PHP settings, "Max file upload size")
| set  upload_max_filesize = 64M  and  post_max_size = 64M  (or more). Each file is uploaded in its own request.
*/

return [
    'max_file_mb'       => (float) env('SK_MSG_MAX_FILE_MB', 50),
    'max_files'         => (int) env('SK_MSG_MAX_FILES', 10),
    'max_group_members' => (int) env('SK_MSG_MAX_GROUP', 500),
    'pending_hours'     => (int) env('SK_MSG_PENDING_HOURS', 24),

    // Any kind of file may be sent EXCEPT programs and scripts: they could be run by whoever downloads them.
    'blocked_extensions' => [
        'exe', 'msi', 'bat', 'cmd', 'com', 'scr', 'pif', 'cpl', 'dll', 'sys', 'lnk', 'hta', 'reg', 'inf',
        'vbs', 'vbe', 'js', 'jse', 'wsf', 'wsh', 'ps1', 'psm1', 'jar', 'apk', 'sh', 'bash', 'cgi',
        'php', 'phtml', 'phar', 'asp', 'aspx', 'jsp',
    ],

    // Shown inside the chat only when BOTH the extension and the type detected from the file itself say so.
    // Everything else (PDF, Word, SVG, HTML ...) is a plain download. SVG is deliberately a download: it can carry scripts.
    'inline_images' => ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'],
    'inline_videos' => ['mp4' => 'video/mp4', 'm4v' => 'video/mp4', 'webm' => 'video/webm', 'ogv' => 'video/ogg', 'mov' => 'video/quicktime'],
];
