<?php

namespace Http;

use Services\MailerService;
use Services\ApiResponse;
use Exception;

/**
 * ContactController
 * 
 * Controller voor het afhandelen van contact formulier verzoeken
 */
class ContactController extends BaseController
{
    private MailerService $mailerService;

    public function __construct()
    {
        parent::__construct();
        $this->mailerService = new MailerService();
    }

    public function sendContactForm(): void
    {
        // Check of het een POST request is
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            ApiResponse::error('Method not allowed', 405);
            return;
        }

        // Lees de JSON body
        $input = json_decode(file_get_contents('php://input'), true);

        // Valideer de input
        $validation = $this->validateContactForm($input);
        if (!$validation['valid']) {
            ApiResponse::error('Validation failed', 400, ['errors' => $validation['errors']]);
            return;
        }

        try {
            // Verstuur de e-mail via MailerService
            $success = $this->mailerService->sendContactForm(
                name: $input['name'],
                email: $input['email'],
                category: $input['category'],
                message: $input['message']
            );

            if ($success) {
                ApiResponse::success([
                    'message' => 'Uw bericht is succesvol verzonden. We nemen zo spoedig mogelijk contact met u op.'
                ]);
            } else {
                ApiResponse::error('Er is een fout opgetreden bij het verzenden van uw bericht. Probeer het later opnieuw.', 500);
            }
        } catch (Exception $e) {
            error_log('Contact form error: ' . $e->getMessage());
            ApiResponse::error('Er is een fout opgetreden bij het verzenden van uw bericht. Probeer het later opnieuw.', 500);
        }
    }

    /**
     * Valideer contact formulier input
     * 
     * @param array|null $input
     * @return array ['valid' => bool, 'errors' => array]
     */
    private function validateContactForm(?array $input): array
    {
        $errors = [];

        // Check of input bestaat
        if ($input === null) {
            return ['valid' => false, 'errors' => ['Invalid JSON input']];
        }

        // Valideer naam
        if (empty($input['name']) || !is_string($input['name'])) {
            $errors['name'] = 'Naam is verplicht';
        } elseif (strlen($input['name']) < 2) {
            $errors['name'] = 'Naam moet minimaal 2 karakters bevatten';
        } elseif (strlen($input['name']) > 100) {
            $errors['name'] = 'Naam mag maximaal 100 karakters bevatten';
        }

        // Valideer e-mail
        if (empty($input['email']) || !is_string($input['email'])) {
            $errors['email'] = 'E-mail is verplicht';
        } elseif (!filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Ongeldig e-mailadres';
        }

        // Valideer categorie
        $validCategories = ['Register Agency', 'Ask a quote', 'Other Questions', 'Technical support'];
        if (empty($input['category']) || !is_string($input['category'])) {
            $errors['category'] = 'Categorie is verplicht';
        } elseif (!in_array($input['category'], $validCategories)) {
            $errors['category'] = 'Ongeldige categorie geselecteerd';
        }

        // Valideer bericht
        if (empty($input['message']) || !is_string($input['message'])) {
            $errors['message'] = 'Bericht is verplicht';
        } elseif (strlen($input['message']) < 10) {
            $errors['message'] = 'Bericht moet minimaal 10 karakters bevatten';
        } elseif (strlen($input['message']) > 5000) {
            $errors['message'] = 'Bericht mag maximaal 5000 karakters bevatten';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
}
