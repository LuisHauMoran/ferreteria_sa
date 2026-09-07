<?php

$hash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC2Q0cYb4qF';

echo '<pre>';

echo "PHP funcionando\n";
echo "password_verify: ";

var_dump(password_verify('password', $hash));

echo "\nVersión PHP: ";
echo PHP_VERSION;

echo '</pre>';