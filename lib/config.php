<?php
// Config loader. config.php lives at the deploy root, OUTSIDE public_html.
// Falls back to config.example.php values only for local testing.
function load_config(): array {
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $candidates = [
        dirname(__DIR__) . '/config.php',
        dirname(__DIR__, 2) . '/config.php',
        __DIR__ . '/../config.php',
    ];
    foreach ($candidates as $p) {
        if (is_file($p)) { $cfg = require $p; return $cfg; }
    }
    $cfg = require __DIR__ . '/../config.example.php';
    return $cfg;
}
