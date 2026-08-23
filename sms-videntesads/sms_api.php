<?php
/**
 * Las órdenes que manda la pantalla: encolar, consultar estado, pausar,
 * reanudar y dar de baja. Devuelve siempre JSON.
 */

require __DIR__ . '/sms_lib.php';

session_start();
header('Content-Type: application/json; charset=utf-8');

$cfg = sms_config();

/* ---- Puerta ---- */
if ($cfg['clave_panel'] !== '' && empty($_SESSION['sms_ok'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Sesión caducada. Vuelve a entrar.']);
    exit;
}

$accion = isset($_REQUEST['accion']) ? $_REQUEST['accion'] : '';
$db     = sms_db();

try {
    switch ($accion) {

        /* -------------------------------------------------- *
         *  Meter un envío en la cola
         * -------------------------------------------------- */
        case 'encolar':
            $texto   = trim((string) ($_POST['texto'] ?? ''));
            $lista   = (string) ($_POST['numeros'] ?? '');
            $nombre  = trim((string) ($_POST['nombre'] ?? ''));
            // Lo manda la pantalla. Es lo que impide que un doble clic
            // cree el mismo envío dos veces.
            $token   = preg_replace('/[^a-f0-9]/', '', (string) ($_POST['token'] ?? ''));

            if ($texto === '') {
                throw new Exception('Escribe el mensaje.');
            }

            /*
             * UN SOLO SMS POR PERSONA.
             *
             * Se limpia el texto (tildes de á/í/ó/ú, comillas de Word,
             * emojis) para que quepa de verdad en 160 caracteres, y se
             * comprueba aquí, en el servidor. Si el navegador fallara o
             * alguien saltara la pantalla, sigue sin poder colarse un
             * mensaje que se cobre doble.
             */
            if (!empty($cfg['quitar_tildes'])) {
                $texto = sms_a_gsm($texto);
            }

            $medida = sms_partes($texto);

            if (!empty($cfg['forzar_un_sms']) && $medida['partes'] > 1) {
                throw new Exception(
                    'El mensaje ocupa ' . $medida['partes'] . ' SMS por persona y se cobraría '
                    . $medida['partes'] . ' veces. Máximo 160 caracteres. Ahora tiene '
                    . $medida['largo'] . '. Recórtalo en '
                    . ($medida['largo'] - 160) . ' caracteres.'
                );
            }
            if (strlen($token) !== 40) {
                throw new Exception('Petición no válida. Recarga la página.');
            }

            // Aquí ya vienen limpios y SIN REPETIDOS: si el mismo teléfono
            // aparece diez veces en la lista, sale una sola vez.
            $telefonos = sms_extraer_telefonos($lista);
            if (!$telefonos) {
                throw new Exception('No he encontrado ningún teléfono válido en la lista.');
            }

            $tope = (int) $cfg['tope_por_envio'];
            if ($tope > 0 && count($telefonos) > $tope) {
                throw new Exception('La lista trae ' . count($telefonos) . ' números y el tope está en ' . $tope . '.');
            }

            $enLista = count($telefonos);

            // Fuera los que están de baja. La consulta va por tandas: con
            // una lista de 50.000 números, meterlos todos en un solo IN
            // reventaría la consulta.
            $bajas = sms_buscar_en_columna($db, 'SELECT telefono FROM sms_bajas WHERE telefono IN', $telefonos);

            // Fuera también los que ya recibieron algo hace poco, aunque
            // fuera de otro envío distinto. Es lo que garantiza que a cada
            // número le llegue UN solo mensaje.
            $repetidos = [];
            $horas = (int) $cfg['no_repetir_horas'];
            if ($horas > 0) {
                $repetidos = sms_buscar_en_columna(
                    $db,
                    "SELECT DISTINCT telefono FROM sms_cola
                      WHERE estado IN ('enviado','enviando','pendiente')
                        AND actualizado > DATE_SUB(NOW(), INTERVAL {$horas} HOUR)
                        AND telefono IN",
                    $telefonos
                );
            }

            $telefonos = array_values(array_filter($telefonos, function ($t) use ($bajas, $repetidos) {
                return !isset($bajas[$t]) && !isset($repetidos[$t]);
            }));

            if (!$telefonos) {
                throw new Exception(
                    'No queda ningún número al que mandar: ' . count($bajas) . ' están de baja y '
                    . count($repetidos) . ' ya recibieron un mensaje en las últimas ' . $horas . ' horas.'
                );
            }

            $db->beginTransaction();

            // Si el token ya existía, es un doble clic: devolvemos el envío
            // que ya se creó en vez de crear otro.
            $st = $db->prepare("SELECT id FROM sms_envios WHERE token = ?");
            $st->execute([$token]);
            $yaEsta = $st->fetchColumn();

            if ($yaEsta) {
                $db->commit();
                echo json_encode(['envio_id' => (int) $yaEsta, 'repetido' => true]);
                exit;
            }

            $st = $db->prepare(
                "INSERT INTO sms_envios (nombre, texto, total, estado, token, creado)
                 VALUES (?, ?, ?, 'activo', ?, NOW())"
            );
            $st->execute([
                ($nombre !== '' ? $nombre : 'Envío del ' . date('d/m/Y H:i')),
                $texto,
                count($telefonos),
                $token,
            ]);
            $envioId = (int) $db->lastInsertId();

            // Se insertan por tandas de 500 en una sola sentencia cada una.
            // De uno en uno, 50.000 números tardarían minutos; así son
            // segundos. INSERT IGNORE + el índice único de la tabla son la
            // última red: aunque algo se colara repetido, no entra dos veces.
            foreach (array_chunk($telefonos, 500) as $tanda) {
                $valores = implode(',', array_fill(0, count($tanda), '(?, ?, NOW())'));
                $datos   = [];
                foreach ($tanda as $t) {
                    $datos[] = $envioId;
                    $datos[] = $t;
                }
                $db->prepare("INSERT IGNORE INTO sms_cola (envio_id, telefono, actualizado) VALUES {$valores}")
                   ->execute($datos);
            }

            // El total real es lo que de verdad ha entrado en la cola.
            $st = $db->prepare("SELECT COUNT(*) FROM sms_cola WHERE envio_id = ?");
            $st->execute([$envioId]);
            $total = (int) $st->fetchColumn();

            $db->prepare("UPDATE sms_envios SET total = ? WHERE id = ?")->execute([$total, $envioId]);

            $db->commit();

            echo json_encode([
                'envio_id'             => $envioId,
                'total'                => $total,
                'en_la_lista'          => $enLista,
                'descartados_por_baja' => count($bajas),
                'descartados_por_repetido' => count($repetidos),
            ]);
            break;

        /* -------------------------------------------------- *
         *  Cómo va
         * -------------------------------------------------- */
        case 'estado':
            $envioId = (int) ($_GET['envio_id'] ?? 0);

            $st = $db->prepare("SELECT id, nombre, total, estado, creado FROM sms_envios WHERE id = ?");
            $st->execute([$envioId]);
            $envio = $st->fetch();

            if (!$envio) {
                throw new Exception('Ese envío no existe.');
            }

            $st = $db->prepare(
                "SELECT estado, COUNT(*) n FROM sms_cola WHERE envio_id = ? GROUP BY estado"
            );
            $st->execute([$envioId]);

            $cuenta = ['pendiente' => 0, 'enviando' => 0, 'enviado' => 0, 'fallido' => 0, 'cancelado' => 0];
            foreach ($st->fetchAll() as $r) {
                $cuenta[$r['estado']] = (int) $r['n'];
            }

            $st = $db->prepare(
                "SELECT telefono, estado, error, actualizado
                   FROM sms_cola
                  WHERE envio_id = ? AND estado IN ('enviado','fallido','cancelado')
                  ORDER BY actualizado DESC, id DESC
                  LIMIT 25"
            );
            $st->execute([$envioId]);

            echo json_encode([
                'envio'   => $envio,
                'cuenta'  => $cuenta,
                'ultimos' => $st->fetchAll(),
            ]);
            break;

        /* -------------------------------------------------- *
         *  Pausar y reanudar
         * -------------------------------------------------- */
        case 'pausar':
        case 'reanudar':
            $envioId = (int) ($_POST['envio_id'] ?? 0);
            $nuevo   = ($accion === 'pausar') ? 'pausado' : 'activo';

            $st = $db->prepare("UPDATE sms_envios SET estado = ? WHERE id = ? AND estado <> 'terminado'");
            $st->execute([$nuevo, $envioId]);

            echo json_encode(['estado' => $nuevo]);
            break;

        /* -------------------------------------------------- *
         *  Bajas
         * -------------------------------------------------- */
        case 'baja':
            $ok = sms_dar_de_baja((string) ($_POST['telefono'] ?? ''), 'baja manual');
            echo json_encode(['ok' => $ok]);
            break;

        default:
            throw new Exception('Orden desconocida.');
    }

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
