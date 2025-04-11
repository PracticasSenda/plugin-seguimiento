<?php
// Asegurar que Moodle se cargue correctamente
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php'); // CLI de Moodle
require_once(__DIR__ . '/../../../blocks/dedication/dedication_lib.php');

// Asegurar que se ejecuta desde la línea de comandos
if (php_sapi_name() !== 'cli') {
    echo "Este script solo puede ejecutarse desde la línea de comandos.\n";
    exit(1);
}

fwrite(STDOUT, "Ingrese el ID del curso: ");
$courseId = trim(fgets(STDIN));

fwrite(STDOUT, "Ingrese el nombre del grupo: ");
$groupName = trim(fgets(STDIN));


$limit = 9000;


// Obtener el ID del grupo por nombre
$groupId = $DB->get_field('groups', 'id', ['courseid' => $courseId, 'name' => $groupName]);
if (!$groupId) {
    echo "Error: No se encontró el grupo '$groupName' en el curso con ID $courseId.\n";
    exit(1);
}

// Obtener el ID del rol de estudiante
$studentRoleId = $DB->get_field('role', 'id', ['shortname' => 'student']);
if (!$studentRoleId) {
    echo "Error: No se encontró el rol de estudiante en Moodle.\n";
    exit(1);
}

$course = get_course($courseId);
if (!$course) {
    echo "Error: No se pudo obtener el curso con ID $courseId.\n";
    exit(1);
}

echo "Evaluando el grupo {$groupName} del curso: {$course->fullname}\n";


// Obtener los usuarios del grupo que sean estudiantes y tengan inscripción activa en el curso
$sql = "SELECT u.id, u.firstname, u.lastname, u.username, u.lastaccess
FROM {user} u
JOIN {groups_members} gm ON u.id = gm.userid
JOIN {role_assignments} ra ON u.id = ra.userid
JOIN {context} ctx ON ra.contextid = ctx.id AND ctx.contextlevel = 50 AND ctx.instanceid = :courseid1
JOIN {user_enrolments} ue ON u.id = ue.userid
JOIN {enrol} e ON ue.enrolid = e.id AND e.courseid = :courseid2
WHERE gm.groupid = :groupid
AND ra.roleid = :roleid
AND ue.status = 0 AND e.status = 0"; 

$groupUsers = $DB->get_records_sql($sql, [
    'courseid1' => $courseId,
    'courseid2' => $courseId,
    'groupid' => $groupId,
    'roleid' => $studentRoleId
]);


if (empty($groupUsers)) {
    echo "No hay estudiantes activos en el grupo '$groupName'.\n";
    exit(1);
}

echo "Usuarios en el grupo '$groupName' con rol de estudiante y estado activo:\n";
foreach ($groupUsers as $user) {
    echo "- {$user->firstname} {$user->lastname} (ID: {$user->id}, Username: {$user->username})\n";
}


// Obtener la calificación de un estudiante en una actividad
function obtenerCalificacionActividad($userId, $itemmodule, $iteminstance) {
    global $DB;

    $gradeItem = $DB->get_record('grade_items', [
        'itemmodule' => $itemmodule,
        'iteminstance' => $iteminstance
    ]);

    if (!$gradeItem) {
        return "Sin completar";
    }

    $grade = $DB->get_record('grade_grades', [
        'itemid' => $gradeItem->id,
        'userid' => $userId
    ]);

    return ($grade && $grade->finalgrade !== null) ? round($grade->finalgrade, 2) : "Sin completar";
}


function obtenerDuracionCurso($courseId) {
    global $DB;

    $sql = "SELECT d.value 
            FROM {customfield_data} d
            JOIN {customfield_field} f ON d.fieldid = f.id
            WHERE d.instanceid = :courseid AND f.shortname = 'horas_totales'";

    $value = $DB->get_field_sql($sql, ['courseid' => $courseId]);

    return $value ? floatval($value) : null;
}


// Función para calcular el porcentaje de finalización
function tablaActividadesNotas($userId, $courseId, $grades) {
    global $DB;

    // Obtener actividades con completitud y calificación asociada
    $sql = "SELECT cm.id, cm.instance, m.name AS module_type, gi.itemname
            FROM {course_modules} cm
            JOIN {modules} m ON m.id = cm.module
            JOIN {grade_items} gi ON gi.iteminstance = cm.instance AND gi.itemmodule = m.name
            WHERE cm.course = :courseid
              AND cm.completion = 2
              AND gi.itemtype = 'mod'";

    $activities = $DB->get_records_sql($sql, ['courseid' => $courseId]);

    if (empty($activities)) {
        return 0;
    }

    $totalActivities = count($activities);
    $completedActivities = 0;

    $gradesTable = "";

    foreach ($activities as $activity) {
        $moduleTable = $activity->module_type;
        $instanceId = $activity->instance;

        // Obtener el nombre real del módulo desde su tabla correspondiente
        $moduleRecord = $DB->get_record($moduleTable, ['id' => $instanceId], 'name');
        $moduleName = $moduleRecord ? $moduleRecord->name : "[Sin nombre]";

        $gradesTable = $gradesTable . $moduleName;

        // Verificar estado de completitud
        $completionStatus = $DB->get_record('course_modules_completion', [
            'userid' => $userId,
            'coursemoduleid' => $activity->id
        ]);

        if($grades==true){
        // Obtener calificación
        $calificacion = obtenerCalificacionActividad($userId, $moduleTable, $instanceId);
        if ($calificacion !== null) {
            $gradesTable = $gradesTable . ": " . $calificacion . "\n";
        }
        }else{
            $gradesTable = $gradesTable . ".\n";
        }
        
        // Contar como completado si tiene estado válido
        if ($completionStatus && in_array($completionStatus->completionstate, [1, 2])) {
            $completedActivities++;
        }
    }

    return $gradesTable;
}



// Función para calcular el tiempo total dedicado por un usuario en un curso
function calcularTiempoDedicado($user, $course, $mintime, $maxtime, $limit) {
    global $DB;

    // Instanciar la clase block_dedication_manager
    $dm = new block_dedication_manager($course, $mintime, $maxtime, $limit);

    // Obtener el tiempo total dedicado en segundos
    $timeInSeconds = $dm->get_user_dedication($user, true);

    return block_dedication_utils::format_dedication($timeInSeconds);
}




function obtenerFechaInicioUsuario($userId) {
    global $DB;

    $sql = "SELECT uid.data 
            FROM {user_info_data} uid
            JOIN {user_info_field} uif ON uid.fieldid = uif.id
            WHERE uif.shortname = 'inicio' 
            AND uid.userid = :userid";

    $fechaInicio = $DB->get_field_sql($sql, ['userid' => $userId]);

    if (!$fechaInicio) {
        return "No especificada";
    }
    return $fechaInicio;
}

function obtenerFechaFinUsuario($userId) {
    global $DB;

    $sql = "SELECT uid.data 
            FROM {user_info_data} uid
            JOIN {user_info_field} uif ON uid.fieldid = uif.id
            WHERE uif.shortname = 'fin' 
            AND uid.userid = :userid";

    $fechaFin = $DB->get_field_sql($sql, ['userid' => $userId]);

    if (!$fechaFin) {
        return "No especificada";
    }
    return $fechaFin;
}



// Evaluar solo a los estudiantes activos
foreach ($groupUsers as $userTo) {
    if (!$userTo) {
        echo "Error: Usuario destino inválido.\n";

        continue;
    }

    try {
        // Obtener el porcentaje de finalización
        $gradeTable = tablaActividadesNotas($userTo->id, $courseId, $grades = true);

        $activityTable = tablaActividadesNotas($userTo->id, $courseId, $grades = false);

        $fechaInicioUsuario = DateTime::createFromFormat('d/m/Y', obtenerFechaInicioUsuario($userTo->id))->format('Y-m-d');
        $fechaFinUsuario = DateTime::createFromFormat('d/m/Y', obtenerFechaFinUsuario($userTo->id))->format('Y-m-d');

        $fechaInseg = strtotime($fechaInicioUsuario);
        $fechaFinseg = strtotime($fechaFinUsuario);

        $tiempo = calcularTiempoDedicado($userTo, $course, $fechaInseg, $fechaFinseg, $limit);

        // Obtener fecha de último acceso legible
        $ultimoAcceso = $userTo->lastaccess ? userdate($userTo->lastaccess) : 'Sin registros recientes';

        $duracionCurso = obtenerDuracionCurso($courseId); // en horas
        $dedicacionRecomendada = $duracionCurso ? round($duracionCurso * 0.4, 2) : null;

        // Usar la API de OpenAI para generar el mensaje de evaluación
        $evaluationMessage = generarMensajeEvaluacion($userTo->firstname, $userTo->lastname, $gradeTable, $course->fullname, $fechaInicioUsuario, $fechaFinUsuario, $tiempo, $groupName, $activityTable, $ultimoAcceso, $duracionCurso, $dedicacionRecomendada);

        echo "Resultado para {$userTo->firstname} {$userTo->lastname}: $evaluationMessage\n";

        echo "----------------------------------------------\n";
        
    } catch (Exception $e) {
        echo "Error al evaluar a {$userTo->firstname} {$userTo->lastname}: " . $e->getMessage() . "\n";
    }
}

echo "Proceso completado. Se ha evaluado a los usuarios del grupo '$groupName' del curso {$courseId}.\n";


function obtenerPlanificacionDidacticaPorGrupo($groupName) {
    $filePath = __DIR__ . '/planificaciones.json';

    // Verificar si el archivo existe
    if (!file_exists($filePath)) {
        return null; // Si no existe el archivo, no hay planificación guardada
    }

    // Leer el archivo JSON
    $jsonData = file_get_contents($filePath);
    $planificaciones = json_decode($jsonData, true);

    // Verificar si la planificación existe para el grupo
    if (isset($planificaciones[$groupName])) {
        return $planificaciones[$groupName]; // Devolver la planificación si existe
    }

    return null; // Si no se encuentra planificación, retornar null
}

function guardarPlanificacionDidacticaPorGrupo($groupName, $plan) {
    $filePath = __DIR__ . '/planificaciones.json';

    // Leer el archivo JSON si existe
    if (file_exists($filePath)) {
        $jsonData = file_get_contents($filePath);
        $planificaciones = json_decode($jsonData, true);
    } else {
        $planificaciones = [];
    }

    // Guardar la nueva planificación para el grupo
    $planificaciones[$groupName] = $plan;

    // Guardar el JSON actualizado
    file_put_contents($filePath, json_encode($planificaciones, JSON_PRETTY_PRINT));
}

function generarMensajeEvaluacion($firstname, $lastname, $gradeTable, $courseName, $fechaIn, $fechaFin, $tiempoded, $groupName, $activityTable, $ultimoAcceso, $duracionCurso, $dedicacionRecomendada) {
    $apiKey = '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';
    $url = 'https://openrouter.ai/api/v1/chat/completions';

    $plan = obtenerPlanificacionDidacticaPorGrupo($groupName);

    if ($plan === null) {
        $plan = generarPlanificacionDidacticaPorGrupo($groupName, $courseName, $fechaIn, $fechaFin, $activityTable);
        guardarPlanificacionDidacticaPorGrupo($groupName, $plan);
    }

    $data = [
        'model' => 'google/gemini-2.0-flash-thinking-exp-1219:free',
        'messages' => [
            ['role' => 'system', 'content' => "Eres un profesor del curso $courseName y te llamas Consuelo que genera mensajes de seguimiento para sus estudiantes. La planificación didáctica del grupo es la siguiente: $plan. Evalúa a los estudiantes en base a esta planificación, siendo objetivo y crítico."],
            ['role' => 'user', 'content' => "Genera un mensaje breve y sencillo para $firstname $lastname, que tiene este progreso en las actividades del curso: {$gradeTable}. El curso empezó el $fechaIn y finaliza el $fechaFin, lleva dedicado al curso $tiempoded y para aprobar se necesita un minimo de tiempo dedicado de $dedicacionRecomendada. El último acceso del alumno fue el $ultimoAcceso. Valora su progreso y da indicaciones para guiar al alumno para completar el curso."]
        ]
    ];

    $options = [
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\n" .
                         "Authorization: Bearer $apiKey\r\n",
            'content' => json_encode($data)
        ]
    ];

    $context = stream_context_create($options);
    $response = file_get_contents($url, false, $context);
    $result = json_decode($response, true);

    return $result['choices'][0]['message']['content'] ?? 'No se pudo generar el mensaje.';
}


function generarPlanificacionDidacticaPorGrupo($groupName, $courseName, $fechaIn, $fechaFin) {
    // Aquí puedes llamar a la API para generar una planificación didáctica si no existe
    $apiKey = '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'; // gratis
    $url = 'https://openrouter.ai/api/v1/chat/completions';

    $data = [
        'model' => 'google/gemini-2.0-flash-thinking-exp-1219:free',
        'messages' => [
            ['role' => 'system', 'content' => "Eres un profesor del curso $courseName. Crea una planificación didáctica para el grupo $groupName."],
            ['role' => 'user', 'content' => "Por favor, crea una planificación didáctica detallada para el grupo $groupName del curso $courseName. Este grupo empieza el $fechaIn y termina el $fechaFin y estas son las actividades que hay en el curso: $activityTable. Quiero que hagas una planificación sencilla y breve con la que evaluar alumnos. incluye los nombres de las actividades y las fechas que deberían seguir para ir bien."]
        ]
    ];

    $options = [
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\n" .
                         "Authorization: Bearer $apiKey\r\n",
            'content' => json_encode($data)
        ]
    ];

    $context = stream_context_create($options);
    $response = file_get_contents($url, false, $context);
    $result = json_decode($response, true);

    return $result['choices'][0]['message']['content'] ?? 'No se pudo generar la planificación.';
}







