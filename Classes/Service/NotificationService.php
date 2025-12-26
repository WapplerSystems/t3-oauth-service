<?php
declare(strict_types=1);

namespace WapplerSystems\OauthService\Service;

use TYPO3\CMS\Core\Mail\MailMessage;

final class NotificationService
{
    public function sendFailureMail(string $to, string $subject, string $body): void
    {
        if (trim($to) === '') {
            return;
        }
        $mail = new MailMessage();
        $mail->to($to)->subject($subject)->text($body)->send();
    }
}
