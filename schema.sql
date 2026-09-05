-- ============================================================
-- PUESTO PARQUE DE LAS BANDERAS — Esquema de base de datos
-- ============================================================
-- Reemplaza el localStorage del frontend por persistencia real
-- en MySQL, consumida vía la API en /api/*.php
-- ============================================================


CREATE DATABASE IF NOT EXISTS puesto_pos
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE puesto_pos;

-- ------------------------------------------------------------
-- 1a. COLORES
-- Paleta reutilizable para pintar los botones de producto en el
-- frontend. Cada fila es un PAR de colores (fondo suave + acento
-- fuerte) en hex — el frontend los aplica directo con `style`, sin
-- pasar por variables CSS ni por index.css. Agregar un producto de
-- venta nuevo y asignarle un color_id existente (o crear una fila
-- de color nueva aquí) lo pinta correctamente sin tocar ningún
-- archivo de código ni recompilar.
-- ------------------------------------------------------------
CREATE TABLE colores (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(30) NOT NULL,   -- solo descriptivo, ej. 'marrón café', 'azul leche'
  fondo VARCHAR(7) NOT NULL,     -- hex del fondo suave, ej. '#fff8ee'
  acento VARCHAR(7) NOT NULL     -- hex del acento fuerte, ej. '#a05c1a'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO colores (nombre, fondo, acento) VALUES
  ('marrón café',   '#fff8ee', '#a05c1a'),
  ('azul leche',    '#eef4ff', '#3a5fa0'),
  ('dorado pan',    '#fffbee', '#9a7020'),
  ('rosa gomitas',  '#fff0f8', '#a03a70'),
  ('verde sandwich','#f0fbf4', '#1a7a4a');

-- ------------------------------------------------------------
-- 1b. PRODUCTOS
-- Catálogo de productos de venta (lo que compra el cliente final)
-- e insumos de compra (lo que el negocio compra a proveedores).
--
-- color_id: solo aplica a productos tipo='venta' (NULL en insumos
-- de compra) — referencia a `colores`, define cómo se pinta su
-- botón en la grilla de venta.
--
-- rendimiento_compra: solo aplica a productos tipo='compra' (NULL
-- en productos de venta) — cuántas porciones/unidades entrega UNA
-- compra de este insumo (ej. bolsa_cafe = 160). Reemplaza el array
-- RENDIMIENTOS que antes vivía hardcodeado en comprar.php: agregar
-- un insumo nuevo aquí, con su rendimiento, hace que comprarlo
-- sume al inventario correctamente sin tocar ningún archivo PHP.
-- NULL significa "no se trackea en inventario" (como azúcar hoy).
-- ------------------------------------------------------------
CREATE TABLE productos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  clave VARCHAR(30) NOT NULL UNIQUE,       -- 'cafe','leche','pan','gomitas','bolsa_cafe',...
  nombre VARCHAR(50) NOT NULL,
  emoji VARCHAR(10) DEFAULT '',
  precio INT NOT NULL,                     -- precio de venta o costo de compra unitario
  tipo ENUM('venta', 'compra') NOT NULL,
  color_id INT NULL,                       -- solo productos tipo='venta'
  rendimiento_compra INT NULL,             -- solo productos tipo='compra'
  FOREIGN KEY (color_id) REFERENCES colores(id)
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
-- 4b. PEDIDOS (carrito de una cuenta, antes de cobrar)
-- Solo debe existir UN pedido con estado 'abierto' a la vez —
-- lo hace cumplir un índice único parcial simulado en la API
-- (MySQL no soporta índices únicos filtrados directamente).
-- Al "Cobrar", el pedido pasa a 'cobrado' y recién ahí se generan
-- las filas reales en `ventas` + se descuenta `inventario` (una
-- fila de ventas por cada línea de pedido_items).
-- Al "Cancelar", el pedido (y sus items) se borran sin dejar
-- rastro en ventas ni tocar inventario.
-- ------------------------------------------------------------
CREATE TABLE pedidos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  estado ENUM('abierto', 'cobrado') NOT NULL DEFAULT 'abierto',
  monto_total INT NOT NULL DEFAULT 0,
  creado_en DATETIME DEFAULT CURRENT_TIMESTAMP,
  cobrado_en DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 4c. PEDIDO_ITEMS (líneas del carrito: café×6, pan×4, ...)
-- ------------------------------------------------------------
CREATE TABLE pedido_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  pedido_id INT NOT NULL,
  producto_id INT NOT NULL,
  cantidad INT NOT NULL,
  subtotal INT NOT NULL,
  FOREIGN KEY (pedido_id) REFERENCES pedidos(id) ON DELETE CASCADE,
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

-- Productos de venta (color_id: 1=marrón café, 2=azul leche, 3=dorado pan,
-- 4=rosa gomitas, 5=verde sandwich — ver tabla `colores` arriba)
INSERT INTO productos (clave, nombre, emoji, precio, tipo, color_id) VALUES
  ('cafe',     'CAFÉ',           '☕', 1500, 'venta', 1),
  ('leche',    'CAFÉ C/ LECHE',  '🥛', 2000, 'venta', 2),
  ('pan',      'PAN',            '🥐', 1000, 'venta', 3),
  ('gomitas',  'GOMITAS / OTRO', '🍬',  500, 'venta', 4),
  ('sandwich', 'SANDWICH',       '🥪', 5000, 'venta', 5);

-- Insumos de compra (rendimiento_compra: porciones/unidades que suma el
-- inventario al comprar UNA unidad de este insumo; NULL = no se trackea)
INSERT INTO productos (clave, nombre, emoji, precio, tipo, rendimiento_compra) VALUES
  ('bolsa_cafe',   'Bolsa Café 500g',       '☕', 27000, 'compra', 160),
  ('leche_polvo',  'Leche Polvo 1000g',     '🥛', 21000, 'compra', 100),
  ('azucar',       'Bolsa Azúcar',          '🍚',  3000, 'compra', NULL),
  ('vasos',        'Paquete Vasos 5oz',     '🥤',  2500, 'compra', 50),
  ('p_jamon',      'Paquete Jamón',         '🍖', 12000, 'compra', 20),
  ('p_queso',      'Paquete Queso',         '🧀', 11000, 'compra', 16),
  ('p_pan',        'Paquete Pan Sandwich',  '🍞', 11000, 'compra', 20),
  ('lote_pan',     'Lote de Pan',           '🥐', 10000, 'compra', NULL); -- precio referencial; cantidad variable por lote, no usa rendimiento fijo

-- Inventario inicial en 0 para los insumos trackeados (rendimiento_compra NOT NULL)
INSERT INTO inventario (producto_id, stock_actual, stock_minimo)
SELECT id, 0, 10 FROM productos WHERE clave IN ('bolsa_cafe', 'leche_polvo', 'vasos', 'pan', 'p_jamon', 'p_queso', 'p_pan');

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

-- 1 sandwich = 1 tajada de jamón + 1 tajada de queso + 2 tajadas de pan
INSERT INTO recetas (producto_venta_id, insumo_id, cantidad_consumida)
SELECT
  (SELECT id FROM productos WHERE clave = 'sandwich'),
  (SELECT id FROM productos WHERE clave = 'p_jamon'), 1
UNION ALL SELECT
  (SELECT id FROM productos WHERE clave = 'sandwich'),
  (SELECT id FROM productos WHERE clave = 'p_queso'), 1
UNION ALL SELECT
  (SELECT id FROM productos WHERE clave = 'sandwich'),
  (SELECT id FROM productos WHERE clave = 'p_pan'), 2;

-- 1 pan vendido = 1 unidad de pan (mismo producto, se descuenta directo en ventas.php)
-- gomitas: sin receta, no descuenta insumo

-- Estas equivalencias ahora viven en la columna `productos.rendimiento_compra`
-- (no hay que buscarlas en comentarios ni en código — son datos consultables):
--   SELECT clave, rendimiento_compra FROM productos WHERE tipo = 'compra';
