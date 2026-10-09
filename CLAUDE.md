# Marcelo Ragonese Propiedades — guía del proyecto

Sitio web + paneles internos de una inmobiliaria (cliente: Marcelo Ragonese, martillero en Ramos Mejía, La Matanza). Lo desarrolla Lucas Scala. Pensado para que lo use personal no técnico: todo tiene que seguir siendo simple. **Este archivo no contiene claves**: las credenciales viven en `api/config.php` y `alquileres/config.php` (ignorados por Git, se copian a mano) y en el servidor.

- Dominio: `https://marceloragonesepropiedades.com` (hosting en Hostinger, PHP + archivos JSON, **sin base de datos**).
- Repo privado: `github.com/ScalaLucas/ProyectoGuillermo` (rama `main`).
- Desarrollo local: `php -S localhost:8899` (config `inmobiliaria-php` en `.claude/launch.json`).
- Idioma de todo el producto y de la comunicación con el usuario: español rioplatense.

## Estructura

| Carpeta | Qué es |
|---|---|
| raíz (`index.html`, `propiedades.html`, `propiedad.html`, `favoritos.html`, `reporte.html`) | Sitio público (HTML + JS vanilla). `script/script.js`, `script/chat.js`, `estilos/estilos.css`. Al cambiar JS/CSS subir el `?v=` de las referencias en el HTML (hay caché en Cloudflare). |
| `api/` | Backend público: `propiedades.php`, `lead.php`, `chat.php` (asistente IA con Gemini), `meli.php` (Mercado Libre), `meli-webhook.php`, `whatsapp.php`, `negocio.php`, `caracteristicas.php`. Datos en `api/data/`. |
| `admin/` | Panel de **propiedades** (ventas). |
| `alquileres/` | Panel de **alquileres** (administración), sistema aparte con sus propios datos. |
| `seguridad/` | Usuarios, roles, 2FA y registro de actividad, compartido por los dos paneles. |
| `deploy_pkg/` | Copias viejas de despliegues completos. Está ignorado por Git; no usar (ver "Publicación"). |

## Reglas críticas (leer antes de tocar nada)

1. **Los datos reales viven solo en el servidor.** Nunca subir ni pisar: `api/data/propiedades.json`, `api/data/meli_tokens.json`, `alquileres/data/`, `seguridad/data/`. La copia local de `propiedades.json` suele estar desactualizada (el equipo edita en vivo desde el panel). Por eso está en `.gitignore`.
2. **Nunca usar `hosting_deployStaticWebsite`** (conector de Hostinger): reemplaza el sitio entero y borra todo lo que no esté en el archivo. Ya pasó un incidente en 2026-09-22 (sitio caído unos minutos).
3. Nunca poner contraseñas, tokens ni hashes en el repo ni en el chat.
4. Antes de publicar un cambio de PHP: `php -l archivo.php`. Probar localmente (render por CLI o `php -S`) y recién después subir.

## Publicación (método seguro: un archivo por vez)

Se usa el conector MCP de Hostinger (`hostinger-hosting-mcp`, instalado con `npm install -g hostinger-api-mcp`; requiere Node 24+; la primera vez y cuando vence la sesión pide iniciar sesión por navegador con `hostinger-hosting-mcp --login` — lo hace el usuario).

**Versión nueva del conector (desde 2026-10):** ya no expone una herramienta por operación sino solo `search`, `execute` y `multi-execute`. Se busca la operación con `search` y se corre con `execute { operation, params }`. Equivalencias: `hosting_listWebsitesV1` → `hosting_websites_list`; `hosting_generateUploadURLV1` → `hosting_files_generate-upload-url`; `hosting_getWebsiteFileContentV1` → `hosting_files_website-content`; `hosting_deployStaticWebsite` → `hosting_deploy-static-website` (**sigue prohibida**, igual que `hosting_websites_deploy-static-site-archive`).

1. `hosting_generateUploadURLV1` (usuario y dominio del hosting) devuelve URL TUS + claves.
2. Por cada archivo: `POST` a `{url}/{ruta/relativa}?override=true` con `Upload-Length`, `Upload-Offset: 0` y `Tus-Resumable: 1.0.0`; luego `PATCH` con los bytes (`Content-Type: application/offset+octet-stream`).
3. Verificar leyendo el archivo con `hosting_getWebsiteFileContentV1` (máx. 5000 líneas por llamada) o abriendo la página.
4. Después de subir `api/*` comprobar que `api/propiedades.php` sigue devolviendo todas las propiedades.

Notas: sobreescribir archivos existentes funciona siempre. **Crear un archivo nuevo por esta vía quedó con 403** al abrirlo (probablemente permisos); para algo nuevo, mejor reutilizar un archivo existente. No hay herramienta para borrar archivos: para neutralizar uno se lo sobreescribe con un stub. Los scripts de despliegue eran temporales (no están en el repo): se rearman en minutos con el algoritmo de arriba.

## Panel de propiedades (`admin/`)

- Acceso: usuarios de `seguridad/` (con permiso "propiedades"). Quedan flags transitorios `legacy_propiedades` / `legacy_alquileres` en `seguridad/data/config.json` para la clave compartida vieja.
- Listado (`index.php`): KPIs, pestañas Todas/Activas/Suspendidas **y** Venta y alquiler / En venta / En alquiler; las activas y las suspendidas van en bloques separados; cada bloque ordenado por dirección (A-Z, clic en "Dirección" invierte); buscador por código/dirección/agente/propietario.
- Formulario (`property-form.php` → `save.php`), orden de secciones: Datos de la operación, Más datos de la propiedad, Otras características (checkboxes), Descripción (con generador IA), Fotos/Videos (máx. 50 fotos), Información privada de la operación, Información interna del equipo. La etiqueta visible es "Cantidad de plantas", pero el dato se guarda como "Cantidad de pisos" (así lo reconoce Mercado Libre).
- Acciones solo para **administradores**: eliminar propiedad y conectar/reconectar Mercado Libre. Publicar/editar/suspender lo puede hacer cualquier usuario con permiso.
- Diseño: `admin_cabecera()` / `admin_pie()` en `admin/lib.php` + `admin/admin.css` (mismos colores que alquileres: bordó `#6E1B2E`, dorado `#C6A15C`, tipografía Manrope).

## API pública y sitio

- `api/propiedades.php` entrega solo las propiedades activas con fotos. Filtros y zonas del sitio salen de los datos reales.
- `api/lead.php` recibe consultas del sitio. `api/chat.php` + `script/chat.js`: asistente IA (Gemini, con reintentos y mensaje amable si hay demasiada demanda).
- **Marca de agua en las fotos de la web** (logo MR centrado, tenue, ~46 % del ancho; constantes `WM_*` en `api/propiedades.php`). Las fotos originales de `assets/propiedades/` **no se modifican**. `api/propiedades.php` reescribe cada ruta de foto a `api/propiedades.php?foto=ARCHIVO&v=HASH`; la primera vez se genera la copia con GD y queda como **archivo estático** en `assets/wm-<WM_VERSION>/` (el listado apunta a ese archivo cuando existe, así lo sirve el hosting directo y rápido; mientras no exista, apunta a `api/propiedades.php?foto=` que lo genera). Para regenerar todas: subir `WM_VERSION`. Si algo falla se sirve la original. Toda foto nueva subida desde el panel la recibe automáticamente. El JSON guardado, el panel y **Mercado Libre siguen usando las originales sin marca** (ML suele rechazar marcas de agua; si se quisiera probar en ML hay que cambiar `meli.php`). Para cambiar el estilo: editar `WM_*` y subir `WM_VERSION` (después conviene recorrer las URLs de a poco, sin saturar el hosting). Las propiedades migradas de Urbano ya traían su propia marca en la foto original.
- Nombre comercial unificado: **Marcelo Ragonese Propiedades** (no "Inmobiliaria"). Dirección: Bolívar 699, Ramos Mejía.

## Integración con Mercado Libre (`api/meli.php`)

- Al guardar una propiedad con fotos se publica o actualiza en ML (tipo de publicación gratuito). Conexión OAuth desde `admin/meli-connect.php` (solo admin). El token se guarda en `api/data/meli_tokens.json` (no tocar).
- Categoría según tipo + operación (`MELI_CATEGORIAS`). Ciudad según zona con `meli_ciudad_para_zona()`: tolera tildes y variantes ("Ramos Mejía Sur/Norte", "San Justo (Centro)"...). Si una zona no mapea, el panel muestra "Esa zona todavía no está mapeada…"; se arregla agregando la localidad en `MELI_CIUDADES` (ML trabaja a nivel partido: La Matanza, Tres de Febrero, Morón…).
- Los atributos se emparejan **por nombre** con los de la categoría de ML (`meli_construir_atributos`); lo que no tiene equivalente se agrega como texto a la descripción. Por eso no conviene renombrar claves internas de características.
- `admin/meli-verificar.php` consulta si la publicación sigue existiendo; `meli-retry.php` reintenta.
- `api/meli-webhook.php` está pensado para avisar por WhatsApp/mail cuando llega una **pregunta** en ML. Requiere WhatsApp Business API (variables `WABA_*` en `api/config.php`, hoy sin completar) y registrar la URL en la app de ML Developers. No está confirmado que el botón "Contactar" de Inmuebles genere ese tipo de evento.

## Panel de alquileres (`alquileres/`)

- Datos en `alquileres/data/contratos.json` (+ backup diario, últimos 30). Núcleo en `alquileres/lib.php`. Lee propiedades de la web en solo lectura.
- **Moneda**: cada contrato tiene `moneda` (`ARS` por defecto o `USD`; los contratos sin el campo siguen en pesos). Se elige en "Condiciones económicas"; todos los montos del contrato (alquiler, depósito, cobros, recibos, avisos, liquidación) se muestran en esa moneda con `dinero($n, $moneda)`. Totales del panel y liquidaciones van **separados por moneda** (`dinero_multi`). Ajuste automático en USD redondea al dólar entero (en pesos, al millar). No hay conversión ni cotización: un pago en pesos de un contrato en USD se carga ya convertido en dólares.
- **Contratos**: partes (locador, locatario, 2 garantes + nota libre), vigencia, alquiler inicial, día de vencimiento, comisión, depósito, interés por mora (% diario), seguro (opcional), notas.
- **Actualizaciones del alquiler**: ICL / IPC / CASA / Acuerdo se cargan a mano (por % **o directamente el nuevo alquiler**). "Porcentaje fijo" con % y frecuencia se aplica solo, compuesto, redondeado al millar (151.499 → 151.000; 151.501 → 152.000); un ajuste manual para el mismo mes tiene prioridad.
- **Cobros**: un pago puede cubrir varios meses (un recibo por mes); conceptos extras múltiples (suman) y descuentos múltiples (restan, p. ej. expensas pagadas directo); si hay mora se sugiere el interés como concepto extra.
- **Mora**: interés simple diario sobre el saldo impago del período (no reconstruye historial día a día). Alertas: 2 o más meses adeudados (habilita desalojo según el contrato modelo) y estimación de rescisión anticipada (1,5 meses el primer año, 1 después, a partir del 6º mes).
- **Recibos**: formato recibo clásico "no válido como factura" (original + duplicado), sin IVA, sin firma/aclaración ni pie. Botón "Compartir por WhatsApp" arma el PDF en el navegador y abre el selector del celular (en escritorio lo descarga). Hay recibo independiente aparte.
- **Avisos** (`avisos.php`): recordatorio, vencimiento, mora al inquilino, y aviso al **fiador** (garante 1/2 o mail dentro de la nota "Garantes") desde el día 5 de mora, por WhatsApp o mail; envío automático opcional por cron (`cron.php`, con token).
- Otros: liquidación por propietario, exportación a CSV, clave propia del panel.

## Seguridad (`seguridad/`)

- Una sola sesión para `/admin/`, `/alquileres/` y `/seguridad/`. Usuarios con rol `admin`/`operador` y permiso por panel. 2FA TOTP opcional por cuenta (toggle global "exigir a todos"), códigos de recuperación, bitácora (`actividad.jsonl`), clave temporal que **obliga** a cambiarse en el primer ingreso.
- Bloqueo por 6 intentos fallidos en 15 minutos (por IP y por usuario) en `seguridad/data/intentos.json`; se destraba solo, o vaciando ese archivo (`{}`).
- Equipo previsto: Marcelo y Viviana → solo Propiedades; Marina → solo Alquileres; Marcelo y Lucas → administradores. Cada panel muestra solo los enlaces a los que el usuario tiene acceso.

## Otras piezas entregadas

Tarjeta personal, manual de marca, talonarios, piezas de Instagram y QR (PDF de página completa y solo-QR para carteles). QR siempre verificar que decodifique antes de entregar.

## Estado y pendientes

**Hecho y en producción:** sitio completo, panel de propiedades con publicación en ML, panel de alquileres completo (contratos, cobros, mora, ajustes, recibos, avisos, fiador), seguridad multiusuario, dominio propio, QR corregido.

**Pendiente / a confirmar:**
- Importación masiva de ~143 contratos desde planilla (falta que llegue el archivo).
- Propiedad `LP812735` (terreno, Roque Sáenz Peña) tiene la zona "Villa del Dique" (dato mal cargado): corregirla para poder publicarla en ML.
- Ficha de **inmobiliaria verificada** (nombre + tilde azul) en ML: es un trámite con Mercado Libre, no se controla desde el código. La cuenta conectada muestra el nickname `Marcelorpropiedades`; antes había quedado conectada una cuenta de prueba (nickname `Mr2026…`), ya reemplazada.
- Derivar el botón "Contactar" de ML a WhatsApp: pendiente de saber si ML lo permite (revisar con su ejecutivo; el teléfono de contacto debería estar en formato celular `+54 9 …`).
- WhatsApp Business API (`WABA_*`) sin configurar; el aviso de consultas ML por WhatsApp no está activo.
- Rotar/entregar las claves iniciales de los paneles viejos cuando se apaguen los flags `legacy_*`.
- Ajuste automático de índices (ICL/IPC) no existe: se cargan a mano.
- Instagram (`@marceloragonese.prop`) ya está activo; revisar que el link del pie del sitio apunte ahí.

## Decisiones importantes

- JSON + PHP en vez de base de datos: simple de alojar y de respaldar; por eso la regla de no pisar `data/`.
- Alquileres separado de propiedades (pedido del cliente); integración solo de lectura.
- Interés por mora y redondeos son simplificaciones deliberadas, explicadas arriba.
- Nada de copias locales de datos reales en Git; secretos solo en archivos ignorados.

## Trabajar desde otra computadora

Clonar el repo, copiar a mano `api/config.php`, `alquileres/config.php` y las fotos (`assets/propiedades`, ignoradas por pesadas), instalar Git, Node, PHP y Claude Code, y volver a iniciar sesión en el conector de Hostinger. La memoria de Claude (`~/.claude/projects/.../memory`) es local de cada máquina.
