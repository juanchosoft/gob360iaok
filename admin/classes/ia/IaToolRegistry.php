<?php

/**
 * Catálogo de herramientas IA con doble control de permisos.
 *
 * Principios:
 * 1. Solo se anuncian a Claude las tools que el usuario PUEDE usar (por RBAC).
 * 2. Al ejecutar, se revalida el permiso (fail-closed).
 * 3. Cada ejecución queda registrada en tbl_ia_tool_logs.
 */
final class IaToolRegistry
{
    /**
     * Define todas las herramientas disponibles.
     * Estructura de cada entry:
     *   'definicion' → array para el parámetro `tools` de la API (nombre, descripción, inputSchema)
     *   'permisos'   → string o string[] (OR lógico entre ellos; '' = solo sesión activa)
     *   'ejecutor'   → callable que recibe array $input y devuelve array
     *
     * @return array<string, array{definicion: array, permisos: string|string[], ejecutor: callable}>
     */
    public static function todas(): array
    {
        return [

            // ── Maestros ─────────────────────────────────────────────────────
            'buscar_municipio' => [
                'definicion' => [
                    'name'        => 'buscar_municipio',
                    'description' => 'Busca municipios del departamento por nombre (búsqueda parcial). '
                                   . 'Úsala primero cuando el usuario mencione el nombre de un municipio '
                                   . 'para obtener su código DANE.',
                    'input_schema' => [
                        'type'       => 'object',
                        'properties' => [
                            'nombre' => [
                                'type'        => 'string',
                                'description' => 'Nombre o parte del nombre del municipio (ej. "Bucaram", "Girón")',
                            ],
                        ],
                        'required' => ['nombre'],
                    ],
                ],
                'permisos' => '',
                'ejecutor' => static fn(array $input) => ToolMaestros::buscarMunicipio($input),
            ],

            'listar_secretarias' => [
                'definicion' => [
                    'name'        => 'listar_secretarias',
                    'description' => 'Lista todas las secretarías del departamento con sus IDs. '
                                   . 'Úsala cuando el usuario pregunte por una secretaría específica.',
                    'input_schema' => [
                        'type'       => 'object',
                        'properties' => new \stdClass(), // sin parámetros
                        'required'   => [],
                    ],
                ],
                'permisos' => '',
                'ejecutor' => static fn(array $input) => ToolMaestros::listarSecretarias($input),
            ],

            // ── Compromisos ──────────────────────────────────────────────────
            'consultar_compromisos' => [
                'definicion' => [
                    'name'        => 'consultar_compromisos',
                    'description' => 'Consulta compromisos del gobernador con municipios y secretarías. '
                                   . 'Devuelve estado (Cumplido/En Trámite/Sin Cumplir), fecha y secretaría responsable. '
                                   . 'Los datos ya vienen filtrados según tu perfil de acceso.',
                    'input_schema' => [
                        'type'       => 'object',
                        'properties' => [
                            'municipio_codigo' => [
                                'type'        => 'string',
                                'description' => 'Código DANE del municipio (usar buscar_municipio si solo tienes el nombre)',
                            ],
                            'estado' => [
                                'type'        => 'string',
                                'enum'        => ['Cumplido', 'En Trámite', 'Sin Cumplir'],
                                'description' => 'Filtrar por estado del compromiso',
                            ],
                            'secretaria_id' => [
                                'type'        => 'integer',
                                'description' => 'ID de la secretaría responsable (usar listar_secretarias para obtenerlo)',
                            ],
                            'limite' => [
                                'type'        => 'integer',
                                'description' => 'Máximo de registros a devolver (por defecto 50, máx 100)',
                            ],
                        ],
                        'required' => [],
                    ],
                ],
                'permisos' => ['compromisos.gobernador.view', 'compromisos.alcalde.view'],
                'ejecutor' => static fn(array $input) => ToolCompromisos::consultarCompromisos($input),
            ],

            'consultar_compromisos_alcalde' => [
                'definicion' => [
                    'name'        => 'consultar_compromisos_alcalde',
                    'description' => 'Consulta compromisos específicos de la alcaldía. '
                                   . 'Filtra automáticamente por el municipio de tu perfil.',
                    'input_schema' => [
                        'type'       => 'object',
                        'properties' => [
                            'municipio_codigo' => [
                                'type'        => 'string',
                                'description' => 'Código DANE del municipio (solo aplica para roles con acceso total)',
                            ],
                            'estado' => [
                                'type'        => 'string',
                                'enum'        => ['Cumplido', 'En Trámite', 'Sin Cumplir'],
                            ],
                            'limite' => ['type' => 'integer'],
                        ],
                        'required' => [],
                    ],
                ],
                'permisos' => 'compromisos.alcalde.view',
                'ejecutor' => static fn(array $input) => ToolCompromisos::consultarCompromisosAlcalde($input),
            ],

            // ── Dashboard ─────────────────────────────────────────────────────
            'resumen_dashboard' => [
                'definicion' => [
                    'name'        => 'resumen_dashboard',
                    'description' => 'Obtiene los indicadores clave del dashboard: totales de compromisos, '
                                   . 'proyectos, visitas y municipios según el perfil del usuario.',
                    'input_schema' => [
                        'type'       => 'object',
                        'properties' => new \stdClass(),
                        'required'   => [],
                    ],
                ],
                'permisos' => '',
                'ejecutor' => static fn(array $input) => ToolDashboard::resumenDashboard($input),
            ],

            // ── Visitas ──────────────────────────────────────────────────────
            'consultar_visitas' => [
                'definicion' => [
                    'name'        => 'consultar_visitas',
                    'description' => 'Consulta visitas municipales del gobernador. '
                                   . 'Filtra por municipio, tipo de visita y año. '
                                   . 'Los datos se restringen según tu perfil de acceso.',
                    'input_schema' => [
                        'type'       => 'object',
                        'properties' => [
                            'municipio_codigo' => ['type' => 'string', 'description' => 'Código DANE del municipio'],
                            'tipo_visita'      => ['type' => 'string', 'description' => 'Tipo de visita (ej. Gobernador, Secretario)'],
                            'anio'             => ['type' => 'integer', 'description' => 'Año a consultar (ej. 2025)'],
                            'limite'           => ['type' => 'integer'],
                        ],
                        'required' => [],
                    ],
                ],
                'permisos' => ['visitas.gobernador.view', 'compromisos.alcalde.view'],
                'ejecutor' => static fn(array $input) => ToolVisitas::consultarVisitas($input),
            ],

            'resumen_visitas_por_provincia' => [
                'definicion' => [
                    'name'        => 'resumen_visitas_por_provincia',
                    'description' => 'Resumen de visitas agrupadas por provincia para un año dado. '
                                   . 'Útil para comparar cobertura territorial.',
                    'input_schema' => [
                        'type'       => 'object',
                        'properties' => [
                            'anio' => ['type' => 'integer', 'description' => 'Año a consultar (default: año actual)'],
                        ],
                        'required' => [],
                    ],
                ],
                'permisos' => 'visitas.gobernador.view',
                'ejecutor' => static fn(array $input) => ToolVisitas::resumenVisitasPorProvincia($input),
            ],

            // ── Proyectos ────────────────────────────────────────────────────
            'consultar_proyectos_secretarias' => [
                'definicion' => [
                    'name'        => 'consultar_proyectos_secretarias',
                    'description' => 'Consulta proyectos de secretarías departamentales con inversión y avance. '
                                   . 'Filtra por secretaría y estado.',
                    'input_schema' => [
                        'type'       => 'object',
                        'properties' => [
                            'secretaria_id' => ['type' => 'integer', 'description' => 'ID de la secretaría'],
                            'estado'        => ['type' => 'string', 'description' => 'Estado del proyecto'],
                            'limite'        => ['type' => 'integer'],
                        ],
                        'required' => [],
                    ],
                ],
                'permisos' => ['secretarias.proyectos.view', 'proyectos.alcaldias.view'],
                'ejecutor' => static fn(array $input) => ToolProyectos::consultarProyectosSecretarias($input),
            ],

            'consultar_proyectos_alcaldias' => [
                'definicion' => [
                    'name'        => 'consultar_proyectos_alcaldias',
                    'description' => 'Consulta proyectos de alcaldías con inversión. '
                                   . 'Para alcaldes filtra automáticamente por su municipio.',
                    'input_schema' => [
                        'type'       => 'object',
                        'properties' => [
                            'municipio_codigo' => ['type' => 'string', 'description' => 'Código DANE del municipio'],
                            'estado'           => ['type' => 'string'],
                            'limite'           => ['type' => 'integer'],
                        ],
                        'required' => [],
                    ],
                ],
                'permisos' => 'proyectos.alcaldias.view',
                'ejecutor' => static fn(array $input) => ToolProyectos::consultarProyectosAlcaldias($input),
            ],

            // ── PAE ──────────────────────────────────────────────────────────
            'consultar_pae' => [
                'definicion' => [
                    'name'        => 'consultar_pae',
                    'description' => 'Consulta el Programa de Alimentación Escolar (PAE): '
                                   . 'sedes, niños focalizados, acceso a agua, electricidad y comedores. '
                                   . 'Agrupa por municipio.',
                    'input_schema' => [
                        'type'       => 'object',
                        'properties' => [
                            'municipio_codigo' => ['type' => 'string'],
                            'anio'             => ['type' => 'integer'],
                            'limite'           => ['type' => 'integer'],
                        ],
                        'required' => [],
                    ],
                ],
                'permisos' => 'secretarias.pae.view',
                'ejecutor' => static fn(array $input) => ToolPae::consultarPae($input),
            ],

            // ── Hacienda ─────────────────────────────────────────────────────
            'consultar_hacienda' => [
                'definicion' => [
                    'name'        => 'consultar_hacienda',
                    'description' => 'Consulta datos de hacienda: recaudo, valor tramitado y operatividad fiscal '
                                   . 'por municipio y tipo de acción.',
                    'input_schema' => [
                        'type'       => 'object',
                        'properties' => [
                            'municipio_codigo' => ['type' => 'string'],
                            'tipo'             => ['type' => 'string', 'description' => 'Tipo de acción fiscal'],
                            'anio'             => ['type' => 'integer'],
                            'limite'           => ['type' => 'integer'],
                        ],
                        'required' => [],
                    ],
                ],
                'permisos' => 'secretarias.hacienda.view',
                'ejecutor' => static fn(array $input) => ToolHacienda::consultarHacienda($input),
            ],

            // ── Factores de inestabilidad ─────────────────────────────────────
            'consultar_factores_municipio' => [
                'definicion' => [
                    'name'        => 'consultar_factores_municipio',
                    'description' => 'Consulta los factores de inestabilidad de un municipio: '
                                   . 'puntaje actual vs inicial por categoría (armado, social, económico). '
                                   . 'Requiere código de municipio.',
                    'input_schema' => [
                        'type'       => 'object',
                        'properties' => [
                            'municipio_codigo' => ['type' => 'string', 'description' => 'Código DANE del municipio (obligatorio)'],
                        ],
                        'required' => ['municipio_codigo'],
                    ],
                ],
                'permisos' => '',
                'ejecutor' => static fn(array $input) => ToolFactores::consultarFactoresMunicipio($input),
            ],

            'consultar_puntajes_departamento' => [
                'definicion' => [
                    'name'        => 'consultar_puntajes_departamento',
                    'description' => 'Ranking de municipios por puntaje de inestabilidad territorial a nivel departamental. '
                                   . 'Solo disponible para admin y gobernador.',
                    'input_schema' => [
                        'type'       => 'object',
                        'properties' => new \stdClass(),
                        'required'   => [],
                    ],
                ],
                'permisos' => '',
                'ejecutor' => static fn(array $input) => ToolFactores::consultarPuntajesDepartamento($input),
            ],

            // ── Plan de Desarrollo ────────────────────────────────────────────
            'consultar_plan_desarrollo' => [
                'definicion' => [
                    'name'        => 'consultar_plan_desarrollo',
                    'description' => 'Consulta metas del Plan de Desarrollo departamental: '
                                   . 'eje estratégico, sector, meta, avance por año y secretaría responsable.',
                    'input_schema' => [
                        'type'       => 'object',
                        'properties' => [
                            'secretaria_id'   => ['type' => 'integer'],
                            'eje_estrategico' => ['type' => 'string', 'description' => 'Parte del nombre del eje estratégico'],
                            'anio'            => ['type' => 'string', 'enum' => ['2024', '2025', '2026', '2027']],
                            'limite'          => ['type' => 'integer'],
                        ],
                        'required' => [],
                    ],
                ],
                'permisos' => 'plan_desarrollo.view',
                'ejecutor' => static fn(array $input) => ToolDesarrollo::consultarPlanDesarrollo($input),
            ],

            // ── Gestión Social ───────────────────────────────────────────────
            'consultar_gestion_social' => [
                'definicion' => [
                    'name'        => 'consultar_gestion_social',
                    'description' => 'Consulta actividades de la Gestora Social (ASPAS): '
                                   . 'tipo de acción, descripción, municipio y población impactada.',
                    'input_schema' => [
                        'type'       => 'object',
                        'properties' => [
                            'municipio_codigo' => ['type' => 'string'],
                            'anio'             => ['type' => 'integer'],
                            'limite'           => ['type' => 'integer'],
                        ],
                        'required' => [],
                    ],
                ],
                'permisos' => 'gestion_social.view',
                'ejecutor' => static fn(array $input) => ToolGestionSocial::consultarGestionSocial($input),
            ],

            // ── Estadísticas globales ────────────────────────────────────────
            'estadisticas_sistema' => [
                'definicion' => [
                    'name'        => 'estadisticas_sistema',
                    'description' => 'Devuelve estadísticas globales del sistema: '
                                   . 'total de compromisos, proyectos, visitas, PAE y gestión social del año actual. '
                                   . 'Se adapta al perfil del usuario.',
                    'input_schema' => [
                        'type'       => 'object',
                        'properties' => new \stdClass(),
                        'required'   => [],
                    ],
                ],
                'permisos' => '',
                'ejecutor' => static fn(array $input) => ToolEstadisticas::estadisticasSistema($input),
            ],

            // ── Reportes ─────────────────────────────────────────────────────
            'generar_reporte_pdf' => [
                'definicion' => [
                    'name'        => 'generar_reporte_pdf',
                    'description' => 'Genera un informe en PDF con la identidad gráfica institucional a '
                                   . 'partir de datos ya consultados en la conversación. Úsala SOLO cuando el '
                                   . 'usuario pida explícitamente un informe/reporte en PDF o para descargar. '
                                   . 'Antes de llamarla, reúne los datos necesarios con las demás tools. '
                                   . 'Escribe contenido_html usando SOLO estas etiquetas: h2, h3, h4, p, table '
                                   . '(con thead/tbody/tr/th/td), ul/ol/li, b/strong, i/em, br, hr — sin '
                                   . 'atributos, sin imágenes ni enlaces externos. Sé lo más completo y '
                                   . 'ordenado posible. La herramienta devuelve una URL: compártela con el '
                                   . 'usuario tal cual, como texto plano, para que sea clicable en el chat.',
                    'input_schema' => [
                        'type'       => 'object',
                        'properties' => [
                            'titulo' => [
                                'type'        => 'string',
                                'description' => 'Título del informe',
                            ],
                            'contenido_html' => [
                                'type'        => 'string',
                                'description' => 'Cuerpo del informe en HTML restringido (ver descripción de la tool)',
                            ],
                        ],
                        'required' => ['titulo', 'contenido_html'],
                    ],
                ],
                'permisos' => 'asistente_ia.pdf.use',
                'ejecutor' => static fn(array $input) => ToolReportes::generarReportePdf($input),
            ],

            // ── Acceso SQL directo (solo SELECT) ─────────────────────────────
            'consultar_base_de_datos' => [
                'definicion' => [
                    'name'        => 'consultar_base_de_datos',
                    'description' => 'Ejecuta una consulta SQL SELECT directa en la base de datos del sistema. '
                                   . 'ÚSALA cuando las herramientas especializadas no cubran la necesidad. '
                                   . 'Solo permite SELECT/WITH. Máximo 250 filas devueltas. '
                                   . 'IMPORTANTE: siempre incluye filtros WHERE de municipio y/o secretaría '
                                   . 'según el scope del usuario (disponible en el bloque dinámico del system prompt). '
                                   . 'Las tablas principales y sus columnas están documentadas en el system prompt. '
                                   . 'Ejemplos de uso: consultas cruzadas entre tablas, cálculos personalizados, '
                                   . 'datos de tablas secundarias como tbl_lideres, tbl_boletin, tbl_prensa, etc.',
                    'input_schema' => [
                        'type'       => 'object',
                        'properties' => [
                            'sql_query' => [
                                'type'        => 'string',
                                'description' => 'Consulta SQL SELECT válida. Debe empezar con SELECT o WITH. '
                                              . 'Solo lectura — INSERT/UPDATE/DELETE/DROP están bloqueados. '
                                              . 'El sistema agrega LIMIT 250 automáticamente si no lo incluyes. '
                                              . 'Usa parámetros literales en el SQL (no placeholders :param).',
                            ],
                        ],
                        'required' => ['sql_query'],
                    ],
                ],
                'permisos' => '',
                'ejecutor' => static fn(array $input) => ToolBaseDeDatos::consultar($input),
            ],

            // ── Directorio de contactos personal del usuario ─────────────────
            'contactos_buscar' => [
                'definicion' => [
                    'name'        => 'contactos_buscar',
                    'description' => 'Busca en el directorio de contactos PERSONAL del usuario en sesión (nunca '
                                   . 'el de otro usuario) por nombre o parte del nombre, para resolver a quién '
                                   . 'se refiere antes de enviar/responder un correo o invitar a alguien a un '
                                   . 'evento. Úsala SIEMPRE que el usuario mencione a alguien por nombre (no por '
                                   . 'su correo directamente) para una acción de Gmail o Calendar.',
                    'input_schema' => [
                        'type'       => 'object',
                        'properties' => [
                            'nombre' => [
                                'type'        => 'string',
                                'description' => 'Nombre o parte del nombre del contacto a buscar',
                            ],
                        ],
                        'required' => ['nombre'],
                    ],
                ],
                'permisos' => 'contactos.propio.view',
                'ejecutor' => static fn(array $input) => ToolContactos::buscar($input),
            ],

            // ── Google Calendar personal del usuario ─────────────────────────
            'calendario_listar_eventos' => [
                'definicion' => [
                    'name'        => 'calendario_listar_eventos',
                    'description' => 'Lista los eventos del calendario de Google PERSONAL del usuario en sesión '
                                   . '(no un calendario institucional) en un rango de fechas. Requiere que el '
                                   . 'usuario haya conectado su cuenta de Google.',
                    'input_schema' => [
                        'type'       => 'object',
                        'properties' => [
                            'desde' => [
                                'type'        => 'string',
                                'description' => 'Fecha/hora inicial en formato ISO 8601 (ej. 2026-08-10T00:00:00-05:00). Por defecto: ahora.',
                            ],
                            'hasta' => [
                                'type'        => 'string',
                                'description' => 'Fecha/hora final en formato ISO 8601. Por defecto: 7 días después de "desde".',
                            ],
                        ],
                        'required' => [],
                    ],
                ],
                'permisos' => 'asistente_ia.google.use',
                'ejecutor' => static fn(array $input) => ToolCalendarGoogle::listarEventos($input),
            ],

            'calendario_crear_evento' => [
                'definicion' => [
                    'name'        => 'calendario_crear_evento',
                    'description' => 'Crea un evento en el calendario de Google PERSONAL del usuario en sesión. '
                                   . 'Si falta el título, la hora de inicio, o a quién invitar y el usuario no lo '
                                   . 'dijo explícitamente, PREGÚNTALE antes de llamar a esta herramienta -- nunca '
                                   . 'inventes esos datos.',
                    'input_schema' => [
                        'type'       => 'object',
                        'properties' => [
                            'titulo'      => ['type' => 'string', 'description' => 'Título/asunto de la reunión o evento'],
                            'inicio'      => ['type' => 'string', 'description' => 'Fecha/hora de inicio en ISO 8601, o solo fecha (YYYY-MM-DD) si es de todo el día'],
                            'fin'         => ['type' => 'string', 'description' => 'Fecha/hora de fin en ISO 8601 (opcional, por defecto igual al inicio)'],
                            'todo_el_dia' => ['type' => 'boolean', 'description' => 'true si es un evento de todo el día'],
                            'ubicacion'   => ['type' => 'string', 'description' => 'Lugar físico o enlace de videollamada (opcional)'],
                            'descripcion' => ['type' => 'string', 'description' => 'Notas/detalle del evento (opcional)'],
                            'invitados'   => [
                                'type'        => 'array',
                                'items'       => ['type' => 'string'],
                                'description' => 'Correos electrónicos de los invitados (opcional)',
                            ],
                        ],
                        'required' => ['titulo', 'inicio'],
                    ],
                ],
                'permisos' => 'asistente_ia.google.use',
                'ejecutor' => static fn(array $input) => ToolCalendarGoogle::crearEvento($input),
            ],

            'calendario_mover_evento' => [
                'definicion' => [
                    'name'        => 'calendario_mover_evento',
                    'description' => 'Cambia la fecha/hora de un evento existente en el calendario de Google '
                                   . 'PERSONAL del usuario. Necesitas el evento_id -- consíguelo primero con '
                                   . 'calendario_listar_eventos si no lo tienes.',
                    'input_schema' => [
                        'type'       => 'object',
                        'properties' => [
                            'evento_id'    => ['type' => 'string', 'description' => 'ID del evento (obtenido de calendario_listar_eventos)'],
                            'nuevo_inicio' => ['type' => 'string', 'description' => 'Nueva fecha/hora de inicio en ISO 8601'],
                            'nuevo_fin'    => ['type' => 'string', 'description' => 'Nueva fecha/hora de fin en ISO 8601 (opcional)'],
                        ],
                        'required' => ['evento_id', 'nuevo_inicio'],
                    ],
                ],
                'permisos' => 'asistente_ia.google.use',
                'ejecutor' => static fn(array $input) => ToolCalendarGoogle::moverEvento($input),
            ],

            'calendario_cancelar_evento' => [
                'definicion' => [
                    'name'        => 'calendario_cancelar_evento',
                    'description' => 'Elimina un evento del calendario de Google PERSONAL del usuario en sesión. '
                                   . 'Acción irreversible: confirma con el usuario antes de llamarla si no fue '
                                   . 'explícito en su pedido.',
                    'input_schema' => [
                        'type'       => 'object',
                        'properties' => [
                            'evento_id' => ['type' => 'string', 'description' => 'ID del evento (obtenido de calendario_listar_eventos)'],
                        ],
                        'required' => ['evento_id'],
                    ],
                ],
                'permisos' => 'asistente_ia.google.use',
                'ejecutor' => static fn(array $input) => ToolCalendarGoogle::cancelarEvento($input),
            ],

            // ── Gmail personal del usuario ────────────────────────────────────
            'gmail_listar_correos' => [
                'definicion' => [
                    'name'        => 'gmail_listar_correos',
                    'description' => 'Lista correos recientes del correo de Gmail PERSONAL del usuario en '
                                   . 'sesión (no un buzón institucional). Por defecto solo los no leídos. '
                                   . 'Devuelve solo metadatos (remitente, asunto, fecha, fragmento) -- para '
                                   . 'el cuerpo completo de uno puntual usa gmail_leer_correo.',
                    'input_schema' => [
                        'type'       => 'object',
                        'properties' => [
                            'filtro' => [
                                'type'        => 'string',
                                'enum'        => ['no_leidos', 'todos'],
                                'description' => 'Por defecto "no_leidos"',
                            ],
                            'limite' => [
                                'type'        => 'integer',
                                'description' => 'Máximo de correos a listar (por defecto 10, máx 25)',
                            ],
                        ],
                        'required' => [],
                    ],
                ],
                'permisos' => 'asistente_ia.google.use',
                'ejecutor' => static fn(array $input) => ToolGmail::listarCorreos($input),
            ],

            'gmail_buscar_correos' => [
                'definicion' => [
                    'name'        => 'gmail_buscar_correos',
                    'description' => 'Busca correos en el Gmail PERSONAL del usuario en sesión usando la '
                                   . 'sintaxis de búsqueda de Gmail (ej. "from:juan@ejemplo.com", '
                                   . '"asunto reunión after:2026/08/01", "subject:factura"). Devuelve solo '
                                   . 'metadatos, igual que gmail_listar_correos.',
                    'input_schema' => [
                        'type'       => 'object',
                        'properties' => [
                            'consulta' => ['type' => 'string', 'description' => 'Texto o filtros de búsqueda estilo Gmail'],
                            'limite'   => ['type' => 'integer', 'description' => 'Máximo de resultados (por defecto 10, máx 25)'],
                        ],
                        'required' => ['consulta'],
                    ],
                ],
                'permisos' => 'asistente_ia.google.use',
                'ejecutor' => static fn(array $input) => ToolGmail::buscarCorreos($input),
            ],

            'gmail_leer_correo' => [
                'definicion' => [
                    'name'        => 'gmail_leer_correo',
                    'description' => 'Trae el cuerpo completo de un correo puntual del Gmail PERSONAL del '
                                   . 'usuario, por su mensaje_id (obtenido de gmail_listar_correos o '
                                   . 'gmail_buscar_correos). Resume lo importante para el usuario en vez de '
                                   . 'leer el cuerpo completo palabra por palabra, salvo que lo pida.',
                    'input_schema' => [
                        'type'       => 'object',
                        'properties' => [
                            'mensaje_id' => ['type' => 'string', 'description' => 'ID del mensaje'],
                        ],
                        'required' => ['mensaje_id'],
                    ],
                ],
                'permisos' => 'asistente_ia.google.use',
                'ejecutor' => static fn(array $input) => ToolGmail::leerCorreo($input),
            ],

            'gmail_marcar_leido' => [
                'definicion' => [
                    'name'        => 'gmail_marcar_leido',
                    'description' => 'Marca un correo del Gmail PERSONAL del usuario como leído o no leído.',
                    'input_schema' => [
                        'type'       => 'object',
                        'properties' => [
                            'mensaje_id' => ['type' => 'string', 'description' => 'ID del mensaje'],
                            'leido'      => ['type' => 'boolean', 'description' => 'true = marcar como leído (por defecto), false = marcar como no leído'],
                        ],
                        'required' => ['mensaje_id'],
                    ],
                ],
                'permisos' => 'asistente_ia.google.use',
                'ejecutor' => static fn(array $input) => ToolGmail::marcarLeido($input),
            ],

            'gmail_enviar_correo' => [
                'definicion' => [
                    'name'        => 'gmail_enviar_correo',
                    'description' => 'Envía un correo nuevo desde el Gmail PERSONAL del usuario en sesión. '
                                   . 'Si falta el destinatario, el asunto o qué escribir y el usuario no lo '
                                   . 'dijo explícitamente, PREGÚNTALE antes de llamar a esta herramienta -- '
                                   . 'nunca inventes esos datos. Es una acción irreversible y visible para '
                                   . 'terceros: confirma con el usuario el contenido antes de enviarlo si no '
                                   . 'fue explícito en su pedido.',
                    'input_schema' => [
                        'type'       => 'object',
                        'properties' => [
                            'para'   => ['type' => 'string', 'description' => 'Correo del destinatario'],
                            'asunto' => ['type' => 'string', 'description' => 'Asunto del correo'],
                            'cuerpo' => ['type' => 'string', 'description' => 'Texto del correo'],
                        ],
                        'required' => ['para', 'asunto', 'cuerpo'],
                    ],
                ],
                'permisos' => 'asistente_ia.google.use',
                'ejecutor' => static fn(array $input) => ToolGmail::enviarCorreo($input),
            ],

            'gmail_responder_correo' => [
                'definicion' => [
                    'name'        => 'gmail_responder_correo',
                    'description' => 'Responde dentro del mismo hilo a un correo existente del Gmail '
                                   . 'PERSONAL del usuario, por su mensaje_id. Si el usuario no dictó '
                                   . 'exactamente qué escribir, PREGÚNTALE antes de llamar a esta '
                                   . 'herramienta. Acción irreversible: confirma el contenido con el '
                                   . 'usuario antes de enviarlo si no fue explícito en su pedido.',
                    'input_schema' => [
                        'type'       => 'object',
                        'properties' => [
                            'mensaje_id' => ['type' => 'string', 'description' => 'ID del correo original al que se responde'],
                            'cuerpo'     => ['type' => 'string', 'description' => 'Texto de la respuesta'],
                        ],
                        'required' => ['mensaje_id', 'cuerpo'],
                    ],
                ],
                'permisos' => 'asistente_ia.google.use',
                'ejecutor' => static fn(array $input) => ToolGmail::responderCorreo($input),
            ],
        ];
    }

    /**
     * Tools ejecutadas por el propio servidor de Anthropic (no pasan por
     * IaToolRegistry::ejecutar — Claude nunca las devuelve como bloque `tool_use`, sino como
     * `server_tool_use` + su resultado, ya resueltos dentro de la misma respuesta).
     * Disponibles para cualquier sesión válida, igual que el resto del asistente.
     *
     * @return array[]
     */
    private static function herramientasServidor(): array
    {
        $servidor = [
            [
                'type'     => 'web_search_20250305',
                'name'     => 'web_search',
                // Limitado a 3 (no el default de 5): cada búsqueda agrega latencia real y
                // este asistente también se usa por voz, donde la respuesta se siente lenta
                // si encadena demasiadas.
                'max_uses' => 3,
            ],
        ];

        return $servidor;
    }

    /**
     * Retorna solo las tools que el usuario en sesión tiene permiso de usar, más las tools
     * nativas de servidor (ej. acceso a internet). Esto es lo que se envía a Claude como
     * lista de herramientas disponibles.
     *
     * @return array[] Definiciones en formato API
     */
    public static function paraUsuario(): array
    {
        $visibles = [];
        foreach (self::todas() as $nombre => $entry) {
            if (self::tienePermiso($entry['permisos'])) {
                $visibles[] = $entry['definicion'];
            }
        }
        // Ordenar alfabéticamente por nombre para que el cache de prompts sea determinista
        usort($visibles, static fn($a, $b) => strcmp($a['name'], $b['name']));

        // Las tools de servidor van al final, en orden fijo (no dependen de permisos RBAC)
        return array_merge($visibles, self::herramientasServidor());
    }

    /**
     * Ejecuta una herramienta con revalidación de permiso y auditoría.
     *
     * @param  string $nombre Nombre de la tool (tal como la llamó Claude)
     * @param  array  $input  Parámetros JSON de la tool
     * @return array  Resultado serializable como JSON
     */
    public static function ejecutar(string $nombre, array $input): array
    {
        $todas = self::todas();

        if (!isset($todas[$nombre])) {
            return ['error' => "Herramienta '{$nombre}' no reconocida."];
        }

        $entry = $todas[$nombre];

        // Revalidación de permiso (fail-closed)
        if (!self::tienePermiso($entry['permisos'])) {
            self::registrarLog($nombre, $input, 0, 0, false, 'Sin permisos RBAC');
            return ['error' => 'No tienes permiso para acceder a esta información.'];
        }

        $inicio = microtime(true);
        try {
            $resultado = ($entry['ejecutor'])($input);
            $ms   = (int) ((microtime(true) - $inicio) * 1000);
            $filas = isset($resultado['compromisos']) ? count($resultado['compromisos'])
                   : (isset($resultado['municipios']) ? count($resultado['municipios'])
                   : (isset($resultado['secretarias']) ? count($resultado['secretarias']) : 0));
            self::registrarLog($nombre, $input, $filas, $ms, true);
            return $resultado;
        } catch (\Throwable $e) {
            $ms = (int) ((microtime(true) - $inicio) * 1000);
            self::registrarLog($nombre, $input, 0, $ms, false, $e->getMessage());
            return ['error' => 'Error al consultar la información. Intenta de nuevo.'];
        }
    }

    // ── Helpers privados ─────────────────────────────────────────────────────

    private static function tienePermiso(string|array $permisos): bool
    {
        // '' significa solo sesión activa (sin permiso específico)
        if ($permisos === '') {
            return isset($_SESSION['session_user']);
        }
        if (is_array($permisos)) {
            // OR: cualquiera de los permisos es suficiente
            foreach ($permisos as $p) {
                if (SessionData::hasPermission($p)) {
                    return true;
                }
            }
            return false;
        }
        return SessionData::hasPermission($permisos);
    }

    private static function registrarLog(
        string $nombre,
        array  $input,
        int    $filas,
        int    $ms,
        bool   $exito,
        string $error = ''
    ): void {
        $usuarioId = (int) (SessionData::getUserId() ?? 0);
        try {
            $db  = new DbConection();
            $pdo = $db->openConect();
            $st  = $pdo->prepare(
                "INSERT INTO tbl_ia_tool_logs
                    (tbl_usuario_id, tool_nombre, tool_input, filas_devueltas, duracion_ms, exito, error, created_at)
                 VALUES (:uid, :tool, :input, :filas, :ms, :exito, :error, :now)"
            );
            $st->execute([
                ':uid'   => $usuarioId,
                ':tool'  => $nombre,
                ':input' => json_encode($input, JSON_UNESCAPED_UNICODE),
                ':filas' => $filas,
                ':ms'    => $ms,
                ':exito' => $exito ? 1 : 0,
                ':error' => $error,
                ':now'   => Util::date(),
            ]);
            $db->closeConect();
        } catch (\Throwable $e) {
            // No romper el flujo principal por un error de auditoría
            error_log("IaToolRegistry::registrarLog error: " . $e->getMessage());
        }
    }
}
