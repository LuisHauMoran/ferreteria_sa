<?php
declare(strict_types=1);

session_start();

/*
|--------------------------------------------------------------------------
| Ferreteria S.A. - index.php
|--------------------------------------------------------------------------
| Sistema integrado de ventas e inventario.
| Todo el frontend y backend de esta demo se encuentra en este único archivo.
|
| Requisitos:
| - PHP 8+
| - MySQL 8+
| - Extensión PDO MySQL habilitada
|
| Base de datos: ferre_tornillo
| Usuario inicial: admin
| Contraseña: password
|--------------------------------------------------------------------------
*/

const DB_HOST = 'localhost';
const DB_NAME = 'ferre_tornillo';
const DB_USER = 'root';
const DB_PASS = '';

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    } catch (PDOException $e) {
        die(
            '<div style="font-family:Arial;padding:30px;max-width:800px;margin:auto">
                <h2>No se pudo conectar con MySQL</h2>
                <p>Verifica que MySQL esté iniciado y que las credenciales de este archivo sean correctas.</p>
                <pre>' . htmlspecialchars($e->getMessage()) . '</pre>
            </div>'
        );
    }

    return $pdo;
}

function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $page = 'dashboard'): never
{
    header('Location: index.php?page=' . urlencode($page));
    exit;
}

function flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'] = [
        'message' => $message,
        'type' => $type
    ];
}

function getFlash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

function loggedIn(): bool
{
    return isset($_SESSION['user']);
}

function currentUser(): ?array
{
    return $_SESSION['user'] ?? null;
}

function audit(string $action, ?string $table = null, ?int $recordId = null, ?string $description = null): void
{
    if (!loggedIn()) {
        return;
    }

    try {
        $stmt = db()->prepare(
            'INSERT INTO auditoria
            (usuario_id, accion, tabla_afectada, registro_id, descripcion, ip)
            VALUES (?, ?, ?, ?, ?, ?)'
        );

        $stmt->execute([
            $_SESSION['user']['id'],
            $action,
            $table,
            $recordId,
            $description,
            $_SERVER['REMOTE_ADDR'] ?? null
        ]);
    } catch (Throwable $e) {
        // La auditoría no debe impedir la operación principal de la demo.
    }
}

function requireLogin(): void
{
    if (!loggedIn()) {
        redirect('login');
    }
}

function requireRole(array $roles): void
{
    requireLogin();

    if (!in_array($_SESSION['user']['rol'], $roles, true)) {
        flash('No tienes permisos para realizar esta operación.', 'error');
        redirect('dashboard');
    }
}

function money(float|int|string $value): string
{
    return 'S/ ' . number_format((float)$value, 2);
}

/*
|--------------------------------------------------------------------------
| LOGIN / LOGOUT
|--------------------------------------------------------------------------
*/

if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    header('Location: index.php');
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'login') {
    $username = trim($_POST['usuario'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = db()->prepare(
        'SELECT u.*, t.nombre AS tienda_nombre
         FROM usuarios u
         LEFT JOIN tiendas t ON t.id = u.tienda_id
         WHERE u.usuario = ? AND u.estado = 1
         LIMIT 1'
    );

    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user'] = [
            'id' => (int)$user['id'],
            'nombre' => $user['nombre'],
            'usuario' => $user['usuario'],
            'rol' => $user['rol'],
            'tienda_id' => $user['tienda_id'] ? (int)$user['tienda_id'] : null,
            'tienda_nombre' => $user['tienda_nombre'] ?? 'Todas'
        ];

        audit('INICIO_SESION', 'usuarios', (int)$user['id'], 'Inicio de sesión');
        redirect('dashboard');
    }

    $_SESSION['login_error'] = 'Usuario o contraseña incorrectos.';
    header('Location: index.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Todas las acciones requieren autenticación excepto login.
|--------------------------------------------------------------------------
*/

if (isset($_POST['action']) && $_POST['action'] !== 'login') {
    requireLogin();

    $action = $_POST['action'];

    try {
        $pdo = db();

        /*
        |--------------------------------------------------------------------------
        | Registrar venta
        |--------------------------------------------------------------------------
        */
        if ($action === 'registrar_venta') {
            requireRole(['ADMINISTRADOR', 'VENDEDOR', 'CAJERO']);

            $productoId = (int)($_POST['producto_id'] ?? 0);
            $cantidad = max(1, (int)($_POST['cantidad'] ?? 1));
            $cliente = trim($_POST['cliente_nombre'] ?? 'Cliente general');
            $documento = trim($_POST['cliente_documento'] ?? '');
            $comprobante = $_POST['tipo_comprobante'] ?? 'BOLETA';
            $medioPago = $_POST['medio_pago'] ?? 'EFECTIVO';
            $descuento = max(0, (float)($_POST['descuento'] ?? 0));

            $tiendaId = (int)($_POST['tienda_id'] ?? ($_SESSION['user']['tienda_id'] ?? 0));

            if ($tiendaId <= 0) {
                throw new RuntimeException('Debes seleccionar una tienda.');
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'SELECT p.*, s.cantidad AS stock_actual, t.nombre AS tienda
                 FROM productos p
                 INNER JOIN stock s ON s.producto_id = p.id AND s.tienda_id = ?
                 INNER JOIN tiendas t ON t.id = s.tienda_id
                 WHERE p.id = ? AND p.estado = 1
                 FOR UPDATE'
            );

            $stmt->execute([$tiendaId, $productoId]);
            $producto = $stmt->fetch();

            if (!$producto) {
                throw new RuntimeException('Producto o stock no encontrado.');
            }

            if ((int)$producto['stock_actual'] < $cantidad) {
                throw new RuntimeException(
                    'Stock insuficiente. Disponible: ' . $producto['stock_actual']
                );
            }

            $subtotal = (float)$producto['precio_venta'] * $cantidad;
            $descuentoMonto = min($subtotal, $descuento);
            $total = $subtotal - $descuentoMonto;

            $numero = 'V-' . date('YmdHis') . '-' . random_int(100, 999);

            $stmt = $pdo->prepare(
                'INSERT INTO ventas
                (numero, tienda_id, usuario_id, cliente_nombre, cliente_documento,
                 tipo_comprobante, subtotal, descuento, total, medio_pago, estado)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "PAGADA")'
            );

            $stmt->execute([
                $numero,
                $tiendaId,
                $_SESSION['user']['id'],
                $cliente,
                $documento,
                $comprobante,
                $subtotal,
                $descuentoMonto,
                $total,
                $medioPago
            ]);

            $ventaId = (int)$pdo->lastInsertId();

            $stmt = $pdo->prepare(
                'INSERT INTO detalle_ventas
                (venta_id, producto_id, cantidad, precio_unitario, descuento, subtotal)
                VALUES (?, ?, ?, ?, ?, ?)'
            );

            $stmt->execute([
                $ventaId,
                $productoId,
                $cantidad,
                $producto['precio_venta'],
                $descuentoMonto,
                $total
            ]);

            $stmt = $pdo->prepare(
                'UPDATE stock
                 SET cantidad = cantidad - ?
                 WHERE producto_id = ? AND tienda_id = ?'
            );

            $stmt->execute([$cantidad, $productoId, $tiendaId]);

            $stmt = $pdo->prepare(
                'INSERT INTO movimientos_inventario
                (producto_id, tienda_id, usuario_id, tipo, cantidad, referencia, observacion)
                VALUES (?, ?, ?, "VENTA", ?, ?, ?)'
            );

            $stmt->execute([
                $productoId,
                $tiendaId,
                $_SESSION['user']['id'],
                $cantidad,
                $numero,
                'Salida automática por venta'
            ]);

            $pdo->commit();

            audit(
                'REGISTRAR_VENTA',
                'ventas',
                $ventaId,
                "Venta {$numero} registrada por " . money($total)
            );

            flash("Venta {$numero} registrada correctamente por " . money($total) . '.');
            redirect('ventas');
        }

        /*
        |--------------------------------------------------------------------------
        | Registrar movimiento de inventario
        |--------------------------------------------------------------------------
        */
        if ($action === 'movimiento_inventario') {
            requireRole(['ADMINISTRADOR', 'ALMACENERO']);

            $productoId = (int)($_POST['producto_id'] ?? 0);
            $tiendaId = (int)($_POST['tienda_id'] ?? 0);
            $tipo = $_POST['tipo'] ?? 'ENTRADA';
            $cantidad = max(1, (int)($_POST['cantidad'] ?? 1));
            $observacion = trim($_POST['observacion'] ?? '');

            if (!in_array($tipo, ['ENTRADA', 'SALIDA', 'AJUSTE'], true)) {
                throw new RuntimeException('Tipo de movimiento inválido.');
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'SELECT cantidad FROM stock
                 WHERE producto_id = ? AND tienda_id = ?
                 FOR UPDATE'
            );
            $stmt->execute([$productoId, $tiendaId]);
            $stock = $stmt->fetch();

            if (!$stock) {
                $stmt = $pdo->prepare(
                    'INSERT INTO stock (producto_id, tienda_id, cantidad)
                     VALUES (?, ?, 0)'
                );
                $stmt->execute([$productoId, $tiendaId]);
                $stockActual = 0;
            } else {
                $stockActual = (int)$stock['cantidad'];
            }

            $nuevoStock = $stockActual;

            if ($tipo === 'ENTRADA') {
                $nuevoStock += $cantidad;
            } elseif ($tipo === 'SALIDA') {
                if ($stockActual < $cantidad) {
                    throw new RuntimeException(
                        "No hay suficiente stock. Disponible: {$stockActual}."
                    );
                }
                $nuevoStock -= $cantidad;
            } else {
                $nuevoStock = $cantidad;
            }

            $stmt = $pdo->prepare(
                'UPDATE stock
                 SET cantidad = ?
                 WHERE producto_id = ? AND tienda_id = ?'
            );
            $stmt->execute([$nuevoStock, $productoId, $tiendaId]);

            $stmt = $pdo->prepare(
                'INSERT INTO movimientos_inventario
                (producto_id, tienda_id, usuario_id, tipo, cantidad, referencia, observacion)
                VALUES (?, ?, ?, ?, ?, ?, ?)'
            );

            $stmt->execute([
                $productoId,
                $tiendaId,
                $_SESSION['user']['id'],
                $tipo,
                $cantidad,
                'MANUAL',
                $observacion
            ]);

            $movimientoId = (int)$pdo->lastInsertId();

            $pdo->commit();

            audit(
                'MOVIMIENTO_INVENTARIO',
                'movimientos_inventario',
                $movimientoId,
                "Movimiento {$tipo}"
            );

            flash('Movimiento de inventario registrado correctamente.');
            redirect('inventario');
        }

        /*
        |--------------------------------------------------------------------------
        | Transferencia entre tiendas
        |--------------------------------------------------------------------------
        */
        if ($action === 'transferencia') {
            requireRole(['ADMINISTRADOR', 'ALMACENERO']);

            $origen = (int)($_POST['origen_id'] ?? 0);
            $destino = (int)($_POST['destino_id'] ?? 0);
            $productoId = (int)($_POST['producto_id'] ?? 0);
            $cantidad = max(1, (int)($_POST['cantidad'] ?? 1));
            $observacion = trim($_POST['observacion'] ?? '');

            if ($origen === $destino) {
                throw new RuntimeException('La tienda de origen y destino deben ser diferentes.');
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'SELECT cantidad FROM stock
                 WHERE producto_id = ? AND tienda_id = ?
                 FOR UPDATE'
            );
            $stmt->execute([$productoId, $origen]);
            $stockOrigen = $stmt->fetch();

            if (!$stockOrigen || (int)$stockOrigen['cantidad'] < $cantidad) {
                throw new RuntimeException('No existe suficiente stock en la tienda de origen.');
            }

            $numero = 'TR-' . date('YmdHis') . '-' . random_int(100, 999);

            $stmt = $pdo->prepare(
                'INSERT INTO transferencias
                (numero, origen_id, destino_id, usuario_id, estado, observacion)
                VALUES (?, ?, ?, ?, "RECIBIDA", ?)'
            );

            $stmt->execute([
                $numero,
                $origen,
                $destino,
                $_SESSION['user']['id'],
                $observacion
            ]);

            $transferenciaId = (int)$pdo->lastInsertId();

            $stmt = $pdo->prepare(
                'INSERT INTO detalle_transferencias
                (transferencia_id, producto_id, cantidad)
                VALUES (?, ?, ?)'
            );
            $stmt->execute([$transferenciaId, $productoId, $cantidad]);

            $stmt = $pdo->prepare(
                'UPDATE stock
                 SET cantidad = cantidad - ?
                 WHERE producto_id = ? AND tienda_id = ?'
            );
            $stmt->execute([$cantidad, $productoId, $origen]);

            $stmt = $pdo->prepare(
                'INSERT INTO stock (producto_id, tienda_id, cantidad)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE cantidad = cantidad + VALUES(cantidad)'
            );
            $stmt->execute([$productoId, $destino, $cantidad]);

            $stmt = $pdo->prepare(
                'INSERT INTO movimientos_inventario
                (producto_id, tienda_id, usuario_id, tipo, cantidad, referencia, observacion)
                VALUES (?, ?, ?, "TRANSFERENCIA_SALIDA", ?, ?, ?)'
            );
            $stmt->execute([
                $productoId,
                $origen,
                $_SESSION['user']['id'],
                $cantidad,
                $numero,
                'Transferencia hacia tienda destino'
            ]);

            $stmt = $pdo->prepare(
                'INSERT INTO movimientos_inventario
                (producto_id, tienda_id, usuario_id, tipo, cantidad, referencia, observacion)
                VALUES (?, ?, ?, "TRANSFERENCIA_ENTRADA", ?, ?, ?)'
            );
            $stmt->execute([
                $productoId,
                $destino,
                $_SESSION['user']['id'],
                $cantidad,
                $numero,
                'Transferencia recibida desde tienda origen'
            ]);

            $pdo->commit();

            audit(
                'TRANSFERENCIA',
                'transferencias',
                $transferenciaId,
                "Transferencia {$numero}"
            );

            flash("Transferencia {$numero} registrada correctamente.");
            redirect('inventario');
        }

        /*
        |--------------------------------------------------------------------------
        | Registrar proveedor
        |--------------------------------------------------------------------------
        */
        if ($action === 'registrar_proveedor') {
            requireRole(['ADMINISTRADOR']);

            $ruc = trim($_POST['ruc'] ?? '');
            $razon = trim($_POST['razon_social'] ?? '');
            $telefono = trim($_POST['telefono'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $direccion = trim($_POST['direccion'] ?? '');

            if ($razon === '') {
                throw new RuntimeException('La razón social es obligatoria.');
            }

            $stmt = $pdo->prepare(
                'INSERT INTO proveedores
                (ruc, razon_social, telefono, email, direccion)
                VALUES (?, ?, ?, ?, ?)'
            );

            $stmt->execute([
                $ruc,
                $razon,
                $telefono,
                $email,
                $direccion
            ]);

            $id = (int)$pdo->lastInsertId();

            audit(
                'CREAR_PROVEEDOR',
                'proveedores',
                $id,
                "Proveedor {$razon}"
            );

            flash('Proveedor registrado correctamente.');
            redirect('compras');
        }

        /*
        |--------------------------------------------------------------------------
        | Registrar compra
        |--------------------------------------------------------------------------
        */
        if ($action === 'registrar_compra') {
            requireRole(['ADMINISTRADOR', 'ALMACENERO']);

            $proveedorId = (int)($_POST['proveedor_id'] ?? 0);
            $productoId = (int)($_POST['producto_id'] ?? 0);
            $tiendaId = (int)($_POST['tienda_id'] ?? 0);
            $cantidad = max(1, (int)($_POST['cantidad'] ?? 1));
            $precio = max(0, (float)($_POST['precio_unitario'] ?? 0));

            if ($proveedorId <= 0 || $productoId <= 0 || $tiendaId <= 0) {
                throw new RuntimeException('Completa todos los datos de la compra.');
            }

            $pdo->beginTransaction();

            $subtotal = $cantidad * $precio;
            $numero = 'C-' . date('YmdHis') . '-' . random_int(100, 999);

            $stmt = $pdo->prepare(
                'INSERT INTO compras
                (numero, proveedor_id, tienda_id, usuario_id, subtotal, total, estado)
                VALUES (?, ?, ?, ?, ?, ?, "RECIBIDA")'
            );

            $stmt->execute([
                $numero,
                $proveedorId,
                $tiendaId,
                $_SESSION['user']['id'],
                $subtotal,
                $subtotal
            ]);

            $compraId = (int)$pdo->lastInsertId();

            $stmt = $pdo->prepare(
                'INSERT INTO detalle_compras
                (compra_id, producto_id, cantidad, precio_unitario, subtotal)
                VALUES (?, ?, ?, ?, ?)'
            );

            $stmt->execute([
                $compraId,
                $productoId,
                $cantidad,
                $precio,
                $subtotal
            ]);

            $stmt = $pdo->prepare(
                'INSERT INTO stock (producto_id, tienda_id, cantidad)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE cantidad = cantidad + VALUES(cantidad)'
            );

            $stmt->execute([$productoId, $tiendaId, $cantidad]);

            $stmt = $pdo->prepare(
                'INSERT INTO movimientos_inventario
                (producto_id, tienda_id, usuario_id, tipo, cantidad, referencia, observacion)
                VALUES (?, ?, ?, "ENTRADA", ?, ?, ?)'
            );

            $stmt->execute([
                $productoId,
                $tiendaId,
                $_SESSION['user']['id'],
                $cantidad,
                $numero,
                'Entrada automática por compra'
            ]);

            $pdo->commit();

            audit(
                'REGISTRAR_COMPRA',
                'compras',
                $compraId,
                "Compra {$numero} por " . money($subtotal)
            );

            flash("Compra {$numero} registrada correctamente.");
            redirect('compras');
        }

        /*
        |--------------------------------------------------------------------------
        | Crear usuario
        |--------------------------------------------------------------------------
        */
        if ($action === 'crear_usuario') {
            requireRole(['ADMINISTRADOR']);

            $nombre = trim($_POST['nombre'] ?? '');
            $usuario = trim($_POST['usuario'] ?? '');
            $password = $_POST['password'] ?? '';
            $rol = $_POST['rol'] ?? 'VENDEDOR';
            $tiendaId = (int)($_POST['tienda_id'] ?? 0);

            $rolesValidos = [
                'ADMINISTRADOR',
                'VENDEDOR',
                'CAJERO',
                'ALMACENERO',
                'GERENTE'
            ];

            if ($nombre === '' || $usuario === '' || strlen($password) < 6) {
                throw new RuntimeException(
                    'Nombre, usuario y una contraseña de al menos 6 caracteres son obligatorios.'
                );
            }

            if (!in_array($rol, $rolesValidos, true)) {
                throw new RuntimeException('Rol inválido.');
            }

            $stmt = $pdo->prepare(
                'INSERT INTO usuarios
                (nombre, usuario, password, rol, tienda_id)
                VALUES (?, ?, ?, ?, ?)'
            );

            $stmt->execute([
                $nombre,
                $usuario,
                password_hash($password, PASSWORD_DEFAULT),
                $rol,
                $tiendaId > 0 ? $tiendaId : null
            ]);

            $id = (int)$pdo->lastInsertId();

            audit(
                'CREAR_USUARIO',
                'usuarios',
                $id,
                "Usuario {$usuario}"
            );

            flash('Usuario creado correctamente.');
            redirect('usuarios');
        }

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash($e->getMessage(), 'error');
        redirect($_GET['page'] ?? 'dashboard');
    }
}

/*
|--------------------------------------------------------------------------
| Datos para la interfaz
|--------------------------------------------------------------------------
*/

if (!loggedIn()) {
    $loginError = $_SESSION['login_error'] ?? null;
    unset($_SESSION['login_error']);
    $flash = getFlash();
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Ferre-Tornillo | Acceso</title>
<style>
*{box-sizing:border-box}
body{
    margin:0;
    min-height:100vh;
    display:flex;
    align-items:center;
    justify-content:center;
    font-family:Arial,Helvetica,sans-serif;
    background:#eef2f6;
    color:#17202a;
}
.login{
    width:390px;
    max-width:92%;
    background:#fff;
    border:1px solid #dfe4ea;
    border-radius:14px;
    padding:32px;
    box-shadow:0 12px 35px rgba(0,0,0,.08);
}
.logo{
    text-align:center;
    font-size:25px;
    font-weight:800;
    margin-bottom:7px;
}
.logo span{color:#f59e0b}
.subtitle{
    text-align:center;
    color:#6b7280;
    font-size:13px;
    margin-bottom:28px;
}
label{
    display:block;
    font-size:13px;
    font-weight:700;
    margin-bottom:7px;
}
input{
    width:100%;
    padding:12px;
    border:1px solid #d1d5db;
    border-radius:8px;
    margin-bottom:16px;
    font-size:14px;
}
button{
    width:100%;
    padding:12px;
    border:0;
    border-radius:8px;
    background:#2563eb;
    color:white;
    font-weight:700;
    cursor:pointer;
}
button:hover{background:#1d4ed8}
.error{
    background:#fee2e2;
    color:#991b1b;
    border:1px solid #fecaca;
    padding:11px;
    border-radius:8px;
    font-size:13px;
    margin-bottom:16px;
}
.info{
    background:#f8fafc;
    border:1px solid #e5e7eb;
    padding:12px;
    border-radius:8px;
    margin-top:18px;
    font-size:12px;
    color:#6b7280;
}
</style>
</head>
<body>
<div class="login">
    <div class="logo">FERRE-<span>TORNILLO</span></div>
    <div class="subtitle">Sistema Integrado de Ventas e Inventario</div>

    <?php if ($loginError): ?>
        <div class="error"><?= e($loginError) ?></div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="action" value="login">

        <label>Usuario</label>
        <input type="text" name="usuario" autocomplete="username" required>

        <label>Contraseña</label>
        <input type="password" name="password" autocomplete="current-password" required>

        <button type="submit">Iniciar sesión</button>
    </form>

    <div class="info">
        <strong>Acceso de demostración</strong><br>
        Usuario: admin<br>
        Contraseña: password
    </div>
</div>
</body>
</html>
<?php
    exit;
}

$user = currentUser();
$page = $_GET['page'] ?? 'dashboard';

$allowedPages = [
    'dashboard',
    'ventas',
    'inventario',
    'compras',
    'reportes',
    'usuarios',
    'config'
];

if (!in_array($page, $allowedPages, true)) {
    $page = 'dashboard';
}

$pdo = db();
$flash = getFlash();

/*
|--------------------------------------------------------------------------
| Consultas generales
|--------------------------------------------------------------------------
*/

$tiendas = $pdo->query(
    'SELECT * FROM tiendas WHERE estado = 1 ORDER BY tipo DESC, nombre'
)->fetchAll();

$productos = $pdo->query(
    'SELECT p.*, c.nombre AS categoria
     FROM productos p
     LEFT JOIN categorias c ON c.id = p.categoria_id
     WHERE p.estado = 1
     ORDER BY p.nombre'
)->fetchAll();

$proveedores = $pdo->query(
    'SELECT * FROM proveedores
     WHERE estado = 1
     ORDER BY razon_social'
)->fetchAll();

/*
|--------------------------------------------------------------------------
| Dashboard
|--------------------------------------------------------------------------
*/

$ventasHoy = (float)$pdo->query(
    "SELECT COALESCE(SUM(total),0)
     FROM ventas
     WHERE DATE(created_at)=CURDATE()
     AND estado='PAGADA'"
)->fetchColumn();

$cantidadVentasHoy = (int)$pdo->query(
    "SELECT COUNT(*)
     FROM ventas
     WHERE DATE(created_at)=CURDATE()
     AND estado='PAGADA'"
)->fetchColumn();

$stockTotal = (int)$pdo->query(
    'SELECT COALESCE(SUM(cantidad),0) FROM stock'
)->fetchColumn();

$alertasStock = (int)$pdo->query(
    'SELECT COUNT(*)
     FROM stock s
     INNER JOIN productos p ON p.id=s.producto_id
     WHERE s.cantidad <= p.stock_minimo
     AND p.estado=1'
)->fetchColumn();

$ventasRecientes = $pdo->query(
    'SELECT v.*, t.nombre AS tienda, u.nombre AS vendedor
     FROM ventas v
     INNER JOIN tiendas t ON t.id=v.tienda_id
     INNER JOIN usuarios u ON u.id=v.usuario_id
     ORDER BY v.id DESC
     LIMIT 8'
)->fetchAll();

$stockRows = $pdo->query(
    'SELECT
        p.codigo,
        p.nombre,
        c.nombre AS categoria,
        t.nombre AS tienda,
        s.cantidad,
        p.stock_minimo
     FROM stock s
     INNER JOIN productos p ON p.id=s.producto_id
     LEFT JOIN categorias c ON c.id=p.categoria_id
     INNER JOIN tiendas t ON t.id=s.tienda_id
     WHERE p.estado=1
     ORDER BY p.nombre, t.nombre'
)->fetchAll();

$movimientos = $pdo->query(
    'SELECT
        m.*,
        p.nombre AS producto,
        t.nombre AS tienda,
        u.nombre AS usuario
     FROM movimientos_inventario m
     INNER JOIN productos p ON p.id=m.producto_id
     INNER JOIN tiendas t ON t.id=m.tienda_id
     LEFT JOIN usuarios u ON u.id=m.usuario_id
     ORDER BY m.id DESC
     LIMIT 15'
)->fetchAll();

$usuarios = $pdo->query(
    'SELECT u.id,u.nombre,u.usuario,u.rol,u.estado,t.nombre AS tienda
     FROM usuarios u
     LEFT JOIN tiendas t ON t.id=u.tienda_id
     ORDER BY u.id DESC'
)->fetchAll();

$comprasRecientes = $pdo->query(
    'SELECT c.*, p.razon_social AS proveedor, t.nombre AS tienda, u.nombre AS usuario
     FROM compras c
     INNER JOIN proveedores p ON p.id=c.proveedor_id
     INNER JOIN tiendas t ON t.id=c.tienda_id
     INNER JOIN usuarios u ON u.id=c.usuario_id
     ORDER BY c.id DESC
     LIMIT 10'
)->fetchAll();

$ventasPorTienda = $pdo->query(
    "SELECT t.nombre, COALESCE(SUM(v.total),0) AS total
     FROM tiendas t
     LEFT JOIN ventas v
       ON v.tienda_id=t.id
      AND v.estado='PAGADA'
      AND MONTH(v.created_at)=MONTH(CURDATE())
      AND YEAR(v.created_at)=YEAR(CURDATE())
     GROUP BY t.id,t.nombre
     ORDER BY total DESC"
)->fetchAll();

$ventasPorProducto = $pdo->query(
    "SELECT
        p.nombre,
        SUM(d.cantidad) AS unidades,
        SUM(d.subtotal) AS total
     FROM detalle_ventas d
     INNER JOIN ventas v ON v.id=d.venta_id AND v.estado='PAGADA'
     INNER JOIN productos p ON p.id=d.producto_id
     GROUP BY p.id,p.nombre
     ORDER BY unidades DESC
     LIMIT 8"
)->fetchAll();

$totalComprasMes = (float)$pdo->query(
    "SELECT COALESCE(SUM(total),0)
     FROM compras
     WHERE estado='RECIBIDA'
     AND MONTH(created_at)=MONTH(CURDATE())
     AND YEAR(created_at)=YEAR(CURDATE())"
)->fetchColumn();

$subtotalVentasMes = (float)$pdo->query(
    "SELECT COALESCE(SUM(v.total),0)
     FROM ventas v
     WHERE v.estado='PAGADA'
     AND MONTH(v.created_at)=MONTH(CURDATE())
     AND YEAR(v.created_at)=YEAR(CURDATE())"
)->fetchColumn();

$costoVentasMes = (float)$pdo->query(
    "SELECT COALESCE(SUM(d.cantidad * p.precio_compra),0)
     FROM detalle_ventas d
     INNER JOIN ventas v ON v.id=d.venta_id AND v.estado='PAGADA'
     INNER JOIN productos p ON p.id=d.producto_id
     WHERE MONTH(v.created_at)=MONTH(CURDATE())
     AND YEAR(v.created_at)=YEAR(CURDATE())"
)->fetchColumn();

$margenMes = $subtotalVentasMes > 0
    ? (($subtotalVentasMes - $costoVentasMes) / $subtotalVentasMes) * 100
    : 0;

$pageTitles = [
    'dashboard' => 'Dashboard',
    'ventas' => 'Ventas y Caja',
    'inventario' => 'Inventario',
    'compras' => 'Compras y Proveedores',
    'reportes' => 'Reportes',
    'usuarios' => 'Usuarios y Roles',
    'config' => 'Configuración'
];

$pageTitle = $pageTitles[$page];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> | Ferre-Tornillo</title>

<style>
:root{
    --bg:#f4f6f8;
    --card:#ffffff;
    --dark:#17202a;
    --dark2:#222f3e;
    --accent:#f59e0b;
    --blue:#2563eb;
    --green:#16a34a;
    --red:#dc2626;
    --purple:#7c3aed;
    --text:#1f2937;
    --muted:#6b7280;
    --border:#e5e7eb;
}
*{box-sizing:border-box}
body{
    margin:0;
    font-family:Arial,Helvetica,sans-serif;
    background:var(--bg);
    color:var(--text);
}
.app{
    min-height:100vh;
}
.sidebar{
    width:245px;
    background:var(--dark);
    color:white;
    position:fixed;
    top:0;
    bottom:0;
    left:0;
    padding:20px 14px;
    overflow-y:auto;
}
.logo{
    font-size:21px;
    font-weight:800;
    padding:8px 12px 24px;
}
.logo span{color:var(--accent)}
.menu-title{
    font-size:10px;
    text-transform:uppercase;
    color:#9ca3af;
    margin:18px 12px 7px;
    letter-spacing:.5px;
}
.menu a{
    display:block;
    text-decoration:none;
    color:#d1d5db;
    padding:11px 12px;
    border-radius:8px;
    margin:3px 0;
    font-size:14px;
}
.menu a:hover,
.menu a.active{
    background:var(--dark2);
    color:#fff;
}
.menu a.active{
    border-left:3px solid var(--accent);
}
.logout{
    position:absolute;
    left:14px;
    right:14px;
    bottom:15px;
    border-top:1px solid #334155;
    padding-top:12px;
}
.logout a{
    color:#fca5a5;
}
main{
    margin-left:245px;
    padding:27px;
}
.top{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:20px;
    margin-bottom:22px;
}
.top h1{
    margin:0;
    font-size:27px;
}
.subtitle{
    margin-top:5px;
    font-size:13px;
    color:var(--muted);
}
.userbox{
    background:#fff;
    border:1px solid var(--border);
    border-radius:9px;
    padding:10px 14px;
    font-size:13px;
}
.flash{
    padding:13px 16px;
    border-radius:9px;
    margin-bottom:18px;
    font-size:13px;
}
.flash.success{
    background:#ecfdf5;
    border:1px solid #bbf7d0;
    color:#166534;
}
.flash.error{
    background:#fef2f2;
    border:1px solid #fecaca;
    color:#991b1b;
}
.cards{
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:16px;
    margin-bottom:20px;
}
.card{
    background:var(--card);
    border:1px solid var(--border);
    border-radius:12px;
    padding:19px;
    box-shadow:0 2px 8px rgba(0,0,0,.03);
}
.card .label{
    font-size:12px;
    color:var(--muted);
}
.card .value{
    font-size:27px;
    font-weight:800;
    margin-top:8px;
}
.card .small{
    font-size:11px;
    color:var(--muted);
    margin-top:6px;
}
.grid{
    display:grid;
    grid-template-columns:2fr 1fr;
    gap:18px;
}
.section{
    background:#fff;
    border:1px solid var(--border);
    border-radius:12px;
    padding:20px;
    margin-bottom:18px;
}
.section h2{
    font-size:18px;
    margin:0 0 16px;
}
.section h3{
    font-size:15px;
    margin:22px 0 12px;
}
table{
    width:100%;
    border-collapse:collapse;
    font-size:13px;
}
th,td{
    text-align:left;
    padding:11px 8px;
    border-bottom:1px solid var(--border);
    vertical-align:middle;
}
th{
    font-size:10px;
    text-transform:uppercase;
    color:var(--muted);
}
.badge{
    display:inline-block;
    padding:5px 8px;
    border-radius:20px;
    font-size:10px;
    font-weight:700;
}
.ok{background:#dcfce7;color:#166534}
.low{background:#fee2e2;color:#991b1b}
.info-badge{background:#dbeafe;color:#1e40af}
.neutral{background:#f1f5f9;color:#475569}
.form{
    display:grid;
    grid-template-columns:repeat(2,1fr);
    gap:14px;
}
.field label{
    display:block;
    font-size:12px;
    font-weight:700;
    margin-bottom:6px;
}
.field input,
.field select,
.field textarea{
    width:100%;
    padding:11px;
    border:1px solid #d1d5db;
    border-radius:7px;
    font:inherit;
    font-size:13px;
    background:white;
}
.field textarea{
    min-height:85px;
    resize:vertical;
}
.full{
    grid-column:1/-1;
}
button,
.btn{
    display:inline-block;
    border:0;
    border-radius:7px;
    padding:11px 15px;
    background:var(--blue);
    color:white;
    font-weight:700;
    cursor:pointer;
    text-decoration:none;
    font-size:13px;
}
button:hover,.btn:hover{opacity:.9}
.btn.green{background:var(--green)}
.btn.purple{background:var(--purple)}
.btn.dark{background:#475569}
.actions{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
}
.quick-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:10px;
}
.quick-grid a{
    text-decoration:none;
    color:white;
    padding:13px;
    border-radius:8px;
    font-size:12px;
    font-weight:700;
    background:var(--blue);
}
.quick-grid a:nth-child(2){background:var(--green)}
.quick-grid a:nth-child(3){background:var(--purple)}
.quick-grid a:nth-child(4){background:#475569}
.store{
    padding:12px;
    border:1px solid var(--border);
    border-radius:8px;
    margin-bottom:8px;
    background:#f8fafc;
    font-size:13px;
}
.kpis{
    display:grid;
    grid-template-columns:repeat(3,1fr);
    gap:14px;
}
.mini{
    background:#f8fafc;
    border:1px solid var(--border);
    border-radius:9px;
    padding:15px;
    font-size:13px;
}
.mini strong{
    display:block;
    font-size:22px;
    margin-top:6px;
}
.chartbar{
    margin:10px 0 15px;
}
.chartbar .row{
    display:flex;
    justify-content:space-between;
    font-size:12px;
    margin-bottom:5px;
}
.bar{
    height:9px;
    border-radius:10px;
    background:#e5e7eb;
    overflow:hidden;
}
.bar span{
    display:block;
    height:100%;
    background:var(--blue);
}
.empty{
    color:var(--muted);
    padding:20px 0;
    text-align:center;
}
.note{
    padding:12px;
    background:#f8fafc;
    border:1px solid var(--border);
    border-radius:8px;
    font-size:12px;
    color:#475569;
}
@media(max-width:1100px){
    .cards{grid-template-columns:repeat(2,1fr)}
    .grid{grid-template-columns:1fr}
}
@media(max-width:750px){
    .sidebar{
        position:static;
        width:100%;
        height:auto;
    }
    .logout{
        position:static;
        margin-top:10px;
    }
    main{
        margin-left:0;
        padding:15px;
    }
    .top{
        flex-direction:column;
        align-items:flex-start;
    }
    .form,.cards,.kpis,.quick-grid{
        grid-template-columns:1fr;
    }
    .full{grid-column:auto}
    table{
        min-width:700px;
    }
    .section{
        overflow-x:auto;
    }
}
</style>
</head>

<body>
<div class="app">

<aside class="sidebar">
    <div class="logo">FERRE-<span>TORNILLO</span></div>

    <div class="menu-title">Principal</div>
    <nav class="menu">
        <a href="?page=dashboard" class="<?= $page === 'dashboard' ? 'active' : '' ?>">Dashboard</a>
        <a href="?page=ventas" class="<?= $page === 'ventas' ? 'active' : '' ?>">Ventas y Caja</a>
        <a href="?page=inventario" class="<?= $page === 'inventario' ? 'active' : '' ?>">Inventario</a>
        <a href="?page=compras" class="<?= $page === 'compras' ? 'active' : '' ?>">Compras</a>
        <a href="?page=reportes" class="<?= $page === 'reportes' ? 'active' : '' ?>">Reportes</a>
    </nav>

    <div class="menu-title">Administración</div>
    <nav class="menu">
        <?php if (in_array($user['rol'], ['ADMINISTRADOR', 'GERENTE'], true)): ?>
            <a href="?page=usuarios" class="<?= $page === 'usuarios' ? 'active' : '' ?>">Usuarios y Roles</a>
        <?php endif; ?>
        <a href="?page=config" class="<?= $page === 'config' ? 'active' : '' ?>">Configuración</a>
    </nav>

    <div class="logout">
        <a href="?logout=1">Cerrar sesión</a>
    </div>
</aside>

<main>

<div class="top">
    <div>
        <h1><?= e($pageTitle) ?></h1>
        <div class="subtitle">
            Sistema Integrado de Ventas e Inventario — Ferretería El Tornillo S.A.
        </div>
    </div>

    <div class="userbox">
        <strong><?= e($user['nombre']) ?></strong><br>
        <?= e($user['rol']) ?> · <?= e($user['tienda_nombre']) ?>
    </div>
</div>

<?php if ($flash): ?>
    <div class="flash <?= e($flash['type']) ?>">
        <?= e($flash['message']) ?>
    </div>
<?php endif; ?>

<?php if ($page === 'dashboard'): ?>

<div class="cards">
    <div class="card">
        <div class="label">Ventas de hoy</div>
        <div class="value"><?= money($ventasHoy) ?></div>
        <div class="small"><?= $cantidadVentasHoy ?> operaciones registradas</div>
    </div>

    <div class="card">
        <div class="label">Operaciones de venta</div>
        <div class="value"><?= $cantidadVentasHoy ?></div>
        <div class="small">Comprobantes pagados hoy</div>
    </div>

    <div class="card">
        <div class="label">Stock total</div>
        <div class="value"><?= number_format($stockTotal) ?></div>
        <div class="small">Unidades en tiendas y almacén</div>
    </div>

    <div class="card">
        <div class="label">Alertas de stock</div>
        <div class="value" style="color:<?= $alertasStock > 0 ? 'var(--red)' : 'var(--green)' ?>">
            <?= $alertasStock ?>
        </div>
        <div class="small">Productos en stock mínimo</div>
    </div>
</div>

<div class="grid">

<div>
    <div class="section">
        <h2>Stock por ubicación</h2>

        <table>
            <thead>
                <tr>
                    <th>Código</th>
                    <th>Producto</th>
                    <th>Tienda</th>
                    <th>Stock</th>
                    <th>Mínimo</th>
                    <th>Estado</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($stockRows as $row): ?>
                <?php $low = (int)$row['cantidad'] <= (int)$row['stock_minimo']; ?>
                <tr>
                    <td><?= e($row['codigo']) ?></td>
                    <td><?= e($row['nombre']) ?></td>
                    <td><?= e($row['tienda']) ?></td>
                    <td><?= number_format((int)$row['cantidad']) ?></td>
                    <td><?= number_format((int)$row['stock_minimo']) ?></td>
                    <td>
                        <span class="badge <?= $low ? 'low' : 'ok' ?>">
                            <?= $low ? 'Stock bajo' : 'Normal' ?>
                        </span>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="section">
        <h2>Ventas recientes</h2>

        <table>
            <thead>
                <tr>
                    <th>Comprobante</th>
                    <th>Tienda</th>
                    <th>Cliente</th>
                    <th>Vendedor</th>
                    <th>Total</th>
                    <th>Estado</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($ventasRecientes as $venta): ?>
                <tr>
                    <td><?= e($venta['numero']) ?></td>
                    <td><?= e($venta['tienda']) ?></td>
                    <td><?= e($venta['cliente_nombre']) ?></td>
                    <td><?= e($venta['vendedor']) ?></td>
                    <td><?= money($venta['total']) ?></td>
                    <td><span class="badge ok"><?= e($venta['estado']) ?></span></td>
                </tr>
            <?php endforeach; ?>

            <?php if (!$ventasRecientes): ?>
                <tr><td colspan="6" class="empty">Todavía no hay ventas registradas.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div>
    <div class="section">
        <h2>Operaciones rápidas</h2>

        <div class="quick-grid">
            <a href="?page=ventas">Nueva venta</a>
            <a href="?page=inventario">Movimiento</a>
            <a href="?page=inventario">Transferencia</a>
            <a href="?page=compras">Nueva compra</a>
        </div>
    </div>

    <div class="section">
        <h2>Ubicaciones</h2>

        <?php foreach ($tiendas as $tienda): ?>
            <div class="store">
                <strong><?= e($tienda['nombre']) ?></strong><br>
                <span style="color:var(--muted)">
                    <?= e($tienda['tipo']) ?> · <?= e($tienda['direccion']) ?>
                </span>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="section">
        <h2>Resumen mensual</h2>

        <div class="mini">
            Ventas del mes
            <strong><?= money($subtotalVentasMes) ?></strong>
        </div>

        <br>

        <div class="mini">
            Compras del mes
            <strong><?= money($totalComprasMes) ?></strong>
        </div>

        <br>

        <div class="mini">
            Margen bruto estimado
            <strong><?= number_format($margenMes, 1) ?>%</strong>
        </div>
    </div>
</div>

</div>

<?php elseif ($page === 'ventas'): ?>

<div class="section">
    <h2>Registrar nueva venta</h2>

    <?php if (!in_array($user['rol'], ['ADMINISTRADOR','VENDEDOR','CAJERO'], true)): ?>
        <div class="note">
            Tu rol no tiene permisos para registrar ventas.
        </div>
    <?php else: ?>

    <form method="post" class="form">
        <input type="hidden" name="action" value="registrar_venta">

        <div class="field">
            <label>Cliente</label>
            <input type="text" name="cliente_nombre" value="Cliente general" required>
        </div>

        <div class="field">
            <label>DNI / RUC</label>
            <input type="text" name="cliente_documento">
        </div>

        <div class="field">
            <label>Producto</label>
            <select name="producto_id" required>
                <option value="">Seleccionar producto</option>
                <?php foreach ($productos as $producto): ?>
                    <option value="<?= $producto['id'] ?>">
                        <?= e($producto['codigo']) ?> — <?= e($producto['nombre']) ?>
                        — <?= money($producto['precio_venta']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label>Cantidad</label>
            <input type="number" name="cantidad" min="1" value="1" required>
        </div>

        <div class="field">
            <label>Tienda</label>
            <select name="tienda_id" required>
                <?php foreach ($tiendas as $tienda): ?>
                    <?php if ($tienda['tipo'] === 'TIENDA' || $user['rol'] === 'ADMINISTRADOR'): ?>
                    <option value="<?= $tienda['id'] ?>"
                        <?= (int)$user['tienda_id'] === (int)$tienda['id'] ? 'selected' : '' ?>>
                        <?= e($tienda['nombre']) ?>
                    </option>
                    <?php endif; ?>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label>Tipo de comprobante</label>
            <select name="tipo_comprobante">
                <option value="BOLETA">Boleta electrónica</option>
                <option value="FACTURA">Factura electrónica</option>
            </select>
        </div>

        <div class="field">
            <label>Medio de pago</label>
            <select name="medio_pago">
                <option value="EFECTIVO">Efectivo</option>
                <option value="TARJETA">Tarjeta</option>
                <option value="YAPE">Yape</option>
                <option value="PLIN">Plin</option>
            </select>
        </div>

        <div class="field">
            <label>Descuento en soles</label>
            <input type="number" name="descuento" min="0" step="0.01" value="0">
        </div>

        <div class="full">
            <button type="submit">Registrar venta</button>
        </div>
    </form>

    <?php endif; ?>
</div>

<div class="section">
    <h2>Últimas ventas</h2>

    <table>
        <thead>
            <tr>
                <th>Número</th>
                <th>Fecha</th>
                <th>Tienda</th>
                <th>Cliente</th>
                <th>Comprobante</th>
                <th>Pago</th>
                <th>Total</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($ventasRecientes as $venta): ?>
            <tr>
                <td><?= e($venta['numero']) ?></td>
                <td><?= e($venta['created_at']) ?></td>
                <td><?= e($venta['tienda']) ?></td>
                <td><?= e($venta['cliente_nombre']) ?></td>
                <td><?= e($venta['tipo_comprobante']) ?></td>
                <td><?= e($venta['medio_pago']) ?></td>
                <td><?= money($venta['total']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="section">
    <h2>Estado de caja</h2>

    <div class="kpis">
        <div class="mini">
            Ventas del día
            <strong><?= money($ventasHoy) ?></strong>
        </div>

        <div class="mini">
            Operaciones
            <strong><?= $cantidadVentasHoy ?></strong>
        </div>

        <div class="mini">
            Estado
            <strong style="font-size:16px;color:var(--green)">Caja operativa</strong>
        </div>
    </div>
</div>

<?php elseif ($page === 'inventario'): ?>

<div class="section">
    <h2>Registrar movimiento de inventario</h2>

    <?php if (in_array($user['rol'], ['ADMINISTRADOR','ALMACENERO'], true)): ?>

    <form method="post" class="form">
        <input type="hidden" name="action" value="movimiento_inventario">

        <div class="field">
            <label>Producto</label>
            <select name="producto_id" required>
                <?php foreach ($productos as $producto): ?>
                    <option value="<?= $producto['id'] ?>">
                        <?= e($producto['codigo']) ?> — <?= e($producto['nombre']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label>Ubicación</label>
            <select name="tienda_id" required>
                <?php foreach ($tiendas as $tienda): ?>
                    <option value="<?= $tienda['id'] ?>"><?= e($tienda['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label>Tipo de movimiento</label>
            <select name="tipo">
                <option value="ENTRADA">Entrada</option>
                <option value="SALIDA">Salida</option>
                <option value="AJUSTE">Ajuste de inventario</option>
            </select>
        </div>

        <div class="field">
            <label>Cantidad</label>
            <input type="number" name="cantidad" min="1" value="1" required>
        </div>

        <div class="field full">
            <label>Observación</label>
            <textarea name="observacion" placeholder="Motivo del movimiento"></textarea>
        </div>

        <div class="full">
            <button type="submit">Registrar movimiento</button>
        </div>
    </form>

    <?php else: ?>

        <div class="note">Tu rol no tiene permisos para registrar movimientos manuales.</div>

    <?php endif; ?>
</div>

<div class="section">
    <h2>Transferencia entre tiendas</h2>

    <?php if (in_array($user['rol'], ['ADMINISTRADOR','ALMACENERO'], true)): ?>

    <form method="post" class="form">
        <input type="hidden" name="action" value="transferencia">

        <div class="field">
            <label>Origen</label>
            <select name="origen_id" required>
                <?php foreach ($tiendas as $tienda): ?>
                    <option value="<?= $tienda['id'] ?>"><?= e($tienda['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label>Destino</label>
            <select name="destino_id" required>
                <?php foreach ($tiendas as $tienda): ?>
                    <option value="<?= $tienda['id'] ?>"><?= e($tienda['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label>Producto</label>
            <select name="producto_id" required>
                <?php foreach ($productos as $producto): ?>
                    <option value="<?= $producto['id'] ?>">
                        <?= e($producto['codigo']) ?> — <?= e($producto['nombre']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label>Cantidad</label>
            <input type="number" name="cantidad" min="1" value="1" required>
        </div>

        <div class="field full">
            <label>Observación</label>
            <textarea name="observacion"></textarea>
        </div>

        <div class="full">
            <button type="submit">Registrar transferencia</button>
        </div>
    </form>

    <?php else: ?>

        <div class="note">Tu rol no tiene permisos para realizar transferencias.</div>

    <?php endif; ?>
</div>

<div class="section">
    <h2>Stock actual</h2>

    <table>
        <thead>
            <tr>
                <th>Código</th>
                <th>Producto</th>
                <th>Categoría</th>
                <th>Ubicación</th>
                <th>Stock</th>
                <th>Mínimo</th>
                <th>Estado</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($stockRows as $row): ?>
            <?php $low = (int)$row['cantidad'] <= (int)$row['stock_minimo']; ?>
            <tr>
                <td><?= e($row['codigo']) ?></td>
                <td><?= e($row['nombre']) ?></td>
                <td><?= e($row['categoria']) ?></td>
                <td><?= e($row['tienda']) ?></td>
                <td><?= number_format((int)$row['cantidad']) ?></td>
                <td><?= number_format((int)$row['stock_minimo']) ?></td>
                <td>
                    <span class="badge <?= $low ? 'low' : 'ok' ?>">
                        <?= $low ? 'Stock bajo' : 'Disponible' ?>
                    </span>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="section">
    <h2>Movimientos recientes</h2>

    <table>
        <thead>
            <tr>
                <th>Fecha</th>
                <th>Producto</th>
                <th>Tienda</th>
                <th>Tipo</th>
                <th>Cantidad</th>
                <th>Referencia</th>
                <th>Usuario</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($movimientos as $mov): ?>
            <tr>
                <td><?= e($mov['created_at']) ?></td>
                <td><?= e($mov['producto']) ?></td>
                <td><?= e($mov['tienda']) ?></td>
                <td><span class="badge info-badge"><?= e($mov['tipo']) ?></span></td>
                <td><?= number_format((int)$mov['cantidad']) ?></td>
                <td><?= e($mov['referencia']) ?></td>
                <td><?= e($mov['usuario'] ?? 'Sistema') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php elseif ($page === 'compras'): ?>

<div class="section">
    <h2>Registrar compra</h2>

    <?php if (in_array($user['rol'], ['ADMINISTRADOR','ALMACENERO'], true)): ?>

    <form method="post" class="form">
        <input type="hidden" name="action" value="registrar_compra">

        <div class="field">
            <label>Proveedor</label>
            <select name="proveedor_id" required>
                <option value="">Seleccionar proveedor</option>
                <?php foreach ($proveedores as $proveedor): ?>
                    <option value="<?= $proveedor['id'] ?>">
                        <?= e($proveedor['razon_social']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label>Producto</label>
            <select name="producto_id" required>
                <?php foreach ($productos as $producto): ?>
                    <option value="<?= $producto['id'] ?>">
                        <?= e($producto['codigo']) ?> — <?= e($producto['nombre']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label>Destino</label>
            <select name="tienda_id" required>
                <?php foreach ($tiendas as $tienda): ?>
                    <option value="<?= $tienda['id'] ?>"><?= e($tienda['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label>Cantidad</label>
            <input type="number" name="cantidad" min="1" value="1" required>
        </div>

        <div class="field">
            <label>Precio unitario de compra</label>
            <input type="number" name="precio_unitario" min="0" step="0.01" value="0.00" required>
        </div>

        <div class="full">
            <button type="submit">Registrar compra y actualizar stock</button>
        </div>
    </form>

    <?php else: ?>

        <div class="note">Tu rol no tiene permisos para registrar compras.</div>

    <?php endif; ?>
</div>

<div class="section">
    <h2>Registrar proveedor</h2>

    <?php if ($user['rol'] === 'ADMINISTRADOR'): ?>

    <form method="post" class="form">
        <input type="hidden" name="action" value="registrar_proveedor">

        <div class="field">
            <label>RUC</label>
            <input type="text" name="ruc">
        </div>

        <div class="field">
            <label>Razón social</label>
            <input type="text" name="razon_social" required>
        </div>

        <div class="field">
            <label>Teléfono</label>
            <input type="text" name="telefono">
        </div>

        <div class="field">
            <label>Correo</label>
            <input type="email" name="email">
        </div>

        <div class="field full">
            <label>Dirección</label>
            <input type="text" name="direccion">
        </div>

        <div class="full">
            <button type="submit">Guardar proveedor</button>
        </div>
    </form>

    <?php endif; ?>
</div>

<div class="section">
    <h2>Proveedores registrados</h2>

    <table>
        <thead>
            <tr>
                <th>RUC</th>
                <th>Razón social</th>
                <th>Teléfono</th>
                <th>Correo</th>
                <th>Dirección</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($proveedores as $proveedor): ?>
            <tr>
                <td><?= e($proveedor['ruc']) ?></td>
                <td><?= e($proveedor['razon_social']) ?></td>
                <td><?= e($proveedor['telefono']) ?></td>
                <td><?= e($proveedor['email']) ?></td>
                <td><?= e($proveedor['direccion']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="section">
    <h2>Compras recientes</h2>

    <table>
        <thead>
            <tr>
                <th>Número</th>
                <th>Fecha</th>
                <th>Proveedor</th>
                <th>Destino</th>
                <th>Total</th>
                <th>Estado</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($comprasRecientes as $compra): ?>
            <tr>
                <td><?= e($compra['numero']) ?></td>
                <td><?= e($compra['created_at']) ?></td>
                <td><?= e($compra['proveedor']) ?></td>
                <td><?= e($compra['tienda']) ?></td>
                <td><?= money($compra['total']) ?></td>
                <td><span class="badge ok"><?= e($compra['estado']) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php elseif ($page === 'reportes'): ?>

<div class="cards">
    <div class="card">
        <div class="label">Ventas del mes</div>
        <div class="value"><?= money($subtotalVentasMes) ?></div>
    </div>

    <div class="card">
        <div class="label">Compras del mes</div>
        <div class="value"><?= money($totalComprasMes) ?></div>
    </div>

    <div class="card">
        <div class="label">Costo de ventas</div>
        <div class="value"><?= money($costoVentasMes) ?></div>
    </div>

    <div class="card">
        <div class="label">Margen bruto</div>
        <div class="value"><?= number_format($margenMes, 1) ?>%</div>
    </div>
</div>

<div class="grid">

<div class="section">
    <h2>Ventas por tienda — mes actual</h2>

    <?php
    $maxVenta = 0;
    foreach ($ventasPorTienda as $v) {
        $maxVenta = max($maxVenta, (float)$v['total']);
    }
    ?>

    <?php foreach ($ventasPorTienda as $ventaTienda): ?>
        <?php
        $width = $maxVenta > 0
            ? ((float)$ventaTienda['total'] / $maxVenta) * 100
            : 0;
        ?>
        <div class="chartbar">
            <div class="row">
                <span><?= e($ventaTienda['nombre']) ?></span>
                <strong><?= money($ventaTienda['total']) ?></strong>
            </div>
            <div class="bar">
                <span style="width:<?= $width ?>%"></span>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="section">
    <h2>Productos de mayor rotación</h2>

    <?php foreach ($ventasPorProducto as $productoVenta): ?>
        <div class="store">
            <strong><?= e($productoVenta['nombre']) ?></strong><br>
            <span style="color:var(--muted)">
                <?= number_format((int)$productoVenta['unidades']) ?> unidades ·
                <?= money($productoVenta['total']) ?>
            </span>
        </div>
    <?php endforeach; ?>
</div>

</div>

<div class="section">
    <h2>Indicadores del negocio</h2>

    <div class="kpis">
        <div class="mini">
            Ventas
            <strong><?= money($subtotalVentasMes) ?></strong>
        </div>

        <div class="mini">
            Costo
            <strong><?= money($costoVentasMes) ?></strong>
        </div>

        <div class="mini">
            Utilidad bruta estimada
            <strong><?= money($subtotalVentasMes - $costoVentasMes) ?></strong>
        </div>
    </div>
</div>

<?php elseif ($page === 'usuarios'): ?>

<?php if ($user['rol'] !== 'ADMINISTRADOR'): ?>

<div class="section">
    <div class="note">
        Solo el Administrador puede gestionar usuarios y roles.
    </div>
</div>

<?php else: ?>

<div class="section">
    <h2>Crear usuario</h2>

    <form method="post" class="form">
        <input type="hidden" name="action" value="crear_usuario">

        <div class="field">
            <label>Nombre completo</label>
            <input type="text" name="nombre" required>
        </div>

        <div class="field">
            <label>Usuario</label>
            <input type="text" name="usuario" required>
        </div>

        <div class="field">
            <label>Contraseña</label>
            <input type="password" name="password" minlength="6" required>
        </div>

        <div class="field">
            <label>Rol</label>
            <select name="rol">
                <option value="ADMINISTRADOR">Administrador</option>
                <option value="VENDEDOR">Vendedor</option>
                <option value="CAJERO">Cajero</option>
                <option value="ALMACENERO">Almacenero</option>
                <option value="GERENTE">Gerente</option>
            </select>
        </div>

        <div class="field">
            <label>Tienda</label>
            <select name="tienda_id">
                <option value="0">Todas / Sin asignar</option>
                <?php foreach ($tiendas as $tienda): ?>
                    <option value="<?= $tienda['id'] ?>">
                        <?= e($tienda['nombre']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="full">
            <button type="submit">Crear usuario</button>
        </div>
    </form>
</div>

<div class="section">
    <h2>Usuarios registrados</h2>

    <table>
        <thead>
            <tr>
                <th>Nombre</th>
                <th>Usuario</th>
                <th>Rol</th>
                <th>Tienda</th>
                <th>Estado</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($usuarios as $usuario): ?>
            <tr>
                <td><?= e($usuario['nombre']) ?></td>
                <td><?= e($usuario['usuario']) ?></td>
                <td><span class="badge info-badge"><?= e($usuario['rol']) ?></span></td>
                <td><?= e($usuario['tienda'] ?? 'Todas') ?></td>
                <td>
                    <span class="badge <?= $usuario['estado'] ? 'ok' : 'low' ?>">
                        <?= $usuario['estado'] ? 'Activo' : 'Inactivo' ?>
                    </span>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php endif; ?>

<?php elseif ($page === 'config'): ?>

<div class="section">
    <h2>Configuración del sistema</h2>

    <div class="kpis">
        <div class="mini">
            Seguridad
            <strong style="font-size:16px">Roles y permisos</strong>
            <span style="color:var(--muted)">
                Control de acceso mediante sesión y roles.
            </span>
        </div>

        <div class="mini">
            Base de datos
            <strong style="font-size:16px">MySQL + PDO</strong>
            <span style="color:var(--muted)">
                Datos centralizados en ferre_tornillo.
            </span>
        </div>

        <div class="mini">
            Auditoría
            <strong style="font-size:16px">Activa</strong>
            <span style="color:var(--muted)">
                Operaciones importantes quedan registradas.
            </span>
        </div>
    </div>
</div>

<div class="section">
    <h2>Arquitectura de la demostración</h2>

    <div class="note">
        <strong>Frontend:</strong> HTML + CSS integrado en index.php<br><br>
        <strong>Backend:</strong> PHP 8 + PDO<br><br>
        <strong>Base de datos:</strong> MySQL<br><br>
        <strong>Autenticación:</strong> sesiones PHP + password_hash/password_verify<br><br>
        <strong>Seguridad:</strong> consultas preparadas, control de roles y auditoría<br><br>
        <strong>Inventario:</strong> stock independiente por tienda<br><br>
        <strong>Integración:</strong> ventas, inventario, compras y reportes conectados
    </div>
</div>

<div class="section">
    <h2>Integración con SUNAT</h2>

    <div class="note">
        En esta versión académica, la emisión de boletas y facturas está
        representada por el registro del tipo de comprobante.
        Para producción se incorporaría un servicio/API de facturación electrónica
        autorizado y el flujo correspondiente de envío y validación.
    </div>
</div>

<div class="section">
    <h2>Funcionamiento offline</h2>

    <div class="note">
        La arquitectura de esta versión está preparada para demostración
        local. El funcionamiento offline real con sincronización posterior
        requeriría incorporar almacenamiento local, cola de operaciones,
        identificadores únicos y un servicio de sincronización.
    </div>
</div>

<?php endif; ?>

</main>
</div>
</body>
</html>