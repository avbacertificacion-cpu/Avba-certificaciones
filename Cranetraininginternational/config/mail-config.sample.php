<?php
/**
 * SAMPLE mail configuration for the CTI contact form.
 *
 * This sample file IS committed to the repository. The real file with real
 * credentials is NOT: copy this file to "mail-config.php" in this same
 * folder directly on the server, fill in the real values, and never commit
 * it. See Cranetraininginternational/README.md for the full setup steps.
 */

return [
    'smtp_host'     => 'smtp.hostinger.com',
    'smtp_port'     => 587,           // 587 for STARTTLS, 465 for implicit SSL
    'smtp_secure'   => 'tls',         // 'tls' or 'ssl'
    'smtp_username' => 'no-reply@example.com',
    'smtp_password' => 'CHANGE-ME',

    'from_email'    => 'no-reply@example.com',
    'from_name'     => 'Crane Training International — Website',

    // Where contact form submissions are delivered.
    'contact_to'    => 'contact@example.com',
];
