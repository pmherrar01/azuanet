<?php
// process_lead.php
session_start();

// 1. CONEXIÓN Y CONFIGURACIÓN (Debe tener $smtp_host, $name_from, obtener_huella_digital(), etc.)
require_once 'conexion.php';

// 2. CARGA DE PHPMAILER
if (file_exists('PHPMailer-master/src/PHPMailer.php')) {
    require 'PHPMailer-master/src/Exception.php';
    require 'PHPMailer-master/src/PHPMailer.php';
    require 'PHPMailer-master/src/SMTP.php';
} else {
    die("Error: No se encuentra PHPMailer en la carpeta 'PHPMailer-master/'.");
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // --- A. CAPTURA DE DATOS TÉCNICOS INVISIBLES ---
    $info_tecnica = obtener_huella_digital(); 
    $ip = $_SERVER['REMOTE_ADDR'];
    $screen_res = isset($_COOKIE['device_res']) ? $conn->real_escape_string($_COOKIE['device_res']) : 'No detectada';

    // --- B. RECOGER DATOS DEL FORMULARIO ---
    $name = $conn->real_escape_string($_POST['leadName']);
    $email = $conn->real_escape_string($_POST['leadEmail']);
    $phone = isset($_POST['leadPhone']) ? $conn->real_escape_string($_POST['leadPhone']) : '';
    $prodName = $conn->real_escape_string($_POST['prodName']);
    $desc = isset($_POST['prodDesc']) ? $conn->real_escape_string($_POST['prodDesc']) : '';
    
    // Checkboxes (Garantizamos 0 o 1)
    $privacyAccepted = isset($_POST['privacyAccepted']) ? 1 : 0;
    $newsletterOptIn = isset($_POST['newsletterOptIn']) ? 1 : 0;
	
	// --- INICIO CÓDIGO ZAPIER (CORREGIDO Y CON LOGS) ---

    // 1. URL de Zapier
    $webhook_url = 'https://hooks.zapier.com/hooks/catch/26148918/uly4rae/';

    // 2. Preparamos los datos
    $data = $_POST; 

    // 3. Inicializamos cURL
    $ch = curl_init($webhook_url);
    $payload = json_encode($data);

    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    // --- LA LÍNEA MÁGICA: Evita errores de certificado SSL ---
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    // 4. Ejecutamos y capturamos errores
    $result = curl_exec($ch);
    $error_msg = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    curl_close($ch);

    // 5. (OPCIONAL) Guardar un log para ver qué pasó
    // Esto creará un archivo 'log_zapier.txt' en tu servidor para que veas si funcionó
    $log_mensaje = date("Y-m-d H:i:s") . " - Código HTTP: " . $http_code . " - Resultado: " . $result . " - Error: " . $error_msg . "\n";
    file_put_contents('log_zapier.txt', $log_mensaje, FILE_APPEND);

    // --- FIN CÓDIGO ZAPIER ---
	
    
    // Datos numéricos para cálculos
    $inv = floatval($_POST['investment']); // Inversión en publicidad
    $cpc = floatval($_POST['cpc']);
    $price = floatval($_POST['price']);
    $marginPercent = floatval($_POST['margin']); // Porcentaje de margen
    $conv = floatval($_POST['conversion']);
    $targetRevenue = floatval($_POST['targetRevenue']);
    $managementFee = floatval($_POST['managementFee']); // Coste de gestión mensual
    $businessType = $conn->real_escape_string($_POST['businessType']);

    // --- C. CÁLCULOS ---
    // Calcular el coste unitario a partir del margen (para compatibilidad con BD existente)
    $marginPerUnit = $price * ($marginPercent / 100);
    $cost = $price - $marginPerUnit; // Coste unitario derivado
    
    // Inversión total = publicidad + gestión
    $totalInvestment = $inv + $managementFee;
    
    $visitors = ($cpc > 0) ? ($inv / $cpc) : 0;
    $sales = ($visitors * $conv) / 100;
    $profit = ($sales * $marginPerUnit) - $totalInvestment; // Beneficio neto considerando gestión
    $roi = ($totalInvestment > 0) ? ($profit / $totalInvestment) * 100 : 0;

    // --- D. TOKEN ÚNICO ---
    $token = bin2hex(random_bytes(32));

    // --- E. GUARDAR EN BASE DE DATOS ---
    $sql = "INSERT INTO leads (
        token, contact_name, email, phone, product_name, product_description,
        investment, cpc, product_price, product_cost, conversion_rate,
        margin_percent, target_revenue, management_fee, business_type,
        roi, net_profit, privacy_accepted, newsletter_opt_in,
        ip_address, user_agent, os, browser, device_type, screen_resolution, language
    ) VALUES (
        '$token', '$name', '$email', '$phone', '$prodName', '$desc',
        '$inv', '$cpc', '$price', '$cost', '$conv',
        '$marginPercent', '$targetRevenue', '$managementFee', '$businessType',
        '$roi', '$profit', $privacyAccepted, $newsletterOptIn,
        '$ip', '{$info_tecnica['ua']}', '{$info_tecnica['os']}', '{$info_tecnica['browser']}', 
        '{$info_tecnica['device']}', '$screen_res', '{$info_tecnica['lang']}'
    )";

    if($conn->query($sql)) {
        
        // --- F. PREPARAR EMAIL ---
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
        $baseUrl = $protocol . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']);
        $reportLink = $baseUrl . "/view_report.php?token=" . $token;
        $pixelUrl = $baseUrl . "/track.php?t=" . $token;
        
        $subject = "Tu Informe: $prodName (Descarga Disponible)";
        
        $messageHTML = "
        <html>
        <body style='font-family: Arial, sans-serif; color: #333; line-height: 1.6;'>
            <div style='max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #eee; border-radius: 10px;'>
                <h2 style='color: #4f46e5;'>Hola $name,</h2>
                <p>Tu análisis para <strong>$prodName</strong> está listo.</p>
                <div style='text-align: center; margin: 30px 0;'>
                    <a href='$reportLink' style='background-color: #4f46e5; color: white; padding: 15px 25px; text-decoration: none; border-radius: 6px; font-weight: bold; display: inline-block;'>
                        DESCARGAR INFORME PDF
                    </a>
                </div>
                <p style='font-size: 12px; color: #666;'>Enlace alternativo: <a href='$reportLink' style='color: #4f46e5;'>$reportLink</a></p>
                <hr style='border: 0; border-top: 1px solid #eee; margin: 20px 0;'>
                <p style='font-size: 12px; color: #999;'>Mensaje automático de $name_from.</p>
            </div>
            <img src='$pixelUrl' width='1' height='1' style='display:none;' alt='' />
        </body>
        </html>";

        $enviado = false;

        // INTENTO 1: SMTP
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Timeout = 5; 
            $mail->SMTPDebug = 0;
            $mail->Host = $smtp_host;
            $mail->SMTPAuth = true;
            $mail->Username = $smtp_user;
            $mail->Password = $smtp_pass;
            $mail->Port = $smtp_port;
            $mail->SMTPSecure = ($smtp_secure === 'ssl') ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;

            // Forzar nombre del remitente
            $remitente_nombre = !empty($name_from) ? $name_from : 'Azuanet Tools';

            $mail->setFrom($email_from, $remitente_nombre);
            $mail->addAddress($email, $name);
            $mail->isHTML(true);
            $mail->CharSet = 'UTF-8';
            $mail->Subject = $subject;
            $mail->Body = $messageHTML;
            $mail->AltBody = "Hola $name, descarga tu informe aquí: $reportLink";

            if($mail->send()) {
                $enviado = true;
            }
        } catch (Exception $e) {
            error_log("Error SMTP PHPMailer: " . $mail->ErrorInfo);
        }

        // INTENTO 2: FALLBACK mail()
        if (!$enviado) {
            $remitente_nombre = !empty($name_from) ? $name_from : 'Azuanet Tools';
            
            $headers = "MIME-Version: 1.0" . "\r\n";
            $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
            // Nombre codificado para evitar que aparezca el email o "info"
            $headers .= "From: " . '=?UTF-8?B?'.base64_encode($remitente_nombre).'?=' . " <$email_from>" . "\r\n";
            $headers .= "Reply-To: $email_from" . "\r\n";
            $headers .= "X-Mailer: PHP/" . phpversion();
            
            @mail($email, $subject, $messageHTML, $headers);
        }

        $_SESSION['sent_email'] = $email;
        header("Location: success.php");
        exit();

    } else {
        echo "Error BD: " . $conn->error;
    }
} else {
    header("Location: index.php");
    exit();
}