<?php
// EXAMPLE config — copy to config.php at the deploy root (OUTSIDE public_html)
// and fill in real values. config.php is NEVER committed (see .gitignore).
return [
    'db_host' => 'localhost',
    'db_name' => 'u390461415_telegram',
    'db_user' => 'u390461415_telegram',
    'db_pass' => 'CHANGE_ME',
    // Random 32+ char string; encrypts bot tokens stored in the database.
    'app_key' => 'CHANGE_ME_TO_A_LONG_RANDOM_STRING',
    'base_url' => 'https://telegram.toolsbydcx.com',
];
