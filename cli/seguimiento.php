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


    $mintime = strtotime("2025-03-12");
    $maxtime = strtotime("2025-03-27");
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


// Obtener la fecha de creación del grupo
/*$groupCreationDate = $DB->get_field('groups', 'timecreated', ['id' => $groupId]);

if (!$groupCreationDate) {
    echo "Error: No se encontró la fecha de creación del grupo '$groupName'.\n";
} else {
    $formattedGroupCreationDate = date('d-m-Y', $groupCreationDate);
    echo "El grupo '$groupName' fue empezó el: $formattedGroupCreationDate\n";
}*/

// Obtener los usuarios del grupo que sean estudiantes y tengan inscripción activa en el curso
$sql = "SELECT u.id, u.firstname, u.lastname, u.username
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

// Función para calcular el porcentaje de finalización
function calcularPorcentajeFinalizacion($userId, $courseId) {
    global $DB;
    
    // Obtener las actividades obligatorias (completitud habilitada)
    $sql = "SELECT cm.id, m.name as module_name, cm.instance
            FROM {course_modules} cm
            JOIN {modules} m ON m.id = cm.module
            JOIN {grade_items} gi ON gi.iteminstance = cm.instance AND gi.itemmodule = m.name
            WHERE cm.course = :courseid
            AND cm.completion = 2"; // Solo actividades con completitud habilitada

    $activities = $DB->get_records_sql($sql, ['courseid' => $courseId]);

    if (empty($activities)) {
        return 0;
    }

    $totalActivities = count($activities);
    $completedActivities = 0;

    // Mostrar el nombre de todas las actividades
    /*echo "Actividades obligatorias del curso:\n";
    foreach ($activities as $activity) {
        echo "- {$activity->module_name} (ID: {$activity->id})\n";
    }*/

    // Comprobar el estado de completitud de cada actividad
    foreach ($activities as $activity) {
        // Verificar el estado de completitud de la actividad para el usuario
        $completionStatus = $DB->get_record('course_modules_completion', [
            'userid' => $userId, 
            'coursemoduleid' => $activity->id
        ]);

        // Si la actividad está completada (1 o 2), sumar
        if ($completionStatus && in_array($completionStatus->completionstate, [1, 2])) {
            $completedActivities++;
        }
    }

    return ($totalActivities > 0) ? round(($completedActivities / $totalActivities) * 100, 2) : 0;
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
    return date($fechaInicio);
}



// Evaluar solo a los estudiantes activos
foreach ($groupUsers as $userTo) {
    if (!$userTo) {
        echo "Error: Usuario destino inválido.\n";

        continue;
    }

    try {
        // Obtener el porcentaje de finalización
        $completionPercentage = calcularPorcentajeFinalizacion($userTo->id, $courseId);

        $fechaInicioUsuario = obtenerFechaInicioUsuario($userTo->id);

        $tiempo = calcularTiempoDedicado($userTo, $course, $mintime, $maxtime, $limit);

        echo "El usuario {$userTo->firstname} {$userTo->lastname} empezó el curso {$courseId} el {$fechaInicioUsuario}, tiene un progreso en el curso del {$completionPercentage}% y ha dedicado {$tiempo} al curso.\n";
        
    } catch (Exception $e) {
        echo "Error al evaluar a {$userTo->firstname} {$userTo->lastname}: " . $e->getMessage() . "\n";
    }
}

echo "Proceso completado. Se ha evaluado a los usuarios del grupo '$groupName' del curso {$courseId}.\n";
