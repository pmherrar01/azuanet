<?php
/**
 * Panel de Administración - Gestión de Leads
 */

session_start();

// Verificar autenticación
define('SESSION_NAME', 'admin_revolving_logged_in');

if (!isset($_SESSION[SESSION_NAME]) || $_SESSION[SESSION_NAME] !== true) {
    header('Location: login.php');
    exit;
}

// Configuración de BD
define('DB_HOST', 'localhost');
define('DB_NAME', 'calculadora_revolving');
define('DB_USER', 'root');
define('DB_PASS', '');

// Función de conexión
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
        die("Error de conexión: " . $e->getMessage());
    }
}

// Procesar logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: login.php');
    exit;
}

// Procesar exportación CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $conn = conectarDB();
    
    $stmt = $conn->query("
        SELECT 
            id,
            nombre,
            telefono,
            email,
            entidad_financiera,
            deuda,
            cuota_mensual,
            tae,
            meses_pagando,
            cantidad_recuperable,
            tiene_seguro,
            tiene_impagos,
            desea_asesor,
            estado,
            enviado_api,
            fecha_envio_api,
            fecha_registro
        FROM leads_revolving 
        ORDER BY id DESC
    ");
    
    $leads = $stmt->fetchAll();
    
    // Configurar headers para descarga CSV
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=leads_' . date('Y-m-d_His') . '.csv');
    
    // Crear archivo CSV
    $output = fopen('php://output', 'w');
    
    // BOM para UTF-8 (para que Excel lo reconozca)
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Cabeceras
    fputcsv($output, [
        'ID',
        'Nombre',
        'Teléfono',
        'Email',
        'Entidad Financiera',
        'Deuda',
        'Cuota Mensual',
        'TAE',
        'Meses Pagando',
        'Recuperable',
        'Seguro',
        'Impagos',
        'Desea Asesor',
        'Estado',
        'Enviado API',
        'Fecha Envío API',
        'Fecha Registro'
    ], ';'); // Usar ; como delimitador para Excel español
    
    // Datos
    foreach ($leads as $lead) {
        fputcsv($output, [
            $lead['id'],
            $lead['nombre'],
            $lead['telefono'],
            $lead['email'],
            $lead['entidad_financiera'],
            number_format($lead['deuda'], 2, ',', '.'),
            number_format($lead['cuota_mensual'], 2, ',', '.'),
            $lead['tae'] . '%',
            $lead['meses_pagando'],
            number_format($lead['cantidad_recuperable'], 2, ',', '.'),
            $lead['tiene_seguro'] ? 'Sí' : 'No',
            $lead['tiene_impagos'] ? 'Sí' : 'No',
            $lead['desea_asesor'] ? 'Sí' : 'No',
            ucfirst($lead['estado']),
            $lead['enviado_api'] ? 'Sí' : 'No',
            $lead['fecha_envio_api'] ?? '',
            $lead['fecha_registro']
        ], ';');
    }
    
    fclose($output);
    exit;
}

// Obtener filtros
$filtroEstado = $_GET['estado'] ?? '';
$filtroFecha = $_GET['fecha'] ?? '';
$busqueda = $_GET['busqueda'] ?? '';

// Construir query con filtros
$conn = conectarDB();

$sql = "SELECT * FROM leads_revolving WHERE 1=1";
$params = [];

if ($filtroEstado) {
    $sql .= " AND estado = :estado";
    $params[':estado'] = $filtroEstado;
}

if ($filtroFecha) {
    $sql .= " AND DATE(fecha_registro) = :fecha";
    $params[':fecha'] = $filtroFecha;
}

if ($busqueda) {
    $sql .= " AND (nombre LIKE :busqueda OR email LIKE :busqueda OR telefono LIKE :busqueda)";
    $params[':busqueda'] = "%$busqueda%";
}

$sql .= " ORDER BY id DESC LIMIT 100";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$leads = $stmt->fetchAll();

// Obtener estadísticas
$stats = $conn->query("
    SELECT 
        COUNT(*) as total,
        SUM(cantidad_recuperable) as total_recuperable,
        COUNT(CASE WHEN DATE(fecha_registro) = CURDATE() THEN 1 END) as hoy,
        COUNT(CASE WHEN estado = 'nuevo' THEN 1 END) as nuevos
    FROM leads_revolving
")->fetch();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel de Administración - Leads</title>
    <style>
        :root {
            --primary: #e68737;
            --primary-dark: #d67627;
            --bg-gray: #f3f4f6;
            --text-main: #1f2937;
            --border-color: #e5e7eb;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
            background: var(--bg-gray);
            color: var(--text-main);
        }
        
        /* HEADER */
        .header {
            background: white;
            border-bottom: 1px solid var(--border-color);
            padding: 16px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .header-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .logo-admin {
            width: 40px;
            height: 40px;
            background: var(--primary);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
            font-weight: bold;
        }
        
        .header-title {
            font-size: 20px;
            font-weight: 700;
        }
        
        .btn-logout {
            background: #ef4444;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.2s;
        }
        
        .btn-logout:hover {
            background: #dc2626;
        }
        
        /* CONTAINER */
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 24px;
        }
        
        /* STATS CARDS */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .stat-label {
            color: #6b7280;
            font-size: 14px;
            margin-bottom: 8px;
        }
        
        .stat-value {
            font-size: 32px;
            font-weight: 800;
            color: var(--primary);
        }
        
        /* FILTERS */
        .filters-bar {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 16px;
        }
        
        .filter-group label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 6px;
        }
        
        .filter-group input,
        .filter-group select {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            font-size: 14px;
        }
        
        .filters-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .btn-primary {
            background: var(--primary);
            color: white;
        }
        
        .btn-primary:hover {
            background: var(--primary-dark);
        }
        
        .btn-secondary {
            background: #6b7280;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #4b5563;
        }
        
        .btn-success {
            background: #10b981;
            color: white;
        }
        
        .btn-success:hover {
            background: #059669;
        }
        
        .btn-send-api {
            background: #fff;
            color: #667eea;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .btn-send-api:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.25);
            background: #f0f0f0;
        }
        
        /* TABLE */
        .table-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            overflow: hidden;
        }
        
        .table-header {
            padding: 20px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .table-title {
            font-size: 18px;
            font-weight: 700;
        }
        
        .table-wrapper {
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th {
            background: var(--bg-gray);
            padding: 12px;
            text-align: left;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            color: #6b7280;
            white-space: nowrap;
        }
        
        td {
            padding: 12px;
            border-bottom: 1px solid var(--border-color);
            font-size: 14px;
        }
        
        tr:hover {
            background: #fafafa;
        }
        
        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .badge-nuevo {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .badge-contactado {
            background: #fef3c7;
            color: #92400e;
        }
        
        .badge-proceso {
            background: #e0e7ff;
            color: #3730a3;
        }
        
        .badge-ganado {
            background: #d1fae5;
            color: #065f46;
        }
        
        .no-results {
            text-align: center;
            padding: 40px;
            color: #6b7280;
        }
        
        @media (max-width: 768px) {
            .filters-grid {
                grid-template-columns: 1fr;
            }
            
            table {
                font-size: 12px;
            }
            
            th, td {
                padding: 8px;
            }
        }
    </style>
</head>
<body>
    <!-- HEADER -->
    <div class="header">
        <div class="header-left">
            <div class="logo-admin">📊</div>
            <h1 class="header-title">Panel de Administración</h1>
        </div>
        <a href="?logout=1" class="btn-logout">Cerrar Sesión</a>
    </div>
    
    <div class="container">
        <!-- ESTADÍSTICAS -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total Leads</div>
                <div class="stat-value"><?php echo number_format($stats['total']); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Leads Hoy</div>
                <div class="stat-value"><?php echo number_format($stats['hoy']); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Pendientes</div>
                <div class="stat-value"><?php echo number_format($stats['nuevos']); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total Recuperable</div>
                <div class="stat-value"><?php echo number_format($stats['total_recuperable'], 0); ?>€</div>
            </div>
        </div>
        
        <!-- BOTÓN DE ENVÍO MANUAL A API -->
        <?php
        // Contar leads pendientes de enviar a API
        $pendientesAPI = $conn->query("SELECT COUNT(*) as total FROM leads_revolving WHERE enviado_api = 0")->fetch()['total'];
        ?>
        <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 12px; padding: 20px; margin-bottom: 24px; color: white; box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                <div>
                    <h3 style="margin: 0 0 5px 0; font-size: 18px;">🔄 Sincronización con API Externa</h3>
                    <p style="margin: 0; opacity: 0.9; font-size: 14px;">
                        <?php if ($pendientesAPI > 0): ?>
                            Hay <strong><?php echo $pendientesAPI; ?> lead(s)</strong> pendiente(s) de enviar a la API del cliente.
                        <?php else: ?>
                            ✅ Todos los leads están sincronizados con la API del cliente.
                        <?php endif; ?>
                    </p>
                </div>
                <div>
                    <?php if ($pendientesAPI > 0): ?>
                        <a href="cron_enviar.php?token=12345_ClaveSegura" 
                           class="btn-send-api"
                           onclick="return confirm('¿Enviar <?php echo $pendientesAPI; ?> lead(s) a la API ahora?');">
                            🚀 Enviar Ahora (<?php echo $pendientesAPI; ?>)
                        </a>
                    <?php else: ?>
                        <button class="btn-send-api" style="opacity: 0.6; cursor: not-allowed;" disabled>
                            ✅ Todo Sincronizado
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- FILTROS -->
        <div class="filters-bar">
            <form method="GET" action="">
                <div class="filters-grid">
                    <div class="filter-group">
                        <label>Buscar</label>
                        <input type="text" name="busqueda" placeholder="Nombre, email o teléfono..." value="<?php echo htmlspecialchars($busqueda); ?>">
                    </div>
                    <div class="filter-group">
                        <label>Estado</label>
                        <select name="estado">
                            <option value="">Todos</option>
                            <option value="nuevo" <?php echo $filtroEstado === 'nuevo' ? 'selected' : ''; ?>>Nuevo</option>
                            <option value="contactado" <?php echo $filtroEstado === 'contactado' ? 'selected' : ''; ?>>Contactado</option>
                            <option value="en_proceso" <?php echo $filtroEstado === 'en_proceso' ? 'selected' : ''; ?>>En Proceso</option>
                            <option value="ganado" <?php echo $filtroEstado === 'ganado' ? 'selected' : ''; ?>>Ganado</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Fecha</label>
                        <input type="date" name="fecha" value="<?php echo htmlspecialchars($filtroFecha); ?>">
                    </div>
                </div>
                <div class="filters-actions">
                    <button type="submit" class="btn btn-primary">Filtrar</button>
                    <a href="admin.php" class="btn btn-secondary">Limpiar</a>
                    <a href="?export=csv" class="btn btn-success">📥 Exportar CSV</a>
                </div>
            </form>
        </div>
        
        <!-- TABLA DE LEADS -->
        <div class="table-container">
            <div class="table-header">
                <div class="table-title">Leads Registrados (últimos 100)</div>
            </div>
            
            <div class="table-wrapper">
                <?php if (count($leads) > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Fecha</th>
                            <th>Nombre</th>
                            <th>Teléfono</th>
                            <th>Email</th>
                            <th>Entidad</th>
                            <th>Deuda</th>
                            <th>Recuperable</th>
                            <th>Estado</th>
                            <th>API</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($leads as $lead): ?>
                        <tr>
                            <td><strong>#<?php echo $lead['id']; ?></strong></td>
                            <td><?php echo date('d/m/Y H:i', strtotime($lead['fecha_registro'])); ?></td>
                            <td><?php echo htmlspecialchars($lead['nombre']); ?></td>
                            <td><?php echo htmlspecialchars($lead['telefono']); ?></td>
                            <td><?php echo htmlspecialchars($lead['email']); ?></td>
                            <td><?php echo htmlspecialchars($lead['entidad_financiera']); ?></td>
                            <td><?php echo number_format($lead['deuda'], 2); ?>€</td>
                            <td><strong style="color: var(--primary);"><?php echo number_format($lead['cantidad_recuperable'], 0); ?>€</strong></td>
                            <td>
                                <span class="badge badge-<?php echo $lead['estado']; ?>">
                                    <?php echo ucfirst($lead['estado']); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($lead['enviado_api'] == 1): ?>
                                    <span class="badge" style="background: #d1fae5; color: #065f46;">✅ Enviado</span>
                                <?php else: ?>
                                    <span class="badge" style="background: #fee2e2; color: #991b1b;">⏳ Pendiente</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="no-results">
                    <p>📭 No se encontraron leads con los filtros seleccionados</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>