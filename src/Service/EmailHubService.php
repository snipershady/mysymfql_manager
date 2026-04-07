<?php

namespace App\Service;

use App\Component\DateTimeIt;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use TypeIdentifier\Service\EffectivePrimitiveTypeIdentifierService;

/**
 * Description of EmailHubService.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 */
final readonly class EmailHubService
{
    public function __construct(private MailerInterface $mailer)
    {
    }

    public function emailResetPassword(Address $addressTo, string $msg, string $subject): array
    {
        $now = new DateTimeIt();
        $nowString = $now->format('Y-m-d H:i:s');
        $templatePath = 'emails/reset_psw.html.twig';
        $context = [
            'to' => $addressTo->getName(),
            'msg' => $msg,
            'now_string' => $nowString,
        ];

        return $this->sendEmail($addressTo, $subject, $context, $templatePath);
    }

    public function emailNotify(Address $addressTo, string $msg, string $subject): array
    {
        $now = new DateTimeIt();
        $nowString = $now->format('Y-m-d H:i:s');
        $templatePath = 'emails/notifica.html.twig';
        $context = [
            'to' => $addressTo->getName(),
            'msg' => $msg,
            'now_string' => $nowString,
        ];

        return $this->sendEmail($addressTo, $subject, $context, $templatePath);
    }

    private function sendEmail(Address $addressTo, string $subject, array $context, string $templatePath): array
    {
        $epti = new EffectivePrimitiveTypeIdentifierService();
        $emailFrom = $epti->getTypedValueFromEnv(needle: 'EMAIL_FROM', trim: true, forceString: true, sanitizeHtml: false);

        $email = new TemplatedEmail()
                ->from($emailFrom)
                ->to($addressTo)
                ->subject($subject)
                ->htmlTemplate($templatePath)
                ->context($context)
        ;
        try {
            $this->mailer->send($email);
        } catch (\Exception $exception) {
            return ['is_valid' => false, 'msg' => $exception->getMessage()];
        }

        return ['is_valid' => true, 'msg' => 'ok'];
    }
}
