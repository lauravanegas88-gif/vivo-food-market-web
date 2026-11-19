<?php
// Conteo de la lista del quiz de VIVO Food (Laura, 2026-11-08): "que me puedas decir cuántas personas hay
// cuando yo te pregunte". Usa la clave de Brevo que ya está en privado/vivo-food.php (no la mueve ni la
// muestra) y devuelve SOLO números: nunca correos ni nombres. Solo le contesta al servidor de la oficina.
// Va en public_html/food-market/conteo/ (por Git, carpeta propia). Lo lee scripts/vivo-lista.sh.
// El número es interno: nunca va en público (memoria vivo-food-cupos-no-publicos).

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex');

const PERMITIDAS = ['2.24.194.177'];   // el VPS de la oficina (docs/servidor.md)
const LISTA_POR_DEFECTO = 5;           // "VIVO Food · prelanzamiento"

function responder(int $estado, array $cuerpo): void {
    http_response_code($estado);
    echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', PERMITIDAS, true)) responder(403, ['ok' => false]);

// Misma búsqueda que api/lista.php: <algo>/privado/vivo-food.php, subiendo carpetas.
$cfg = [];
$dir = __DIR__;
for ($n = 0; $n < 5 && !$cfg; $n++) {
    $dir = dirname($dir);
    $ruta = $dir . '/privado/vivo-food.php';
    if (is_file($ruta)) { $c = require $ruta; if (is_array($c)) $cfg = $c; }
}
$clave = $cfg['brevo_api_key'] ?? '';
if ($clave === '' || $clave === 'PEGAR-AQUI-LA-CLAVE-DE-BREVO') responder(500, ['ok' => false, 'error' => 'config']);
$lista = (int)($cfg['lista_id'] ?? LISTA_POR_DEFECTO) ?: LISTA_POR_DEFECTO;

function brevo(string $ruta, string $clave): array {
    $ch = curl_init('https://api.brevo.com/v3' . $ruta);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['api-key: ' . $clave, 'accept: application/json'],
    ]);
    $txt = curl_exec($ch);
    $estado = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$estado, is_string($txt) ? (json_decode($txt, true) ?: []) : []];
}

// Correos de prueba del quiz (6 y 7 nov): no cuentan como personas.
function esPrueba(string $c): bool {
    $c = strtolower($c);
    return str_starts_with($c, 'lauravanegas88') || str_ends_with($c, '@vivolive.co')
        || str_contains($c, 'prueba') || str_contains($c, '@example.');
}

$hoy = (new DateTime('now', new DateTimeZone('America/Bogota')))->format('Y-m-d');
$total = 0; $pruebas = 0; $hoyN = 0; $offset = 0;
do {
    [$estado, $r] = brevo("/contacts/lists/$lista/contacts?limit=500&offset=$offset", $clave);
    if ($estado !== 200) responder(502, ['ok' => false, 'error' => 'brevo', 'estado' => $estado]);
    $contactos = $r['contacts'] ?? [];
    foreach ($contactos as $p) {
        $total++;
        if (esPrueba((string)($p['email'] ?? ''))) { $pruebas++; continue; }
        $alta = $p['createdAt'] ?? '';
        if ($alta !== '') {
            $d = (new DateTime($alta))->setTimezone(new DateTimeZone('America/Bogota'))->format('Y-m-d');
            if ($d === $hoy) $hoyN++;
        }
    }
    $offset += 500;
} while (count($contactos) === 500 && $offset < 20000);

responder(200, ['ok' => true, 'lista' => $lista, 'total' => $total, 'pruebas' => $pruebas,
    'personas' => $total - $pruebas, 'hoy' => $hoyN, 'fecha' => $hoy]);
