<?php
/**
 * POST /api/comprar.php
 *
 * Dos formas de uso:
 *
 * A) Compra de insumo con rendimiento fijo (café, leche, vasos, jamón,
 *    queso, pan de sándwich, o cualquier insumo nuevo que agregues):
 *    { "producto_clave": "bolsa_cafe", "monto_total": 27000 }
 *    -> suma automáticamente el rendimiento al inventario. El rendimiento
 *       se lee de `productos.rendimiento_compra` (columna en la base) —
 *       agregar un insumo nuevo en phpMyAdmin con su rendimiento hace que
 *       comprarlo sume al inventario correctamente, SIN tocar este
 *       archivo ni ningún otro código. Si `rendimiento_compra` es NULL
 *       (como azúcar), el insumo no se trackea: solo registra el gasto.
 *       Si el insumo no tiene fila aún en `inventario`, se crea sola en
 *       0 antes de sumarle el rendimiento (evita que quede huérfano).
 *
 * B) Lote de pan (el que se vende suelto) con cantidad y precio variables:
 *    { "producto_clave": "lote_pan", "cantidad": 22, "monto_total": 10000, "descripcion": "Pan x22" }
 *    -> suma `cantidad` unidades al inventario de "pan" y ajusta el
 *       costo unitario del pan (monto_total / cantidad) para futuros cálculos.
 *
 * C) Gasto libre sin insumo asociado ("Otro gasto"):
 *    { "producto_clave": null, "monto_total": 8000, "descripcion": "Otro gasto" }
 *    -> solo registra el gasto en `compras`, sin tocar inventario.
 */
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Método no permitido', 405);
}

$body = get_json_body();
$claveProducto = isset($body['producto_clave']) ? trim((string)$body['producto_clave']) : null;
$montoTotal = (int)($body['monto_total'] ?? 0);
$cantidad = isset($body['cantidad']) ? (int)$body['cantidad'] : 1;
$descripcion = isset($body['descripcion']) ? trim((string)$body['descripcion']) : null;

if ($montoTotal <= 0) {
    json_error('monto_total (> 0) es requerido', 422);
}

$pdo = get_db();

try {
    $pdo->beginTransaction();

    $productoId = null;

    if ($claveProducto === 'lote_pan') {
        // Compra de pan suelto: cantidad variable, ajusta costo unitario del pan
        if ($cantidad <= 0) {
            $pdo->rollBack();
            json_error('cantidad (> 0) es requerida para lote_pan', 422);
        }
        $stmt = $pdo->prepare("SELECT id FROM productos WHERE clave = 'pan'");
        $stmt->execute();
        $pan = $stmt->fetch();
        if (!$pan) {
            $pdo->rollBack();
            json_error("Producto 'pan' no encontrado", 404);
        }
        $productoId = $pan['id'];

        $stmt = $pdo->prepare("INSERT INTO compras (producto_id, descripcion, cantidad, monto_total) VALUES (?, ?, ?, ?)");
        $stmt->execute([$productoId, $descripcion ?? "Pan ×$cantidad", $cantidad, $montoTotal]);

        $stmt = $pdo->prepare("UPDATE inventario SET stock_actual = stock_actual + ? WHERE producto_id = ?");
        $stmt->execute([$cantidad, $productoId]);

        $costoUnitario = (int)round($montoTotal / $cantidad);
        $stmt = $pdo->prepare("UPDATE productos SET precio = ? WHERE clave = 'lote_pan'");
        $stmt->execute([$costoUnitario]);

    } elseif ($claveProducto !== null && $claveProducto !== '') {
        // Compra de insumo estándar — el rendimiento se lee de la base
        $stmt = $pdo->prepare("SELECT id, rendimiento_compra FROM productos WHERE clave = ? AND tipo = 'compra'");
        $stmt->execute([$claveProducto]);
        $insumo = $stmt->fetch();
        if (!$insumo) {
            $pdo->rollBack();
            json_error("Insumo '$claveProducto' no encontrado", 404);
        }
        $productoId = $insumo['id'];
        $rendimiento = $insumo['rendimiento_compra'];

        $stmt = $pdo->prepare("INSERT INTO compras (producto_id, descripcion, cantidad, monto_total) VALUES (?, ?, ?, ?)");
        $stmt->execute([$productoId, $descripcion, 1, $montoTotal]);

        if ($rendimiento !== null) {
            // Asegura que exista la fila de inventario antes de sumarle
            // (un insumo nuevo recién agregado en `productos` puede no
            // tener aún su fila en `inventario` — la creamos en 0 si falta).
            $stmt = $pdo->prepare("SELECT id FROM inventario WHERE producto_id = ?");
            $stmt->execute([$productoId]);
            if (!$stmt->fetch()) {
                $pdo->prepare("INSERT INTO inventario (producto_id, stock_actual, stock_minimo) VALUES (?, 0, 10)")
                    ->execute([$productoId]);
            }

            $stmt = $pdo->prepare("UPDATE inventario SET stock_actual = stock_actual + ? WHERE producto_id = ?");
            $stmt->execute([(int)$rendimiento, $productoId]);
        }
        // rendimiento_compra NULL (ej. azúcar): no se trackea, solo queda el gasto registrado.

    } else {
        // Gasto libre sin insumo asociado ("Otro gasto")
        $stmt = $pdo->prepare("INSERT INTO compras (producto_id, descripcion, cantidad, monto_total) VALUES (NULL, ?, 1, ?)");
        $stmt->execute([$descripcion ?? 'Otro gasto', $montoTotal]);
    }

    $pdo->commit();

    json_response([
        'ok' => true,
        'producto_clave' => $claveProducto,
        'monto_total' => $montoTotal,
        'cantidad' => $cantidad,
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_error('Error al registrar la compra', 500, $e->getMessage());
}
