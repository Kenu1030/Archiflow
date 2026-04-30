<?php
// Central mail configuration.
// WARNING: Storing real credentials in the repository is insecure.
// Move these to environment variables (getenv) or a non-committed .env file ASAP.
return [
    'transport' => 'smtp',
    'host' => 'smtp.gmail.com',
    'port' => 587,
    'encryption' => PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS,
    'username' => 'kenuabadia@gmail.com',
    'password' => 'rtzl yoek glhh jopw', // GMail App Password (rotate & secure!)
    'from_email' => 'kenuabadia@gmail.com',
    'from_name' => 'Archiflow Notifications',
    'reply_to_email' => 'kenuabadia@gmail.com',
    'reply_to_name' => 'Archiflow Support',
    'debug' => 0, // 0 = off, 2 = verbose
];