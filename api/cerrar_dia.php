<?php
/**
 * POST /api/cerrar_dia.php
 * Calcula el balance del día en curso (reusa la misma lógica de balance.php)
 * y lo guarda como snapshot en `cierres_dia`. La deuda_resultante de este
 * cierre queda activa como deuda_inicial del próximo día hasta que un
 * balance positivo la cubra.
 *
 * ganancia_dia = total_ventas - total_compras. El costo estimado por
 * receta (total_costos_insumos) se guarda solo como dato informativo,
 * NO se resta aquí — ese gasto ya está contado en total_compras cuando
 * se registró la compra del insumo. Restarlo también aquí duplicaría
 * el descuento del mismo gasto real.
 */
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Método no permitido', 405);
}

$pdo = get_db();

try {
    $pdo->beginTransaction();

    // ── Recalcular el balance actual (misma lógica que balance.php) ──
    $ultimoCierre = $pdo->query("SELECT deuda_resultante, cerrado_en FROM cierres_dia ORDER BY id DESC LIMIT 1")->fetch();
    $deudaInicial = $ultimoCierre ? (int)$ultimoCierre['deuda_resultante'] : 0;
    $fechaUltimoCierre = $ultimoCierre ? $ultimoCierre['cerrado_en'] : null;

    $where = $fechaUltimoCierre ? "WHERE fecha_hora > :desde" : "";
    $params = $fechaUltimoCierre ? ['desde' => $fechaUltimoCierre] : [];

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(monto_total),0) FROM ventas $where");
    $stmt->execute($params);
    $totalVentas = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(monto_total),0) FROM compras $where");
    $stmt->execute($params);
    $totalCompras = (int)$stmt->fetchColumn();

    $sqlCostos = "
        SELECT COALESCE(SUM(
            r.cantidad_consumida * v.cantidad *
            (SELECT precio FROM productos WHERE id = r.insumo_id) /
            (CASE
                WHEN (SELECT clave FROM productos WHERE id = r.insumo_id) = 'bolsa_cafe' THEN 160
                WHEN (SELECT clave FROM productos WHERE id = r.insumo_id) = 'leche_polvo' THEN 100
                WHEN (SELECT clave FROM productos WHERE id = r.insumo_id) = 'vasos' THEN 50
                ELSE 1
            END)
        ), 0)
        FROM ventas v
        INNER JOIN recetas r ON r.producto_venta_id = v.producto_id
        " . ($fechaUltimoCierre ? "WHERE v.fecha_hora > :desde" : "");
    $stmt = $pdo->prepare($sqlCostos);
    $stmt->execute($params);
    $totalCostosInsumos = (int)round((float)$stmt->fetchColumn());

    $costoUnitarioPan = (int)($pdo->query("SELECT precio FROM productos WHERE clave = 'lote_pan'")->fetchColumn() ?: 0);
    $sqlPan = "
        SELECT COALESCE(SUM(v.cantidad), 0)
        FROM ventas v INNER JOIN productos p ON p.id = v.producto_id
        WHERE p.clave = 'pan'" . ($fechaUltimoCierre ? " AND v.fecha_hora > :desde" : "");
    $stmt = $pdo->prepare($sqlPan);
    $stmt->execute($params);
    $totalCostosInsumos += ((int)$stmt->fetchColumn()) * $costoUnitarioPan;

    $gananciaDia = $totalVentas - $totalCompras;
    $balanceReal = $gananciaDia - $deudaInicial;
    $deudaResultante = $balanceReal < 0 ? abs($balanceReal) : 0;

    // ── Guardar el cierre ──
    $stmt = $pdo->prepare("
        INSERT INTO cierres_dia
            (fecha, deuda_inicial, total_ventas, total_costos_insumos, total_compras, ganancia_dia, balance_real, deuda_resultante)
        VALUES (CURDATE(), ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$deudaInicial, $totalVentas, $totalCostosInsumos, $totalCompras, $gananciaDia, $balanceReal, $deudaResultante]);

    $pdo->commit();

    json_response([
        'ok' => true,
        'deuda_inicial' => $deudaInicial,
        'total_ventas' => $totalVentas,
        'total_costos_insumos' => $totalCostosInsumos,
        'total_compras' => $totalCompras,
        'ganancia_dia' => $gananciaDia,
        'balance_real' => $balanceReal,
        'deuda_resultante' => $deudaResultante,
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_error('Error al cerrar el día', 500, $e->getMessage());
}
