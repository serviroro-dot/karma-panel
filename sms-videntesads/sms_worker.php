<?php
/**
 * El trabajador: es quien manda los SMS de verdad.
 *
 * Se lanza solo desde el cron, cada minuto:
 *     * * * * * /usr/bin/php /ruta/al/sitio/sms_worker.php >> /var/log/sms_worker.log 2>&1
 *
 * Da igual que se solape con la pasada anterior: hay un candado y solo
 * corre uno a la vez. Y si se corta la luz, al volver sigue donde iba.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este archivo solo se ejecuta desde el servidor.\n");
}

require __DIR__ . '/sms_lib.php';

$cfg = sms_config();
$db  = sms_db();

/* ---- Candado: que no corran dos a la vez ---- */
$candado = fopen(sys_get_temp_dir() . '/sms_worker.lock', 'c');
if (!$candado || !flock($candado, LOCK_EX | LOCK_NB)) {
    exit("Ya hay otro trabajando. Salgo.\n");
}

$porSegundo = max(1, (int) $cfg['por_segundo']);
$esperaUs   = (int) (1000000 / $porSegundo);
$maxIntent  = max(1, (int) $cfg['max_intentos']);
$lote       = max(1, (int) $cfg['lote']);

// Marca de este lote, para saber cuáles he cogido yo.
$marca = bin2hex(random_bytes(16));

/*
 * Rescate: si una pasada anterior se cortó a medias (corte de luz, reinicio),
 * habrán quedado filas en 'enviando' que nadie va a tocar. Pasados 15 minutos
 * las devolvemos a la cola. Se cuenta el intento, así que si el problema
 * fuera del propio mensaje acabaría en fallido y no daría vueltas eternas.
 */
$db->exec(
    "UPDATE sms_cola
        SET estado = 'pendiente', lote = NULL, intentos = intentos + 1,
            error = 'reanudado tras corte', actualizado = NOW()
      WHERE estado = 'enviando'
        AND actualizado < DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
);

/*
 * Paso 1 — RESERVAR.
 * Marco como 'enviando' las que voy a mandar yo. Este UPDATE es atómico:
 * si otro proceso entrara a la vez, no podría coger las mismas filas.
 * Esto es lo que garantiza que ningún número se mande dos veces.
 */
$reservar = $db->prepare(
    "UPDATE sms_cola c
        JOIN sms_envios e ON e.id = c.envio_id
        SET c.estado = 'enviando', c.lote = ?, c.actualizado = NOW()
      WHERE c.estado = 'pendiente'
        AND e.estado = 'activo'
        AND c.intentos < ?
      ORDER BY c.id
      LIMIT {$lote}"
);
$reservar->execute([$marca, $maxIntent]);

if ($reservar->rowCount() === 0) {
    sms_cerrar_envios_terminados($db);
    exit("Nada pendiente.\n");
}

/* Paso 2 — coger las reservadas y mandarlas una a una */
$st = $db->prepare(
    "SELECT c.id, c.telefono, c.intentos, e.texto
       FROM sms_cola c
       JOIN sms_envios e ON e.id = c.envio_id
      WHERE c.lote = ?
      ORDER BY c.id"
);
$st->execute([$marca]);
$filas = $st->fetchAll();

$okEstado   = $db->prepare("UPDATE sms_cola SET estado='enviado', id_pasarela=?, error=NULL, intentos=intentos+1, actualizado=NOW() WHERE id=?");
$malEstado  = $db->prepare("UPDATE sms_cola SET estado='fallido', error=?, intentos=intentos+1, actualizado=NOW() WHERE id=?");
$reintentar = $db->prepare("UPDATE sms_cola SET estado='pendiente', error=?, intentos=intentos+1, lote=NULL, actualizado=NOW() WHERE id=?");
$cancelar   = $db->prepare("UPDATE sms_cola SET estado='cancelado', error=?, actualizado=NOW() WHERE id=?");

$enviados = 0;
$fallidos = 0;

foreach ($filas as $f) {

    // Por si se dio de baja mientras estaba en cola.
    if (sms_esta_de_baja($f['telefono'])) {
        $cancelar->execute(['de baja', $f['id']]);
        continue;
    }

    $r = sms_enviar($f['telefono'], $f['texto']);

    if ($r['ok']) {
        $okEstado->execute([$r['id'], $f['id']]);
        $enviados++;
    } elseif ($r['reintentable'] && ($f['intentos'] + 1) < $maxIntent) {
        // Fallo pasajero: vuelve a la cola y se prueba en la siguiente pasada.
        $reintentar->execute([mb_substr($r['error'], 0, 255), $f['id']]);
    } else {
        $malEstado->execute([mb_substr($r['error'], 0, 255), $f['id']]);
        $fallidos++;
    }

    usleep($esperaUs);
}

sms_cerrar_envios_terminados($db);

echo "Enviados: {$enviados} · Fallidos: {$fallidos}\n";


/**
 * Marca como terminado el envío que ya no tiene nada pendiente.
 */
function sms_cerrar_envios_terminados($db)
{
    $db->exec(
        "UPDATE sms_envios e
            SET e.estado = 'terminado'
          WHERE e.estado = 'activo'
            AND NOT EXISTS (
                SELECT 1 FROM sms_cola c
                 WHERE c.envio_id = e.id
                   AND c.estado IN ('pendiente','enviando')
            )"
    );
}
