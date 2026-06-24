<?php
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($uri === '/' || $uri === '') {
    header('Location: /public/auth/login.html');
    exit;
}

$file = __DIR__ . $uri;
if (is_file($file)) {
    $ext = pathinfo($file, PATHINFO_EXTENSION);
    $types = [
        'css' => 'text/css',
        'js' => 'application/javascript',
        'html' => 'text/html',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'json' => 'application/json',
    ];
    if (isset($types[$ext])) {
        header('Content-Type: ' . $types[$ext]);
    }
    return false;
}

http_response_code(404);
echo '404 Not Found';
