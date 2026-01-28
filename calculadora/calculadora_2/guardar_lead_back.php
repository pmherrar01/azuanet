<?php
/**
 * Guardar Lead - Calculadora Revolving
 * Procesa y guarda los datos del formulario en MySQL
 */

// Configuración de headers para JSON
header('Content-Type: application/json; charset=utf-8');

// Configuración de la base de datos
define('DB_HOST', 'localhost');
define('DB_NAME', 'calculadora_revolving');
define('DB_USER', 'root');           
define('DB_PASS', '');      

// Función para conectar a la base de datos
function conectarDB() {
    try {
        $conn = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]
        );
        return $conn;
    } catch(PDOException $e) {
        error_log("Error de conexión: " . $e->getMessage());
        return null;
    }
}

// Función para limpiar y validar datos
function limpiarDato($dato) {
    return htmlspecialchars(strip_tags(trim($dato)), ENT_QUOTES, 'UTF-8');
}

// Función para validar email
function validarEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

// Función para validar teléfono (formato español)
function validarTelefono($telefono) {
    // Eliminar espacios y caracteres no numéricos excepto + al inicio
    $tel = preg_replace('/[^\d+]/', '', $telefono);
    // Validar formato básico (mínimo 9 dígitos)
    return preg_match('/^(\+34)?[6-9]\d{8}$/', $tel);
}

// Respuesta por defecto
$response = [
    'success' => false,
    'message' => 'Error desconocido'
];

// Verificar que sea una petición POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $response['message'] = 'Método no permitido';
    echo json_encode($response);
    exit;
}

try {
    // Recoger y limpiar datos del formulario
    $nombre = limpiarDato($_POST['nombre'] ?? '');
    $telefono = limpiarDato($_POST['telefono'] ?? '');
    $email = limpiarDato($_POST['email'] ?? '');
    $entidad = limpiarDato($_POST['entidad'] ?? '');
    $asesor = isset($_POST['asesor']) ? 1 : 0;
    
    // Datos de la calculadora
    $deuda = floatval($_POST['deuda'] ?? 0);
    $cuota = floatval($_POST['cuota'] ?? 0);
    $tae = floatval($_POST['tae'] ?? 0);
    $tiempo_pagando = intval($_POST['tiempo_pagando'] ?? 0);
    $recuperable = floatval($_POST['recuperable'] ?? 0);
    $tiene_seguro = intval($_POST['tiene_seguro'] ?? 0);
    $hay_impagos = intval($_POST['hay_impagos'] ?? 0);
    
    // Validaciones
    $errores = [];
    
    if (empty($nombre)) {
        $errores[] = 'El nombre es obligatorio';
    }
    
    if (empty($telefono)) {
        $errores[] = 'El teléfono es obligatorio';
    } elseif (!validarTelefono($telefono)) {
        $errores[] = 'El teléfono no tiene un formato válido';
    }
    
    if (empty($email)) {
        $errores[] = 'El email es obligatorio';
    } elseif (!validarEmail($email)) {
        $errores[] = 'El email no tiene un formato válido';
    }
    
    if (empty($entidad)) {
        $errores[] = 'La entidad financiera es obligatoria';
    }
    
    if (!isset($_POST['privacidad'])) {
        $errores[] = 'Debes aceptar la política de privacidad';
    }
    
    // Si hay errores, devolverlos
    if (!empty($errores)) {
        $response['message'] = implode('. ', $errores);
        echo json_encode($response);
        exit;
    }
    
    // Conectar a la base de datos
    $conn = conectarDB();
    
    if (!$conn) {
        $response['message'] = 'Error de conexión con la base de datos';
        echo json_encode($response);
        exit;
    }
    
    // Preparar la consulta SQL
    $sql = "INSERT INTO leads_revolving (
        nombre,
        telefono,
        email,
        entidad_financiera,
        desea_asesor,
        deuda,
        cuota_mensual,
        tae,
        meses_pagando,
        cantidad_recuperable,
        tiene_seguro,
        tiene_impagos,
        ip_cliente,
        user_agent,
        fecha_registro
    ) VALUES (
        :nombre,
        :telefono,
        :email,
        :entidad,
        :asesor,
        :deuda,
        :cuota,
        :tae,
        :tiempo,
        :recuperable,
        :seguro,
        :impagos,
        :ip,
        :user_agent,
        NOW()
    )";
    
    $stmt = $conn->prepare($sql);
    
    // Ejecutar la consulta
    $resultado = $stmt->execute([
        ':nombre' => $nombre,
        ':telefono' => $telefono,
        ':email' => $email,
        ':entidad' => $entidad,
        ':asesor' => $asesor,
        ':deuda' => $deuda,
        ':cuota' => $cuota,
        ':tae' => $tae,
        ':tiempo' => $tiempo_pagando,
        ':recuperable' => $recuperable,
        ':seguro' => $tiene_seguro,
        ':impagos' => $hay_impagos,
        ':ip' => $_SERVER['REMOTE_ADDR'],
        ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Desconocido'
    ]);
    
    if ($resultado) {
        $lead_id = $conn->lastInsertId();
        
        // OPCIONAL: Enviar email de notificación
        // enviarEmailNotificacion($nombre, $email, $telefono, $recuperable);
        
        // OPCIONAL: Integrar con CRM externo
        // enviarACRM($lead_id, $nombre, $email, $telefono, $deuda, $recuperable);
        
        $response['success'] = true;
        $response['message'] = 'Solicitud registrada correctamente';
        $response['lead_id'] = $lead_id;
    } else {
        $response['message'] = 'Error al guardar los datos';
    }
    
} catch (PDOException $e) {
    error_log("Error PDO: " . $e->getMessage());
    $response['message'] = 'Error al procesar la solicitud';
} catch (Exception $e) {
    error_log("Error general: " . $e->getMessage());
    $response['message'] = 'Error al procesar la solicitud';
}

// Devolver respuesta
echo json_encode($response);

// ========================================
// FUNCIONES OPCIONALES
// ========================================

/**
 * Enviar email de notificación al equipo
 */
function enviarEmailNotificacion($nombre, $email, $telefono, $recuperable) {
    $para = 'tu-email@empresa.com';
    $asunto = 'Nuevo Lead - Calculadora Revolving';
    
    $mensaje = "
    <html>
    <head>
        <title>Nuevo Lead</title>
    </head>
    <body>
        <h2>Nuevo lead registrado</h2>
        <p><strong>Nombre:</strong> $nombre</p>
        <p><strong>Email:</strong> $email</p>
        <p><strong>Teléfono:</strong> $telefono</p>
        <p><strong>Puede recuperar:</strong> " . number_format($recuperable, 2) . " €</p>
        <p><strong>Fecha:</strong> " . date('d/m/Y H:i:s') . "</p>
    </body>
    </html>
    ";
    
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= "From: noreply@tudominio.com" . "\r\n";
    
    mail($para, $asunto, $mensaje, $headers);
}

/**
 * Integrar con CRM externo (ejemplo genérico)
 */
function enviarACRM($lead_id, $nombre, $email, $telefono, $deuda, $recuperable) {
    // Ejemplo de integración con API externa
    $url_crm = 'https://tu-crm.com/api/leads';
    
    $data = [
        'lead_id' => $lead_id,
        'nombre' => $nombre,
        'email' => $email,
        'telefono' => $telefono,
        'deuda' => $deuda,
        'recuperable' => $recuperable,
        'origen' => 'Calculadora Web'
    ];
    
    $options = [
        'http' => [
            'header' => "Content-type: application/json\r\n" .
                       "Authorization: Bearer TU_API_KEY\r\n",
            'method' => 'POST',
            'content' => json_encode($data)
        ]
    ];
    
    $context = stream_context_create($options);
    $result = file_get_contents($url_crm, false, $context);
    
    return $result !== FALSE;
}
?>