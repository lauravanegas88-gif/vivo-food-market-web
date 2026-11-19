<?php
// Lista de prelanzamiento de VIVO Food: recibe el quiz de /food-market/ y guarda a la persona.
//
// 1. Copia de respaldo (CSV) fuera de public_html, siempre, antes de llamar a Brevo.
//    Sin clave de Brevo en la configuración, se queda aquí (modo respaldo) y se sube después.
// 2. Brevo: crea o actualiza el contacto con sus atributos y lo mete a la lista.
// 3. Si llegó con ?ref=CODIGO de otra persona y es nuevo, le suma un referido a esa persona.
// 4. Si es nuevo, Brevo le manda la bienvenida (d0-bienvenida.html, al lado de este archivo).
// Responde JSON: {ok, codigo} o {ok:false, error}. La página solo muestra el resultado con ok:true.
//
// La clave de Brevo NO está aquí: vive en un archivo de configuración fuera de public_html
// (ver config.ejemplo.php). Este archivo se puede publicar tal cual.

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

const VERSION_AUTORIZACION = '2026-11-07';   // cambia si cambia el texto de la autorización
const PERFILES = ['casa', 'bienestar', 'origen', 'temporada'];
const MOLESTIAS = ['se_danan', 'calidad', 'siempre_lo_mismo', 'salir_seguido', 'ninguna'];

function responder(int $estado, array $cuerpo): void {
    http_response_code($estado);
    echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE);
    exit;
}

// Busca la configuración subiendo carpetas desde aquí: <algo>/privado/vivo-food.php
// En Hostinger queda en /home/uXXXX/domains/vivolive.co/privado/ (al lado de public_html, no adentro).
function cargarConfig(): array {
    $env = getenv('VIVO_FOOD_CONFIG');
    $candidatos = $env ? [$env] : [];
    $dir = __DIR__;
    for ($n = 0; $n < 5; $n++) {
        $dir = dirname($dir);
        $candidatos[] = $dir . '/privado/vivo-food.php';
    }
    foreach ($candidatos as $ruta) {
        if (is_file($ruta)) {
            $c = require $ruta;
            if (is_array($c)) { $c['_dir'] = dirname($ruta); return $c; }
        }
    }
    return [];
}

function limpiar(string $s, int $max): string {
    $s = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s) ?? '');
    return mb_substr($s, 0, $max);
}

// Código para invitar: estable por correo (el mismo correo siempre da el mismo código),
// sin letras que se confunden (0/O, 1/I/L).
function codigoDe(string $correo, string $secreto): string {
    $abc = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $h = hash_hmac('sha256', $correo, $secreto, true);
    $c = '';
    for ($k = 0; $k < 6; $k++) { $c .= $abc[ord($h[$k]) % strlen($abc)]; }
    return $c;
}

// Llamada a Brevo. Se puede reemplazar en las pruebas con $GLOBALS['brevo_transporte'].
function brevo(string $metodo, string $ruta, ?array $cuerpo, array $cfg): array {
    if (isset($GLOBALS['brevo_transporte'])) { return ($GLOBALS['brevo_transporte'])($metodo, $ruta, $cuerpo); }
    $ch = curl_init(($cfg['brevo_url'] ?? 'https://api.brevo.com/v3') . $ruta);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $metodo,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_HTTPHEADER => ['api-key: ' . $cfg['brevo_api_key'], 'accept: application/json', 'content-type: application/json'],
    ]);
    if ($cuerpo !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($cuerpo, JSON_UNESCAPED_UNICODE)); }
    $texto = curl_exec($ch);
    $estado = $texto === false ? 0 : (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$estado, $texto ? (json_decode($texto, true) ?? []) : []];
}

// Archivo de códigos: codigo -> correo, para encontrar a quien invitó. Una línea por persona.
function correoDelCodigo(string $codigo, string $archivo): ?string {
    if (!is_file($archivo)) return null;
    $f = fopen($archivo, 'r');
    while (($linea = fgets($f)) !== false) {
        [$c, $correo] = array_pad(explode("\t", rtrim($linea, "\n"), 2), 2, '');
        if ($c === $codigo) { fclose($f); return $correo; }
    }
    fclose($f);
    return null;
}

function anotar(string $archivo, string $linea): bool {
    return file_put_contents($archivo, $linea . "\n", FILE_APPEND | LOCK_EX) !== false;
}

// Freno simple: máximo de envíos por IP en una hora.
function demasiados(string $dir, string $ip, int $max): bool {
    $archivo = $dir . '/limite-' . date('YmdH') . '.txt';
    $ipHash = substr(hash('sha256', $ip), 0, 16);
    $n = 0;
    if (is_file($archivo)) {
        foreach (file($archivo, FILE_IGNORE_NEW_LINES) as $l) { if ($l === $ipHash) $n++; }
    }
    if ($n >= $max) return true;
    anotar($archivo, $ipHash);
    return false;
}

// Enlace para darse de baja: el código prueba que el correo es de quien hace clic.
function firmaBaja(string $correo, string $secreto): string {
    return substr(hash_hmac('sha256', 'baja:' . $correo, $secreto), 0, 20);
}

// Bienvenida (D0) por correo transaccional de Brevo: sin automatización en la pantalla de Brevo.
// La plantilla usa {{ contact.X }} como en Brevo; aquí se pasa como {{ params.X }}.
function bienvenida(string $correo, string $nombre, array $atributos, array $cfg): void {
    if (($cfg['bienvenida'] ?? true) === false) return;
    $plantilla = $cfg['bienvenida_html'] ?? __DIR__ . '/d0-bienvenida.html';
    if (!is_file($plantilla)) return;
    $baja = 'https://vivolive.co/food-market/api/baja.php?c=' . rawurlencode($correo) . '&t=' . firmaBaja($correo, $cfg['secreto']);
    $html = str_replace(['contact.', '{{ unsubscribe }}'], ['params.', $baja], (string)file_get_contents($plantilla));
    $params = array_intersect_key($atributos, array_flip(['NOMBRE', 'PERFIL', 'MOLESTIA', 'CODIGO_INVITAR']));
    [$estado, $cuerpo] = brevo('POST', '/smtp/email', [
        'sender' => ['name' => 'VIVO LIVE', 'email' => $cfg['remitente'] ?? 'hola@vivolive.co'],
        'to' => [['email' => $correo, 'name' => $nombre]],
        'subject' => 'Tu resultado del quiz de VIVO',
        'htmlContent' => $html, 'params' => $params, 'tags' => ['vivo-food-d0'],
    ], $cfg);
    if ($estado !== 201) anotar($cfg['_dir'] . '/errores.log', date('c') . ' bienvenida ' . $estado . ' ' . $correo . ' ' . json_encode($cuerpo));
}

function procesar(array $d, array $cfg, string $ip): array {
    if (!$cfg || empty($cfg['secreto']) || $cfg['secreto'] === 'CADENA-LARGA-AL-AZAR') {
        return [500, ['ok' => false, 'error' => 'config']];
    }
    $dir = $cfg['_dir'];
    // Sin clave de Brevo todavía: la persona queda solo en el respaldo y se sube con subir-pendientes.php.
    $soloRespaldo = empty($cfg['brevo_api_key']) || $cfg['brevo_api_key'] === 'PEGAR-AQUI-LA-CLAVE-DE-BREVO'
                    || empty($cfg['lista_id']);

    // Trampa para robots: un campo que la persona no ve. Si viene lleno, se finge éxito y no se guarda.
    if (!empty($d['sitio_web'])) return [200, ['ok' => true, 'codigo' => '']];
    if (demasiados($dir, $ip, (int)($cfg['max_por_hora'] ?? 10))) return [429, ['ok' => false, 'error' => 'espera']];

    $correo = mb_strtolower(limpiar((string)($d['correo'] ?? ''), 120));
    $nombre = limpiar((string)($d['nombre'] ?? ''), 60);
    $barrio = limpiar((string)($d['barrio'] ?? ''), 80);
    $perfil = (string)($d['perfil'] ?? '');
    $molestia = (string)($d['molestia'] ?? '');
    $dia = (int)($d['cumple_dia'] ?? 0);
    $mes = (int)($d['cumple_mes'] ?? 0);
    $ref = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($d['ref'] ?? '')) ?? '');
    $fuente = limpiar((string)($d['fuente'] ?? ''), 60);

    if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) return [400, ['ok' => false, 'error' => 'correo']];
    if ($nombre === '') return [400, ['ok' => false, 'error' => 'nombre']];
    if ($barrio === '') return [400, ['ok' => false, 'error' => 'barrio']];
    if (($d['autorizacion_datos'] ?? '') !== 'si') return [400, ['ok' => false, 'error' => 'autorizacion']];
    if (!in_array($perfil, PERFILES, true)) $perfil = 'temporada';
    if (!in_array($molestia, MOLESTIAS, true)) $molestia = 'ninguna';
    if ($dia < 1 || $dia > 31 || $mes < 1 || $mes > 12) { $dia = 0; $mes = 0; }

    $respuestas = json_decode((string)($d['respuestas'] ?? ''), true);
    $resp = [];
    foreach (['p1', 'p2', 'p3', 'p4'] as $p) {
        $v = is_array($respuestas) && isset($respuestas[$p]) && is_array($respuestas[$p]) ? $respuestas[$p] : [];
        $resp[$p] = limpiar(implode(' | ', array_map('strval', $v)), 250);
    }

    $codigo = codigoDe($correo, $cfg['secreto']);
    if ($ref === $codigo || !preg_match('/^[A-Z0-9]{6}$/', $ref)) $ref = '';
    $ahora = date('c');

    // 1. Respaldo: si Brevo falla, aquí queda y se sube después (subir-pendientes.php).
    $fila = [$ahora, $correo, $nombre, $barrio, $dia ?: '', $mes ?: '', $perfil, $molestia,
             $resp['p1'], $resp['p2'], $resp['p3'], $resp['p4'], $codigo, $ref, $fuente,
             VERSION_AUTORIZACION, substr(hash('sha256', $ip), 0, 16)];
    $csv = fopen('php://temp', 'r+');
    fputcsv($csv, $fila, ',', '"', '\\');
    rewind($csv);
    $linea = rtrim(stream_get_contents($csv), "\n");
    fclose($csv);
    $respaldo = $dir . '/lista-vivo-food.csv';
    $guardado = anotar($respaldo, $linea);

    if ($soloRespaldo) {
        if (!$guardado) return [502, ['ok' => false, 'error' => 'guardar']];
        anotar($dir . '/pendientes-brevo.txt', $correo);
        if (correoDelCodigo($codigo, $dir . '/codigos.tsv') === null) anotar($dir . '/codigos.tsv', $codigo . "\t" . $correo);
        return [200, ['ok' => true, 'codigo' => $codigo]];
    }

    // 2. Brevo. Los nombres de atributo deben existir en Brevo (ver notas-conexion.md).
    $atributos = [
        'NOMBRE' => $nombre, 'BARRIO' => $barrio,
        'PERFIL' => $perfil, 'MOLESTIA' => $molestia,
        'P1_CAMBIARIA' => $resp['p1'], 'P2_DONDE_COMPRA' => $resp['p2'],
        'P3_LE_PASA' => $resp['p3'], 'P4_PESA_MAS' => $resp['p4'],
        'CODIGO_INVITAR' => $codigo, 'FUENTE' => $fuente,
        'FECHA_REGISTRO' => date('Y-m-d'), 'AUTORIZA_DATOS' => true,
        'VERSION_AUTORIZACION' => VERSION_AUTORIZACION,
    ];
    // El disparador de aniversario de Brevo ignora el año; 2000 fue bisiesto, así cabe el 29 de febrero.
    if ($dia && checkdate($mes, $dia, 2000)) $atributos['CUMPLEANOS'] = sprintf('2000-%02d-%02d', $mes, $dia);
    if ($ref !== '') $atributos['INVITADO_POR'] = $ref;   // solo si viene: un segundo envío no lo borra

    [$estado, $cuerpo] = brevo('POST', '/contacts', [
        'email' => $correo, 'attributes' => $atributos,
        'listIds' => [(int)$cfg['lista_id']], 'updateEnabled' => true,
    ], $cfg);
    $nuevo = $estado === 201;
    $enBrevo = $estado === 201 || $estado === 204;

    if (!$enBrevo) {
        anotar($dir . '/pendientes-brevo.txt', $correo);
        anotar($dir . '/errores.log', $ahora . ' brevo ' . $estado . ' ' . json_encode($cuerpo));
        // Sin Brevo pero con respaldo: la persona sí quedó; se sube después.
        if ($guardado) return [200, ['ok' => true, 'codigo' => $codigo]];
        return [502, ['ok' => false, 'error' => 'guardar']];
    }

    // 3. Códigos y referidos. Solo cuenta si es un contacto nuevo (no se infla reenviando).
    $codigos = $dir . '/codigos.tsv';
    if (correoDelCodigo($codigo, $codigos) === null) anotar($codigos, $codigo . "\t" . $correo);
    if ($nuevo && $ref !== '') {
        $quien = correoDelCodigo($ref, $codigos);
        if ($quien !== null && $quien !== $correo) {
            [$e, $c] = brevo('GET', '/contacts/' . rawurlencode($quien), null, $cfg);
            $n = $e === 200 ? (int)($c['attributes']['REFERIDOS'] ?? 0) : 0;
            [$e2] = brevo('PUT', '/contacts/' . rawurlencode($quien), ['attributes' => ['REFERIDOS' => $n + 1]], $cfg);
            anotar($dir . '/referidos.tsv', $ahora . "\t" . $ref . "\t" . $correo . "\t" . ($e2 === 204 ? 'ok' : 'error ' . $e2));
        }
    }
    // 4. Bienvenida, solo la primera vez (repetir el quiz no la reenvía).
    if ($nuevo) bienvenida($correo, $nombre, $atributos, $cfg);
    return [200, ['ok' => true, 'codigo' => $codigo]];
}

// Solo corre como página si no lo cargan las pruebas.
if (!defined('VIVO_FOOD_PRUEBAS')) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') responder(405, ['ok' => false, 'error' => 'metodo']);
    $crudo = file_get_contents('php://input') ?: '';
    $datos = str_starts_with((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')
        ? (json_decode($crudo, true) ?: []) : $_POST;
    [$estado, $cuerpo] = procesar($datos, cargarConfig(), (string)($_SERVER['REMOTE_ADDR'] ?? ''));
    responder($estado, $cuerpo);
}
