<?php

use Illuminate\Support\Facades\Schedule;

// On the 1st of every month, save the month that just ended as a file for the Admin (the Admin pages also
// catch up by themselves when this does not run, so it works even without the scheduler).
Schedule::command('activity:archive')->monthlyOn(1, '00:10');

// Files that were uploaded to a chat but never sent are removed after a day.
Schedule::command('messages:purge-pending')->hourly();

// Every night: one zip with the database and all uploaded files (kept in storage/app/backups, the newest 14).
Schedule::command('sk:backup')->dailyAt('02:00')->withoutOverlapping();
