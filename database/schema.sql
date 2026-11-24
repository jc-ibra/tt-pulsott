-- PULSOTT · Pulso y Seguimiento Operativo Trantor Technologies - base de datos (MySQL / MariaDB de XAMPP)
-- Se puede ejecutar varias veces: no borra datos existentes.

CREATE DATABASE IF NOT EXISTS pulsott
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE pulsott;

-- Un renglón por proyecto. "datos" guarda el proyecto completo tal como lo maneja la pantalla.
CREATE TABLE IF NOT EXISTS proyectos (
  id             VARCHAR(64)  NOT NULL,
  nombre         VARCHAR(255) NOT NULL DEFAULT '',
  version        INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Sube en cada guardado; evita que dos personas se pisen',
  archivado      TINYINT(1)   NOT NULL DEFAULT 0,
  motivo_archivo VARCHAR(20)  NULL COMMENT 'concluido, cancelado o suspendido',
  color          VARCHAR(20)  NULL,
  num_filas      INT UNSIGNED NOT NULL DEFAULT 0,
  actualizado    VARCHAR(32)  NULL COMMENT 'Fecha ISO del último cambio hecho en pantalla',
  datos          LONGTEXT     NOT NULL CHECK (JSON_VALID(datos)),
  creado_en      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modificado_en  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_proyectos_archivado (archivado)
) ENGINE=InnoDB;

-- Respaldo de cada guardado (se conservan los últimos N por proyecto, ver config.php).
-- También guarda la última versión de un proyecto antes de borrarlo.
CREATE TABLE IF NOT EXISTS proyectos_versiones (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  proyecto_id  VARCHAR(64)  NOT NULL,
  version      INT UNSIGNED NOT NULL,
  accion       VARCHAR(10)  NOT NULL DEFAULT 'guardar' COMMENT 'guardar o borrar',
  datos        LONGTEXT     NOT NULL,
  usuario      VARCHAR(50)  NULL COMMENT 'Quién guardó',
  ip           VARCHAR(45)  NULL,
  guardado_en  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_versiones_proyecto (proyecto_id, id)
) ENGINE=InnoDB;

-- Ajustes generales del sistema.
CREATE TABLE IF NOT EXISTS ajustes (
  clave          VARCHAR(64) NOT NULL,
  valor          LONGTEXT    NOT NULL,
  modificado_en  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (clave)
) ENGINE=InnoDB;

-- Personas que pueden entrar al sistema.
--   admin:    todo, incluida la administración de usuarios y borrar proyectos
--   operador: captura y modifica proyectos
--   consulta: solo ve, filtra y descarga
--   responsable: consulta el sistema y captura seguimientos de desviaciones de su área
CREATE TABLE IF NOT EXISTS usuarios (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  usuario            VARCHAR(50)  NOT NULL,
  nombre             VARCHAR(120) NOT NULL,
  clave_hash         VARCHAR(255) NOT NULL COMMENT 'password_hash() de PHP; nunca la contraseña',
  rol                ENUM('admin','operador','consulta','responsable') NOT NULL DEFAULT 'consulta',
  area               VARCHAR(50)  NULL COMMENT 'Área asignada al responsable',
  activo             TINYINT(1)   NOT NULL DEFAULT 1,
  debe_cambiar_clave TINYINT(1)   NOT NULL DEFAULT 1,
  intentos_fallidos  INT UNSIGNED NOT NULL DEFAULT 0,
  bloqueado_hasta    DATETIME     NULL,
  version_sesion     INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Al subir, cierra las sesiones abiertas de esa persona',
  ultimo_acceso      DATETIME     NULL,
  creado_en          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modificado_en      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY ux_usuarios_usuario (usuario)
) ENGINE=InnoDB;

-- Actualización para instalaciones existentes
ALTER TABLE usuarios MODIFY COLUMN rol ENUM('admin','operador','consulta','responsable') NOT NULL DEFAULT 'consulta';
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS area VARCHAR(50) NULL COMMENT 'Área asignada al responsable' AFTER rol;

-- Registro de seguridad: accesos, intentos fallidos, borrados y cambios de usuarios.
CREATE TABLE IF NOT EXISTS bitacora (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  fecha       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  usuario_id  INT UNSIGNED NULL,
  usuario     VARCHAR(50)  NULL,
  accion      VARCHAR(40)  NOT NULL,
  detalle     VARCHAR(500) NULL,
  ip          VARCHAR(45)  NULL,
  PRIMARY KEY (id),
  KEY ix_bitacora_fecha (fecha),
  KEY ix_bitacora_ip_accion (ip, accion, fecha)
) ENGINE=InnoDB;

-- Historial de compromisos y comentarios de los responsables de área.
CREATE TABLE IF NOT EXISTS seguimiento_desviaciones (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  proyecto_id     VARCHAR(64)  NOT NULL,
  fila_id         VARCHAR(255) NOT NULL,
  area            VARCHAR(50)  NOT NULL,
  usuario_id      INT UNSIGNED NOT NULL,
  usuario         VARCHAR(50)  NOT NULL,
  comentario      TEXT         NOT NULL,
  dependencias    TEXT         NULL,
  compromiso      DATETIME     NOT NULL,
  tiene_glpi      TINYINT(1)   NOT NULL,
  folio_glpi      VARCHAR(100) NULL,
  creado_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_seguimiento_desviacion (proyecto_id, fila_id, area, id),
  KEY ix_seguimiento_area_fecha (area, creado_en)
) ENGINE=InnoDB;

ALTER TABLE seguimiento_desviaciones ADD COLUMN IF NOT EXISTS dependencias TEXT NULL AFTER comentario;
-- Botón "Marcar como resuelta": el cierre no lleva fecha compromiso.
ALTER TABLE seguimiento_desviaciones ADD COLUMN IF NOT EXISTS resuelta TINYINT(1) NOT NULL DEFAULT 0 AFTER folio_glpi;
ALTER TABLE seguimiento_desviaciones MODIFY compromiso DATETIME NULL;

-- Lo que PULSOTT documentó en cada ticket de GLPI (botón "Sincronizar GLPI").
-- "firma" resume estatus/avance/comentario: si no cambió, no se vuelve a documentar.
CREATE TABLE IF NOT EXISTS glpi_sincronizaciones (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  proyecto_id     VARCHAR(64)  NOT NULL,
  fila_id         VARCHAR(255) NOT NULL,
  folio           INT UNSIGNED NOT NULL,
  accion          VARCHAR(20)  NOT NULL COMMENT 'seguimiento, resolver, en_curso, planificado, en_espera',
  estado_antes    TINYINT      NULL,
  estado_despues  TINYINT      NULL,
  texto           TEXT         NOT NULL,
  firma           VARCHAR(64)  NOT NULL,
  ok              TINYINT(1)   NOT NULL,
  error           VARCHAR(500) NULL,
  usuario         VARCHAR(50)  NOT NULL,
  creado_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_glpi_proyecto_folio (proyecto_id, folio, id)
) ENGINE=InnoDB;

-- Actualización para bases creadas con la primera versión
ALTER TABLE proyectos_versiones ADD COLUMN IF NOT EXISTS usuario VARCHAR(50) NULL COMMENT 'Quién guardó' AFTER datos;

-- Prospectos: oportunidades de venta (montos estimados; no es dinero facturado ni cobrado)
CREATE TABLE IF NOT EXISTS prospectos (
  id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  cliente         VARCHAR(120)  NOT NULL,
  descripcion     VARCHAR(500)  NOT NULL,
  alcance         VARCHAR(1000) NULL,
  unidades        DECIMAL(14,2) NOT NULL DEFAULT 1,
  precio_unitario DECIMAL(16,2) NOT NULL DEFAULT 0,
  importe         DECIMAL(20,2) AS (unidades * precio_unitario) STORED COMMENT 'Importe sin IVA = unidades × precio unitario',
  factura         VARCHAR(60)   NULL,
  cobrado         DECIMAL(16,2) NOT NULL DEFAULT 0,
  estatus         VARCHAR(30)   NOT NULL DEFAULT 'Pendiente',
  baja            TINYINT(1)    NOT NULL DEFAULT 0,
  motivo_baja     VARCHAR(300)  NULL,
  creado_por      VARCHAR(50)   NULL,
  modificado_por  VARCHAR(50)   NULL,
  creado_en       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  modificado_en   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_prospectos_cliente (cliente),
  KEY ix_prospectos_baja (baja)
) ENGINE=InnoDB;

-- Prospectos: fecha en que se envió la propuesta al cliente
ALTER TABLE prospectos ADD COLUMN IF NOT EXISTS fecha_propuesta DATE NULL COMMENT 'Fecha de envío de la propuesta' AFTER estatus;

-- Fotos de las personas (coordinadores, responsables): una por nombre, compartida entre proyectos.
-- La imagen llega ya recortada y reducida desde el navegador (data URL JPEG de ~150 px).
CREATE TABLE IF NOT EXISTS fotos_personas (
  clave          VARCHAR(190) NOT NULL COMMENT 'Nombre normalizado: minúsculas, sin acentos ni espacios dobles',
  nombre         VARCHAR(190) NOT NULL,
  imagen         MEDIUMTEXT   NOT NULL,
  modificado_por VARCHAR(50)  NULL,
  modificado_en  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (clave)
) ENGINE=InnoDB;
