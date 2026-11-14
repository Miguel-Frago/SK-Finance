<?php

namespace App\Support;

use App\Mail\SkNotice;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Sends an SkNotice and tells the caller whether it worked, so a mail problem never loses the Admin's decision. */
class AccessMailer
{
    public static function send(string $to, SkNotice $mail): bool
    {
        try {
            Mail::to($to)->send($mail);

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }
}
