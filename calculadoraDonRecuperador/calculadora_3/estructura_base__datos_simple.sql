-- ================================================
-- BASE DE DATOS SIMPLIFICADA: CALCULADORA REVOLVING
-- Versión sin procedimientos almacenados
-- Compatible con MySQL 5.7+ y MariaDB 10.x
-- ================================================

-- Crear base de datos
CREATE DATABASE IF NOT EXISTS calculadora_revolving
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE calculadora_revolving;

-- ================================================
-- TABLA PRINCIPAL: leads_revolving
-- ================================================

CREATE TABLE IF NOT EXISTS leads_revolving (
    -- Identificador único
    id INT AUTO_INCREMENT PRIMARY KEY,
    
    -- Datos personales del cliente
    nombre VARCHAR(100) NOT NULL,
    telefono VARCHAR(20) NOT NULL,
    email VARCHAR(150) NOT NULL,
    entidad_financiera VARCHAR(100) NOT NULL,
    desea_asesor TINYINT(1) DEFAULT 0 COMMENT '0=No, 1=Sí',
    
    -- Datos de la calculadora
    deuda DECIMAL(10,2) DEFAULT 0.00 COMMENT 'Deuda pendiente en euros',
    cuota_mensual DECIMAL(10,2) DEFAULT 0.00 COMMENT 'Cuota mensual que paga',
    tae DECIMAL(5,2) DEFAULT 0.00 COMMENT 'TAE (Interés)',
    meses_pagando INT DEFAULT 0 COMMENT 'Tiempo pagando en meses',
    cantidad_recuperable DECIMAL(10,2) DEFAULT 0.00 COMMENT 'Cantidad estimada a recuperar',
    tiene_seguro TINYINT(1) DEFAULT 0 COMMENT '0=No, 1=Sí',
    tiene_impagos TINYINT(1) DEFAULT 0 COMMENT '0=No, 1=Sí',
    
    -- Metadatos y seguimiento
    ip_cliente VARCHAR(45) NULL COMMENT 'IP del cliente',
    user_agent TEXT NULL COMMENT 'Navegador/dispositivo del cliente',
    fecha_registro DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Fecha y hora de registro',
    
    -- Estado del lead
    estado ENUM('nuevo', 'contactado', 'en_proceso', 'ganado', 'perdido', 'cancelado') 
        DEFAULT 'nuevo' COMMENT 'Estado del lead',
    fecha_contacto DATETIME NULL COMMENT 'Fecha del primer contacto',
    fecha_ultima_actualizacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Notas y seguimiento
    notas TEXT NULL COMMENT 'Notas internas sobre el lead',
    asignado_a VARCHAR(100) NULL COMMENT 'Asesor asignado',
    
    -- Índices para mejorar rendimiento
    INDEX idx_email (email),
    INDEX idx_telefono (telefono),
    INDEX idx_estado (estado),
    INDEX idx_fecha_registro (fecha_registro),
    INDEX idx_entidad (entidad_financiera)
    
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Leads capturados desde la calculadora revolving';

-- ================================================
-- TABLA: historial_contactos
-- ================================================

CREATE TABLE IF NOT EXISTS historial_contactos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lead_id INT NOT NULL,
    fecha_contacto DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    tipo_contacto ENUM('llamada', 'email', 'whatsapp', 'sms', 'reunion', 'otro') NOT NULL,
    resultado ENUM('exitoso', 'sin_respuesta', 'rechazado', 'reagendar', 'otro') DEFAULT 'otro',
    notas TEXT NULL,
    realizado_por VARCHAR(100) NULL COMMENT 'Usuario/asesor que realizó el contacto',
    
    FOREIGN KEY (lead_id) REFERENCES leads_revolving(id) ON DELETE CASCADE,
    INDEX idx_lead_id (lead_id),
    INDEX idx_fecha (fecha_contacto)
    
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Historial de contactos con los leads';

-- ================================================
-- VISTA: resumen_leads
-- ================================================

CREATE OR REPLACE VIEW resumen_leads AS
SELECT 
    DATE(fecha_registro) as fecha,
    COUNT(*) as total_leads,
    SUM(CASE WHEN desea_asesor = 1 THEN 1 ELSE 0 END) as con_asesor,
    AVG(deuda) as promedio_deuda,
    AVG(cantidad_recuperable) as promedio_recuperable,
    SUM(cantidad_recuperable) as total_recuperable,
    COUNT(DISTINCT entidad_financiera) as entidades_distintas,
    estado,
    COUNT(*) as leads_por_estado
FROM leads_revolving
GROUP BY DATE(fecha_registro), estado;

-- ================================================
-- VISTA: leads_por_entidad
-- ================================================

CREATE OR REPLACE VIEW leads_por_entidad AS
SELECT 
    entidad_financiera,
    COUNT(*) as total_leads,
    AVG(deuda) as deuda_promedio,
    AVG(cantidad_recuperable) as recuperable_promedio,
    AVG(tae) as tae_promedio
FROM leads_revolving
GROUP BY entidad_financiera
ORDER BY total_leads DESC;

-- ================================================
-- CONSULTAS ÚTILES
-- ================================================

-- Ver todos los leads del día:
-- SELECT * FROM leads_revolving WHERE DATE(fecha_registro) = CURDATE();

-- Ver leads pendientes de contactar:
-- SELECT * FROM leads_revolving WHERE estado = 'nuevo' ORDER BY fecha_registro DESC;

-- Ver estadísticas del mes:
-- SELECT * FROM resumen_leads WHERE fecha >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH);

-- Ver leads por entidad:
-- SELECT * FROM leads_por_entidad;

-- Ver leads con mayor cantidad recuperable:
-- SELECT nombre, telefono, email, cantidad_recuperable 
-- FROM leads_revolving 
-- WHERE cantidad_recuperable > 5000 
-- ORDER BY cantidad_recuperable DESC;

-- Actualizar estado de un lead:
-- UPDATE leads_revolving 
-- SET estado = 'contactado', 
--     notas = CONCAT(IFNULL(notas, ''), '\n[', NOW(), '] Cliente interesado'),
--     asignado_a = 'Juan Pérez',
--     fecha_contacto = NOW()
-- WHERE id = 1;

-- Añadir nota a un lead existente:
-- UPDATE leads_revolving 
-- SET notas = CONCAT(IFNULL(notas, ''), '\n[', NOW(), '] Nueva nota aquí')
-- WHERE id = 1;

-- Ver historial completo de un lead:
-- SELECT l.*, 
--        (SELECT COUNT(*) FROM historial_contactos WHERE lead_id = l.id) as num_contactos
-- FROM leads_revolving l
-- WHERE l.id = 1;

-- ================================================
-- FIN DEL SCRIPT
-- ================================================