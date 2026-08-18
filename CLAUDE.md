# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Idioma de Comunicación

**IMPORTANTE**: Todas las respuestas, explicaciones y documentación deben proporcionarse en **español**.

## Contexto del Proyecto

**Acción Unificada - Gobierno de Santander** es una plataforma de gestión gubernamental para el Departamento de Santander, Colombia. Rastrea visitas municipales, proyectos, compromisos y factores socioeconómicos por municipio/vereda, con integración de IA (GPT-4) y mapas interactivos.

**Stack**: PHP 8.2 + PDO/MySQL · MariaDB · jQuery + Bootstrap · TCPDF · Highcharts · PHPMailer

## Ejecutar la Aplicación

No hay paso de compilación. Los archivos PHP se interpretan en tiempo de ejecución sobre Apache/Docker.

```bash
# Verificar que el contenedor MariaDB esté activo
docker ps

# Acceso: http://localhost/santander/login.php
```

**Credenciales de BD (desarrollo local)** — definidas en `admin/classes/DbConection.php`:
- Host: `localhost` · DB: `santander` · Usuario: `root` · Contraseña: `""` (vacía)

> En producción Docker el host es `mariadb`, DB `gobernacion_prod_db`. Para cambiar entorno editar `DbConection.php:14-18`.

## Arquitectura

### Dos caminos para peticiones al backend

**1. Dispatcher AJAX central** — `admin/ajax/rqst.php`
- Recibe `op` vía `$_REQUEST` y hace `switch` para incluir la clase y ejecutar el método.
- Toda operación (excepto login) pasa por `PermissionGate::authorizeOperation($op)` que consulta `admin/config/ajax_permissions_map.php`.
- Patrón de llamada desde JS:
```javascript
$.ajax({ url: 'admin/ajax/rqst.php', type: 'POST', data: { op: 'mioperacion', ...params } });
```

**2. Controladores REST** — `admin/controllers/*.php`
- Endpoints JSON alternativos usados por algunas vistas más recientes.
- Permisos validados desde `admin/config/controller_permissions_map.php`.

### Sistema de Permisos RBAC

El sistema migró de IDs legacy a claves de permiso (`permission_keys`). Las clases involucradas:

| Archivo | Rol |
|---|---|
| `Authorization.php` | Núcleo: carga permisos desde `tbl_roles`/`tbl_role_has_permissions` |
| `PermissionGate.php` | Interceptor AJAX: política fail-closed (op desconocida = 403) |
| `PermissionCatalog.php` | Fuente de verdad de todas las claves (ej. `compromisos.gobernador.update`) |
| `PermissionLegacyMap.php` | Bridge: ID numérico legacy → KEY string RBAC |
| `admin/config/ajax_permissions_map.php` | Mapa op AJAX → permiso requerido |

**SuperAdministrador** tiene bypass total. Para verificar permisos en vistas:
```php
// Recomendado (nuevo):
SessionData::hasPermission('modulo.submodulo.accion');

// Legado (evitar en código nuevo):
SessionData::getPermission($id_numerico);
```

### Estructura de Sesión

`$_SESSION['session_user']` contiene: `id`, `tipo`, `nombre`, `apellido`, `img`, `tbl_municipio_id`, `tbl_secretarias_id`, `permisos` (legacy), `permission_keys` (RBAC), `role`.

### Clases de Negocio

En `admin/classes/`, todas siguen el mismo contrato:
```php
class MiClase {
    public static function getAll($rqst): array {
        $db = new DbConection();
        $pdo = $db->openConect();
        // ... consultas con sentencias preparadas
        $db->closeConect();
        return ['output' => ['valid' => true/false, 'response' => $data]];
    }
    public static function save($rqst): array { ... }
}
```
**Siempre retornar** `['output' => ['valid' => bool, 'response' => $data]]`.

### Vistas (raíz del proyecto)

Más de 140 archivos `.php` en la raíz. Cada vista debe incluir:
```php
<?php include './admin/include/head.php'; // valida sesión y redirige a login.php si no hay sesión ?>
<body>
    <?php include './admin/include/navbar.php'; ?>
    <?php include './admin/include/header.php'; ?>
    <div class="pcoded-main-container"><!-- contenido --></div>
    <?php include './admin/include/footer.php'; ?>
</body>
```
`head.php` ya maneja el `session_start()` y la redirección a login — no duplicar en las vistas.

### Autenticación y Roles

Login en `login.php`: contraseña hasheada con MD5 antes de enviarse. Roles y redirección post-login:

| Tipo (`$_SESSION['session_user']['tipo']`) | Redirección |
|---|---|
| `SuperAdministrador`, `Administrador` | `dashboard.php` |
| `Secretario_Despacho`, `Auxiliar`, `Auxiliar_secret_gob` | `dash_secretarias.php` (o ruta custom) |
| `Alcalde`, `Auxiliar_Alcalde` | `dahsboard_alcaldias.php` |

Helpers de tipo en `Util.php`: constantes como `Util::ADMINISTRADOR`, `Util::ALCALDE`, etc.

## Agregar una Operación AJAX

1. Agregar el caso al `switch` en `admin/ajax/rqst.php`:
```php
case 'mi_nueva_op':
    include '../classes/MiClase.php';
    echo json_encode(MiClase::miMetodo($rqst));
    break;
```

2. Registrar el permiso en `admin/config/ajax_permissions_map.php`:
```php
'mi_nueva_op' => 'modulo.submodulo.accion',  // o '' para solo sesión
```

3. Si el permiso es nuevo, agregarlo también en `admin/classes/PermissionCatalog.php`.

## Migraciones de Base de Datos

Scripts SQL en `admin/db/` — aplicar manualmente vía cliente MySQL. No hay herramienta automatizada.

Tablas clave: `tbl_compromisos`, `tbl_ciudades`, `tbl_secretarias`, `tbl_municipios`, `tbl_usuarios`, `tbl_roles`, `tbl_role_has_permissions`, `tbl_permissions`.

## Módulos Principales

| Módulo | Clases principales |
|---|---|
| Compromisos | `Compromisos.php`, `CompromisosFactorPilar.php`, `CompromisoMunicipioAlcalde.php` |
| Proyectos (secretarías/planeación) | `Ingreso_proyectos_secretarias.php`, `Proyectos.php`, `Ministeriospro.php`, `Proyectos4.php` |
| Proyectos alcaldías | `ProyectosAlcaldias.php`, `ProyectosAlcalde.php`, `ProyectosRpc.php` |
| Visitas | `Visitas.php`, `Visitasg.php`, `VisitasgAspas.php` |
| Mapas | `Mapa.php`, `Colombia.php`, `Estado.php`, `Ciudad.php` |
| PAE | `IngresoPae.php`, `PaeArcgis.php`, `PaeArcgisMunicipios.php` |
| Hacienda | `Hacienda.php`, `HaciendaImport.php` |
| Gestora Social | `GestoraSocial.php`, `GestoraSocialAspas.php` |
| Factores inestabilidad | `Factores.php`, `FactoresInestabilidadGobernacion.php`, `FactoresInestabilidadGeneral.php` |
| Asistente IA | `ConsultasIA.php` (GPT-4, config en `config.ini` bajo `[openai]`) |

## Subsistema Asistente IA (Claude + ElevenLabs)

Widget de chat flotante integrado en todas las vistas vía `admin/include/gerenic_script.php`. Usa Claude (Anthropic) para texto y ElevenLabs para voz.

### Archivos clave

| Rol | Archivo |
|---|---|
| Orquestador loop tool-use | `admin/classes/ia/AsistenteIA.php` |
| Wrapper SDK Anthropic | `admin/classes/ia/ClaudeService.php` |
| Scoping territorial (REGLA DE ORO) | `admin/classes/ia/IaScope.php` |
| Catálogo de 17 tools con RBAC | `admin/classes/ia/IaToolRegistry.php` |
| CRUD conversaciones/mensajes BD | `admin/classes/ia/IaConversacion.php` |
| STT + TTS ElevenLabs | `admin/classes/ia/ElevenLabsService.php` |
| Herramientas de datos | `admin/classes/ia/herramientas/Tool*.php` |
| Endpoint chat texto | `admin/ajax/ia_chat.php` |
| Endpoint STT + Claude | `admin/ajax/ia_stt.php` |
| Endpoint TTS | `admin/ajax/ia_tts.php` |
| Endpoint historial | `admin/ajax/ia_historial.php` |
| Widget CSS | `assets/css/ia-widget.css` |
| Widget JS | `assets/js/ia-widget.js` |
| Vista auditoría (admin) | `asistente_ia_logs.php` |
| Progreso de implementación | `docs_ia/PROGRESO.md` |

### REGLA DE ORO territorial (inmutable)

```
Alcalde / Auxiliar_Alcalde     → filtro WHERE tbl_municipio_id = SessionData::getCodigoMunicipio()
Secretario_Despacho / Auxiliar → filtro WHERE tbl_secretarias_id = SessionData::getSecretaria()
Admin / Gobernador / SuperAdmin → sin filtro territorial
```

Este filtro se aplica **server-side en cada tool** y no puede ser sobrescrito por ningún prompt ni por Claude.

### Tool `consultar_base_de_datos`

Permite a Claude ejecutar SELECT arbitrarios. Protecciones activas:
- Solo permite SELECT / WITH (CTEs)
- Bloquea `information_schema`, `mysql`, `performance_schema`, `sys`
- Bloquea INSERT/UPDATE/DELETE/DROP/TRUNCATE/ALTER/CREATE/GRANT/REVOKE y comentarios `/* */`
- Limita a 250 filas (inyecta `LIMIT 250` si no viene)
- Limita el SQL a 2000 caracteres

### Permisos IA

| Clave | Quién la tiene |
|---|---|
| `asistente_ia.chat.use` | Todos los roles (9/9) |
| `asistente_ia.voz.use` | Todos los roles (9/9) |
| `asistente_ia.logs.view` | Solo SuperAdmin y Admin |

### Archivos que NO se deben tocar (código OpenAI legado)

```
ConsultasIA.php  chatgpt_handler.php  gpt.php  abogadoia.php
contratacion_estructurador_ia.php  listado_preguntas_ai*.php
asistente_ia.php  stt.php  tts.php  procesar_audio.php
```

### Configuración de Entorno (IA)

- `config.ini → [anthropic]`: `api_key`, `model` (claude-sonnet-4-6), `max_tokens` (4096)
- `config.ini → [elevenlabs]`: `api_key`, `voice_id`, `tts_model`, `stt_model`
- Tablas BD: `tbl_ia_conversaciones`, `tbl_ia_mensajes`, `tbl_ia_tool_logs`

## Configuración de Entorno

- `admin/classes/DbConection.php`: credenciales de base de datos (local vs producción)
- `config.ini`: claves API de OpenAI (`[openai]`), Anthropic (`[anthropic]`), ElevenLabs (`[elevenlabs]`)
- `.env`: ruta a credenciales de Google Cloud (Speech/TTS)
- `composer.json`: dependencias mínimas (la mayoría de librerías están incluidas directamente en `vendor/` o `plugins/`)

## Notas Técnicas

- **Zona horaria**: `America/Bogota` — establecida en `DbConection.php`. El timestamp de servidor usa `DATE_ADD(NOW(), INTERVAL 1 HOUR)`.
- **Charset**: `utf8mb4` con collation `utf8mb4_unicode_ci` — ya configurado en `openConect()`.
- **Rutas de include**: relativas desde la ubicación del archivo que las llama (ej. `'./admin/classes/'` desde la raíz, `'../classes/'` desde `admin/ajax/`).
- **DataTables, Select2, SweetAlert2**: cargados globalmente desde `admin/include/footer.php`. No re-incluir en vistas.
- **Carga de imágenes**: destino `assets/img/admin/`. Manejo vía `$_FILES` en las clases correspondientes (ej. `Galeria`, `Prensa`, `Inversion`).
