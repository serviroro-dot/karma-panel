<?php
/**
 * Funciones comunes: base de datos, teléfonos y envío a la pasarela.
 * No hay que tocar nada de este archivo.
 */

function sms_config()
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require __DIR__ . '/sms_config.php';
    }
    return $cfg;
}

function sms_db()
{
    static $pdo = null;
    if ($pdo === null) {
        $c = sms_config()['db'];
        $pdo = new PDO(
            "mysql:host={$c['host']};dbname={$c['nombre']};charset=utf8mb4",
            $c['user'],
            $c['pass'],
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    }
    return $pdo;
}

/**
 * Deja el teléfono en formato internacional sin el +.
 * Devuelve '' si no hay manera de darle sentido.
 */
function sms_normalizar_telefono($tel)
{
    $tel = trim((string) $tel);
    if ($tel === '') {
        return '';
    }

    $mas = (strpos($tel, '+') === 0);
    $d   = preg_replace('/\D+/', '', $tel);

    if ($d === '') {
        return '';
    }

    // 0034600... -> 34600...
    if (strpos($d, '00') === 0) {
        $d = substr($d, 2);
        $mas = true;
    }

    // Si no traía prefijo internacional, le ponemos el del país.
    if (!$mas) {
        $pais = (string) sms_config()['prefijo_pais'];
        if (strpos($d, $pais) !== 0 || strlen($d) <= strlen($pais)) {
            $d = $pais . ltrim($d, '0');
        }
    }

    // Un número internacional válido va de 8 a 15 dígitos.
    if (strlen($d) < 8 || strlen($d) > 15) {
        return '';
    }

    return $d;
}

/** Saca los teléfonos de un texto pegado o de un CSV, ya limpios y sin repetidos. */
function sms_extraer_telefonos($texto)
{
    $trozos = preg_split('/[\s,;|]+/', (string) $texto, -1, PREG_SPLIT_NO_EMPTY);
    $out    = [];

    foreach ($trozos as $t) {
        $n = sms_normalizar_telefono($t);
        if ($n !== '') {
            $out[$n] = true;   // la clave elimina los duplicados
        }
    }

    return array_keys($out);
}

/** ¿Está de baja? */
function sms_esta_de_baja($telefono)
{
    $st = sms_db()->prepare("SELECT 1 FROM sms_bajas WHERE telefono = ?");
    $st->execute([$telefono]);
    return (bool) $st->fetchColumn();
}

function sms_dar_de_baja($telefono, $motivo = 'baja solicitada')
{
    $tel = sms_normalizar_telefono($telefono);
    if ($tel === '') {
        return false;
    }
    $st = sms_db()->prepare(
        "INSERT INTO sms_bajas (telefono, motivo, fecha) VALUES (?, ?, NOW())
         ON DUPLICATE KEY UPDATE motivo = VALUES(motivo)"
    );
    $st->execute([$tel, mb_substr($motivo, 0, 80)]);

    // Y cancelamos lo que tuviera pendiente.
    $st = sms_db()->prepare(
        "UPDATE sms_cola SET estado = 'cancelado', error = 'de baja', actualizado = NOW()
         WHERE telefono = ? AND estado IN ('pendiente','enviando')"
    );
    $st->execute([$tel]);

    return true;
}


/* ------------------------------------------------------------------ *
 *  Envío a la pasarela
 * ------------------------------------------------------------------ */

/**
 * Manda UN mensaje.
 * Devuelve ['ok' => bool, 'id' => string, 'error' => string, 'reintentable' => bool]
 */
function sms_enviar($telefono, $texto)
{
    $cfg = sms_config();

    switch ($cfg['pasarela']) {
        case 'twilio':
            return sms_enviar_twilio($telefono, $texto, $cfg['twilio']);
        case 'labsmobile':
            return sms_enviar_labsmobile($telefono, $texto, $cfg['labsmobile']);
        case 'generico':
            return sms_enviar_generico($telefono, $texto, $cfg['generico']);
    }

    return ['ok' => false, 'id' => '', 'error' => 'pasarela no configurada', 'reintentable' => false];
}

function sms_enviar_twilio($telefono, $texto, $c)
{
    $url = 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($c['account_sid']) . '/Messages.json';

    $campos = ['To' => '+' . $telefono, 'Body' => $texto];

    // Un Messaging Service empieza por MG; un número normal, por +.
    if (strpos($c['remitente'], 'MG') === 0) {
        $campos['MessagingServiceSid'] = $c['remitente'];
    } else {
        $campos['From'] = $c['remitente'];
    }

    $r = sms_http($url, 'POST', http_build_query($campos), [
        'Content-Type: application/x-www-form-urlencoded',
    ], $c['account_sid'] . ':' . $c['auth_token']);

    if ($r['error_red'] !== '') {
        return ['ok' => false, 'id' => '', 'error' => $r['error_red'], 'reintentable' => true];
    }

    $j = json_decode($r['cuerpo'], true);

    if ($r['codigo'] >= 200 && $r['codigo'] < 300 && !empty($j['sid'])) {
        return ['ok' => true, 'id' => $j['sid'], 'error' => '', 'reintentable' => false];
    }

    $msg = isset($j['message']) ? $j['message'] : ('HTTP ' . $r['codigo']);

    // 429 = vas demasiado rápido. 5xx = problema suyo. Ambos se reintentan.
    $reintentable = ($r['codigo'] === 429 || $r['codigo'] >= 500);

    return ['ok' => false, 'id' => '', 'error' => $msg, 'reintentable' => $reintentable];
}

function sms_enviar_labsmobile($telefono, $texto, $c)
{
    $cuerpo = json_encode([
        'message'   => $texto,
        'tpoa'      => $c['remitente'],
        'recipient' => [['msisdn' => $telefono]],
    ], JSON_UNESCAPED_UNICODE);

    $r = sms_http('https://api.labsmobile.com/json/send', 'POST', $cuerpo, [
        'Content-Type: application/json',
        'Cache-Control: no-cache',
    ], $c['usuario'] . ':' . $c['token']);

    if ($r['error_red'] !== '') {
        return ['ok' => false, 'id' => '', 'error' => $r['error_red'], 'reintentable' => true];
    }

    $j = json_decode($r['cuerpo'], true);

    // En LabsMobile, code "0" es correcto.
    if ($r['codigo'] >= 200 && $r['codigo'] < 300 && isset($j['code']) && (string) $j['code'] === '0') {
        return ['ok' => true, 'id' => isset($j['subid']) ? $j['subid'] : '', 'error' => '', 'reintentable' => false];
    }

    $msg = isset($j['message']) ? $j['message'] : ('HTTP ' . $r['codigo']);

    return ['ok' => false, 'id' => '', 'error' => $msg, 'reintentable' => ($r['codigo'] >= 500)];
}

function sms_enviar_generico($telefono, $texto, $c)
{
    $campos = [];
    foreach ($c['campos'] as $k => $v) {
        $campos[$k] = str_replace(['{telefono}', '{texto}'], [$telefono, $texto], $v);
    }

    $url        = str_replace(['{telefono}', '{texto}'], [$telefono, rawurlencode($texto)], $c['url']);
    $cabeceras  = [];
    foreach ($c['cabeceras'] as $k => $v) {
        $cabeceras[] = $k . ': ' . $v;
    }

    if (strtoupper($c['metodo']) === 'GET') {
        $sep    = (strpos($url, '?') === false) ? '?' : '&';
        $url   .= $campos ? $sep . http_build_query($campos) : '';
        $cuerpo = null;
    } elseif ($c['formato'] === 'json') {
        $cabeceras[] = 'Content-Type: application/json';
        $cuerpo      = json_encode($campos, JSON_UNESCAPED_UNICODE);
    } else {
        $cabeceras[] = 'Content-Type: application/x-www-form-urlencoded';
        $cuerpo      = http_build_query($campos);
    }

    $r = sms_http($url, strtoupper($c['metodo']), $cuerpo, $cabeceras, '');

    if ($r['error_red'] !== '') {
        return ['ok' => false, 'id' => '', 'error' => $r['error_red'], 'reintentable' => true];
    }

    $ok = ($r['codigo'] >= 200 && $r['codigo'] < 300);

    if ($ok && $c['exito_si_contiene'] !== '') {
        $ok = (strpos($r['cuerpo'], $c['exito_si_contiene']) !== false);
    }

    if ($ok) {
        return ['ok' => true, 'id' => '', 'error' => '', 'reintentable' => false];
    }

    return [
        'ok'           => false,
        'id'           => '',
        'error'        => 'HTTP ' . $r['codigo'] . ' ' . mb_substr(trim($r['cuerpo']), 0, 120),
        'reintentable' => ($r['codigo'] >= 500),
    ];
}

function sms_http($url, $metodo, $cuerpo, $cabeceras, $userpwd)
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $metodo,
        CURLOPT_HTTPHEADER     => $cabeceras,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    if ($cuerpo !== null && $metodo !== 'GET') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $cuerpo);
    }
    if ($userpwd !== '') {
        curl_setopt($ch, CURLOPT_USERPWD, $userpwd);
    }

    $resp   = curl_exec($ch);
    $codigo = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    return [
        'cuerpo'    => ($resp === false) ? '' : $resp,
        'codigo'    => $codigo,
        'error_red' => $err,
    ];
}
