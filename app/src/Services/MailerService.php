<?php

namespace Services;

use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Address;
use Exception;

class MailerService
{
    private Mailer $mailer;
    private array $config;

    public function __construct()
    {
        $this->config = require __DIR__ . '/../../config/mailer.php';        
        $dsn = $this->buildDsn();

        $transport = Transport::fromDsn($dsn);
        $this->mailer = new Mailer($transport);
    }

    private function buildDsn(): string
    {
        $config = $this->config;
        $dsn = sprintf(
            '%s://%s:%s@%s:%d',
            $config['transport'],
            urlencode($config['username']),
            urlencode($config['password']),
            $config['host'],
            $config['port']
        );

        // Alleen voor development: tijdelijk SSL verificatie uitschakelen
        $dsn .= '?verify_peer=0';

        return $dsn;
    }

    public function send(
        string $to,
        string $subject,
        string $htmlContent,
        ?string $textContent = null,
        ?string $replyTo = null,
        ?string $toName = null
    ): bool {
        try {
            $email = (new Email())
                ->from(new Address(
                    $this->config['from']['address'],
                    $this->config['from']['name']
                ))
                ->to(new Address($to, $toName ?? $to))
                ->subject($subject)
                ->html($htmlContent);

            if ($textContent !== null) {
                $email->text($textContent);
            }

            $this->mailer->send($email);

            return true;
        } catch (Exception $e) {
            error_log('Mailer error: ' . $e->getMessage());
            throw new Exception('Failed to send email: ' . $e->getMessage());
        }
    }

    public function sendContactForm(
        string $name,
        string $email,
        string $category,
        string $message
    ): bool {
        $htmlContent = $this->buildContactEmailHtml($name, $email, $category, $message);
        $textContent = $this->buildContactEmailText($name, $email, $category, $message);

        $success = $this->send(
            to: $this->config['to']['address'],
            subject: 'Contact formulier: ' . $category,
            htmlContent: $htmlContent,
            textContent: $textContent,
            replyTo: $email,
            toName: $this->config['to']['name']
        );
        if ($success) {
            $this->sendConfirmationEmail($name, $email, $category, $message);
        }

        return $success;
    }

    private function sendConfirmationEmail(
        string $name,
        string $email,
        string $category,
        string $message
    ): bool {
        try {
            $htmlContent = $this->buildConfirmationEmailHtml($name, $email, $category, $message);
            $textContent = $this->buildConfirmationEmailText($name, $category, $message);

            return $this->send(
                to: $email,
                subject: 'Bevestiging: Uw vraag is ontvangen',
                htmlContent: $htmlContent,
                textContent: $textContent,
                toName: $name
            );
        } catch (Exception $e) {
            error_log('Confirmation email error: ' . $e->getMessage());
            return false;
        }
    }

    private function buildContactEmailHtml(
        string $name,
        string $email,
        string $category,
        string $message
    ): string {
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background-color: #142725; color: white; padding: 20px; border-radius: 5px 5px 0 0; }
                .content { background-color: #fff; padding: 20px; border: 1px solid #ddd; }
                .footer { background-color: #f4f4f4; padding: 10px; text-align: center; font-size: 12px; border-radius: 0 0 5px 5px; }
                .info-box { background-color: #f9f9f9; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
                .info-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #e5e5e5; }
                .info-row:last-child { border-bottom: none; }
                .info-label { font-weight: bold; color: #142725; }
                .message-box { background-color: #f9f9f9; padding: 15px; border-left: 4px solid #142725; margin-top: 20px; }
                .category-badge { display: inline-block; background-color: #142725; color: white; padding: 5px 10px; border-radius: 3px; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h2 style='margin: 0;'>Nieuw Contact Bericht</h2>
                </div>
                <div class='content'>                    
                    <div class='info-box'>
                        <div class='info-row'>
                            <span class='info-label'>Naam:</span>
                            <span>{$name}</span>
                        </div>
                        <div class='info-row'>
                            <span class='info-label'>E-mail:</span>
                            <span><a href='mailto:{$email}' style='color: #142725;'>{$email}</a></span>
                        </div>
                        <div class='info-row'>
                            <span class='info-label'>Categorie:</span>
                            <span><span class='category-badge'>{$category}</span></span>
                        </div>
                    </div>
                    
                    <h3 style='color: #142725;'>Bericht:</h3>
                    <div class='message-box'>
                        " . nl2br(htmlspecialchars($message)) . "
                    </div>

                </div>
                <div class='footer'>
                    <p>Verzonden via het contact formulier op <strong>vickimmo.mathisdebaere.ikdoeict.be</strong></p>
                </div>
            </div>
        </body>
        </html>
        ";
    }

    private function buildContactEmailText(
        string $name,
        string $email,
        string $category,
        string $message
    ): string {
        return "
Nieuw Contact Formulier Bericht
================================

Naam: {$name}
E-mail: {$email}
Categorie: {$category}

Bericht:
--------
{$message}

---
Dit bericht is verzonden via het contact formulier op Vickimmo.be
        ";
    }


    private function buildConfirmationEmailHtml(
        string $name,
        string $email,
        string $category,
        string $message
    ): string {
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background-color: #142725; color: white; padding: 20px; border-radius: 5px 5px 0 0; }
                .content { background-color: #fff; padding: 20px; border: 1px solid #ddd; }
                .footer { background-color: #f4f4f4; padding: 10px; text-align: center; font-size: 12px; border-radius: 0 0 5px 5px; }
                .info-box { background-color: #f9f9f9; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
                .info-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #e5e5e5; }
                .info-row:last-child { border-bottom: none; }
                .info-label { font-weight: bold; color: #142725; }
                .message-box { background-color: #f9f9f9; padding: 15px; border-left: 4px solid #142725; margin-top: 20px; }
                .category-badge { display: inline-block; background-color: #142725; color: white; padding: 5px 10px; border-radius: 3px; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h2 style='margin: 0;'>Bedankt voor uw bericht!</h2>
                </div>
                <div class='content'>
                    <p>Beste {$name}, we hebben uw bericht succesvol ontvangen en zullen zo snel mogelijk contact met u opnemen.</p>
                    <h3 style='color: #142725;'>Kopie van uw bericht:</h3>               
                    <div class='info-box'>
                        <div class='info-row'>
                            <span class='info-label'>E-mail:</span>
                            <span><a href='mailto:{$email}' style='color: #142725;'>{$email}</a></span>
                        </div>
                        <div class='info-row'>
                            <span class='info-label'>Categorie:</span>
                            <span><span class='category-badge'>{$category}</span></span>
                        </div>
                    </div>
                    
                    <h3 style='color: #142725;'>Uw bericht:</h3>
                    <div class='message-box'>
                        " . nl2br(htmlspecialchars($message)) . "
                    </div>
                    
                    <p style='margin-top: 20px;'>Met vriendelijke groet,<br><strong>Het Vickimmo Team</strong></p>
                </div>
                <div class='footer'>
                    <p>Verzonden via het contact formulier op <strong>vickimmo.mathisdebaere.ikdoeict.be</strong></p>
                </div>
            </div>
        </body>
        </html>
        ";
    }

    private function buildConfirmationEmailText(
        string $name,
        string $category,
        string $message
    ): string {
        return "
Bedankt voor uw bericht!
========================

Beste {$name},

We hebben uw bericht succesvol ontvangen en zullen zo spoedig mogelijk contact met u opnemen.

Kopie van uw vraag:
-------------------
Categorie: {$category}

Uw bericht:
{$message}

Met vriendelijke groet,
Het Vickimmo Team

---
Dit is een automatisch gegenereerd bericht. Gelieve niet te antwoorden op deze e-mail.
        ";
    }
}
