<?php

namespace App\Support;

use App\Http\Controllers\MessageController;
use App\Http\Controllers\MessageFileController;
use App\Http\Controllers\MessageGroupController;
use Illuminate\Support\Facades\Route;

/**
 * The messaging addresses. Registered twice from routes/web.php: inside the barangay group (/messages/*, names messages.*)
 * and inside the Admin group (/admin/messages/*, names admin.messages.*). The controllers pick the sign-in system from
 * the route name, never from anything the browser sends.
 */
final class MessageRoutes
{
    public static function register(): void
    {
        // --- lists and people (the box polls these) ---
        Route::get('/messages/state', [MessageController::class, 'state'])->middleware('throttle:messages-read')->name('messages.state');
        Route::get('/messages/people', [MessageController::class, 'people'])->middleware('throttle:messages-read')->name('messages.people');
        Route::post('/messages/direct', [MessageController::class, 'open'])->middleware('throttle:messages-write')->name('messages.open');

        // --- groups ---
        Route::post('/messages/group', [MessageGroupController::class, 'create'])->middleware('throttle:messages-write')->name('messages.group.create');

        // --- files (before /messages/{conversation}) ---
        Route::get('/messages/files/{attachment}', [MessageFileController::class, 'show'])->whereNumber('attachment')->middleware('throttle:messages-files')->name('messages.files.show');
        Route::delete('/messages/files/{attachment}', [MessageFileController::class, 'discard'])->whereNumber('attachment')->middleware('throttle:messages-write')->name('messages.files.discard');

        // --- one chat ---
        Route::get('/messages/{conversation}', [MessageController::class, 'show'])->whereNumber('conversation')->middleware('throttle:messages-read')->name('messages.show');
        Route::delete('/messages/{conversation}', [MessageGroupController::class, 'destroy'])->whereNumber('conversation')->middleware('throttle:messages-write')->name('messages.group.destroy');
        Route::post('/messages/{conversation}/send', [MessageController::class, 'send'])->whereNumber('conversation')->middleware('throttle:messages-write')->name('messages.send');
        Route::post('/messages/{conversation}/files', [MessageFileController::class, 'upload'])->whereNumber('conversation')->middleware('throttle:messages-upload')->name('messages.files.upload');
        Route::post('/messages/{conversation}/read', [MessageController::class, 'read'])->whereNumber('conversation')->middleware('throttle:messages-read')->name('messages.read');
        Route::post('/messages/{conversation}/hide', [MessageController::class, 'hide'])->whereNumber('conversation')->middleware('throttle:messages-write')->name('messages.hide');

        // --- editing and deleting a message ---
        Route::patch('/messages/{conversation}/messages/{message}', [MessageController::class, 'edit'])->whereNumber(['conversation', 'message'])->middleware('throttle:messages-write')->name('messages.edit');
        Route::get('/messages/{conversation}/messages/{message}/edits', [MessageController::class, 'edits'])->whereNumber(['conversation', 'message'])->middleware('throttle:messages-read')->name('messages.edits');
        Route::delete('/messages/{conversation}/messages/{message}', [MessageController::class, 'delete'])->whereNumber(['conversation', 'message'])->middleware('throttle:messages-write')->name('messages.delete');

        // --- the members of a group ---
        Route::get('/messages/{conversation}/members', [MessageGroupController::class, 'members'])->whereNumber('conversation')->middleware('throttle:messages-read')->name('messages.members');
        Route::post('/messages/{conversation}/members', [MessageGroupController::class, 'add'])->whereNumber('conversation')->middleware('throttle:messages-write')->name('messages.members.add');
        Route::post('/messages/{conversation}/members/remove', [MessageGroupController::class, 'remove'])->whereNumber('conversation')->middleware('throttle:messages-write')->name('messages.members.remove');
        Route::post('/messages/{conversation}/rename', [MessageGroupController::class, 'rename'])->whereNumber('conversation')->middleware('throttle:messages-write')->name('messages.rename');
        Route::post('/messages/{conversation}/leave', [MessageGroupController::class, 'leave'])->whereNumber('conversation')->middleware('throttle:messages-write')->name('messages.leave');
    }
}
