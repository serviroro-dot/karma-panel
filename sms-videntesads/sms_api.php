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
            if (strlen($token) !== 40) {
                throw new Exception('Petición no válida. Recarga la página.');
            }

            $telefonos = sms_extraer_telefonos($lista);
            if (!$telefonos) {
                throw new Exception('No he encontrado ningún teléfono válido en la lista.');
            }

            // Fuera los que están de baja.
            $bajas = [];
            $marcas = implode(',', array_fill(0, count($telefonos), '?'));
            $st = $db->prepare("SELECT telefono FROM sms_bajas WHERE telefono IN ($marcas)");
            $st->execute($telefonos);
            foreach ($st->fetchAll() as $b) {
                $bajas[$b['telefono']] = true;
            }
            $telefonos = array_values(array_filter($telefonos, function ($t) use ($bajas) {
                return !isset($bajas[$t]);
            }));

            if (!$telefonos) {
                throw new Exception('Todos los números de la lista están dados de baja.');
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

            // INSERT IGNORE + índice único: aunque la lista traiga repetidos,
            // cada teléfono entra una sola vez.
            $ins = $db->prepare("INSERT IGNORE INTO sms_cola (envio_id, telefono, actualizado) VALUES (?, ?, NOW())");
            foreach ($telefonos as $t) {
                $ins->execute([$envioId, $t]);
            }

            $db->commit();

            echo json_encode([
                'envio_id'  => $envioId,
                'total'     => count($telefonos),
                'descartados_por_baja' => count($bajas),
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
