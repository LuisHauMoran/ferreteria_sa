<?php

$pdo = new PDO(
    'mysql:host=localhost;dbname=ferre_tornillo;charset=utf8mb4',
    'root',
    ''
);

$hash = password_hash('password', PASSWORD_DEFAULT);

$stmt = $pdo->prepare(
    "UPDATE usuarios SET password = ? WHERE usuario = 'admin'"
);

$stmt->execute([$hash]);

echo "Contraseña de admin restablecida correctamente.<br>";
echo "Usuario: admin<br>";
echo "Contraseña: password<br>";
echo "Hash generado: " . htmlspecialchars($hash);