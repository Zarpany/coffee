import { useState, useRef, useEffect, useCallback } from "react";

// ─── Config ─────────────────────────────────────────────────────────────────
// Ajusta esta base si tu API PHP vive en otra ruta/dominio.
const API_BASE = "/api";

// ─── Types ────────────────────────────────────────────────────────────────────
type ProductId = "cafe" | "leche" | "pan" | "gomitas";

interface ProductoApi {
  id: number;
  clave: ProductId;
  nombre: string;
  emoji: string;
  precio: number;
  costo_unitario: number;
}

interface InventarioItem {
  clave: string;
  nombre: string;
  emoji: string;
  stock_actual: number;
  stock_minimo: number;
}

interface VentaLog {
  id: number;
  producto_clave: ProductId;
  producto: string;
  emoji: string;
  cantidad: number;
  monto_total: number;
  fecha_hora: string;
}

interface CompraLog {
  id: number;
  producto_clave: string | null;
  producto: string | null;
  emoji: string | null;
  descripcion: string | null;
  cantidad: number;
  monto_total: number;
  fecha_hora: string;
}

interface Balance {
  total_ventas: number;
  total_costos_insumos: number;
  total_compras: number;
  ganancia_dia: number;
  deuda_inicial: number;
  balance_real: number;
}

// ─── Static Data (solo presentación; los datos reales vienen de la API) ────────
const MISC_PURCHASES = [
  { clave: "bolsa_cafe",  label: "Café en grano (500g)", emoji: "☕", amount: 27000 },
  { clave: "leche_polvo", label: "Leche en polvo (1kg)", emoji: "🥛", amount: 21000 },
  { clave: "azucar",      label: "Azúcar",               emoji: "🍚", amount:  3000 },
  { clave: "vasos",       label: "Vasos 5oz",             emoji: "🥤", amount:  2500 },
];

const PAN_QTY_OPTIONS    = [20, 22, 24, 26];
const PAN_PRICE_OPTIONS  = [10000, 11000, 12000, 13000, 14000];
const PRODUCT_COLORS: Record<ProductId, { bg: string; accent: string }> = {
  cafe:    { bg: "var(--color-cafe)",    accent: "var(--color-cafe-accent)" },
  leche:   { bg: "var(--color-leche)",   accent: "var(--color-leche-accent)" },
  pan:     { bg: "var(--color-pan)",     accent: "var(--color-pan-accent)" },
  gomitas: { bg: "var(--color-gomitas)", accent: "var(--color-gomitas-accent)" },
};

const fmt = (n: number) => (n < 0 ? "-$" : "$") + Math.abs(n).toLocaleString("es-CL");

// ─── API helpers ──────────────────────────────────────────────────────────────
async function apiGet<T>(path: string): Promise<T> {
  const res = await fetch(`${API_BASE}/${path}`);
  if (!res.ok) throw new Error(`GET ${path} → ${res.status}`);
  return res.json();
}

async function apiPost<T>(path: string, body: unknown): Promise<T> {
  const res = await fetch(`${API_BASE}/${path}`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(body),
  });
  if (!res.ok) {
    const err = await res.json().catch(() => ({}));
    throw new Error(err.error || `POST ${path} → ${res.status}`);
  }
  return res.json();
}

// ─── App ──────────────────────────────────────────────────────────────────────
export default function App() {
  const [tab, setTab]           = useState<"ventas" | "compras">("ventas");
  const [multiplier, setMult]   = useState("");
  const [showEnd, setShowEnd]   = useState(false);
  const [showPanBatch, setShowPanBatch] = useState(false);
  const [panBatchQty,  setPanBatchQty]  = useState(20);
  const [panBatchPrice, setPanBatchPrice] = useState(10000);
  const [customAmount, setCustom] = useState("");
  const [flash, setFlash] = useState<{ msg: string; positive: boolean } | null>(null);

  // ── Datos que antes vivían en localStorage / memoria, ahora vienen del backend ──
  const [productos, setProductos] = useState<ProductoApi[]>([]);
  const [inventario, setInventario] = useState<InventarioItem[]>([]);
  const [ventasHoy, setVentasHoy] = useState<VentaLog[]>([]);
  const [comprasHoy, setComprasHoy] = useState<CompraLog[]>([]);
  const [balance, setBalance] = useState<Balance | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const flashTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

  // ── Carga inicial + refresco tras cada acción ──
  const refreshAll = useCallback(async () => {
    try {
      setError(null);
      const [catalogo, hist, bal] = await Promise.all([
        apiGet<{ productos: ProductoApi[]; inventario: InventarioItem[] }>("productos.php"),
        apiGet<{ ventas: VentaLog[]; compras: CompraLog[] }>("historial.php"),
        apiGet<Balance>("balance.php"),
      ]);
      setProductos(catalogo.productos);
      setInventario(catalogo.inventario);
      setVentasHoy(hist.ventas);
      setComprasHoy(hist.compras);
      setBalance(bal);
    } catch (e) {
      setError(e instanceof Error ? e.message : "Error al cargar datos");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    refreshAll();
  }, [refreshAll]);

  // ── Derived: costo unitario por clave, para mostrar en botones ──
  const unitCosts: Record<string, number> = {};
  productos.forEach(p => { unitCosts[p.clave] = p.costo_unitario; });

  const stockPorClave: Record<string, number> = {};
  inventario.forEach(i => { stockPorClave[i.clave] = i.stock_actual; });

  // ─── Actions ───────────────────────────────────────────────────────────────
  const triggerFlash = (msg: string, positive: boolean) => {
    setFlash({ msg, positive });
    if (flashTimer.current) clearTimeout(flashTimer.current);
    flashTimer.current = setTimeout(() => setFlash(null), 1400);
  };

  const handleProduct = async (item: ProductoApi) => {
    const qty = parseInt(multiplier || "1", 10);
    try {
      await apiPost("vender.php", { producto_clave: item.clave, cantidad: qty });
      triggerFlash(`+${qty > 1 ? qty + "× " : ""}${item.emoji} ${fmt(item.precio * qty)}`, true);
      setMult("");
      await refreshAll();
    } catch (e) {
      triggerFlash(e instanceof Error ? e.message : "Error al vender", false);
    }
  };

  const handlePurchase = async (claveOMotivo: string | null, amount: number, emoji: string, descripcion?: string) => {
    try {
      await apiPost("comprar.php", {
        producto_clave: claveOMotivo,
        monto_total: amount,
        descripcion: descripcion ?? null,
      });
      triggerFlash(`${emoji} −${fmt(amount)}`, false);
      await refreshAll();
    } catch (e) {
      triggerFlash(e instanceof Error ? e.message : "Error al comprar", false);
    }
  };

  const handlePanBatch = async () => {
    try {
      await apiPost("comprar.php", {
        producto_clave: "lote_pan",
        cantidad: panBatchQty,
        monto_total: panBatchPrice,
        descripcion: `Pan ×${panBatchQty}`,
      });
      triggerFlash(`🥐 −${fmt(panBatchPrice)}`, false);
      setShowPanBatch(false);
      await refreshAll();
    } catch (e) {
      triggerFlash(e instanceof Error ? e.message : "Error al comprar pan", false);
    }
  };

  const handleCustomPurchase = async () => {
    const val = parseInt(customAmount.replace(/\D/g, ""), 10);
    if (!val) return;
    await handlePurchase(null, val, "💸", "Otro gasto");
    setCustom("");
  };

  const handleNewDay = async () => {
    try {
      await apiPost("cerrar_dia.php", {});
      setShowEnd(false);
      await refreshAll();
    } catch (e) {
      triggerFlash(e instanceof Error ? e.message : "Error al cerrar el día", false);
    }
  };

  // ─── Date header ──────────────────────────────────────────────────────────
  const dateStr = new Date().toLocaleDateString("es-CL", {
    weekday: "long", day: "numeric", month: "short",
  }).toUpperCase();

  const totalSales     = balance?.total_ventas ?? 0;
  const totalCosts     = balance?.total_costos_insumos ?? 0;
  const totalPurchases = balance?.total_compras ?? 0;
  const gananciaHoy    = balance?.ganancia_dia ?? 0;
  const carryDebt      = balance?.deuda_inicial ?? 0;
  const balanceReal    = balance?.balance_real ?? 0;

  if (loading) {
    return <div className="app"><p style={{ padding: "2rem", textAlign: "center" }}>Cargando…</p></div>;
  }

  return (
    <div className="app">

      {/* ── Error banner ── */}
      {error && (
        <div style={{ background: "#fee2e2", color: "#991b1b", padding: "0.5rem 1rem", fontSize: "0.8rem" }}>
          ⚠ {error} — revisa que la API PHP esté disponible en {API_BASE}
        </div>
      )}

      {/* ── Header ── */}
      <header className="header">
        <p className="header-meta">Puesto Parque de las Banderas · {dateStr}</p>
        <div className="header-stats">
          <Stat
            label={balanceReal >= 0 ? "Balance real (ganancia)" : "Balance real (déficit)"}
            value={fmt(balanceReal)}
            color={balanceReal >= 0 ? "var(--color-green)" : "var(--color-red)"} />
          <Stat label="Ventas hoy"   value={fmt(totalSales)}     color="var(--color-amber)" />
          <Stat label="Compras hoy"  value={fmt(totalPurchases)} color="var(--color-red)" />
          {carryDebt > 0 && (
            <div className="debt-badge">⚠ Deuda anterior {fmt(carryDebt)}</div>
          )}
        </div>
        <button className="history-btn" title="Historial (próximamente)">🕐</button>
      </header>

      {/* ── Tabs ── */}
      <div className="tabs">
        <button className={`tab-btn ${tab === "ventas" ? "active-ventas" : ""}`}
          onClick={() => setTab("ventas")}>Ventas</button>
        <button className={`tab-btn ${tab === "compras" ? "active-compras" : ""}`}
          onClick={() => setTab("compras")}>Compras</button>
      </div>

      {/* ── Flash ── */}
      {flash && (
        <div className="flash-wrap">
          <div className={`flash-pill ${flash.positive ? "positive" : "negative"}`}>{flash.msg}</div>
        </div>
      )}

      {/* ════════════════ VENTAS TAB ════════════════ */}
      {tab === "ventas" && (
        <div className="page-content">

          {/* Multiplier strip */}
          <div className="mult-strip">
            <span className="mult-label">Cant.</span>
            {["2","3","4","5","6","8","10"].map(n => (
              <button key={n}
                className={`mult-btn ${multiplier === n ? "active" : ""}`}
                onClick={() => setMult(multiplier === n ? "" : n)}>
                {n}
              </button>
            ))}
            {multiplier && <span className="mult-active-label">×{multiplier} activo</span>}
          </div>

          {/* Product grid */}
          <div className="grid-2">
            {productos.map(item => (
              <ProductBtn key={item.clave} item={item} multiplier={multiplier}
                unitCost={unitCosts[item.clave] ?? 0} onTap={handleProduct} />
            ))}
          </div>

          {/* Bottom actions */}
          <div className="action-row">
            <button className="action-btn action-btn-secondary" onClick={() => setTab("compras")}>
              🛒 Registrar compra
            </button>
            <button className="action-btn action-btn-green" onClick={() => setShowEnd(true)}>
              📊 Finalizar día
            </button>
          </div>

          {/* Sales log */}
          {ventasHoy.length > 0 && (
            <div className="card">
              <p className="card-label">Últimas ventas</p>
              <div style={{ display: "flex", flexDirection: "column", gap: "0.2rem", maxHeight: "7rem", overflowY: "auto" }}>
                {ventasHoy.slice(0, 10).map((t) => {
                  const cost   = (unitCosts[t.producto_clave] ?? 0) * t.cantidad;
                  const margin = t.monto_total - cost;
                  return (
                    <div key={t.id} className="log-row">
                      <span className="log-row-name">
                        {t.emoji} {t.producto}{t.cantidad > 1 ? ` ×${t.cantidad}` : ""}
                      </span>
                      <div style={{ display: "flex", gap: "0.75rem", alignItems: "center" }}>
                        <span className="log-row-cost">costo {fmt(cost)}</span>
                        <span className="log-row-sale">{fmt(t.monto_total)}</span>
                        <span className="log-row-margin">+{fmt(margin)}</span>
                      </div>
                    </div>
                  );
                })}
              </div>
            </div>
          )}
        </div>
      )}

      {/* ════════════════ COMPRAS TAB ════════════════ */}
      {tab === "compras" && (
        <div className="page-content">

          <p className="card-label" style={{ marginBottom: 0 }}>Insumos recurrentes</p>

          <div className="grid-2">
            {/* Pan batch — full width */}
            <button className="purchase-btn purchase-btn-pan-batch" onClick={() => setShowPanBatch(true)}>
              <span className="purchase-btn-emoji">🥐</span>
              <div className="purchase-btn-pan-batch-info">
                <span className="purchase-btn-pan-batch-title">Compra de Pan</span>
                <span className="purchase-btn-pan-batch-sub">
                  20–26 unidades · stock actual {stockPorClave["pan"] ?? 0}
                </span>
              </div>
              <span className="purchase-btn-pan-batch-arrow">›</span>
            </button>

            {/* Misc insumos */}
            {MISC_PURCHASES.map(p => (
              <button key={p.clave} className="purchase-btn"
                onClick={() => handlePurchase(p.clave, p.amount, p.emoji)}>
                <span className="purchase-btn-emoji">{p.emoji}</span>
                <span className="purchase-btn-label">{p.label}</span>
                <span className="purchase-btn-price">−{fmt(p.amount)}</span>
              </button>
            ))}
          </div>

          {/* Inventory snapshot */}
          {inventario.length > 0 && (
            <div className="card">
              <p className="card-label">Inventario actual</p>
              <div style={{ display: "flex", flexDirection: "column", gap: "0.2rem" }}>
                {inventario.map(i => (
                  <div key={i.clave} className="log-row">
                    <span className="log-row-name">{i.emoji} {i.nombre}</span>
                    <span style={{
                      color: i.stock_actual <= i.stock_minimo ? "var(--color-red)" : "var(--color-text-soft)",
                      fontWeight: 700, fontSize: "0.8rem"
                    }}>
                      {i.stock_actual}
                    </span>
                  </div>
                ))}
              </div>
            </div>
          )}

          {/* Custom numpad */}
          <div className="card">
            <p className="card-label">Otro gasto</p>
            <NumPad value={customAmount} onChange={setCustom} onConfirm={handleCustomPurchase} />
          </div>

          {/* Purchase log */}
          {comprasHoy.length > 0 && (
            <div className="card">
              <p className="card-label">Compras del día</p>
              <div style={{ display: "flex", flexDirection: "column", gap: "0.2rem", maxHeight: "6rem", overflowY: "auto" }}>
                {comprasHoy.map((t) => (
                  <div key={t.id} className="log-row">
                    <span className="log-row-name">{t.descripcion ?? t.producto}</span>
                    <span style={{ color: "var(--color-red)", fontWeight: 700, fontSize: "0.8rem" }}>
                      −{fmt(t.monto_total)}
                    </span>
                  </div>
                ))}
              </div>
            </div>
          )}
        </div>
      )}

      {/* ════════════════ PAN BATCH MODAL ════════════════ */}
      {showPanBatch && (
        <div className="pan-modal-backdrop" onClick={() => setShowPanBatch(false)}>
          <div className="pan-modal" onClick={e => e.stopPropagation()}>
            <p className="pan-modal-title">🥐 Compra de Pan</p>

            <div>
              <p className="pill-group-label">Cantidad de panes</p>
              <div className="pill-group">
                {PAN_QTY_OPTIONS.map(n => (
                  <button key={n} className={`pill ${panBatchQty === n ? "active" : ""}`}
                    onClick={() => setPanBatchQty(n)}>
                    {n} panes
                  </button>
                ))}
              </div>
            </div>

            <div>
              <p className="pill-group-label">Precio del lote</p>
              <div className="pill-group">
                {PAN_PRICE_OPTIONS.map(p => (
                  <button key={p} className={`pill ${panBatchPrice === p ? "active" : ""}`}
                    onClick={() => setPanBatchPrice(p)}>
                    {fmt(p)}
                  </button>
                ))}
              </div>
            </div>

            <div className="pan-cost-preview">
              <span className="pan-cost-preview-label">Costo por unidad</span>
              <span className="pan-cost-preview-value">
                {fmt(Math.round(panBatchPrice / panBatchQty))} / pan
              </span>
            </div>

            <button className="pan-confirm-btn" onClick={handlePanBatch}>
              ✓ Registrar {panBatchQty} panes por {fmt(panBatchPrice)}
            </button>
            <button className="pan-cancel-btn" onClick={() => setShowPanBatch(false)}>Cancelar</button>
          </div>
        </div>
      )}

      {/* ════════════════ END OF DAY MODAL ════════════════ */}
      {showEnd && (
        <div className="modal-backdrop">
          <div className="modal-box">
            <div style={{ textAlign: "center" }}>
              <p style={{ fontSize: "2rem", margin: "0 0 0.25rem" }}>📊</p>
              <h2 className="modal-title">Liquidación del Día</h2>
              <p className="modal-subtitle">{dateStr}</p>
            </div>

            <div className="liq-surface">
              <LiqRow emoji="💰" label="Ventas totales"    value={fmt(totalSales)}    color="var(--color-amber)" />
              <LiqRow emoji="🛒" label="Compras realizadas" value={`−${fmt(totalPurchases)}`} color="var(--color-red)" />
              <hr className="liq-divider" />
              <LiqRow emoji="📈" label="Ganancia del día"  value={fmt(gananciaHoy)}
                color={gananciaHoy >= 0 ? "var(--color-green)" : "var(--color-red)"} />

              {carryDebt > 0 && (
                <>
                  <LiqRow emoji="⚠" label="Deuda anterior"  value={`−${fmt(carryDebt)}`} color="var(--color-red)" />
                  <hr className="liq-divider" />
                  <LiqRow emoji="✅" label="BALANCE REAL" value={fmt(balanceReal)}
                    color={balanceReal >= 0 ? "var(--color-green)" : "var(--color-red)"} large />
                </>
              )}

              {carryDebt === 0 && (
                <>
                  <hr className="liq-divider" />
                  <LiqRow emoji="✅" label="GANANCIA NETA" value={fmt(gananciaHoy)}
                    color={gananciaHoy >= 0 ? "var(--color-green)" : "var(--color-red)"} large />
                </>
              )}
            </div>

            {totalCosts > 0 && (
              <p style={{ fontSize: "0.7rem", color: "var(--color-text-faint)", textAlign: "center", margin: "0.25rem 0 0" }}>
                Costo estimado de insumos usados hoy: {fmt(totalCosts)} (referencial, ya incluido dentro de tus compras)
              </p>
            )}

            {balanceReal < 0 && (
              <div className="debt-note">
                ⚠ Quedarás con una deuda de {fmt(Math.abs(balanceReal))} que se arrastrará al día siguiente
                hasta ser cubierta con ganancias.
              </div>
            )}

            {balanceReal >= 0 && carryDebt > 0 && (
              <div style={{ background: "#dcfce7", border: "1px solid #86efac", borderRadius: "0.75rem",
                padding: "0.65rem 0.85rem", fontSize: "0.72rem", color: "var(--color-green)", fontWeight: 600 }}>
                ✅ ¡Deuda saldada! La deuda anterior de {fmt(carryDebt)} quedó cubierta.
              </div>
            )}

            <div className="modal-btn-row">
              <button className="modal-btn modal-btn-ghost" onClick={() => setShowEnd(false)}>
                Seguir vendiendo
              </button>
              <button className="modal-btn modal-btn-confirm" onClick={handleNewDay}>
                {balanceReal < 0 ? "Cerrar (con deuda)" : "Nueva jornada"}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

// ─── Sub-components ───────────────────────────────────────────────────────────

function ProductBtn({ item, multiplier, unitCost, onTap }: {
  item: ProductoApi; multiplier: string; unitCost: number; onTap: (i: ProductoApi) => void;
}) {
  const qty    = parseInt(multiplier || "1", 10);
  const total  = item.precio * qty;
  const cost   = unitCost * qty;
  const margin = total - cost;
  const colors = PRODUCT_COLORS[item.clave] ?? { bg: "var(--color-surface)", accent: "var(--color-text)" };

  return (
    <button className={`product-btn product-btn-${item.clave}`} onClick={() => onTap(item)}>
      <span className="product-btn-emoji">{item.emoji}</span>
      <span className="product-btn-name">{item.nombre}</span>
      <span className="product-btn-price" style={{ color: colors.accent }}>
        {qty > 1 ? `${fmt(total)} ×${qty}` : fmt(item.precio)}
      </span>
      <span className="product-btn-cost">
        costo {fmt(cost)} · +{fmt(margin)}
      </span>
    </button>
  );
}

function Stat({ label, value, color }: { label: string; value: string; color: string }) {
  return (
    <div>
      <p className="stat-label">{label}</p>
      <p className="stat-value" style={{ color }}>{value}</p>
    </div>
  );
}

function LiqRow({ emoji, label, value, color, large }: {
  emoji: string; label: string; value: string; color: string; large?: boolean;
}) {
  return (
    <div className="liq-row">
      <span className={`liq-row-label${large ? " large" : ""}`}>{emoji} {label}</span>
      <span className={`liq-row-value${large ? " large" : ""}`} style={{ color }}>{value}</span>
    </div>
  );
}

function NumPad({ value, onChange, onConfirm }: {
  value: string; onChange: (v: string) => void; onConfirm: () => void;
}) {
  const keys = ["1","2","3","4","5","6","7","8","9","000","0","⌫"];
  const press = (k: string) => {
    if (k === "⌫") onChange(value.slice(0, -1));
    else if (value.length < 7) onChange(value + k);
  };
  const display = value ? `$${parseInt(value).toLocaleString("es-CL")}` : "$0";
  return (
    <div style={{ display: "flex", flexDirection: "column", gap: "0.4rem" }}>
      <div className={`numpad-display ${!value ? "empty" : ""}`}>{display}</div>
      <div className="numpad-grid">
        {keys.map(k => (
          <button key={k} className="numpad-key" onClick={() => press(k)}>{k}</button>
        ))}
      </div>
      <button className="numpad-confirm" disabled={!value} onClick={onConfirm}>
        ➕ Registrar gasto
      </button>
    </div>
  );
}
