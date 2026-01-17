<?php

return [
    // SMTP server instellingen
    'transport' => 'smtp',
    'host' => 'smtp.gmail.com',                    // Of: sandbox.smtp.mailtrap.io
    'port' => 587,                                  // 587 voor TLS
    'encryption' => 'tls',                          // 'tls' of 'ssl'
    
    // Authenticatie - VEREIST
    'username' => 'contactvickimmo@gmail.com',     // Gmail email of Mailtrap username
    'password' => 'ctztdkjgbukqgvli',              // App Password (ZONDER SPATIES!) of Mailtrap password
    
    // Van adres (moet overeenkomen met Gmail account voor Gmail)
    'from' => [
        'address' => 'contactvickimmo@gmail.com',   // Moet hetzelfde zijn als username voor Gmail
        'name' => 'Vickimmo Website'
    ],
    
    // Naar adres (waar contact formulier berichten naartoe gaan)
    'to' => [
        'address' => 'mathis.debaere@student.odisee.be',
        'name' => 'Mathis De Baere | Vickimmo Support'
    ],
    
    // Timeout instellingen
    'timeout' => 30,
];
