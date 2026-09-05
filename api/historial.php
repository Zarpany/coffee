<?php
/**
 * GET /api/historial.php
 * Historial de ventas y compras desde el último cierre de día
 * (equivalente al "log del día" que el frontend mantenía en memoria).
 *
 * GET /api/historial.php?resumen=1
 * Devuelve el resumen consolidado por fecha (para reportes históricos).
 */
require_once __DIR__ . '/db.php';

$pdo = get_db();

if (isset($_GET['resumen'])) {
    $resumen = $pdo->query("
        SELECT
            DATE(fecha_hora) AS fecha,
            COUNT(id) AS total_transacciones,
            SUM(monto_total) AS total_ingresado
        FROM ventas
        GROUP BY DATE(fecha_hora)
        ORDER BY fecha DESC
    ")->fetchAll();

    json_response(['resumen' => $resumen]);
}

$fechaUltimoCierre = $pdo->query("
    SELECT cerrado_en FROM cierres_dia ORDER BY id DESC LIMIT 1
")->fetchColumn();

$paramsVentas = [];
$whereVentas = "";
if ($fechaUltimoCierre) {
    $whereVentas = "WHERE v.fecha_hora > :desde";
    $paramsVentas = ['desde' => $fechaUltimoCierre];
}

$stmt = $pdo->prepare("
    SELECT v.id, p.clave AS producto_clave, p.nombre AS producto, p.emoji,
           v.cantidad, v.monto_total, v.fecha_hora
    FROM ventas v
    INNER JOIN productos p ON v.producto_id = p.id
    $whereVentas
    ORDER BY v.fecha_hora DESC
");
$stmt->execute($paramsVentas);
$ventas = $stmt->fetchAll();

$whereCompras = $fechaUltimoCierre ? "WHERE c.fecha_hora > :desde" : "";
$stmt = $pdo->prepare("
    SELECT c.id, p.clave AS producto_clave, p.nombre AS producto, p.emoji,
           c.descripcion, c.cantidad, c.monto_total, c.fecha_hora
    FROM compras c
    LEFT JOIN productos p ON c.producto_id = p.id
    $whereCompras
    ORDER BY c.fecha_hora DESC
");
$stmt->execute($paramsVentas);
$compras = $stmt->fetchAll();

json_response([
    'ventas' => $ventas,
    'compras' => $compras,
]);
