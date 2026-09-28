<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Spanish language strings for mod_skilland.
 *
 * @package    mod_skilland
 * @copyright  2024 SkilLand <https://skilland.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

$string['aria_next_lesson'] = 'Siguiente lección: {$a}';
$string['aria_previous_lesson'] = 'Lección anterior: {$a}';
$string['autoupdate'] = 'Avisarme de actualizaciones de contenido';
$string['autoupdate_desc'] = 'Cuando se publique una nueva versión en Skilland, se avisa a los profesores de este curso y esta actividad ofrece aplicarla. No se sustituye nada hasta que un profesor aplica la actualización, lo que elimina todos los intentos y calificaciones de los estudiantes para esta actividad, sin posibilidad de recuperarlos.';
$string['autoupdate_warning'] = 'Los avisos de actualización están activados y "Bloquear después del primer acceso" está desactivada: se avisa a los profesores de cada cambio de contenido en Skilland, y aplicar uno elimina y vuelve a crear esta actividad, borrando los intentos y calificaciones de todos los estudiantes. Active "Bloquear después del primer acceso" para dejar de recibir avisos una vez que los estudiantes hayan comenzado.';
$string['back_to_lessons'] = 'Volver a lecciones';
$string['behaviour'] = 'Configuración de Comportamiento';
$string['completed'] = 'Completado';
$string['completiondetail:lessons'] = 'Completar todas las lecciones';
$string['completionlessons'] = 'Completar todas las lecciones';
$string['completionlessons_desc'] = 'El alumnado debe completar o aprobar todas las lecciones visibles';
$string['completionlessons_help'] = 'Si se activa, la actividad se marca como completada cuando el alumno ha completado o aprobado todas las lecciones visibles. Las lecciones ocultas no cuentan, y una actividad sin lecciones visibles nunca se completa. El progreso se conserva cuando se reconstruye el paquete SCORM.';
$string['configure_plugin_settings'] = 'Configurar ajustes del plugin';
$string['configure_skilland'] = 'Configurar Skilland';
$string['configure_skilland_desc'] = 'Configurar el mapeo de curso de Skilland para este curso';
$string['content_being_prepared'] = 'El contenido de esta lección o tema se está preparando. Vuelva más tarde.';
$string['content_coming_soon'] = 'Contenido próximamente';
$string['content_last_updated'] = 'Contenido actualizado por última vez: {$a}. Cualquier actualización de esta actividad reinicia el progreso en ella.';
$string['content_not_provisioned'] = 'Contenido aún no disponible';
$string['course_unknown'] = 'Curso desconocido (ID {$a})';
$string['course_unknown_warning'] = 'El curso de SkilLand vinculado ya no está disponible para este sitio. Elige otro curso o borra la selección.';
$string['coursemapping_exists'] = 'Este curso está vinculado al curso de Skilland: {$a}';
$string['coursemapping_info'] = 'Seleccione el curso de Skilland para este curso de Moodle. Este curso de Skilland se utilizará para todas las actividades de Skilland en este curso de Moodle.';
$string['coursemapping_info_help'] = 'El mapeo de curso de Skilland se establece una vez por curso de Moodle y se comparte entre todas las actividades de Skilland.';
$string['coursemapping_select'] = 'ID de Curso de Skilland';
$string['create_course_confirm_body'] = '¿Crear «{$a}» en SkilLand? El curso de SkilLand se crea ahora y queda vinculado a este curso de Moodle, aunque canceles este formulario.';
$string['create_course_confirm_replace'] = 'Este curso de Moodle ya está vinculado a un curso de SkilLand: el nuevo curso sustituye ese vínculo.';
$string['create_course_confirm_title'] = 'Crear un curso de SkilLand';
$string['create_course_confirm_yes'] = 'Crear';
$string['create_in_skilland'] = 'Crear en SkilLand';
$string['creating_course'] = 'Creando curso en Skilland...';
$string['current_topic_unavailable'] = 'Tema actual (SkilLand no disponible)';
$string['customfield_exists'] = 'El campo personalizado existe';
$string['customfield_missing'] = 'Campo personalizado no encontrado. Se vuelve a crear en la próxima actualización del plugin o la primera vez que un curso se vincula a un curso de SkilLand.';
$string['customfield_skilland_course_id'] = 'ID de Curso de Skilland';
$string['customfield_skilland_course_id_desc'] = 'El ID de Curso de Skilland asociado con este curso de Moodle. Se utiliza para vincular todas las actividades de Skilland en el curso de Moodle con el curso de Skilland correcto.';
$string['customfield_status'] = 'Estado del campo personalizado del curso';
$string['deselect_all'] = 'Deseleccionar todo';
$string['destructive_confirm_action'] = 'Reemplazar contenido y eliminar progreso';
$string['edit_course_settings'] = 'Editar en configuración del curso';
$string['edit_in_skilland'] = 'Editar contenido';
$string['edit_in_skilland_desc'] = 'Haga clic para abrir el editor de lecciones de Skilland. Iniciará sesión automáticamente con su cuenta de Moodle.';
$string['edit_in_skilland_header'] = 'Editar en Skilland';
$string['edit_in_skilland_header_desc'] = 'Editar contenido del curso "{$a}" en la plataforma Skilland';
$string['edit_lessons_in_skilland'] = 'Editar lecciones en Skilland';
$string['error_api_unavailable'] = 'No se pudo contactar con el servicio de SkilLand. Inténtalo más tarde o pide al administrador del sitio que revise la configuración del plugin de SkilLand.';
$string['error_config_invalid_credentials'] = 'Credenciales de API inválidas. Por favor, verifique su ID de Organización y Clave API en los ajustes del plugin.';
$string['error_config_missing_apikey'] = 'La clave API de Skilland no está configurada. Por favor, configúrela en los ajustes del plugin.';
$string['error_config_missing_courseid'] = 'Se requiere el ID de Curso de Skilland para obtener temas.';
$string['error_config_missing_endpoint'] = 'El endpoint de GraphQL no está configurado. Por favor, configúrelo en los ajustes del plugin.';
$string['error_config_missing_lessonid'] = 'Se requiere el ID de Lección de Skilland para obtener el paquete SCORM.';
$string['error_config_missing_orgid'] = 'El ID de Organización de Skilland no está configurado. Por favor, configúrelo en los ajustes del plugin.';
$string['error_config_missing_topicid'] = 'Se requiere el ID de Tema de Skilland para obtener lecciones.';
$string['error_course_not_mapped'] = 'Este curso de Moodle no está vinculado a un curso de Skilland. Configura primero el ID de Curso de Skilland en los ajustes del curso.';
$string['error_course_not_mapped_to_skill'] = 'El contenido de Skilland solicitado no pertenece al curso de Skilland vinculado a este curso de Moodle.';
$string['error_create_course'] = 'Error al crear curso: {$a}';
$string['error_create_course_failed'] = 'Error al crear el curso en Skilland.';
$string['error_fetch_courses'] = 'Error al obtener cursos de Skilland. Por favor, verifique su configuración e intente de nuevo.';
$string['error_fetch_courses_detail'] = 'Error al obtener cursos de Skilland: {$a}';
$string['error_fetch_topics'] = 'Error al obtener temas';
$string['error_fetch_topics_detail'] = 'Error al obtener temas de Skilland: {$a}';
$string['error_grade_scale_unsupported'] = 'No se admiten escalas. Elige una calificación por puntos o Ninguna.';
$string['error_graphql'] = 'Error de GraphQL: {$a}';
$string['error_graphql_apikey_inactive'] = 'La clave API está inactiva. Regenere la clave API en los ajustes de organización de Skilland.';
$string['error_graphql_apikey_not_found'] = 'No se encontró una clave API para esta organización. Genere una clave API en los ajustes de organización de Skilland.';
$string['error_graphql_http'] = 'Error HTTP al llamar a la API de Skilland: {$a}';
$string['error_graphql_invalid_apikey'] = 'Clave API inválida. Verifique su clave API en los ajustes del plugin.';
$string['error_graphql_invalid_json'] = 'Respuesta JSON inválida de la API de Skilland';
$string['error_graphql_invalid_orgid_format'] = 'Formato de ID de Organización inválido. Verifique su ID de Organización en los ajustes del plugin.';
$string['error_graphql_org_not_found'] = 'Organización no encontrada. Verifique su ID de Organización en los ajustes del plugin.';
$string['error_graphql_unknown'] = 'Error de GraphQL desconocido';
$string['error_http'] = 'HTTP {$a}';
$string['error_http_redirect'] = 'El servidor de Skilland respondió con una redirección (HTTP {$a}), que no se sigue. Revisa la URL configurada.';
$string['error_insecure_url'] = 'La URL de {$a} de Skilland debe usar https://.';
$string['error_lessons_not_in_topic'] = 'Las lecciones seleccionadas no pertenecen a este tema. Vuelve a seleccionar las lecciones y guarda de nuevo.';
$string['error_loading_courses'] = 'Error al cargar cursos: {$a}';
$string['error_missing_parameters'] = 'Faltan parámetros requeridos para la redirección SSO.';
$string['error_network'] = 'Error de red';
$string['error_no_valid_id_provisioning'] = 'No se proporcionó un ID válido para el aprovisionamiento';
$string['error_package_host_not_allowed'] = 'No se pueden descargar paquetes SCORM desde {$a}. Añade el host al ajuste de hosts de paquetes SCORM si es de confianza.';
$string['error_package_not_zip'] = 'El paquete SCORM descargado no es un archivo zip válido.';
$string['error_package_too_large'] = 'El paquete SCORM supera el límite de {$a} MB.';
$string['error_plugin_disabled'] = 'El plugin de Skilland está desactivado.';
$string['error_provision_in_progress'] = 'El contenido SCORM de esta actividad ya se está preparando. Inténtalo de nuevo en un momento.';
$string['error_scorm_create_failed'] = 'Error al crear la actividad SCORM: {$a}';
$string['error_scorm_download_failed'] = 'Error al descargar el paquete SCORM: {$a}';
$string['error_scorm_fetch_failed'] = 'Error al obtener el paquete SCORM de Skilland.';
$string['error_scorm_hash_mismatch'] = 'La verificación de integridad del paquete SCORM falló. El archivo descargado puede estar corrupto.';
$string['error_scorm_not_available'] = 'El contenido SCORM no está disponible para esta lección.';
$string['error_scorm_parse_failed'] = 'No se pudo convertir el paquete SCORM en lecciones ejecutables: {$a}';
$string['error_scorm_signature_invalid'] = 'La firma del paquete SCORM de Skilland no corresponde al paquete, así que no se importó.';
$string['error_scorm_signature_malformed'] = 'La firma del paquete SCORM de Skilland está mal formada, así que el paquete no se importó.';
$string['error_scorm_signature_missing'] = 'El paquete SCORM de Skilland no está firmado, así que no se importó. Pida al administrador del sitio que revise los ajustes del plugin Skilland.';
$string['error_scorm_signature_unknown_key'] = 'El paquete SCORM de Skilland está firmado con una clave en la que este sitio no confía, así que no se importó. Pida al administrador del sitio que revise las claves de firma de paquetes SCORM.';
$string['error_scorm_upload_failed'] = 'Error al subir el paquete SCORM a Moodle.';
$string['error_signing_keys_invalid'] = 'Líneas no válidas: {$a}. Cada línea debe ser idclave:clavepublica, donde el id de clave tiene de 1 a 64 letras, dígitos, puntos, guiones bajos o guiones (cada id una sola vez) y la clave pública es base64 de exactamente 32 bytes.';
$string['error_sso_secret_known_dev'] = 'Este secreto compartido SSO se publicó como valor de desarrollo y no puede usarse. Copie el secreto de su organización (al menos 32 bytes) desde SkilLand › Ajustes › Integraciones › Moodle.';
$string['error_sso_secret_too_short'] = 'El secreto compartido SSO debe tener al menos 32 bytes. Copie el secreto de su organización desde SkilLand › Ajustes › Integraciones › Moodle.';
$string['error_sso_user_not_allowed'] = 'Esta cuenta de Moodle no puede iniciar sesión en SkilLand: se rechazan las cuentas de invitado, suspendidas, eliminadas, sin confirmar y sin acceso (nologin). Contacte con el administrador del sitio si cree que es un error.';
$string['error_topicid_required'] = 'Se requiere un tema. Seleccione uno, o reintente si la lista de temas no se pudo cargar.';
$string['error_unknown'] = 'Error desconocido';
$string['error_url_https_required'] = 'Esta URL debe usar https://.';
$string['failed'] = 'Fallido';
$string['go_to_skilland'] = 'Ir a Skilland';
$string['hidelabels'] = 'Ocultar códigos de Skilland';
$string['hidelabels_desc'] = 'Ocultar códigos de Skilland (ej. T2-L1) de los nombres de actividades visibles para estudiantes';
$string['in_progress'] = 'En progreso';
$string['invalidactivity'] = 'Actividad especificada inválida.';
$string['launch_lesson'] = 'Iniciar lección';
$string['lesson_not_available'] = 'Esta lección no está disponible en esta actividad.';
$string['lesson_sco_missing'] = 'Esta lección no está en el paquete de contenido actual. Usa Actualizar Desde Skilland en los ajustes de la actividad para regenerarlo.';
$string['lessons'] = 'Lecciones';
$string['lessonselection'] = 'Seleccionar Lecciones';
$string['lessonselection_help'] = 'Elija qué lecciones de este tema importar como actividades SCORM.';
$string['loading'] = 'Cargando...';
$string['loading_courses'] = 'Cargando cursos de Skilland...';
$string['lockafterfirstaccess'] = 'Bloquear después del primer acceso';
$string['lockafterfirstaccess_desc'] = 'Una vez que un estudiante haya accedido al contenido, evitar que la actualización automática elimine y vuelva a crear este paquete SCORM (se conservan sus intentos y calificaciones)';
$string['lockafterfirstaccess_hint'] = 'Para mantener el contenido actual sin cambios para los estudiantes que ya han empezado, activa "{$a}" en los ajustes de la actividad.';
$string['messageprovider:contentupdate'] = 'Actualizaciones de contenido de Skilland disponibles para sus actividades';
$string['missing_lessons_warning'] = 'Estas lecciones ya no están disponibles en SkilLand. Seguirán formando parte de esta actividad hasta que las elimine.';
$string['modulename']   = 'Contenido Skilland';
$string['modulename_help'] = 'Vincula un tema de SkilLand y sus lecciones como una actividad de Moodle, provisionada como contenido SCORM que registra el progreso del alumnado.';
$string['modulenameplural'] = 'Contenidos Skilland';
$string['new_content_available'] = 'Nuevo contenido disponible';
$string['no_courses_available'] = 'No hay cursos disponibles';
$string['no_lessons_configured'] = 'Aún no se han seleccionado lecciones para este tema. Edite la configuración de la actividad para agregar lecciones.';
$string['no_lessons_found'] = 'No se encontraron lecciones para este tema.';
$string['no_next_lesson'] = 'No hay siguiente lección';
$string['no_previous_lesson'] = 'No hay lección anterior';
$string['no_topics_available'] = 'No hay temas disponibles';
$string['not_started'] = 'No iniciado';
$string['open_new_course_in_skilland'] = 'Abrir el nuevo curso en SkilLand';
$string['pluginadministration'] = 'Administración de contenido Skilland';
$string['pluginname']   = 'Contenido Skilland';
$string['privacy:metadata:skilland'] = 'Para iniciar la sesión de un usuario en SkilLand Studio (SSO), listar los cursos de SkilLand de un docente y crear un curso de SkilLand desde Moodle (lo que crea una cuenta de SkilLand para ese correo electrónico), el plugin envía datos personales a la plataforma SkilLand de la organización. Los datos ya enviados a SkilLand los gestiona el administrador de SkilLand de la organización.';
$string['privacy:metadata:skilland:courseaccess'] = 'Los cursos de Moodle en los que está matriculado el usuario y que están vinculados a un curso de SkilLand, enviados al iniciar sesión en SkilLand Studio.';
$string['privacy:metadata:skilland:email'] = 'La dirección de correo electrónico del usuario, enviada al iniciar sesión en SkilLand Studio, al listar sus cursos de SkilLand y al crear un curso de SkilLand.';
$string['privacy:metadata:skilland:fullname'] = 'El nombre completo del usuario, enviado al iniciar sesión en SkilLand Studio.';
$string['privacy:metadata:skilland:role'] = 'El rol de SkilLand que se asigna al usuario, enviado al iniciar sesión en SkilLand Studio.';
$string['privacy:metadata:skilland:userid'] = 'El ID de usuario de Moodle, enviado al iniciar sesión en SkilLand Studio para que SkilLand reconozca la misma cuenta de Moodle en cada inicio de sesión.';
$string['privacy:metadata:skilland_progress'] = 'El mejor estado y la mejor puntuación que cada alumno ha alcanzado en cada lección de una actividad Skilland.';
$string['privacy:metadata:skilland_progress:lessonid'] = 'El ID de la lección.';
$string['privacy:metadata:skilland_progress:score'] = 'La puntuación más alta que el alumno ha alcanzado en la lección.';
$string['privacy:metadata:skilland_progress:status'] = 'El mejor estado que el alumno ha alcanzado en la lección.';
$string['privacy:metadata:skilland_progress:timemodified'] = 'La fecha en que se actualizó el progreso por última vez.';
$string['privacy:metadata:skilland_progress:userid'] = 'El ID del alumno.';
$string['privacy:path:progress'] = 'Progreso de las lecciones';
$string['provision_content'] = 'Preparar contenido';
$string['provision_content_desc'] = 'Descargar y crear la lección SCORM desde Skilland.';
$string['provision_failed'] = 'Error al preparar el contenido: {$a}';
$string['provision_success'] = '¡Contenido preparado correctamente!';
$string['provision_topic'] = 'Preparar contenido del tema';
$string['provision_topic_desc'] = 'Descargar y crear el paquete SCORM que contiene todas las lecciones de este tema.';
$string['provisioning'] = 'Preparando contenido...';
$string['provisioning_elapsed'] = 'Preparando contenido… {$a}';
$string['provisioning_timeout_message'] = 'Esto está tardando más de lo esperado. Sigue ejecutándose en segundo plano: puedes actualizar esta página en unos minutos para comprobarlo.';
$string['provisioning_wait_hint'] = 'Esto puede tardar varios minutos en temas grandes. Mantén esta página abierta.';
$string['ready_to_start'] = 'Listo';
$string['remove_from_activity'] = 'Eliminar de la actividad';
$string['retry'] = 'Reintentar';
$string['score'] = 'Puntuación';
$string['scorm_download_complete'] = 'Descarga completa';
$string['scorm_downloading'] = 'Descargando contenido de la lección...';
$string['scorm_missing_reprovision'] = 'El paquete de contenido de esta actividad se ha eliminado. Vuelva a prepararlo para que las lecciones se puedan reproducir.';
$string['scorm_not_ready'] = 'Esta lección aún no está lista para reproducir. Por favor, espere a que el contenido esté preparado.';
$string['select_all'] = 'Seleccionar todo';
$string['select_skilland_course'] = 'Seleccionar un curso de Skilland...';
$string['select_topic'] = 'Seleccionar un tema';
$string['select_topic_first'] = 'Seleccione un tema primero para ver las lecciones.';
$string['set_skilland_course_id'] = 'Establecer ID de Curso de Skilland en configuración del curso';
$string['settings_apikey'] = 'Clave API';
$string['settings_apikey_desc'] = 'Ingrese su clave API de Skilland aquí.';
$string['settings_devmode'] = 'Registro de depuración detallado';
$string['settings_devmode_desc'] = 'Registra los detalles de las peticiones con nivel DEBUG_DEVELOPER. No lo active nunca en un sitio de producción.';
$string['settings_frontend_url'] = 'URL del Frontend';
$string['settings_frontend_url_desc'] = 'La URL de la aplicación frontend de Skilland. Se usa para las redirecciones SSO y como base de la API REST de SkilLand (paquetes SCORM de los temas), autenticada con la clave API. Si no está configurada, se usará la URL del endpoint GraphQL sin /graphql.';
$string['settings_graphql_endpoint'] = 'Endpoint de GraphQL';
$string['settings_graphql_endpoint_desc'] = 'La URL del endpoint heredado de la API GraphQL de Skilland. Los paquetes SCORM de los temas se obtienen ahora de la API REST bajo la URL del Frontend; este endpoint solo se usa como alternativa cuando esa API responde 401, 403 o 404 o no se puede alcanzar. Déjalo vacío para desactivar la alternativa.';
$string['settings_orgid'] = 'ID de Organización';
$string['settings_orgid_desc'] = 'Ingrese su ID de Organización de Skilland aquí.';
$string['settings_package_hosts'] = 'Hosts de paquetes SCORM';
$string['settings_package_hosts_desc'] = 'Hosts separados por comas desde los que se pueden descargar paquetes SCORM, además de los hosts de la URL del Frontend y del endpoint GraphQL. "*.example.com" solo coincide con subdominios de example.com.';
$string['settings_package_max_mb'] = 'Tamaño máximo del paquete SCORM (MB)';
$string['settings_package_max_mb_desc'] = 'Las descargas que superen este tamaño se cancelan.';
$string['settings_signingkeys'] = 'Claves de firma de paquetes SCORM';
$string['settings_signingkeys_desc'] = 'SkilLand firma cada paquete SCORM que envía, y este sitio importa un paquete solo cuando su firma Ed25519 se verifica, para el tema solicitado, con una clave de confianza: las claves incluidas en el plugin más las que figuran aquí, una <code>idclave:clavepublicabase64</code> por línea. Un paquete se ejecuta en el reproductor SCORM de Moodle como contenido de este sitio, llama a la API SCORM y lee y escribe el registro SCORM de cada estudiante, así que añada solo una clave pública que SkilLand haya publicado, por ejemplo mientras rota sus claves. Los paquetes sin firma se rechazan siempre.';
$string['settings_sso_secret'] = 'Secreto Compartido SSO';
$string['settings_sso_secret_desc'] = 'El secreto SSO de Moodle de su organización. Cópielo desde SkilLand › Ajustes › Integraciones › Moodle: SkilLand deriva un secreto para cada organización y solo acepta los tokens de inicio de sesión firmados con el que corresponde al ID de Organización de este sitio. Debe tener al menos 32 bytes.';
$string['skilland:accessstudio'] = 'Abrir SkilLand Studio y vincular cursos';
$string['skilland:addinstance'] = 'Añadir una nueva actividad de contenido Skilland';
$string['skilland:provision'] = 'Preparar y actualizar el contenido de SkilLand en una actividad';
$string['skilland:view'] = 'Ver contenido Skilland';
$string['skilland_course_id'] = 'Curso de Skilland';
$string['skilland_course_id_help'] = 'El Curso de Skilland para este curso de Moodle. Este valor se establece en la configuración del curso y se aplica a todas las actividades de Skilland en el curso de Moodle.';
$string['skilland_course_id_not_set'] = 'El ID de Curso de Skilland no está establecido para este curso.';
$string['skilland_course_id_required'] = 'El ID de Curso de Skilland debe estar establecido para este curso. {$a}';
$string['skilland_course_id_required_message'] = 'Antes de crear una actividad de Skilland, primero debe establecer el ID de Curso de Skilland en la configuración del curso.';
$string['sso_continue'] = 'Continuar a SkilLand';
$string['sso_redirecting'] = 'Iniciando sesión en SkilLand…';
$string['task_sync_content'] = 'Sincronizar contenido de Skilland para actividades con actualización automática';
$string['toggle_fullscreen'] = 'Alternar pantalla completa';
$string['topic_change_confirm'] = 'Cambiar el tema reemplaza el contenido de esta actividad.';
$string['topic_change_confirm_students'] = 'Cambiar el tema reemplaza el contenido de esta actividad. {$a} estudiante(s) tienen progreso que se eliminará permanentemente.';
$string['topic_change_confirm_title'] = '¿Cambiar el tema?';
$string['topic_changed_reprovision_failed'] = 'Se ha cambiado el tema, pero no se ha podido generar su contenido. Abre la actividad y usa Preparar contenido del tema para crearlo.';
$string['topic_no_longer_available'] = '(ya no disponible en SkilLand)';
$string['topic_no_longer_available_warning'] = 'El tema guardado para esta actividad ya no está disponible en SkilLand. Se conserva su configuración; seleccione otro tema para reemplazarlo.';
$string['topicid'] = 'Tema';
$string['topicid_help'] = 'Seleccione el tema dentro del curso de Skilland que representa esta actividad.';
$string['update_available'] = 'Actualización disponible';
$string['update_available_desc'] = 'El contenido ha sido actualizado en Skilland. Haga clic en el botón para actualizar el paquete SCORM.';
$string['update_confirm_message'] = 'Esto descargará el contenido más reciente de Skilland y reemplazará el paquete SCORM actual.';
$string['update_confirm_message_students'] = 'Esto descargará el contenido más reciente de Skilland y reemplazará el paquete SCORM actual. {$a} estudiante(s) tienen progreso en este tema que se eliminará permanentemente y no podrá recuperarse.';
$string['update_confirm_title'] = '¿Actualizar contenido?';
$string['update_error'] = 'Error al actualizar el contenido';
$string['update_from_skilland'] = 'Actualizar desde Skilland';
$string['update_notification_body'] = 'El contenido de Skilland de "{$a->activity}" en {$a->course} ha cambiado. No se ha sustituido nada: abra la actividad y pulse "Actualizar desde Skilland" para aplicarlo. Aplicar la actualización elimina los intentos y calificaciones de los estudiantes en esta actividad.

{$a->url}';
$string['update_notification_small'] = 'Hay contenido nuevo de Skilland disponible para "{$a->activity}".';
$string['update_notification_subject'] = 'Actualización de contenido disponible: {$a->activity}';
$string['update_success'] = 'Contenido actualizado exitosamente desde Skilland.';
$string['updated'] = 'Actualizado';
$string['updated_on'] = 'Actualizado {$a}';
$string['updating'] = 'Actualizando...';
