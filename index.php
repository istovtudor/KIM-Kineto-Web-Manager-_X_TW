<?php
/** Redirect la pagina de login (XAMPP: http://localhost/ProiectWEB/) */
$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
header('Location: ' . $base . '/public/auth/login.html');
exit;
