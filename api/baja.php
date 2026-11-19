<?php
// Darse de baja de los correos de VIVO Food (el enlace del pie de la bienvenida).
//
// GET  muestra un botón para confirmar; no da de baja todavía (los antivirus del correo abren los enlaces solos).
// POST revisa que el código sea de ese correo y lo bloquea en Brevo (emailBlacklisted): no le llega nada más.
// Usa la misma configuración y la misma conexión a Brevo que lista.php.

declare(strict_types=1);
if (!defined('VIVO_FOOD_PRUEBAS')) define('VIVO_FOOD_PRUEBAS', true);   // carga las funciones de lista.php sin correr su página
require_once __DIR__ . '/lista.php';

function procesarBaja(string $correo, string $firma, array $cfg): string {
    $correo = mb_strtolower(trim($correo));
    if ($correo === '' || empty($cfg['secreto']) || !hash_equals(firmaBaja($correo, $cfg['secreto']), $firma)) return 'invalido';
    [$estado] = brevo('PUT', '/contacts/' . rawurlencode($correo), ['emailBlacklisted' => true], $cfg);
    if ($estado === 204) return 'hecho';
    anotar($cfg['_dir'] . '/errores.log', date('c') . ' baja ' . $estado . ' ' . $correo);
    return 'error';
}

function paginaBaja(string $titulo, string $texto, string $formulario = ''): void {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex"><title>VIVO · ' . htmlspecialchars($titulo) . '</title>'
        . '<style>body{margin:0;background:#FBF6EA;color:#2D2A26;font-family:Arial,Helvetica,sans-serif}'
        . 'main{max-width:440px;margin:15vh auto;padding:0 20px;text-align:center}h1{font-size:24px;color:#5F6638}'
        . 'p{font-size:16px;line-height:1.5}button{background:#5F6638;color:#fff;border:0;border-radius:24px;padding:12px 28px;font-size:16px;cursor:pointer}</style>'
        . '</head><body><main><h1>' . htmlspecialchars($titulo) . '</h1><p>' . $texto . '</p>' . $formulario . '</main></body></html>';
    exit;
}

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'baja.php') {
    $correo = (string)($_REQUEST['c'] ?? '');
    $firma = (string)($_REQUEST['t'] ?? '');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $r = procesarBaja($correo, $firma, cargarConfig());
        if ($r === 'hecho') paginaBaja('Listo', 'No te volverán a llegar correos de VIVO Food. Si fue un error, escríbenos a hola@vivolive.co.');
        if ($r === 'invalido') paginaBaja('Enlace no válido', 'Usa el enlace del pie de tu correo o escríbenos a hola@vivolive.co.');
        paginaBaja('No pudimos hacerlo', 'Inténtalo de nuevo en un rato o escríbenos a hola@vivolive.co.');
    }
    paginaBaja('¿Te das de baja?', 'Dejarán de llegarte los correos de VIVO Food a <b>' . htmlspecialchars($correo) . '</b>.',
        '<form method="post"><input type="hidden" name="c" value="' . htmlspecialchars($correo) . '">'
        . '<input type="hidden" name="t" value="' . htmlspecialchars($firma) . '"><button>Sí, darme de baja</button></form>');
}
