<?php
/**
 * Cron Job - Envío de Leads a API Externa (Por Lotes)
 * Adaptado a la estructura de BD del cliente
 */

// ================================================================
// CONFIGURACIÓN
// ================================================================

// Token de seguridad (CAMBIAR EN PRODUCCIÓN)
define('CRON_SECRET', '12345_ClaveSegura_CAMBIAR');

// Configuración de BD
define('DB_HOST', 'localhost');
define('DB_NAME', 'calculadora_revolving');
define('DB_USER', 'root');
define('DB_PASS', '');

// Configuración de la API del cliente
define('API_URL', 'https://api.cliente.com/recibir-lead');
define('API_ENABLED', false); // Cambiar a true cuando tengas la URL real
define('API_TIMEOUT', 10);

// ================================================================
// SEGURIDAD: VERIFICAR TOKEN
// ================================================================

$token = $_GET['token'] ?? '';

if ($token !== CRON_SECRET) {
    http_response_code(403);
    die('❌ Acceso denegado. Token inválido.');
}

// ================================================================
// FUNCIONES
// ================================================================

function conectarDB() {
    try {
        return new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]
        );
    } catch(PDOException $e) {
        die("❌ Error de conexión: " . $e->getMessage());
    }
}

function dividirNombreApellidos($nombreCompleto) {
    $partes = explode(' ', trim($nombreCompleto), 2);
    return [
        'nombre' => $partes[0] ?? '',
        'apellidos' => $partes[1] ?? ''
    ];
}

function limpiarTelefono($telefono) {
    // Eliminar todo excepto dígitos
    $tel = preg_replace('/[^\d]/', '', $telefono);
    
    // Si empieza con +34, quitarlo
    if (substr($tel, 0, 2) === '34') {
        $tel = substr($tel, 2);
    }
    
    // Si empieza con 0034, quitarlo
    if (substr($tel, 0, 4) === '0034') {
        $tel = substr($tel, 4);
    }
    
    // Tomar solo los primeros 9 dígitos
    return substr($tel, 0, 9);
}

function enviarAAPI($lead) {
    if (!API_ENABLED) {
        // Modo test: simular envío exitoso
        return [
            'success' => true,
            'message' => 'Modo test (API desactivada)',
            'http_code' => 200
        ];
    }
    
    try {
        // Dividir nombre completo en nombre y apellidos
        $nombreApellidos = dividirNombreApellidos($lead['nombre']);
        
        // Limpiar teléfono (solo 9 dígitos)
        $telefonoLimpio = limpiarTelefono($lead['telefono']);
        
        // Preparar payload adaptado a la estructura del cliente
        $payload = json_encode([
            // DATOS PERSONALES (para tabla solicitudes del cliente)
            'origen' => 'revolving',
            'nombre' => $nombreApellidos['nombre'],
            'apellidos' => $nombreApellidos['apellidos'],
            'email' => $lead['email'],
            'telefono' => $telefonoLimpio,
            
            // DATOS FINANCIEROS (calculadora)
            'entidad_financiera' => $lead['entidad_financiera'],
            'deuda' => floatval($lead['deuda']),
            'cuota_mensual' => floatval($lead['cuota_mensual']),
            'tae' => floatval($lead['tae']),
            'meses_pagando' => intval($lead['meses_pagando']),
            'cantidad_recuperable' => floatval($lead['cantidad_recuperable']),
            'tiene_seguro' => (bool)$lead['tiene_seguro'],
            'tiene_impagos' => (bool)$lead['tiene_impagos'],
            'desea_asesor' => (bool)$lead['desea_asesor'],
            
            // METADATOS
            'id_lead_origen' => $lead['id'],
            'fecha_registro' => $lead['fecha_registro'],
            'fuente' => 'calculadora_web',
            'ip_cliente' => $lead['ip_cliente'] ?? null,
            
            // CAMPOS ADICIONALES (no disponibles en calculadora)
            'dni' => null,
            'direccion' => null,
            'codigo_postal' => null,
            'id_provincia' => null,
            'id_municipio' => null
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        
        // Inicializar cURL
        $ch = curl_init(API_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json; charset=utf-8',
                'Content-Length: ' . strlen($payload),
                'User-Agent: CalculadoraRevolving/1.0'
            ],
            CURLOPT_TIMEOUT => API_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true
        ]);
        
        // Ejecutar petición
        $respuesta = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        // Verificar respuesta
        if ($httpCode >= 200 && $httpCode < 300) {
            return [
                'success' => true,
                'message' => 'Enviado correctamente',
                'http_code' => $httpCode,
                'response' => $respuesta
            ];
        } else {
            return [
                'success' => false,
                'message' => "Error HTTP $httpCode" . ($error ? ": $error" : ""),
                'http_code' => $httpCode,
                'response' => $respuesta
            ];
        }
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Excepción: ' . $e->getMessage(),
            'http_code' => 0
        ];
    }
}

// ================================================================
// INICIO DEL PROCESO
// ================================================================

$esManual = isset($_GET['manual']) && $_GET['manual'] === '1';

if ($esManual) {
    echo "<!DOCTYPE html>
<html lang='es'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Envío de Leads a API</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: system-ui, -apple-system, sans-serif;
            background: #f3f4f6;
            padding: 20px;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        h1 {
            color: #1f2937;
            border-bottom: 3px solid #e68737;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        .log {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 12px 16px;
            margin: 10px 0;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            line-height: 1.5;
        }
        .success { background: #d1fae5; border-color: #6ee7b7; color: #065f46; }
        .error { background: #fee2e2; border-color: #fca5a5; color: #991b1b; }
        .info { background: #dbeafe; border-color: #93c5fd; color: #1e40af; }
        .warning { background: #fef3c7; border-color: #fcd34d; color: #92400e; }
        .summary {
            background: linear-gradient(135deg, #e68737 0%, #d67627 100%);
            color: white;
            padding: 25px;
            border-radius: 12px;
            margin-top: 25px;
            text-align: center;
            font-size: 18px;
            font-weight: bold;
            box-shadow: 0 4px 12px rgba(230, 135, 55, 0.3);
        }
        .btn-back {
            display: inline-block;
            margin-top: 20px;
            padding: 12px 24px;
            background: #6b7280;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s;
        }
        .btn-back:hover { background: #4b5563; transform: translateY(-2px); }
        .progress { font-weight: bold; color: #e68737; }
        hr { border: none; border-top: 1px solid #e5e7eb; margin: 20px 0; }
    </style>
</head>
<body>
    <div class='container'>
        <h1>🚀 Envío de Leads a API del Cliente</h1>
        <p style='color: #6b7280; margin-bottom: 20px;'>
            <strong>Iniciado:</strong> " . date('d/m/Y H:i:s') . "
        </p>
        <hr>";
}

// Conectar a BD
$conn = conectarDB();

// Obtener leads pendientes de envío
$stmt = $conn->query("
    SELECT * 
    FROM leads_revolving 
    WHERE enviado_api = 0 
    ORDER BY id ASC
");

$leadsPendientes = $stmt->fetchAll();
$totalPendientes = count($leadsPendientes);

if ($esManual) {
    echo "<div class='log info'>📋 <strong>Leads pendientes:</strong> $totalPendientes</div>";
    if (!API_ENABLED) {
        echo "<div class='log warning'>⚠️ <strong>MODO TEST:</strong> La API está desactivada. Los leads se marcarán como enviados pero no se enviarán realmente.</div>";
    }
}

if ($totalPendientes === 0) {
    if ($esManual) {
        echo "<div class='log success'>✅ No hay leads pendientes. Todos están sincronizados.</div>";
        echo "<a href='admin.php' class='btn-back'>← Volver al Panel</a>";
        echo "</div></body></html>";
    } else {
        echo "✅ No hay leads pendientes\n";
    }
    exit;
}

// Contadores
$exitosos = 0;
$fallidos = 0;
$errores = [];

// Procesar cada lead
foreach ($leadsPendientes as $index => $lead) {
    $leadId = $lead['id'];
    $nombreLead = $lead['nombre'];
    $numero = $index + 1;
    
    if ($esManual) {
        echo "<div class='log'><span class='progress'>[$numero/$totalPendientes]</span> Procesando Lead #$leadId - $nombreLead...</div>";
        flush();
    }
    
    // Enviar a la API
    $resultado = enviarAAPI($lead);
    
    if ($resultado['success']) {
        // Actualizar en BD como enviado
        $stmtUpdate = $conn->prepare("
            UPDATE leads_revolving 
            SET enviado_api = 1, 
                fecha_envio_api = NOW() 
            WHERE id = :id
        ");
        $stmtUpdate->execute([':id' => $leadId]);
        
        $exitosos++;
        
        if ($esManual) {
            $statusMsg = API_ENABLED ? "enviado (HTTP {$resultado['http_code']})" : "marcado como enviado (modo test)";
            echo "<div class='log success'>✅ Lead #$leadId $statusMsg</div>";
        }
        
        error_log("Cron API - Lead #$leadId procesado exitosamente");
        
    } else {
        $fallidos++;
        $mensajeError = $resultado['message'];
        $errores[] = "Lead #$leadId ($nombreLead): $mensajeError";
        
        if ($esManual) {
            echo "<div class='log error'>❌ Lead #$leadId falló: $mensajeError</div>";
        }
        
        error_log("Cron API - Lead #$leadId falló: $mensajeError");
    }
    
    // Pausa entre peticiones
    usleep(250000); // 0.25 segundos
}

// ================================================================
// REPORTE FINAL
// ================================================================

if ($esManual) {
    echo "<hr>";
    echo "<div class='summary'>";
    echo "📊 RESUMEN DEL PROCESO<br><br>";
    echo "Total procesados: <strong>$totalPendientes</strong><br>";
    echo "Exitosos: <strong style='color: #d1fae5;'>$exitosos</strong><br>";
    echo "Fallidos: <strong style='color: #fee2e2;'>$fallidos</strong><br><br>";
    echo "<small style='font-size: 14px; font-weight: normal; opacity: 0.9;'>";
    echo date('d/m/Y H:i:s');
    echo "</small>";
    echo "</div>";
    
    if (count($errores) > 0) {
        echo "<h3 style='color: #991b1b; margin-top: 30px;'>⚠️ Errores Detectados:</h3>";
        echo "<div class='log error'>";
        foreach ($errores as $error) {
            echo "• " . htmlspecialchars($error) . "<br>";
        }
        echo "</div>";
        echo "<div class='log info'><strong>ℹ️ Nota:</strong> Los leads con error NO se marcaron como enviados. Se reintentarán en la próxima ejecución.</div>";
    }
    
    echo "<div style='text-align: center;'>";
    echo "<a href='admin.php' class='btn-back'>← Volver al Panel de Administración</a>";
    echo "</div>";
    echo "</div></body></html>";
    
} else {
    // Salida para cron job (texto plano)
    echo "========================================\n";
    echo "CRON JOB - Envío de Leads a API\n";
    echo "Fecha: " . date('Y-m-d H:i:s') . "\n";
    echo "========================================\n";
    echo "Total procesados: $totalPendientes\n";
    echo "Exitosos: $exitosos\n";
    echo "Fallidos: $fallidos\n";
    echo "========================================\n";
    
    if (count($errores) > 0) {
        echo "\nERRORES:\n";
        foreach ($errores as $error) {
            echo "• $error\n";
        }
    }
}

// Log general
error_log("Cron API completado - Procesados: $totalPendientes, Exitosos: $exitosos, Fallidos: $fallidos");
?>