<?php
/**
 * Manejo del pedido (carrito) en curso. Solo puede existir UNO
 * con estado 'abierto' a la vez.
 *
 * GET  /api/pedido.php
 *   Devuelve el pedido abierto actual (o null si no hay ninguno) con sus items.
 *
 * POST /api/pedido.php  { "accion": "crear" }
 *   Crea un pedido nuevo en estado 'abierto'. Si ya hay uno abierto,
 *   simplemente lo devuelve (no crea uno segundo).
 *
 * POST /api/pedido.php  { "accion": "agregar", "producto_clave": "cafe", "cantidad": 1 }
 *   Suma `cantidad` unidades de ese producto al pedido abierto.
 *   Si el producto ya está en el carrito, incrementa su línea existente.
 *   Si cantidad hace que la línea llegue a 0 o menos, la elimina.
 *
 * POST /api/pedido.php  { "accion": "cancelar" }
 *   Borra el pedido abierto y sus items. No toca ventas ni inventario.
 */
require_once __DIR__ . '/db.php';

$pdo = get_db();

function get_pedido_abierto_con_items(PDO $pdo): ?array {
    $pedido = $pdo->query("SELECT * FROM pedidos WHERE estado = 'abierto' ORDER BY id DESC LIMIT 1")->fetch();
    if (!$pedido) return null;

    $stmt = $pdo->prepare("
        SELECT pi.id, pi.producto_id, p.clave AS producto_clave, p.nombre, p.emoji,
               pi.cantidad, pi.subtotal
        FROM pedido_items pi
        INNER JOIN productos p ON p.id = pi.producto_id
        WHERE pi.pedido_id = ?
        ORDER BY pi.id ASC
    ");
    $stmt->execute([$pedido['id']]);
    $pedido['items'] = $stmt->fetchAll();
    return $pedido;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    json_response(['pedido' => get_pedido_abierto_con_items($pdo)]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Método no permitido', 405);
}

$body = get_json_body();
$accion = trim($body['accion'] ?? '');

try {
    if ($accion === 'crear') {
        $pdo->beginTransaction();
        // Solo uno abierto a la vez: si ya existe, se reutiliza.
        $existente = $pdo->query("SELECT id FROM pedidos WHERE estado = 'abierto' ORDER BY id DESC LIMIT 1")->fetch();
        if (!$existente) {
            $pdo->prepare("INSERT INTO pedidos (estado, monto_total) VALUES ('abierto', 0)")->execute();
        }
        $pdo->commit();
        json_response(['pedido' => get_pedido_abierto_con_items($pdo)]);
    }

    if ($accion === 'agregar') {
        $claveProducto = trim($body['producto_clave'] ?? '');
        $cantidad = (int)($body['cantidad'] ?? 1); // puede ser negativo para restar

        if ($claveProducto === '' || $cantidad === 0) {
            json_error('producto_clave y cantidad (≠ 0) son requeridos', 422);
        }

        $pdo->beginTransaction();

        $pedido = $pdo->query("SELECT id FROM pedidos WHERE estado = 'abierto' ORDER BY id DESC LIMIT 1")->fetch();
        if (!$pedido) {
            $pdo->rollBack();
            json_error('No hay ningún pedido abierto. Crea uno primero.', 404);
        }
        $pedidoId = $pedido['id'];

        $stmt = $pdo->prepare("SELECT id, precio FROM productos WHERE clave = ? AND tipo = 'venta'");
        $stmt->execute([$claveProducto]);
        $producto = $stmt->fetch();
        if (!$producto) {
            $pdo->rollBack();
            json_error("Producto '$claveProducto' no encontrado", 404);
        }

        $stmt = $pdo->prepare("SELECT id, cantidad FROM pedido_items WHERE pedido_id = ? AND producto_id = ?");
        $stmt->execute([$pedidoId, $producto['id']]);
        $item = $stmt->fetch();

        $nuevaCantidad = ($item ? (int)$item['cantidad'] : 0) + $cantidad;

        if ($nuevaCantidad <= 0) {
            // Se quita del carrito por completo
            if ($item) {
                $pdo->prepare("DELETE FROM pedido_items WHERE id = ?")->execute([$item['id']]);
            }
        } elseif ($item) {
            $nuevoSubtotal = $nuevaCantidad * (int)$producto['precio'];
            $pdo->prepare("UPDATE pedido_items SET cantidad = ?, subtotal = ? WHERE id = ?")
                ->execute([$nuevaCantidad, $nuevoSubtotal, $item['id']]);
        } else {
            $subtotal = $nuevaCantidad * (int)$producto['precio'];
            $pdo->prepare("INSERT INTO pedido_items (pedido_id, producto_id, cantidad, subtotal) VALUES (?, ?, ?, ?)")
                ->execute([$pedidoId, $producto['id'], $nuevaCantidad, $subtotal]);
        }

        // Recalcular el total del pedido
        $totalStmt = $pdo->prepare("SELECT COALESCE(SUM(subtotal), 0) FROM pedido_items WHERE pedido_id = ?");
        $totalStmt->execute([$pedidoId]);
        $nuevoTotal = (int)$totalStmt->fetchColumn();
        $pdo->prepare("UPDATE pedidos SET monto_total = ? WHERE id = ?")->execute([$nuevoTotal, $pedidoId]);

        $pdo->commit();
        json_response(['pedido' => get_pedido_abierto_con_items($pdo)]);
    }

    if ($accion === 'cancelar') {
        $pdo->beginTransaction();
        $pedido = $pdo->query("SELECT id FROM pedidos WHERE estado = 'abierto' ORDER BY id DESC LIMIT 1")->fetch();
        if ($pedido) {
            // pedido_items se borra solo por ON DELETE CASCADE
            $pdo->prepare("DELETE FROM pedidos WHERE id = ?")->execute([$pedido['id']]);
        }
        $pdo->commit();
        json_response(['ok' => true]);
    }

    json_error("Acción '$accion' no reconocida", 422);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_error('Error al procesar el pedido', 500, $e->getMessage());
}
