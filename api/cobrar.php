<?php
/**
 * POST /api/cobrar.php
 * Cierra el pedido (carrito) abierto: por cada línea en `pedido_items`,
 * genera la venta real correspondiente en `ventas` (vía
 * registrar_venta_y_descontar, la misma función que usa vender.php) y
 * descuenta el inventario. Luego marca el pedido como 'cobrado'.
 *
 * Todo en una sola transacción — si algo falla a mitad de camino, no
 * quedan ventas parciales ni inventario descontado a medias.
 */
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Método no permitido', 405);
}

$pdo = get_db();

try {
    $pdo->beginTransaction();

    $pedido = $pdo->query("SELECT * FROM pedidos WHERE estado = 'abierto' ORDER BY id DESC LIMIT 1")->fetch();
    if (!$pedido) {
        $pdo->rollBack();
        json_error('No hay ningún pedido abierto para cobrar', 404);
    }

    $stmt = $pdo->prepare("
        SELECT pi.producto_id, pi.cantidad, p.clave, p.nombre, p.precio
        FROM pedido_items pi
        INNER JOIN productos p ON p.id = pi.producto_id
        WHERE pi.pedido_id = ?
    ");
    $stmt->execute([$pedido['id']]);
    $items = $stmt->fetchAll();

    if (count($items) === 0) {
        $pdo->rollBack();
        json_error('El pedido está vacío, agrega productos antes de cobrar', 422);
    }

    $ventasGeneradas = [];
    $montoTotal = 0;

    foreach ($items as $item) {
        $producto = [
            'id' => $item['producto_id'],
            'clave' => $item['clave'],
            'nombre' => $item['nombre'],
            'precio' => $item['precio'],
        ];
        $resultado = registrar_venta_y_descontar($pdo, $producto, (int)$item['cantidad']);
        $ventasGeneradas[] = [
            'producto' => $item['nombre'],
            'cantidad' => (int)$item['cantidad'],
            'monto_total' => $resultado['monto_total'],
        ];
        $montoTotal += $resultado['monto_total'];
    }

    $pdo->prepare("UPDATE pedidos SET estado = 'cobrado', monto_total = ?, cobrado_en = NOW() WHERE id = ?")
        ->execute([$montoTotal, $pedido['id']]);

    $pdo->commit();

    json_response([
        'ok' => true,
        'pedido_id' => (int)$pedido['id'],
        'monto_total' => $montoTotal,
        'ventas' => $ventasGeneradas,
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_error('Error al cobrar el pedido', 500, $e->getMessage());
}
