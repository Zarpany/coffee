<?php
/**
 * POST /api/vender.php
 * Body: { "producto_clave": "cafe", "cantidad": 2 }
 *
 * Venta directa de UN producto (equivalente a tocar un botón sin usar
 * el carrito de pedido.php). Reutiliza registrar_venta_y_descontar()
 * de db.php, la misma función que usa cobrar.php para cada línea del
 * carrito — así el descuento de inventario es idéntico en ambos flujos.
 */
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Método no permitido', 405);
}

$body = get_json_body();
$claveProducto = trim($body['producto_clave'] ?? '');
$cantidad = (int)($body['cantidad'] ?? 1);

if ($claveProducto === '' || $cantidad <= 0) {
    json_error('producto_clave y cantidad (> 0) son requeridos', 422);
}

$pdo = get_db();

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT id, clave, nombre, precio FROM productos WHERE clave = ? AND tipo = 'venta'");
    $stmt->execute([$claveProducto]);
    $producto = $stmt->fetch();

    if (!$producto) {
        $pdo->rollBack();
        json_error("Producto de venta '$claveProducto' no encontrado", 404);
    }

    $resultado = registrar_venta_y_descontar($pdo, $producto, $cantidad);

    $pdo->commit();

    json_response([
        'ok' => true,
        'venta_id' => $resultado['venta_id'],
        'producto' => $producto['nombre'],
        'cantidad' => $cantidad,
        'monto_total' => $resultado['monto_total'],
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_error('Error al registrar la venta', 500, $e->getMessage());
}
