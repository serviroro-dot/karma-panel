<?php
/**
 * Funciones comunes: base de datos, teléfonos y envío a la pasarela.
 * No hay que tocar nada de este archivo.
 */

function sms_config()
{
    static $cfg = null;
    if ($cfg === null) {
        // SMS_CONFIG solo se usa en las pruebas, para apuntar a una
        // configuración de mentira. En tu servidor nunca está puesta, así
        // que siempre lee sms_config.php.
        $ruta = getenv('SMS_CONFIG');
        $cfg  = require (($ruta && is_readable($ruta)) ? $ruta : __DIR__ . '/sms_config.php');
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

/* ------------------------------------------------------------------ *
 *  El texto del mensaje: que quepa SIEMPRE en un solo SMS
 * ------------------------------------------------------------------ */

/**
 * El alfabeto que usan los SMS (GSM 03.38). Fíjate en que están é, è, à,
 * ò, ù, ñ, ü, ç... pero NO están á, í, ó, ú. Ésa es la trampa que dobla
 * la factura sin que se note.
 */
function sms_alfabeto_gsm()
{
    // OJO: en comillas simples a propósito. Con comillas dobles, PHP toma
    // el símbolo del dólar como principio de una variable y se come parte
    // del alfabeto — con lo que la é de "Café" se perdería.
    return '@£$¥èéùìòÇ' . "\n" . 'Øø' . "\r"
         . 'ÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?'
         . '¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà';
}

/** Estos caben, pero ocupan dos huecos en vez de uno. */
function sms_alfabeto_gsm_extendido()
{
    return '^{}\\[~]|€';
}

/**
 * Cambia lo que no cabe en el alfabeto de los SMS por su equivalente que
 * sí cabe: las tildes de "más" o "número", las comillas curvas que pone
 * Word, las rayas largas, los puntos suspensivos de un solo carácter.
 * Lo que no tiene equivalente (emojis) se quita.
 *
 * Así el mensaje se queda en 160 caracteres de verdad y se cobra UNO por
 * persona, que es lo que se quiere.
 */
function sms_a_gsm($texto)
{
    $cambios = [
        'á'=>'a', 'í'=>'i', 'ó'=>'o', 'ú'=>'u',
        'Á'=>'A', 'Í'=>'I', 'Ó'=>'O', 'Ú'=>'U',
        'Â'=>'A', 'Ê'=>'E', 'Î'=>'I', 'Ô'=>'O', 'Û'=>'U',
        'â'=>'a', 'ê'=>'e', 'î'=>'i', 'ô'=>'o', 'û'=>'u',
        'ã'=>'a', 'õ'=>'o', 'ẽ'=>'e', 'ĩ'=>'i', 'ũ'=>'u',
        'À'=>'A', 'È'=>'E', 'Ì'=>'I', 'Ò'=>'O', 'Ù'=>'U',
        'ï'=>'i', 'Ï'=>'I', 'ë'=>'e', 'Ë'=>'E', 'ÿ'=>'y',
        'ý'=>'y', 'Ý'=>'Y', 'č'=>'c', 'š'=>'s', 'ž'=>'z',
        // La Ç mayúscula sí está en el alfabeto de los SMS, pero la ç
        // minúscula no. Se cambia por c para no perder la letra: "Provença"
        // quedaría en "Provena", que no se entiende.
        'ç'=>'c',
        'Ã'=>'A', 'Õ'=>'O', 'ŕ'=>'r', 'ŀ'=>'l',
        'º'=>'o', 'ª'=>'a',
        // Comillas y rayas que mete Word y los móviles
        '“'=>'"', '”'=>'"', '„'=>'"', '«'=>'"', '»'=>'"',
        '‘'=>"'", '’'=>"'", '‚'=>"'", '´'=>"'", '`'=>"'",
        '–'=>'-', '—'=>'-', '‑'=>'-', '−'=>'-',
        '…'=>'...', '•'=>'-', '·'=>'.', '°'=>'o',
        '™'=>'TM', '©'=>'(c)', '®'=>'(r)', '½'=>'1/2', '¼'=>'1/4',
        "\xC2\xA0" => ' ',      // espacio duro
        "\xE2\x82\xAC" => '€',  // el euro sí cabe (ocupa dos)
    ];

    $texto = strtr($texto, $cambios);

    // Lo que quede fuera del alfabeto (emojis y demás) se elimina.
    $permitido = sms_alfabeto_gsm() . sms_alfabeto_gsm_extendido();
    $salida    = '';

    foreach (preg_split('//u', $texto, -1, PREG_SPLIT_NO_EMPTY) as $c) {
        if (mb_strpos($permitido, $c) !== false) {
            $salida .= $c;
        } elseif (trim($c) === '') {
            $salida .= ' ';         // cualquier espacio raro pasa a espacio normal
        }
        // el resto se descarta
    }

    // Espacios repetidos que hayan quedado al quitar cosas.
    return trim(preg_replace('/ {2,}/', ' ', $salida));
}

/**
 * Cuántos SMS cobra la operadora por este texto, y en qué alfabeto va.
 * Devuelve ['unicode' => bool, 'largo' => int, 'partes' => int]
 */
function sms_partes($texto)
{
    $base = sms_alfabeto_gsm();
    $ext  = sms_alfabeto_gsm_extendido();

    $largo = 0;
    $esGsm = true;

    foreach (preg_split('//u', $texto, -1, PREG_SPLIT_NO_EMPTY) as $c) {
        if (mb_strpos($base, $c) !== false)      { $largo += 1; }
        elseif (mb_strpos($ext, $c) !== false)   { $largo += 2; }
        else { $esGsm = false; break; }
    }

    if ($esGsm) {
        return [
            'unicode' => false,
            'largo'   => $largo,
            'partes'  => $largo === 0 ? 0 : ($largo <= 160 ? 1 : (int) ceil($largo / 153)),
        ];
    }

    $largo = mb_strlen($texto, 'UTF-8');

    return [
        'unicode' => true,
        'largo'   => $largo,
        'partes'  => $largo === 0 ? 0 : ($largo <= 70 ? 1 : (int) ceil($largo / 67)),
    ];
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

/**
 * Ejecuta una consulta "... IN (...)" por tandas y devuelve los teléfonos
 * encontrados como claves de un array.
 *
 * Va por tandas a propósito: una lista de 50.000 números metida de golpe en
 * un IN pasa del tamaño máximo de consulta que admite MySQL y falla. Así
 * puede entrar una lista tan grande como quiera.
 */
function sms_buscar_en_columna($db, $sqlHastaElIn, array $telefonos, $porTanda = 500)
{
    $encontrados = [];

    foreach (array_chunk($telefonos, $porTanda) as $tanda) {
        $marcas = implode(',', array_fill(0, count($tanda), '?'));
        $st = $db->prepare($sqlHastaElIn . " ({$marcas})");
        $st->execute($tanda);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $tel) {
            $encontrados[$tel] = true;
        }
    }

    return $encontrados;
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

        case 'falsa':
            // Solo para las pruebas: no manda nada, apunta cuántas órdenes
            // ha recibido para poder contarlas después.
            $f = fopen(sys_get_temp_dir() . '/sms_falsos.log', 'a');
            if ($f) {
                flock($f, LOCK_EX);
                fwrite($f, $telefono . "\n");
                flock($f, LOCK_UN);
                fclose($f);
            }
            return ['ok' => true, 'id' => 'falso', 'error' => '', 'reintentable' => false];
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
