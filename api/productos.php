<?php
/**
 * GET /api/productos.php
 * Devuelve:
 * - productos: catálogo de venta con costo unitario dinámico (por receta)
 *   y su color (fondo/acento en hex, vía JOIN con `colores`) para pintar
 *   su botón en el frontend sin ninguna clase CSS por producto.
 * - inventario: stock actual de insumos trackeados
 * - insumos_compra: catálogo de insumos de compra a precio fijo, para
 *   pintar la grilla de "Insumos recurrentes" en la pestaña Compras SIN
 *   hardcodear nada en el frontend — agregar un insumo nuevo aquí en la
 *   base (tabla `productos`, tipo='compra') lo hace aparecer solo.
 *   EXCLUYE 'lote_pan': ese insumo tiene su propio modal de cantidad y
 *   precio variables (ver PanBatchModal en el frontend) y sigue siendo
 *   un caso especial fijo en el código, no uno de precio fijo genérico.
 *
 * Escalabilidad: agregar un producto de venta nuevo en `productos`
 * (con un color_id existente en `colores`, o creando uno nuevo) y su
 * receta en `recetas` lo hace aparecer completo — grilla, color y
 * costo unitario — sin tocar ni recompilar ningún archivo de código.
 * Lo mismo para un insumo de compra nuevo con su `rendimiento_compra`.
 */
require_once __DIR__ . '/db.php';

$pdo = get_db();

// Productos de venta, con su color (fondo/acento) vía JOIN con `colores`.
// LEFT JOIN: si un producto no tiene color_id asignado, fondo/acento
// vienen NULL y el frontend aplica su propio color por defecto.
$productosVenta = $pdo->query("
    SELECT p.id, p.clave, p.nombre, p.emoji, p.precio, c.fondo, c.acento
    FROM productos p
    LEFT JOIN colores c ON c.id = p.color_id
    WHERE p.tipo = 'venta'
")->fetchAll();

// Inventario de insumos trackeados (join con productos para nombre)
$inventario = $pdo->query("
    SELECT p.clave, p.nombre, p.emoji, i.stock_actual, i.stock_minimo
    FROM inventario i
    INNER JOIN productos p ON p.id = i.producto_id
")->fetchAll();

// Precio e insumo de compra (para calcular costo unitario por receta)
$costosInsumo = $pdo->query("
    SELECT p.clave, p.precio AS precio_base, p.rendimiento_compra
    FROM productos p
    WHERE p.tipo = 'compra'
")->fetchAll();
$costoPorClave = [];
$rendimientoPorClave = [];
foreach ($costosInsumo as $c) {
    $costoPorClave[$c['clave']] = (int)$c['precio_base'];
    $rendimientoPorClave[$c['clave']] = $c['rendimiento_compra'] !== null ? (int)$c['rendimiento_compra'] : null;
}

// Insumos de compra a precio fijo, para la grilla de "Insumos recurrentes"
// (todo tipo='compra' EXCEPTO lote_pan, que tiene su propio modal aparte)
$insumosCompra = $pdo->query("
    SELECT clave, nombre, emoji, precio
    FROM productos
    WHERE tipo = 'compra' AND clave != 'lote_pan'
    ORDER BY id ASC
")->fetchAll();
foreach ($insumosCompra as &$ins) {
    $ins['precio'] = (int)$ins['precio'];
}
unset($ins);

// Recetas por producto de venta, para calcular costo unitario dinámico
$recetas = $pdo->query("
    SELECT pv.clave AS producto_clave, pi.clave AS insumo_clave, r.cantidad_consumida
    FROM recetas r
    INNER JOIN productos pv ON pv.id = r.producto_venta_id
    INNER JOIN productos pi ON pi.id = r.insumo_id
")->fetchAll();

// Costo por porción = precio de compra del insumo ÷ su rendimiento_compra
// (ambos ya vienen de la base, no hay ningún número hardcodeado aquí)
$costoUnitario = [];
foreach ($recetas as $r) {
    $insumo = $r['insumo_clave'];
    $rendimiento = $rendimientoPorClave[$insumo] ?? 1;
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
    'productos'      => $productosVenta,
    'inventario'     => $inventario,
    'insumos_compra' => $insumosCompra,
]);
