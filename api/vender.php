<?php
/**
 * POST /api/vender.php
 * Body: { "producto_clave": "cafe", "cantidad": 2 }
 *
 * 1. Inserta el registro en `ventas`.
 * 2. Por cada insumo en la receta del producto, descuenta del inventario
 *    (cantidad_consumida * cantidad vendida).
 * 3. Si el producto vendido es "pan", descuenta directo su propio inventario
 *    (el pan se compra y se vende como la misma unidad, sin receta de insumos).
 *
 * Todo dentro de una transacción: si algo falla, no queda venta huérfana.
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

    // 1. Buscar el producto de venta
    $stmt = $pdo->prepare("SELECT id, nombre, precio FROM productos WHERE clave = ? AND tipo = 'venta'");
    $stmt->execute([$claveProducto]);
    $producto = $stmt->fetch();

    if (!$producto) {
        $pdo->rollBack();
        json_error("Producto de venta '$claveProducto' no encontrado", 404);
    }

    $montoTotal = (int)$producto['precio'] * $cantidad;

    // 2. Registrar la venta
    $stmt = $pdo->prepare("INSERT INTO ventas (producto_id, cantidad, monto_total) VALUES (?, ?, ?)");
    $stmt->execute([$producto['id'], $cantidad, $montoTotal]);
    $ventaId = $pdo->lastInsertId();

    // 3. Descontar insumos según receta
    $stmt = $pdo->prepare("
        SELECT insumo_id, cantidad_consumida
        FROM recetas
        WHERE producto_venta_id = ?
    ");
    $stmt->execute([$producto['id']]);
    $receta = $stmt->fetchAll();

    $stmtUpdateInv = $pdo->prepare("
        UPDATE inventario SET stock_actual = stock_actual - ?
        WHERE producto_id = ?
    ");

    foreach ($receta as $insumo) {
        $descuento = (int)$insumo['cantidad_consumida'] * $cantidad;
        $stmtUpdateInv->execute([$descuento, $insumo['insumo_id']]);
    }

    // 4. Caso especial: el pan se vende y se compra como la misma unidad (sin receta)
    if ($claveProducto === 'pan') {
        $stmtUpdateInv->execute([$cantidad, $producto['id']]);
    }

    $pdo->commit();

    json_response([
        'ok' => true,
        'venta_id' => (int)$ventaId,
        'producto' => $producto['nombre'],
        'cantidad' => $cantidad,
        'monto_total' => $montoTotal,
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_error('Error al registrar la venta', 500, $e->getMessage());
}
