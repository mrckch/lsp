<?php

declare(strict_types=1);

namespace App\Domain\Mail;

use App\Domain\Mail\Models\MailAttachment;
use App\Domain\Mail\Models\MailMessage;
use App\Domain\Mail\Models\MailSettings;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Versendet Mails über die in mail_settings konfigurierten SMTP-Daten und
 * protokolliert in mail_messages.
 */
final class MailService
{
    /**
     * @param  array{to:array,cc?:array,bcc?:array,subject:string,body_html?:string,body_text?:string,attachments?:array,related_entity_type?:string,related_entity_id?:int,includes_clearnames?:bool}  $payload
     */
    public function send(array $payload, ?int $sentByUserId = null): MailMessage
    {
        $message = MailMessage::create([
            'to_addresses' => json_encode($payload['to']),
            'cc' => isset($payload['cc']) ? json_encode($payload['cc']) : null,
            'bcc' => isset($payload['bcc']) ? json_encode($payload['bcc']) : null,
            'subject' => $payload['subject'],
            'body_html' => $payload['body_html'] ?? null,
            'body_text' => $payload['body_text'] ?? null,
            'status' => 'queued',
            'related_entity_type' => $payload['related_entity_type'] ?? null,
            'related_entity_id' => $payload['related_entity_id'] ?? null,
            'includes_clearnames' => $payload['includes_clearnames'] ?? false,
            'sent_by_user_id' => $sentByUserId,
            'created_at' => now(),
        ]);

        foreach ($payload['attachments'] ?? [] as $att) {
            MailAttachment::create([
                'mail_message_id' => $message->id,
                'generated_document_id' => $att['generated_document_id'] ?? null,
                'file_name' => $att['file_name'],
                'mime_type' => $att['mime_type'] ?? 'application/pdf',
                'size_bytes' => $att['size_bytes'] ?? 0,
            ]);
        }

        try {
            $this->configureMailer();
            Mail::send([], [], function ($mail) use ($payload, $message) {
                $mail->to($payload['to']);
                if (! empty($payload['cc'])) {
                    $mail->cc($payload['cc']);
                }
                if (! empty($payload['bcc'])) {
                    $mail->bcc($payload['bcc']);
                }
                $mail->subject($payload['subject']);
                if (! empty($payload['body_html'])) {
                    $mail->html($payload['body_html']);
                }
                if (! empty($payload['body_text'])) {
                    $mail->text($payload['body_text']);
                }
                foreach ($message->attachments as $att) {
                    if ($att->generatedDocument) {
                        $mail->attachData(
                            Storage::disk('local')->get($att->generatedDocument->file_path),
                            $att->file_name,
                            ['mime' => $att->mime_type],
                        );
                    }
                }
            });

            $message->update(['status' => 'sent', 'sent_at' => now()]);
        } catch (\Throwable $e) {
            $message->update([
                'status' => 'failed',
                'error_message' => mb_substr($e->getMessage(), 0, 1000),
            ]);
        }

        return $message->refresh();
    }

    /**
     * Sendet eine Mail mit einem rohen Anhang (z. B. ein gerade erzeugtes ZIP),
     * ohne diesen vorab als generated_document zu persistieren.
     */
    public function sendWithRawAttachment(
        array $to,
        string $subject,
        string $bodyHtml,
        string $attachmentName,
        string $attachmentMime,
        string $attachmentBytes,
        bool $includesClearnames = false,
        ?int $userId = null,
    ): MailMessage {
        $message = MailMessage::create([
            'to_addresses' => json_encode($to),
            'subject' => $subject,
            'body_html' => $bodyHtml,
            'status' => 'queued',
            'includes_clearnames' => $includesClearnames,
            'sent_by_user_id' => $userId,
            'created_at' => now(),
        ]);

        MailAttachment::create([
            'mail_message_id' => $message->id,
            'file_name' => $attachmentName,
            'mime_type' => $attachmentMime,
            'size_bytes' => strlen($attachmentBytes),
        ]);

        try {
            $this->configureMailer();
            Mail::send([], [], function ($mail) use ($to, $subject, $bodyHtml, $attachmentName, $attachmentMime, $attachmentBytes) {
                $mail->to($to);
                $mail->subject($subject);
                $mail->html($bodyHtml);
                $mail->attachData($attachmentBytes, $attachmentName, ['mime' => $attachmentMime]);
            });

            $message->update(['status' => 'sent', 'sent_at' => now()]);
        } catch (\Throwable $e) {
            $message->update([
                'status' => 'failed',
                'error_message' => mb_substr($e->getMessage(), 0, 1000),
            ]);
        }

        return $message->refresh();
    }

    private function configureMailer(): void
    {
        $settings = MailSettings::singleton();
        if (! $settings->is_active || $settings->smtp_host === null) {
            // Fallback: nutze Default-Mailer (z. B. log oder array in Tests)
            return;
        }

        Config::set('mail.default', 'smtp');
        Config::set('mail.mailers.smtp', [
            'transport' => 'smtp',
            'host' => $settings->smtp_host,
            'port' => $settings->smtp_port,
            'encryption' => $settings->smtp_encryption === 'none' ? null : $settings->smtp_encryption,
            'username' => $settings->smtp_username,
            'password' => $settings->smtp_password,
            'timeout' => 30,
        ]);
        Config::set('mail.from', [
            'address' => $settings->from_address,
            'name' => $settings->from_name,
        ]);
    }
}
