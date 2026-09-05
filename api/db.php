<?php
/**
 * Conexión centralizada a MySQL (PDO) + helpers de respuesta JSON.
 * Ajusta las credenciales según tu hosting.
 */

// ── CORS: ajusta el origen si sirves el frontend desde otro dominio ──
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Credenciales de tu hosting ──
define('DB_HOST', 'localhost');
define('DB_NAME', 'puesto_pos');
define('DB_USER', 'TU_USUARIO_MYSQL');
define('DB_PASS', 'TU_CLAVE_MYSQL');

function get_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
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
            json_error('No se pudo conectar a la base de datos', 500, $e->getMessage());
        }
    }
    return $pdo;
}

function json_response($data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error(string $message, int $status = 400, ?string $detail = null): void {
    http_response_code($status);
    echo json_encode(['error' => $message, 'detail' => $detail], JSON_UNESCAPED_UNICODE);
    exit;
}

function get_json_body(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        json_error('Cuerpo JSON inválido o vacío', 400);
    }
    return $data;
}

/**
 * Registra una venta de un producto (INSERT en `ventas`) y descuenta el
 * inventario correspondiente (por receta, o directo si es "pan").
 * NO abre ni cierra transacción — el llamador debe envolver esto en su
 * propio beginTransaction()/commit(), para poder agrupar varias líneas
 * (ej. un pedido completo) en una sola transacción atómica.
 *
 * @param PDO $pdo
 * @param array $producto  Fila de `productos` (debe incluir id, clave, precio, nombre)
 * @param int $cantidad
 * @return array{venta_id:int, monto_total:int}
 */
function registrar_venta_y_descontar(PDO $pdo, array $producto, int $cantidad): array {
    $montoTotal = (int)$producto['precio'] * $cantidad;

    $stmt = $pdo->prepare("INSERT INTO ventas (producto_id, cantidad, monto_total) VALUES (?, ?, ?)");
    $stmt->execute([$producto['id'], $cantidad, $montoTotal]);
    $ventaId = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare("SELECT insumo_id, cantidad_consumida FROM recetas WHERE producto_venta_id = ?");
    $stmt->execute([$producto['id']]);
    $receta = $stmt->fetchAll();

    $stmtUpdateInv = $pdo->prepare("UPDATE inventario SET stock_actual = stock_actual - ? WHERE producto_id = ?");

    foreach ($receta as $insumo) {
        $descuento = (int)$insumo['cantidad_consumida'] * $cantidad;
        $stmtUpdateInv->execute([$descuento, $insumo['insumo_id']]);
    }

    if ($producto['clave'] === 'pan') {
        $stmtUpdateInv->execute([$cantidad, $producto['id']]);
    }

    return ['venta_id' => $ventaId, 'monto_total' => $montoTotal];
}
