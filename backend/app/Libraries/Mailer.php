<?php

namespace App\Libraries;

use Config\Email as EmailConfig;
use Config\Florist;
use RuntimeException;

/**
 * Sends the site's emails (password-reset links). With florist.mailer = log (local development) the email is only
 * written to writable/logs; with smtp / mail / sendmail it really goes out, using the email.* settings in .env.
 * Get it with service('mailer').
 */
class Mailer
{
    public function __construct(protected Florist $config)
    {
    }

    /** True when emails really go out (so "Forgot password?" can work). */
    public function canSend(): bool
    {
        return in_array($this->config->mailer, ['smtp', 'mail', 'sendmail'], true);
    }

    public function send(string $to, string $subject, string $html, string $text): void
    {
        if (! $this->canSend()) {
            log_message('info', "Email (florist.mailer = {$this->config->mailer}, not sent)\nTo: {to}\nSubject: {subject}\n\n{text}", [
                'to' => $to, 'subject' => $subject, 'text' => $text,
            ]);

            return;
        }

        $settings = config(EmailConfig::class);
        $email    = service('email');
        $email->initialize(['protocol' => $this->config->mailer, 'mailType' => 'html', 'charset' => 'UTF-8']);
        $email->setFrom($settings->fromEmail !== '' ? $settings->fromEmail : 'no-reply@cityflorist.local', $settings->fromName !== '' ? $settings->fromName : 'City Florist');
        $email->setTo($to);
        $email->setSubject($subject);
        $email->setMessage($html);
        $email->setAltMessage($text);
        if (! $email->send(false)) {
            throw new RuntimeException('The email could not be sent: ' . strip_tags((string) $email->printDebugger(['headers'])));
        }
    }
}
