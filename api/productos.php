<?php
/**
 * GET /api/productos.php
 * Devuelve el catálogo de productos de venta con su costo unitario
 * actual (derivado de la última compra de sus insumos) y el stock
 * de insumos relacionados, para pintar la grilla del frontend.
 */
require_once __DIR__ . '/db.php';

$pdo = get_db();

// Productos de venta
$productosVenta = $pdo->query("SELECT id, clave, nombre, emoji, precio FROM productos WHERE tipo = 'venta'")->fetchAll();

// Inventario de insumos trackeados (join con productos para nombre)
$inventario = $pdo->query("
    SELECT p.clave, p.nombre, p.emoji, i.stock_actual, i.stock_minimo
    FROM inventario i
    INNER JOIN productos p ON p.id = i.producto_id
")->fetchAll();

// Costo unitario de insumos de compra (precio de la última compra registrada, o el precio base del catálogo)
$costosInsumo = $pdo->query("
    SELECT p.clave, p.precio AS precio_base
    FROM productos p
    WHERE p.tipo = 'compra'
")->fetchAll();
$costoPorClave = [];
foreach ($costosInsumo as $c) {
    $costoPorClave[$c['clave']] = (int)$c['precio_base'];
}

// Recetas por producto de venta, para calcular costo unitario dinámico
$recetas = $pdo->query("
    SELECT pv.clave AS producto_clave, pi.clave AS insumo_clave, r.cantidad_consumida
    FROM recetas r
    INNER JOIN productos pv ON pv.id = r.producto_venta_id
    INNER JOIN productos pi ON pi.id = r.insumo_id
")->fetchAll();

// Costo por porción de café/leche: se toma del precio de compra / rendimiento estándar
// café: 27000 / 160 porciones, leche: 21000 / 100 cucharadas, vasos: 2500 / 50
$rendimientos = ['bolsa_cafe' => 160, 'leche_polvo' => 100, 'vasos' => 50];

$costoUnitario = [];
foreach ($recetas as $r) {
    $insumo = $r['insumo_clave'];
    $rendimiento = $rendimientos[$insumo] ?? 1;
    $precioBase = $costoPorClave[$insumo] ?? 0;
    $costoPorPorcion = $rendimiento > 0 ? $precioBase / $rendimiento : 0;
    $costoUnitario[$r['producto_clave']] = ($costoUnitario[$r['producto_clave']] ?? 0)
        + $costoPorPorcion * (int)$r['cantidad_consumida'];
}

foreach ($productosVenta as &$p) {
    $p['precio'] = (int)$p['precio'];
    $p['costo_unitario'] = isset($costoUnitario[$p['clave']]) ? round($costoUnitario[$p['clave']]) : 0;
}
unset($p);

json_response([
    'productos'  => $productosVenta,
    'inventario' => $inventario,
]);