<?php

declare(strict_types=1);

/**
 * Administra los emisores. Se ejecuta en el servidor, con el mismo usuario de la API:
 *
 *   php bin/emisor.php crear <rut> "<nombre>"   crea el emisor o cambia su token
 *   php bin/emisor.php revocar <rut>            quita su acceso
 *   php bin/emisor.php listar
 */

require dirname(__DIR__) . '/src/Emisores.php';

use FelServ\LibreDteWorker\Emisores;

$base = (string) getenv('FELFACT_EMISORES');
if ($base === '') {
    fwrite(STDERR, "Define FELFACT_EMISORES con la carpeta de los emisores.\n");
    exit(1);
}
if (!is_dir($base) && !mkdir($base, 0700, true)) {
    fwrite(STDERR, "No se pudo crear $base\n");
    exit(1);
}

$emisores = new Emisores($base);
[, $orden, $rut, $nombre] = $argv + [null, '', '', ''];

try {
    switch ($orden) {
        case 'crear':
            echo $emisores->crear($rut, $nombre) . "\n";
            fwrite(STDERR, "Guarda este token: no se puede volver a consultar.\n");
            break;
        case 'revocar':
            $emisores->revocar($rut);
            echo "Acceso revocado.\n";
            break;
        case 'listar':
            foreach ($emisores->listar() as $e) {
                printf("%-12s %-8s %-14s %s\n", $e['rut'], $e['activo'] ? 'activo' : 'revocado', $e['certificado'] ? 'con certificado' : 'sin certificado', $e['nombre']);
            }
            break;
        default:
            fwrite(STDERR, "Uso: php bin/emisor.php crear|revocar|listar [rut] [nombre]\n");
            exit(1);
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
