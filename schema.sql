-- ============================================================
-- PUESTO PARQUE DE LAS BANDERAS — Esquema de base de datos
-- ============================================================
-- Reemplaza el localStorage del frontend por persistencia real
-- en MySQL, consumida vía la API en /api/*.php
-- ============================================================
--
-- ⚠️ IMPORTANTE SOBRE EMOJIS (utf8mb4):
-- Cada CREATE TABLE de abajo fuerza CHARSET=utf8mb4 explícitamente.
-- Esto es necesario porque en hostings donde la base ya viene creada
-- desde un panel (como InfinityFree), la línea CREATE DATABASE de
-- aquí abajo se ignora, y las tablas heredarían el charset por
-- defecto de esa base (a veces "utf8" de 3 bytes o "latin1"), que
-- NO alcanza para representar emojis (necesitan 4 bytes) — el
-- resultado son signos "?" en vez de los emojis.
--
-- Si ya ejecutaste una versión anterior de este schema y ves "?" en
-- vez de emojis: no basta con cambiar el charset de la tabla ahora,
-- los datos ya insertados quedaron corrompidos. Hay que BORRAR la
-- tabla `productos` y volver a correr los INSERT de más abajo.
-- ============================================================

CREATE DATABASE IF NOT EXISTS puesto_pos
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE puesto_pos;

-- ------------------------------------------------------------
-- 1. PRODUCTOS
-- Catálogo de productos de venta (lo que compra el cliente final)
-- e insumos de compra (lo que el negocio compra a proveedores).
-- ------------------------------------------------------------
CREATE TABLE productos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  clave VARCHAR(30) NOT NULL UNIQUE,       -- 'cafe','leche','pan','gomitas','bolsa_cafe',...
  nombre VARCHAR(50) NOT NULL,
  emoji VARCHAR(10) DEFAULT '',
  precio INT NOT NULL,                     -- precio de venta o costo de compra unitario
  tipo ENUM('venta', 'compra') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 2. INVENTARIO
-- Solo para productos que se trackean por porciones/unidades:
-- café (porciones), leche (cucharadas), vasos (unidades), pan (unidades).
-- Azúcar y gomitas no se trackean (según decisión del negocio).
-- ------------------------------------------------------------
CREATE TABLE inventario (
  id INT AUTO_INCREMENT PRIMARY KEY,
  producto_id INT NOT NULL,
  stock_actual INT NOT NULL DEFAULT 0,
  stock_minimo INT NOT NULL DEFAULT 10,
  FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 3. VENTAS
-- Un registro por producto vendido (venta de "cafe", "leche", "pan", "gomitas")
-- ------------------------------------------------------------
CREATE TABLE ventas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  producto_id INT NOT NULL,
  cantidad INT NOT NULL,
  monto_total INT NOT NULL,
  fecha_hora DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (producto_id) REFERENCES productos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 4. COMPRAS
-- Un registro por cada compra de insumo o gasto general.
-- producto_id es NULL cuando es "Otro gasto" sin insumo asociado.
-- ------------------------------------------------------------
CREATE TABLE compras (
  id INT AUTO_INCREMENT PRIMARY KEY,
  producto_id INT NULL,
  descripcion VARCHAR(100) NULL,          -- usado en "Otro gasto" / lotes de pan con detalle
  cantidad INT NOT NULL DEFAULT 1,
  monto_total INT NOT NULL,
  fecha_hora DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (producto_id) REFERENCES productos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 5. RECETAS (insumos que consume cada producto de venta)
-- Ej: "cafe" consume 1 porción de "insumo_cafe" y 1 "insumo_vaso".
-- ------------------------------------------------------------
CREATE TABLE recetas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  producto_venta_id INT NOT NULL,          -- FK a productos (tipo venta)
  insumo_id INT NOT NULL,                  -- FK a productos (tipo compra, trackeado en inventario)
  cantidad_consumida INT NOT NULL DEFAULT 1,
  FOREIGN KEY (producto_venta_id) REFERENCES productos(id) ON DELETE CASCADE,
  FOREIGN KEY (insumo_id) REFERENCES productos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 6. CIERRES DE DÍA
-- Guarda el snapshot de cada jornada cerrada: lo que arrastraba
-- de deuda al iniciar el día y el balance con el que terminó.
-- La deuda "activa" es la deuda_resultante del último cierre.
-- ------------------------------------------------------------
CREATE TABLE cierres_dia (
  id INT AUTO_INCREMENT PRIMARY KEY,
  fecha DATE NOT NULL,
  deuda_inicial INT NOT NULL DEFAULT 0,
  total_ventas INT NOT NULL DEFAULT 0,
  total_costos_insumos INT NOT NULL DEFAULT 0,
  total_compras INT NOT NULL DEFAULT 0,
  ganancia_dia INT NOT NULL DEFAULT 0,        -- ventas - costos - compras
  balance_real INT NOT NULL DEFAULT 0,        -- ganancia_dia - deuda_inicial
  deuda_resultante INT NOT NULL DEFAULT 0,    -- max(0, -balance_real)
  cerrado_en DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- DATOS INICIALES (SEED)
-- ============================================================

-- Productos de venta
INSERT INTO productos (clave, nombre, emoji, precio, tipo) VALUES
  ('cafe',     'CAFÉ',           '☕', 1500, 'venta'),
  ('leche',    'CAFÉ C/ LECHE',  '🥛', 2000, 'venta'),
  ('pan',      'PAN',            '🥐', 1000, 'venta'),
  ('gomitas',  'GOMITAS / OTRO', '🍬',  500, 'venta');

-- Insumos de compra
INSERT INTO productos (clave, nombre, emoji, precio, tipo) VALUES
  ('bolsa_cafe',   'Bolsa Café 500g',    '☕', 27000, 'compra'),
  ('leche_polvo',  'Leche Polvo 1000g',  '🥛', 21000, 'compra'),
  ('azucar',       'Bolsa Azúcar',       '🍚',  3000, 'compra'),
  ('vasos',        'Paquete Vasos 5oz',  '🥤',  2500, 'compra'),
  ('lote_pan',     'Lote de Pan',        '🥐', 10000, 'compra'); -- precio referencial; se ajusta por venta

-- Inventario inicial en 0 para los insumos trackeados
-- (café: porciones, leche: cucharadas, vasos: unidades, pan: unidades)
INSERT INTO inventario (producto_id, stock_actual, stock_minimo)
SELECT id, 0, 10 FROM productos WHERE clave IN ('bolsa_cafe', 'leche_polvo', 'vasos', 'pan');

-- Nota: el inventario de "pan" usa producto_id de la clave 'pan' (producto de venta),
-- porque el pan se vende y se compra como la misma unidad (no hay transformación).
-- El inventario de café/leche/vasos usa el insumo de COMPRA porque son porciones/derivados.

-- Recetas: qué consume cada venta
-- 1 café = 1 porción de café + 1 vaso
INSERT INTO recetas (producto_venta_id, insumo_id, cantidad_consumida)
SELECT
  (SELECT id FROM productos WHERE clave = 'cafe'),
  (SELECT id FROM productos WHERE clave = 'bolsa_cafe'), 1
UNION ALL SELECT
  (SELECT id FROM productos WHERE clave = 'cafe'),
  (SELECT id FROM productos WHERE clave = 'vasos'), 1;

-- 1 café con leche = 1 porción de café + 1 cucharada de leche + 1 vaso
INSERT INTO recetas (producto_venta_id, insumo_id, cantidad_consumida)
SELECT
  (SELECT id FROM productos WHERE clave = 'leche'),
  (SELECT id FROM productos WHERE clave = 'bolsa_cafe'), 1
UNION ALL SELECT
  (SELECT id FROM productos WHERE clave = 'leche'),
  (SELECT id FROM productos WHERE clave = 'leche_polvo'), 1
UNION ALL SELECT
  (SELECT id FROM productos WHERE clave = 'leche'),
  (SELECT id FROM productos WHERE clave = 'vasos'), 1;

-- 1 pan vendido = 1 unidad de pan (mismo producto, se descuenta directo en ventas.php)
-- gomitas: sin receta, no descuenta insumo

-- Equivalencias de conversión (para referencia del equipo/documentación, no se usan en runtime):
--   1 bolsa_cafe   (500g,  $27.000) -> +160 porciones de café  al comprar
--   1 leche_polvo  (1000g, $21.000) -> +100 cucharadas de leche al comprar
--   1 vasos        (paquete, $2.500) -> +50 vasos al comprar
--   1 lote_pan     (22 uds típico)   -> +cantidad indicada al comprar (cantidad variable)