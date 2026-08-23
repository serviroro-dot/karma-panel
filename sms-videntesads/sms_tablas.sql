-- Tablas del envío de SMS.
-- Se ejecuta UNA vez. No borra nada de lo que ya tengas.

CREATE TABLE IF NOT EXISTS sms_envios (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    nombre      VARCHAR(120)   NOT NULL DEFAULT '',
    texto       TEXT           NOT NULL,
    total       INT            NOT NULL DEFAULT 0,
    estado      ENUM('activo','pausado','terminado') NOT NULL DEFAULT 'activo',
    -- Evita que un doble clic cree el mismo envío dos veces.
    token       CHAR(40)       NOT NULL,
    creado      DATETIME       NOT NULL,
    UNIQUE KEY uq_token (token),
    KEY ix_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE IF NOT EXISTS sms_cola (
    id          BIGINT AUTO_INCREMENT PRIMARY KEY,
    envio_id    INT            NOT NULL,
    telefono    VARCHAR(20)    NOT NULL,
    estado      ENUM('pendiente','enviando','enviado','fallido','cancelado') NOT NULL DEFAULT 'pendiente',
    intentos    TINYINT        NOT NULL DEFAULT 0,
    id_pasarela VARCHAR(64)    NULL,
    error       VARCHAR(255)   NULL,
    lote        CHAR(32)       NULL,
    actualizado DATETIME       NULL,
    -- Un mismo teléfono no puede entrar dos veces en el mismo envío.
    -- Esto lo garantiza la base de datos, no el programa: aunque algo
    -- fallara arriba, aquí no entra el repetido.
    UNIQUE KEY uq_envio_tel (envio_id, telefono),
    KEY ix_trabajo (estado, envio_id),
    KEY ix_lote (lote),
    -- Para poder preguntar rápido "¿a este número le mandé algo hoy?"
    -- aunque haya cientos de miles de filas.
    KEY ix_tel_reciente (telefono, estado, actualizado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- Números que no quieren recibir más. La cola los salta siempre.
CREATE TABLE IF NOT EXISTS sms_bajas (
    telefono    VARCHAR(20)    NOT NULL PRIMARY KEY,
    motivo      VARCHAR(80)    NOT NULL DEFAULT 'baja solicitada',
    fecha       DATETIME       NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
