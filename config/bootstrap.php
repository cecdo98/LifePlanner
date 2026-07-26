<?php
    // Ponto único de arranque de sessão. Substitui os session_start() dispersos
    // por cada página para garantir que os cookies de sessão têm sempre os
    // mesmos parâmetros de segurança (httponly, samesite, secure quando aplicável).
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        ]);
        session_start();
    }
?>
