<?php

declare(strict_types=1);

/**
 * FelFact API: emite documentos tributarios electrónicos en Chile, sobre LibreDTE.
 *
 * POST /v1/<accion> con cuerpo JSON y cabecera X-Api-Token.
 * Responde {"ok": true, ...} o {"ok": false, "error": "..."}.
 * Acciones: caf_info, generar, enviar, estado, probar.
 */

date_default_timezone_set('America/Santiago');
ini_set('display_errors', '0');

require dirname(__DIR__) . '/vendor/autoload.php';

use FelServ\LibreDteWorker\Acciones;
use FelServ\LibreDteWorker\Emisores;

function responder(int $codigo, array $respuesta): never
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($respuesta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

set_error_handler(function (int $no, string $msg, string $file, int $line): bool {
    if (!(error_reporting() & $no)) {
        return false;
    }
    throw new ErrorException($msg, 0, $no, $file, $line);
});

// Salud (sin token): solo indica que la API está arriba.
$ruta = trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
if ($ruta === 'salud' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    responder(200, ['ok' => true]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    responder(405, ['ok' => false, 'error' => 'Método no permitido.']);
}

// Dos formas de entrar: el token único de la instalación, con su certificado, o el token de un
// emisor, que solo puede firmar con su propio certificado y por su propio RUT.
$token = (string) getenv('LIBREDTE_API_TOKEN');
$enviado = (string) ($_SERVER['HTTP_X_API_TOKEN'] ?? '');
$emisores = new Emisores((string) getenv('FELFACT_EMISORES'));
if (strlen($token) < 32 && !$emisores->activo()) {
    responder(500, ['ok' => false, 'error' => 'La API no tiene token configurado.']);
}
$emisor = null;
if (strlen($token) < 32 || !hash_equals($token, $enviado)) {
    $emisor = $emisores->porToken($enviado);
    if ($emisor === null) {
        responder(401, ['ok' => false, 'error' => 'Token inválido.']);
    }
}

if (!preg_match('#^v1/([a-z_]+)$#', $ruta, $m)) {
    responder(404, ['ok' => false, 'error' => 'Ruta no encontrada.']);
}

$cuerpo = file_get_contents('php://input', false, null, 0, 4 * 1024 * 1024);
$in = json_decode((string) $cuerpo, true);
if (!is_array($in)) {
    responder(400, ['ok' => false, 'error' => 'El cuerpo debe ser JSON.']);
}

try {
    $acciones = new Acciones(
        $emisor['certificado'] ?? (string) getenv('LIBREDTE_CERTIFICADO'),
        $emisor === null ? (string) getenv('LIBREDTE_CERTIFICADO_CLAVE') : '',
        (string) getenv('LIBREDTE_CLAVE_MAESTRA'),
        $emisor['rut'] ?? null
    );
    responder(200, ['ok' => true] + $acciones->ejecutar($m[1], $in));
} catch (Throwable $e) {
    // Mensaje legible, sin traza (puede incluir rutas o datos).
    error_log('libredte_api ' . $m[1] . ': ' . get_class($e) . ': ' . $e->getMessage());
    responder(422, ['ok' => false, 'error' => trim($e->getMessage())]);
}
