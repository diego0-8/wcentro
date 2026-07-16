# HANDOFF — CRM estándar T&S / Banco W

Documento de continuidad para **no empezar de cero** en otros proyectos CRM.
Fecha de referencia: 2026-07-08 · Base de trabajo: `wcentro` (Banco W CRM)

> **Uso:** Copia este archivo a la carpeta de los proyectos avanzados (o a cada repo).
> Lo **común** de los CRM es este documento. Lo que **cambia por cliente** suele ser solo:
> 1) Árbol de tipificación del asesor  
> 2) Carga / esquema de bases de datos (CSV, columnas, `base_id`)

---

## 1. Qué es este sistema

CRM de cobranza / gestión telefónica en **PHP vanilla** (sin Laravel/Symfony), estilo MVC ligero, sobre XAMPP.

| Capa | Ubicación |
|------|-----------|
| Front controller | `index.php?action=...` |
| Config / sesión / DB | `config.php` |
| Asterisk / WebRTC | `config/asterisk.php` |
| Controladores | `controllers/` |
| Modelos PDO | `models/` |
| Vistas | `views/` |
| JS / CSS | `assets/js/`, `assets/css/` |
| Softphone | `assets/js/softphone-web.js` + `sip.min.js` |
| Login brand | `views/login.php` + `assets/css/login.css` + `img/` |

**Roles:** `administrador` · `coordinador` · `asesor`

**Sesión:** `session_name('wcentro_SID')` — en otros proyectos cambia el nombre de sesión y `DB_NAME` / `APP_URL`.

---

## 2. Arquitectura que NO debe romperse

### 2.1 Routing (`index.php`)

1. Públicos: `login`, `logout`, `check_updates`
2. AJAX admin (rol `administrador`)
3. AJAX coordinador (rol `coordinador`)
4. AJAX asesor (rol `asesor`)
5. Vistas con sesión + **autorización por rol** (`$vistasPorRol`)
6. Fallback: redirect al dashboard del rol o a login

### 2.2 Auth

- Login: `LoginController` → `Usuario::obtenerPorUsuario` + `password_verify` contra `contraseña_hash`
- Sesión típica: `usuario_id` (= cédula), `usuario_rol`, nombre, extensión/SIP
- **Obligatorio:** cada vista solo accesible para su rol (no basta con “hay sesión”)

Mapa de roles → acciones (patrón a replicar):

```
administrador → dashboard, admin_usuarios, admin_asignaciones, admin_reportes,
                admin_configuracion, admin_crear_usuario, admin_asignar_personal

coordinador   → coordinador_dashboard, coordinador_gestion, coordinador_exporte

asesor        → asesor_dashboard, asesor_gestionar
```

### 2.3 Base de datos

- PDO, `ERRMODE_EXCEPTION`, utf8mb4
- Credenciales locales típicas XAMPP: `root` / vacío (ajustar por entorno)
- Constantes: `DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME`, `APP_URL`, `APP_NAME`

### 2.4 Softphone (asesor)

- Condición: rol `asesor` + extensión + clave SIP en tabla `usuarios`
- Campos: `extension` / `sip_password` (legacy); a veces se menciona `extension_telefono` / `clave_extension` (solo si existen en el esquema)
- Librerías: `sip.min.js` + `softphone-web.js` + CSS softphone
- Config WSS/SIP en `config/asterisk.php` (`getWebRTCConfig()`)
- Requiere HTTPS (o contexto seguro) para micrófono
- Logs de debug solo si `ASTERISK_DEBUG_MODE` está activo

### 2.5 Carga de bases (coordinador) — patrón común

- Vista: `Coord_gestion.php`
- JS activo preferido: `coord-comercio-factura.js` (no depender solo de `coord-gestion.js` huérfano)
- Acciones AJAX típicas: `cargar_csv`, `obtener_bases`, `crear_asignacion_clientes`, filtros, acceso a base, etc.
- **Por proyecto cambian:** columnas del CSV, nombre de tablas/campos de cliente, tipificación ligada a la base

### 2.6 Tipificación (asesor) — lo que MÁS cambia por proyecto

- UI en `asesor_gestionar.php` (selects nivel 1 / nivel 2 / …)
- Lógica JS en `asesor-gestionar.js` (u equivalente)
- Persistencia vía `guardar_gestion` → historial / tipificación
- **Al crear un CRM nuevo:** redefinir el árbol de tipificación; no copiar ciego el de Banco W

---

## 3. Estructura de carpetas (referencia)

```
proyecto/
├── index.php
├── config.php
├── config/asterisk.php
├── controllers/
│   ├── LoginController.php
│   ├── AdminDashboardController.php
│   ├── AdminUsuarioController.php
│   ├── AdminAsignacionController.php
│   ├── AdminReportesController.php
│   ├── CoordDashboardController.php
│   ├── CoordGestionController.php
│   └── AsesorGestionController.php
├── models/
│   ├── Usuario.php, Asignacion.php, Cliente.php, BaseCliente.php
│   ├── HistorialGestion.php, Tarea.php, Tiempo.php, Obligacion.php, …
├── views/
│   ├── login.php, Navbar.php, Header.php, partials/
│   ├── admin_*.php, Coord_*.php, asesor_*.php
├── assets/js/  assets/css/  assets/audio/
├── img/
└── HANDOFF.md   ← este archivo
```

---

## 4. Correcciones ya aplicadas en wcentro (julio 2026)

Usar como checklist al auditar otros CRMs clonados:

| Severidad | Problema | Solución aplicada |
|-----------|----------|-------------------|
| Crítico | `admin_asignar_personal.php` corrupto (parse error / HTML mezclado) | Vista reconstruida: pestañas Asignar / Gestionar / Estadísticas / Historial |
| Alto | Vistas sin chequeo de rol | `$vistasPorRol` en `index.php` |
| Medio | `removeFile()` faltaba en JS de carga CSV | Añadido en `coord-comercio-factura.js` |
| Medio | Admin enlazaba a `coordinador_gestion` (bloqueado por rol) | Botón → `admin_asignar_personal` |
| Medio | Tabs admin no abrían Usuarios/Asignaciones según `action` | `tabDesdeAction()` en `admin-dashboard.js` |
| Medio | `asignar_clientes` llamaba al método equivocado | Enruta a `crearAsignacionClientes()` |
| Bajo | Logs softphone siempre activos | Solo con `ASTERISK_DEBUG_MODE` |
| Bajo | Mensajes a `sql/` y `scripts/` inexistentes | Textos genéricos |
| Bajo | Favicon con `APP_URL` de producción | Ruta relativa `img/...` |
| IDE | Intelephense: “Undefined variable” en vistas | Usar `isset($var) && is_array($var)` antes de defaults |

### Patrón seguro en vistas (evitar falso positivo del linter)

```php
$coordinadores = (isset($coordinadores) && is_array($coordinadores)) ? $coordinadores : [];
$estadisticas  = (isset($estadisticas)  && is_array($estadisticas))  ? $estadisticas  : [];
$asignacionesLista = (isset($asignaciones) && is_array($asignaciones)) ? $asignaciones : [];
```

Las variables las inyecta `index.php` antes del `require` de la vista. No es error de runtime; el IDE no ve el scope del front controller.

---

## 5. Flujo de login (común)

```
GET/POST index.php?action=login
  POST → LoginController::login()
       → password_verify + estado activo
       → sesión + redirect por rol:
            admin      → ?action=dashboard
            coordinador→ ?action=coordinador_dashboard
            asesor     → ?action=asesor_dashboard
  GET / fallo → views/login.php
```

Logout limpia cookie de sesión + `session_destroy`.

---

## 6. Qué cambia vs qué reutilizar entre proyectos

### Reutilizar tal cual (trayectoria común)

- Front controller + mapa de acciones
- Auth / roles / sesión
- Softphone WebRTC (ajustar solo host Asterisk)
- Asignación asesor↔coordinador (admin)
- Dashboards por rol (estructura)
- Tiempos de gestión / pausas (si ya existen)
- HybridUpdater + `check_updates` (JSON vacío seguro)
- Navbar / Header compartidos

### Personalizar por proyecto

| Área | Qué ajustar |
|------|-------------|
| **Tipificación** | Niveles, etiquetas, reglas de obligatoriedad, mapeo a BD |
| **Carga de bases** | Columnas CSV, separador, tabla destino, `base_id`, validaciones |
| Branding | `APP_NAME`, logos en `img/`, CSS login |
| Config | `APP_URL`, `DB_NAME`, nombre de sesión, Asterisk |
| Exportes / reportes | Columnas y filtros del cliente |

---

## 7. Cómo usar este handoff en Cursor (10 proyectos)

### Opción A — Copiar a cada proyecto (inmediata)

1. Copia `HANDOFF.md` a la raíz de cada CRM (o a la carpeta madre de los dos avanzados).
2. En un chat nuevo: *“Lee HANDOFF.md y continúa con …”*.

### Opción B — User Rules globales (recomendado a medio plazo)

En **Cursor Settings → Rules → User Rules**, pega un resumen corto de las secciones 2, 4 y 6 (trayectoria común).

En cada proyecto, añade solo:

- `.cursor/rules/tipificacion.mdc` → árbol de ese cliente  
- `.cursor/rules/carga-bases.mdc` → columnas/CSV de ese cliente  

Así no repites instrucciones en cada chat; solo el delta tipificación/carga.

### Opción C — Carpeta madre con los 2 avanzados

```
crms-avanzados/
├── HANDOFF.md          ← este archivo (fuente de verdad común)
├── proyecto-a/
└── proyecto-b/
```

Abre la carpeta madre o cada subproyecto; al inicio pide leer `HANDOFF.md`.

---

## 8. Reglas de trabajo para el agente (copiar a User Rules si quieres)

- Responder siempre en **español**.
- No reinventar Laravel/frameworks: mantener PHP vanilla + `index.php?action=`.
- No romper softphone, auth por rol ni carga CSV sin necesidad.
- No inventar carpetas `sql/` o `scripts/` que no existan.
- Preferir prepared statements PDO.
- No hacer commit ni push a menos que el usuario lo pida.
- Antes de tipificación o carga CSV: leer las rules del proyecto; si no hay, preguntar.

---

## 9. Deuda conocida / no crítica (no confundir con bugs urgentes)

- Contraseña SIP puede quedar en el HTML del softphone (limitación del diseño WebRTC client-side).
- `APP_URL` a veces apunta a producción aunque se desarrolle en XAMPP local.
- `coord-gestion.js` tiene funciones no cargadas en la vista actual; el flujo vivo usa `coord-comercio-factura.js`.
- `error-handler.js` puede existir sin estar incluido en vistas.
- No hay CSRF en formularios/AJAX (mejora futura).
- Rate limiting de login configurado en constantes pero no siempre aplicado de verdad.

---

## 10. Checklist al clonar / abrir otro CRM

- [ ] Existe `index.php` como front controller único
- [ ] `config.php` con sesión propia y DB correcta
- [ ] Vistas con **autorización por rol** (no solo sesión)
- [ ] AJAX filtra por rol y responde siempre JSON (nunca HTML de error)
- [ ] Softphone: `sip.min.js` + `softphone-web.js` + credenciales en usuario
- [ ] Carga CSV: `removeFile` / botones alineados con el JS que realmente se incluye
- [ ] Tipificación documentada para **este** cliente (árbol)
- [ ] Esquema de carga CSV documentado para **este** cliente
- [ ] Login y assets de imagen existen (rutas relativas)
- [ ] `php -l` limpio en PHP tocados

---

## 11. Próximos pasos sugeridos (carpeta de proyectos avanzados)

1. Copiar este `HANDOFF.md` a la carpeta madre.
2. Para cada uno de los 2 proyectos avanzados, crear un anexo corto:
   - `TIPIFICACION.md` — árbol real del asesor
   - `CARGA-BASES.md` — columnas y reglas CSV
3. (Opcional) Pegar sección 8 en **User Rules** de Cursor para que aplique a los 10 proyectos.
4. Auditar con la sección 4 (checklist de bugs ya vistos en wcentro).

---

## 12. Glosario rápido de acciones

| Action | Rol | Función |
|--------|-----|---------|
| `login` / `logout` | público | Auth |
| `dashboard` | admin | Panel admin |
| `admin_asignar_personal` | admin | Asignar asesor↔coordinador |
| `admin_reportes` | admin | Reportes / historial CSV |
| `coordinador_gestion` | coord | Carga bases / asignaciones de clientes |
| `coordinador_exporte` | coord | Exportes |
| `asesor_gestionar` | asesor | Gestión + tipificación + softphone |
| `check_updates` | cualquiera | JSON vacío para HybridUpdater |
| `crear_asignacion` | admin AJAX | Crear asignación personal |
| `cargar_csv` | coord AJAX | Carga de base |
| `guardar_gestion` | asesor AJAX | Guardar tipificación/gestión |

---

*Fin del handoff. Actualizar este documento cuando se cierre una sesión grande (qué se arregló / qué falta), para que el siguiente chat no parta de cero.*
