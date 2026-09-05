# Puesto Parque de las Banderas — POS

App de punto de venta para el puesto: café, café con leche, pan y
gomitas/otros. Frontend en React + TypeScript + Vite, backend en PHP +
MySQL.

## Estructura

```
src/            → código fuente React (App.tsx es el componente principal)
api/            → backend PHP (7 endpoints REST)
schema.sql      → esquema de base de datos MySQL
index.html      → punto de entrada de Vite
```

## Desarrollo local

Requiere Node.js instalado.

```bash
npm install
npm run dev       # servidor de desarrollo con recarga en caliente
npm run build     # genera la carpeta dist/ lista para subir a un hosting
```

`npm run build` es el paso que convierte `src/App.tsx` (TypeScript,
no lo entiende el navegador) en JS/CSS planos dentro de `dist/` — eso
es lo que se sube al hosting, nunca `src/` directamente.

## Backend (api/)

Antes de usar los endpoints, edita `api/db.php` con las credenciales
reales de tu base MySQL (host, nombre de base, usuario, clave). Los
valores que trae por defecto son placeholders de ejemplo, no
credenciales reales.

Carga `schema.sql` en tu base MySQL (por phpMyAdmin o `mysql -u user -p
< schema.sql`) antes de usar la app — crea las tablas y los datos
iniciales del catálogo.

