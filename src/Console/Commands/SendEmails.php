<?php

declare(strict_types=1);

namespace Oneduo\MailScheduler\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Mail;
use Oneduo\MailScheduler\Enums\EmailStatus;
use Oneduo\MailScheduler\Exceptions\InvalidRecipients;
use Oneduo\MailScheduler\Exceptions\RecipientException;
use Oneduo\MailScheduler\Models\ScheduledEmail;
use Throwable;

class SendEmails extends Command
{
    protected $signature = 'mail-scheduler:send';

    protected $description = 'Send a batch of scheduled emails';

    public function handle(): int
    {
        ScheduledEmail::query()
            ->where('scheduled_emails.status', '<>', EmailStatus::SENT)
            ->where('scheduled_emails.attempts', '<', config('mail-scheduler.max_attempts'))
            ->where(function (Builder $builder) {
                $builder
                    ->whereNull('scheduled_emails.send_at')
                    ->orWhere('scheduled_emails.send_at', '<=', now());
            })
            ->oldest()
            ->limit(config('mail-scheduler.batch_size'))
            ->each(fn(ScheduledEmail $email) => $this->sendEmail($email));

        return 0;
    }

    protected function sendEmail(ScheduledEmail $email): void
    {
        try {
            if (empty(array_filter($email->recipients))) {
                throw RecipientException::empty();
            }

            $mailable = (clone $email)->mailable;

            Mail::mailer($email->mailer)->to($email->recipients)->send($mailable);
        } catch (Throwable $throwable) {
            report($throwable);

            $email->update([
                'status' => EmailStatus::ERROR,
                'error' => $throwable->getMessage(),
                'stacktrace' => $throwable->getTraceAsString(),
                'attempts' => $email->attempts + 1,
                'attempted_at' => now(),
            ]);

            return;
        }

        $email->update([
            'status' => EmailStatus::SENT,
            'attempts' => $email->attempts + 1,
            'attempted_at' => now(),
        ]);
    }
}
