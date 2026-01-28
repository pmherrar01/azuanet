<?php
/**
 * TEST DE CONEXIÓN A LA BASE DE DATOS
 * Usa este archivo para verificar que la conexión funciona
 */

// Activar errores para debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>🔍 Test de Conexión - Calculadora Revolving</h2>";
echo "<hr>";

// =============================================
// CONFIGURACIÓN - MODIFICA ESTOS VALORES
// =============================================
$db_host = 'localhost';
$db_name = 'calculadora_revolving';
$db_user = 'root';
$db_pass = '';  // En XAMPP por defecto está vacío

echo "<h3>📋 Configuración actual:</h3>";
echo "<ul>";
echo "<li><strong>Host:</strong> $db_host</li>";
echo "<li><strong>Base de datos:</strong> $db_name</li>";
echo "<li><strong>Usuario:</strong> $db_user</li>";
echo "<li><strong>Contraseña:</strong> " . (empty($db_pass) ? '(vacía)' : '(configurada)') . "</li>";
echo "</ul>";
echo "<hr>";

// =============================================
// TEST 1: ¿PHP tiene soporte MySQL/PDO?
// =============================================
echo "<h3>✅ Test 1: Soporte MySQL/PDO</h3>";
if (extension_loaded('pdo') && extension_loaded('pdo_mysql')) {
    echo "✅ <span style='color:green'>PHP tiene soporte para PDO y MySQL</span><br>";
} else {
    echo "❌ <span style='color:red'>ERROR: PHP no tiene PDO o PDO_MySQL instalado</span><br>";
    echo "<strong>Solución:</strong> Activa las extensiones en php.ini<br>";
    die();
}
echo "<hr>";

// =============================================
// TEST 2: ¿Podemos conectar al servidor MySQL?
// =============================================
echo "<h3>🔌 Test 2: Conexión al servidor MySQL</h3>";
try {
    $conn = new PDO(
        "mysql:host=$db_host",
        $db_user,
        $db_pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    echo "✅ <span style='color:green'>Conexión al servidor MySQL exitosa</span><br>";
    
    // Mostrar versión de MySQL
    $version = $conn->query('SELECT VERSION()')->fetchColumn();
    echo "📌 <strong>Versión de MySQL/MariaDB:</strong> $version<br>";
    
} catch(PDOException $e) {
    echo "❌ <span style='color:red'>ERROR de conexión al servidor MySQL</span><br>";
    echo "<strong>Mensaje de error:</strong> " . $e->getMessage() . "<br>";
    echo "<br><strong>Posibles soluciones:</strong><br>";
    echo "<ul>";
    echo "<li>Verifica que MySQL esté corriendo (en XAMPP, arranca MySQL)</li>";
    echo "<li>Verifica usuario y contraseña</li>";
    echo "<li>Si la contraseña no está vacía, ponla en la variable \$db_pass</li>";
    echo "</ul>";
    die();
}
echo "<hr>";

// =============================================
// TEST 3: ¿Existe la base de datos?
// =============================================
echo "<h3>🗄️ Test 3: Verificar base de datos</h3>";
try {
    $conn = new PDO(
        "mysql:host=$db_host;dbname=$db_name",
        $db_user,
        $db_pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    echo "✅ <span style='color:green'>Base de datos '$db_name' existe y se puede acceder</span><br>";
} catch(PDOException $e) {
    echo "❌ <span style='color:red'>ERROR: No se puede acceder a la base de datos '$db_name'</span><br>";
    echo "<strong>Mensaje de error:</strong> " . $e->getMessage() . "<br>";
    echo "<br><strong>Solución:</strong><br>";
    echo "<ul>";
    echo "<li>La base de datos no existe. Debes importar el archivo <code>estructura_base_datos_simple.sql</code></li>";
    echo "<li>Ve a phpMyAdmin: <a href='http://localhost/phpmyadmin' target='_blank'>http://localhost/phpmyadmin</a></li>";
    echo "<li>Importa el archivo SQL desde la pestaña 'Importar'</li>";
    echo "</ul>";
    die();
}
echo "<hr>";

// =============================================
// TEST 4: ¿Existen las tablas necesarias?
// =============================================
echo "<h3>📊 Test 4: Verificar tablas</h3>";
try {
    $stmt = $conn->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (count($tables) > 0) {
        echo "✅ <span style='color:green'>Tablas encontradas:</span><br>";
        echo "<ul>";
        foreach ($tables as $table) {
            echo "<li>$table</li>";
            
            // Contar registros en cada tabla
            $count = $conn->query("SELECT COUNT(*) FROM $table")->fetchColumn();
            echo " → <em>$count registro(s)</em>";
        }
        echo "</ul>";
        
        // Verificar que existe la tabla leads_revolving
        if (in_array('leads_revolving', $tables)) {
            echo "✅ <span style='color:green'>La tabla 'leads_revolving' existe correctamente</span><br>";
        } else {
            echo "⚠️ <span style='color:orange'>ADVERTENCIA: No se encontró la tabla 'leads_revolving'</span><br>";
            echo "Asegúrate de haber importado el archivo SQL correctamente<br>";
        }
        
    } else {
        echo "⚠️ <span style='color:orange'>La base de datos existe pero no tiene tablas</span><br>";
        echo "<strong>Solución:</strong> Importa el archivo <code>estructura_base_datos_simple.sql</code><br>";
    }
} catch(PDOException $e) {
    echo "❌ <span style='color:red'>ERROR al consultar tablas</span><br>";
    echo "<strong>Mensaje de error:</strong> " . $e->getMessage() . "<br>";
}
echo "<hr>";

// =============================================
// TEST 5: Probar INSERT (simulado)
// =============================================
echo "<h3>💾 Test 5: Capacidad de escritura</h3>";
try {
    // Intentar un INSERT de prueba
    $stmt = $conn->prepare("
        INSERT INTO leads_revolving 
        (nombre, telefono, email, entidad_financiera, deuda, cuota_mensual, tae, meses_pagando, cantidad_recuperable, ip_cliente)
        VALUES 
        (:nombre, :telefono, :email, :entidad, :deuda, :cuota, :tae, :tiempo, :recuperable, :ip)
    ");
    
    $test_data = [
        ':nombre' => 'TEST - No borrar',
        ':telefono' => '000000000',
        ':email' => 'test@test.com',
        ':entidad' => 'TEST',
        ':deuda' => 1000,
        ':cuota' => 100,
        ':tae' => 20,
        ':tiempo' => 12,
        ':recuperable' => 500,
        ':ip' => $_SERVER['REMOTE_ADDR']
    ];
    
    $stmt->execute($test_data);
    $test_id = $conn->lastInsertId();
    
    echo "✅ <span style='color:green'>INSERT de prueba exitoso (ID: $test_id)</span><br>";
    echo "Se creó un registro de prueba en la base de datos<br>";
    
    // Eliminar el registro de prueba
    $conn->exec("DELETE FROM leads_revolving WHERE id = $test_id");
    echo "🗑️ Registro de prueba eliminado<br>";
    
} catch(PDOException $e) {
    echo "❌ <span style='color:red'>ERROR al intentar INSERT</span><br>";
    echo "<strong>Mensaje de error:</strong> " . $e->getMessage() . "<br>";
}
echo "<hr>";

// =============================================
// RESUMEN FINAL
// =============================================
echo "<h2>✅ RESUMEN</h2>";
echo "<div style='background:#d4edda; padding:15px; border-radius:5px; border-left:4px solid #28a745'>";
echo "<strong style='color:#155724'>🎉 ¡TODO ESTÁ CORRECTO!</strong><br>";
echo "La base de datos está configurada correctamente y lista para usar.<br>";
echo "Puedes probar el formulario ahora.";
echo "</div>";

echo "<br><br>";
echo "<a href='calculadora-con-formulario.html' style='background:#e68737; color:white; padding:10px 20px; text-decoration:none; border-radius:5px;'>→ Ir a la calculadora</a>";
?>

<style>
body {
    font-family: Arial, sans-serif;
    max-width: 800px;
    margin: 50px auto;
    padding: 20px;
    background: #f5f5f5;
}
h2 {
    color: #333;
    border-bottom: 3px solid #e68737;
    padding-bottom: 10px;
}
h3 {
    color: #555;
    margin-top: 20px;
}
ul {
    background: white;
    padding: 15px 30px;
    border-radius: 5px;
}
code {
    background: #f0f0f0;
    padding: 2px 6px;
    border-radius: 3px;
    color: #c7254e;
}
hr {
    border: none;
    border-top: 1px solid #ddd;
    margin: 20px 0;
}
</style>