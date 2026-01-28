<?php
/**
 * Guardar Lead - Calculadora Revolving
 * Versión 3.0 - Sin webhook en tiempo real
 * Los leads se envían por lotes mediante cron_enviar.php
 */

// Configuración de headers para JSON
header('Content-Type: application/json; charset=utf-8');

// Configuración de la base de datos
define('DB_HOST', 'localhost');
define('DB_NAME', 'calculadora_revolving');
define('DB_USER', 'root');
define('DB_PASS', '');

// Configuración de seguridad
define('RATE_LIMIT_MAX_REQUESTS', 3);
define('RATE_LIMIT_TIME_WINDOW', 3600);

// ================================================================
// FUNCIONES DE UTILIDAD
// ================================================================

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

function limpiarDato($dato) {
    return htmlspecialchars(strip_tags(trim($dato)), ENT_QUOTES, 'UTF-8');
}

function validarEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

function validarTelefono($telefono) {
    $tel = preg_replace('/[^\d+]/', '', $telefono);
    return preg_match('/^(\+34)?[6-9]\d{8}$/', $tel);
}

// ================================================================
// SEGURIDAD: HONEYPOT
// ================================================================
function verificarHoneypot($valorHoneypot) {
    if (!empty($valorHoneypot)) {
        error_log("Bot detectado - Honeypot rellenado. IP: " . $_SERVER['REMOTE_ADDR']);
        return false;
    }
    return true;
}

// ================================================================
// SEGURIDAD: RATE LIMITING
// ================================================================
function verificarRateLimit($conn, $ip) {
    try {
        $conn->exec("
            CREATE TABLE IF NOT EXISTS rate_limiting (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ip_address VARCHAR(45) NOT NULL,
                timestamp INT NOT NULL,
                INDEX idx_ip (ip_address),
                INDEX idx_timestamp (timestamp)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        
        $ahora = time();
        $ventanaTiempo = $ahora - RATE_LIMIT_TIME_WINDOW;
        
        $conn->exec("DELETE FROM rate_limiting WHERE timestamp < " . ($ahora - 86400));
        
        $stmt = $conn->prepare("
            SELECT COUNT(*) as total 
            FROM rate_limiting 
            WHERE ip_address = :ip 
            AND timestamp > :ventana
        ");
        $stmt->execute([
            ':ip' => $ip,
            ':ventana' => $ventanaTiempo
        ]);
        
        $resultado = $stmt->fetch();
        $totalPeticiones = $resultado['total'];
        
        if ($totalPeticiones >= RATE_LIMIT_MAX_REQUESTS) {
            error_log("Rate limit excedido. IP: $ip - Peticiones: $totalPeticiones");
            return false;
        }
        
        $stmt = $conn->prepare("INSERT INTO rate_limiting (ip_address, timestamp) VALUES (:ip, :timestamp)");
        $stmt->execute([
            ':ip' => $ip,
            ':timestamp' => $ahora
        ]);
        
        return true;
        
    } catch(PDOException $e) {
        error_log("Error en rate limiting: " . $e->getMessage());
        return true;
    }
}

// ================================================================
// RESPUESTA POR DEFECTO
// ================================================================
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
    // ================================================================
    // 1. VERIFICACIÓN HONEYPOT
    // ================================================================
    $honeypot = $_POST['website'] ?? '';
    
    if (!verificarHoneypot($honeypot)) {
        $response['message'] = 'Solicitud no válida';
        echo json_encode($response);
        exit;
    }
    
    // ================================================================
    // 2. VERIFICACIÓN RATE LIMITING
    // ================================================================
    $conn = conectarDB();
    
    if (!$conn) {
        $response['message'] = 'Error de conexión con la base de datos';
        echo json_encode($response);
        exit;
    }
    
    $ip_cliente = $_SERVER['REMOTE_ADDR'];
    
    if (!verificarRateLimit($conn, $ip_cliente)) {
        $response['message'] = 'Has enviado demasiadas solicitudes. Por favor, espera unos minutos e inténtalo de nuevo.';
        echo json_encode($response);
        exit;
    }
    
    // ================================================================
    // 3. VALIDAR Y LIMPIAR DATOS
    // ================================================================
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
    
    if (!empty($errores)) {
        $response['message'] = implode('. ', $errores);
        echo json_encode($response);
        exit;
    }
    
    // ================================================================
    // 4. GUARDAR EN BASE DE DATOS LOCAL
    // ================================================================
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
        enviado_api,
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
        0,
        NOW()
    )";
    
    $stmt = $conn->prepare($sql);
    
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
        ':ip' => $ip_cliente,
        ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Desconocido'
    ]);
    
    if ($resultado) {
        $lead_id = $conn->lastInsertId();
        
        error_log("Lead #$lead_id guardado exitosamente. Pendiente de envío a API.");
        
        // ================================================================
        // 5. RESPUESTA EXITOSA
        // ================================================================
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

echo json_encode($response);
?>