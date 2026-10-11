<?php
// Copy to config.php OUTSIDE the web document root. Never put this file in public/.
return [
    'username' => 'digitalismundus',
    'must_change_password' => true, // only used when initializing the account
    'auth_key' => 'REPLACE_WITH_BASE64_32_RANDOM_BYTES', // encryption of TOTP secret
    'password_hash' => 'REPLACE_WITH_PASSWORD_HASH',
    'secure_cookie' => true, // HTTPS in production
    'storage' => __DIR__ . '/storage',
];
