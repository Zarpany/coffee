<?php
/**
 * GET /api/balance.php
 * Balance del día EN CURSO (desde el último cierre hasta ahora):
 * - total_ventas: suma de precios de venta reales (sin descontar nada)
 * - total_compras: lo gastado en insumos/mercancía/otros gastos
 * - ganancia_dia = ventas - compras  (el gasto de insumos YA está en
 *   `total_compras`, así que NO se vuelve a restar un "costo estimado"
 *   por receta — eso duplicaría el descuento del mismo gasto real)
 * - deuda_inicial = deuda_resultante del último cierre en `cierres_dia`
 * - balance_real = ganancia_dia - deuda_inicial
 *
 * `total_costos_insumos` se sigue devolviendo, pero es solo un dato
 * INFORMATIVO (costo estimado por receta) para mostrar el margen
 * aproximado por producto en el frontend — no participa en ninguna
 * suma o resta del balance real.
 */
require_once __DIR__ . '/db.php';

$pdo = get_db();

// Deuda inicial: la deuda_resultante del cierre más reciente (0 si no hay cierres)
$ultimoCierre = $pdo->query("
    SELECT deuda_resultante FROM cierres_dia ORDER BY id DESC LIMIT 1
")->fetch();
$deudaInicial = $ultimoCierre ? (int)$ultimoCierre['deuda_resultante'] : 0;

// Fecha/hora del último cierre: solo contamos transacciones DESPUÉS de esa marca como "del día actual"
$fechaUltimoCierre = $pdo->query("
    SELECT cerrado_en FROM cierres_dia ORDER BY id DESC LIMIT 1
")->fetchColumn();

if ($fechaUltimoCierre) {
    $whereVentas = "WHERE fecha_hora > :desde";
    $whereCompras = "WHERE fecha_hora > :desde";
    $params = ['desde' => $fechaUltimoCierre];
} else {
    $whereVentas = "";
    $whereCompras = "";
    $params = [];
}

// Total de ventas del período actual (montos de venta reales, tal cual se cobraron)
$stmt = $pdo->prepare("SELECT COALESCE(SUM(monto_total), 0) AS total FROM ventas $whereVentas");
$stmt->execute($params);
$totalVentas = (int)$stmt->fetchColumn();

// Total de compras del período actual (esto YA es el gasto real en insumos/mercancía)
$stmt = $pdo->prepare("SELECT COALESCE(SUM(monto_total), 0) AS total FROM compras $whereCompras");
$stmt->execute($params);
$totalCompras = (int)$stmt->fetchColumn();

// ── Costo estimado por receta (SOLO informativo, no afecta el balance) ──
// Se usa únicamente para mostrar "costo $X · +$Y de margen" en cada botón
// de producto en el frontend.
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
    ), 0) AS total_costo
    FROM ventas v
    INNER JOIN recetas r ON r.producto_venta_id = v.producto_id
    " . ($fechaUltimoCierre ? "WHERE v.fecha_hora > :desde" : "");
$stmt = $pdo->prepare($sqlCostos);
$stmt->execute($params);
$totalCostosInsumos = (int)round((float)$stmt->fetchColumn());

$costoUnitarioPan = (int)($pdo->query("SELECT precio FROM productos WHERE clave = 'lote_pan'")->fetchColumn() ?: 0);
$sqlPan = "
    SELECT COALESCE(SUM(v.cantidad), 0) AS unidades
    FROM ventas v
    INNER JOIN productos p ON p.id = v.producto_id
    WHERE p.clave = 'pan'" . ($fechaUltimoCierre ? " AND v.fecha_hora > :desde" : "");
$stmt = $pdo->prepare($sqlPan);
$stmt->execute($params);
$unidadesPanVendidas = (int)$stmt->fetchColumn();
$totalCostosInsumos += $unidadesPanVendidas * $costoUnitarioPan;

// ── Balance real: solo ventas menos compras. El costo de insumos ya
//    quedó reflejado en total_compras cuando se registró esa compra;
//    no se resta una segunda vez aquí. ──
$gananciaDia = $totalVentas - $totalCompras;
$balanceReal = $gananciaDia - $deudaInicial;

json_response([
    'total_ventas' => $totalVentas,
    'total_costos_insumos' => $totalCostosInsumos, // informativo, no usado en ganancia_dia
    'total_compras' => $totalCompras,
    'ganancia_dia' => $gananciaDia,
    'deuda_inicial' => $deudaInicial,
    'balance_real' => $balanceReal,
]);
