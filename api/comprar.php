<?php
/**
 * POST /api/comprar.php
 *
 * Dos formas de uso:
 *
 * A) Compra de insumo con rendimiento fijo (café, leche, azúcar, vasos):
 *    { "producto_clave": "bolsa_cafe", "monto_total": 27000 }
 *    -> suma automáticamente el rendimiento estándar al inventario
 *       (160 porciones café / 100 cucharadas leche / 50 vasos).
 *       Azúcar no tiene inventario trackeado: solo registra el gasto.
 *
 * B) Lote de pan con cantidad y precio variables:
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

// Rendimiento estándar por insumo (porciones/unidades que entrega cada compra)
const RENDIMIENTOS = [
    'bolsa_cafe'  => 160, // 500g -> 160 porciones de café (rendimiento real observado, ~2 días de venta)
    'leche_polvo' => 100, // 1000g -> 100 cucharadas
    'vasos'       => 50,  // 1 paquete -> 50 vasos
    // 'azucar' no tiene rendimiento: no se trackea en inventario
];

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
        // Compra de pan: cantidad variable, ajusta costo unitario del pan
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

        // Registrar compra
        $stmt = $pdo->prepare("INSERT INTO compras (producto_id, descripcion, cantidad, monto_total) VALUES (?, ?, ?, ?)");
        $stmt->execute([$productoId, $descripcion ?? "Pan ×$cantidad", $cantidad, $montoTotal]);

        // Sumar unidades al inventario de pan
        $stmt = $pdo->prepare("UPDATE inventario SET stock_actual = stock_actual + ? WHERE producto_id = ?");
        $stmt->execute([$cantidad, $productoId]);

        // Guardamos el costo unitario del lote como referencia en el insumo 'lote_pan'
        // (el precio de venta del pan, en la tabla producto 'pan', NO se toca aquí)
        $costoUnitario = (int)round($montoTotal / $cantidad);
        $stmt = $pdo->prepare("UPDATE productos SET precio = ? WHERE clave = 'lote_pan'");
        $stmt->execute([$costoUnitario]);

    } elseif ($claveProducto !== null && $claveProducto !== '') {
        // Compra de insumo estándar (bolsa_cafe, leche_polvo, azucar, vasos)
        $stmt = $pdo->prepare("SELECT id FROM productos WHERE clave = ? AND tipo = 'compra'");
        $stmt->execute([$claveProducto]);
        $insumo = $stmt->fetch();
        if (!$insumo) {
            $pdo->rollBack();
            json_error("Insumo '$claveProducto' no encontrado", 404);
        }
        $productoId = $insumo['id'];

        $stmt = $pdo->prepare("INSERT INTO compras (producto_id, descripcion, cantidad, monto_total) VALUES (?, ?, ?, ?)");
        $stmt->execute([$productoId, $descripcion, 1, $montoTotal]);

        if (array_key_exists($claveProducto, RENDIMIENTOS)) {
            $stmt = $pdo->prepare("UPDATE inventario SET stock_actual = stock_actual + ? WHERE producto_id = ?");
            $stmt->execute([RENDIMIENTOS[$claveProducto], $productoId]);
        }
        // Azúcar: no tiene fila en inventario, solo queda el gasto registrado.

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