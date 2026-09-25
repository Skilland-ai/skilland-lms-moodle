<?php
defined('MOODLE_INTERNAL') || die;

$string['pluginname']   = 'Contenido Skilland';
$string['modulename']   = 'Contenido Skilland';
$string['modulenameplural'] = 'Contenidos Skilland';
$string['skilland:addinstance'] = 'Añadir una nueva actividad de contenido Skilland';
$string['skilland:view'] = 'Ver contenido Skilland';
$string['skilland:submit'] = 'Enviar a actividades Skilland';
$string['pluginadministration'] = 'Administración de contenido Skilland';

// Settings.
$string['settings_apikey'] = 'Clave API';
$string['settings_apikey_desc'] = 'Ingrese su clave API de Skilland aquí.';
$string['settings_orgid'] = 'ID de Organización';
$string['settings_orgid_desc'] = 'Ingrese su ID de Organización de Skilland aquí.';

// Course mapping.
$string['coursemapping_exists'] = 'Este curso está vinculado al curso de Skilland: {$a}';
$string['coursemapping_select'] = 'ID de Curso de Skilland';
$string['coursemapping_info'] = 'Seleccione el curso de Skilland para este curso de Moodle. Este curso de Skilland se utilizará para todas las actividades de Skilland en este curso de Moodle.';
$string['coursemapping_info_help'] = 'El mapeo de curso de Skilland se establece una vez por curso de Moodle y se comparte entre todas las actividades de Skilland.';

// Activity form.
$string['topicid'] = 'Tema';
$string['topicid_help'] = 'Seleccione el tema dentro del curso de Skilland que representa esta actividad.';
$string['select_topic'] = 'Seleccionar un tema';
$string['no_topics_available'] = 'No hay temas disponibles';
$string['loading'] = 'Cargando...';
$string['lessonselection'] = 'Seleccionar Lecciones';
$string['lessonselection_help'] = 'Elija qué lecciones de este tema importar como actividades SCORM.';
$string['lessons'] = 'Lecciones';
$string['select_topic_first'] = 'Seleccione un tema primero para ver las lecciones.';
$string['no_lessons_found'] = 'No se encontraron lecciones para este tema.';

// Behaviour settings.
$string['behaviour'] = 'Configuración de Comportamiento';
$string['autoupdate'] = 'Actualización automática de contenido';
$string['autoupdate_desc'] = 'Actualizar automáticamente las actividades SCORM cuando se publique una nueva versión en Skilland';
$string['lockafterfirstaccess'] = 'Bloquear después del primer acceso';
$string['lockafterfirstaccess_desc'] = 'Evitar actualizaciones una vez que los estudiantes hayan accedido al contenido';
$string['hidelabels'] = 'Ocultar códigos de Skilland';
$string['hidelabels_desc'] = 'Ocultar códigos de Skilland (ej. T2-L1) de los nombres de actividades visibles para estudiantes';

// Course custom field.
$string['customfield_skilland_course_id'] = 'ID de Curso de Skilland';
$string['customfield_skilland_course_id_desc'] = 'El ID de Curso de Skilland asociado con este curso de Moodle. Se utiliza para vincular todas las actividades de Skilland en el curso de Moodle con el curso de Skilland correcto.';
$string['skilland_course_id'] = 'Curso de Skilland';
$string['skilland_course_id_help'] = 'El Curso de Skilland para este curso de Moodle. Este valor se establece en la configuración del curso y se aplica a todas las actividades de Skilland en el curso de Moodle.';
$string['skilland_course_id_not_set'] = 'El ID de Curso de Skilland no está establecido para este curso.';
$string['skilland_course_id_required'] = 'El ID de Curso de Skilland debe estar establecido para este curso. {$a}';
$string['skilland_course_id_required_message'] = 'Antes de crear una actividad de Skilland, primero debe establecer el ID de Curso de Skilland en la configuración del curso.';
$string['set_skilland_course_id'] = 'Establecer ID de Curso de Skilland en configuración del curso';
$string['edit_course_settings'] = 'Editar en configuración del curso';

// GraphQL settings.
$string['settings_graphql_endpoint'] = 'Endpoint de GraphQL';
$string['settings_graphql_endpoint_desc'] = 'La URL del endpoint de la API GraphQL de Skilland.';
$string['settings_frontend_url'] = 'URL del Frontend';
$string['settings_frontend_url_desc'] = 'La URL de la aplicación frontend de Skilland (para redirecciones SSO). Si no está configurada, se usará la URL del endpoint GraphQL.';

// SSO settings.
$string['settings_sso_secret'] = 'Secreto Compartido SSO';
$string['settings_sso_secret_desc'] = 'Secreto compartido para autenticación SSO. Debe coincidir con la variable de entorno MOODLE_SSO_SECRET en el backend de Skilland. Use al menos 32 bytes aleatorios, por ejemplo generados con <code>openssl rand -base64 32</code>.';

// Development settings.
$string['settings_devmode'] = 'Registro de depuración detallado';
$string['settings_devmode_desc'] = 'Registra los detalles de las peticiones con nivel DEBUG_DEVELOPER. No lo active nunca en un sitio de producción.';

// Error messages.
$string['error_config_missing_orgid'] = 'El ID de Organización de Skilland no está configurado. Por favor, configúrelo en los ajustes del plugin.';
$string['error_config_missing_apikey'] = 'La clave API de Skilland no está configurada. Por favor, configúrela en los ajustes del plugin.';
$string['error_config_missing_endpoint'] = 'El endpoint de GraphQL no está configurado. Por favor, configúrelo en los ajustes del plugin.';
$string['error_config_invalid_credentials'] = 'Credenciales de API inválidas. Por favor, verifique su ID de Organización y Clave API en los ajustes del plugin.';
$string['error_sso_secret_too_short'] = 'El secreto compartido SSO debe tener al menos 32 bytes. Genere uno con openssl rand -base64 32.';
$string['error_sso_secret_known_dev'] = 'Este secreto compartido SSO se publicó como valor de desarrollo y no puede usarse. Genere uno nuevo con openssl rand -base64 32.';
$string['error_url_https_required'] = 'Esta URL debe usar https://.';
$string['error_graphql_http'] = 'Error HTTP al llamar a la API de Skilland: {$a}';
$string['error_graphql_invalid_json'] = 'Respuesta JSON inválida de la API de Skilland';
$string['error_graphql'] = 'Error de GraphQL: {$a}';
$string['error_graphql_unknown'] = 'Error de GraphQL desconocido';
$string['error_http'] = 'HTTP {$a}';
$string['error_fetch_courses'] = 'Error al obtener cursos de Skilland. Por favor, verifique su configuración e intente de nuevo.';
$string['error_fetch_topics'] = 'Error al obtener temas';
$string['error_config_missing_courseid'] = 'Se requiere el ID de Curso de Skilland para obtener temas.';
$string['error_config_missing_topicid'] = 'Se requiere el ID de Tema de Skilland para obtener lecciones.';
$string['error_config_missing_lessonid'] = 'Se requiere el ID de Lección de Skilland para obtener el paquete SCORM.';
$string['configure_plugin_settings'] = 'Configurar ajustes del plugin';

// Custom field creation in settings.
$string['customfield_status'] = 'Estado del Campo Personalizado del Curso';
$string['customfield_exists'] = 'El campo personalizado existe';
$string['customfield_missing'] = 'Campo personalizado no encontrado - haga clic en el botón de abajo para crearlo';
$string['create_customfield_button'] = 'Crear Campo Personalizado';
$string['customfield_created'] = '¡Campo personalizado creado exitosamente!';
$string['customfield_create_failed'] = 'Error al crear el campo personalizado. Por favor, revise los registros de error o créelo manualmente.';

// View page.
$string['no_lessons_configured'] = 'Aún no se han seleccionado lecciones para este tema. Edite la configuración de la actividad para agregar lecciones.';
$string['not_started'] = 'No iniciado';
$string['ready_to_start'] = 'Listo';
$string['in_progress'] = 'En progreso';
$string['completed'] = 'Completado';
$string['failed'] = 'Fallido';
$string['score'] = 'Puntuación';
$string['updated'] = 'Actualizado';
$string['back_to_lessons'] = 'Volver a lecciones';
$string['toggle_fullscreen'] = 'Alternar pantalla completa';
$string['launch_lesson'] = 'Iniciar Lección';
$string['content_coming_soon'] = 'Contenido Próximamente';
$string['content_being_prepared'] = 'El contenido de esta lección está siendo preparado. Por favor, vuelva más tarde.';
$string['content_not_provisioned'] = 'Contenido Aún No Disponible';
$string['lesson_not_found'] = 'Lección no encontrada.';
$string['scorm_not_ready'] = 'Esta lección aún no está lista para reproducir. Por favor, espere a que el contenido sea provisionado.';

// SCORM integration.
$string['error_scorm_not_available'] = 'El contenido SCORM no está disponible para esta lección.';
$string['error_scorm_fetch_failed'] = 'Error al obtener el paquete SCORM de Skilland: {$a}';
$string['error_scorm_download_failed'] = 'Error al descargar el paquete SCORM: {$a}';
$string['error_scorm_hash_mismatch'] = 'La verificación de integridad del paquete SCORM falló. El archivo descargado puede estar corrupto.';
$string['error_scorm_create_failed'] = 'Error al crear la actividad SCORM: {$a}';
$string['error_scorm_upload_failed'] = 'Error al subir el paquete SCORM a Moodle.';
$string['scorm_downloading'] = 'Descargando contenido de la lección...';
$string['scorm_download_complete'] = 'Descarga completa';
$string['provision_content'] = 'Provisionar Contenido';
$string['provision_content_desc'] = 'Descargar y crear la lección SCORM desde Skilland.';
$string['provision_topic'] = 'Provisionar Contenido del Tema';
$string['provision_topic_desc'] = 'Descargar y crear el paquete SCORM que contiene todas las lecciones de este tema.';
$string['provisioning'] = 'Provisionando contenido...';
$string['provision_success'] = '¡Contenido provisionado exitosamente!';
$string['provision_failed'] = 'Error al provisionar contenido: {$a}';

// New content indicator.
$string['new_content_available'] = 'Nuevo Contenido Disponible';

// Update from Skilland.
$string['update_from_skilland'] = 'Actualizar Desde Skilland';
$string['updating'] = 'Actualizando...';
$string['update_confirm_title'] = '¿Actualizar Contenido?';
$string['update_confirm_message'] = 'Esto descargará el contenido más reciente de Skilland y reemplazará el paquete SCORM actual. ADVERTENCIA: Todo el progreso y las calificaciones de los estudiantes para este tema se eliminarán permanentemente. Esta acción no se puede deshacer. ¿Está seguro de que desea continuar?';
$string['update_success'] = 'Contenido actualizado exitosamente desde Skilland.';
$string['update_error'] = 'Error al actualizar el contenido';

// Edit in Skilland.
$string['edit_in_skilland'] = 'Editar Contenido';
$string['edit_lessons_in_skilland'] = 'Editar Lecciones en Skilland';
$string['edit_in_skilland_desc'] = 'Haga clic para abrir el editor de lecciones de Skilland. Iniciará sesión automáticamente con su cuenta de Moodle.';
$string['edit_in_skilland_header'] = 'Editar en Skilland';
$string['edit_in_skilland_header_desc'] = 'Editar contenido del curso "{$a}" en la plataforma Skilland';

// Lesson selection.
$string['select_all'] = 'Seleccionar Todo';
$string['deselect_all'] = 'Deseleccionar Todo';

// Configure Skilland button.
$string['configure_skilland'] = 'Configurar Skilland';
$string['configure_skilland_desc'] = 'Configurar el mapeo de curso de Skilland para este curso';
$string['go_to_skilland'] = 'Ir a Skilland';

// Create in Skilland.
$string['create_in_skilland'] = '+ Crear en Skilland';
$string['creating_course'] = 'Creando curso en Skilland...';

// Auto-update / scheduled task.
$string['task_sync_content'] = 'Sincronizar contenido de Skilland para actividades con actualización automática';
$string['update_available'] = 'Actualización Disponible';
$string['update_available_desc'] = 'El contenido ha sido actualizado en Skilland. Haga clic en el botón para actualizar el paquete SCORM.';

// Plugin disabled.
$string['error_plugin_disabled'] = 'El plugin de Skilland está desactivado.';
$string['error_course_not_mapped'] = 'Este curso de Moodle no está vinculado a un curso de Skilland. Configura primero el ID de Curso de Skilland en los ajustes del curso.';
$string['error_course_not_mapped_to_skill'] = 'El contenido de Skilland solicitado no pertenece al curso de Skilland vinculado a este curso de Moodle.';

// Errors.
$string['invalidactivity'] = 'Actividad especificada inválida.';
$string['error_missing_parameters'] = 'Faltan parámetros requeridos para la redirección SSO.';
