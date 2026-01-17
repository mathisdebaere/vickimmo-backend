<?php

return [
    // SMTP  instellingen
    'transport' => 'smtp',
    'host' => 'smtp.gmail.com',
    'port' => 587,
    
    'username' => 'contactvickimmo@gmail.com',
    'password' => 'ctztdkjgbukqgvli',
    
    'from' => [
        'address' => 'noreply@gmail.com',
        'name' => 'Vickimmo Support'
    ],
    
    'to' => [
        'address' => 'mathis.debaere@student.odisee.be',
        'name' => 'Mathis De Baere | Vickimmo Support'
    ],
    
    'timeout' => 10,
];
