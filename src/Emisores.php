<?php

declare(strict_types=1);

namespace FelServ\LibreDteWorker;

use RuntimeException;

/**
 * Registro de emisores: cada uno tiene un token, una carpeta y su propio certificado.
 *
 *   <base>/<rut>/emisor.json        rut, nombre, hash del token
 *   <base>/<rut>/certificado.p12    lo sube el emisor con certificado_guardar
 *   <base>/tokens/<sha256>          contiene el RUT dueño del token
 *
 * El token no se guarda: solo su hash. Con 256 bits aleatorios, SHA-256 es suficiente y
 * permite encontrar al emisor sin recorrer las carpetas.
 */
class Emisores
{
    public function __construct(private string $base)
    {
    }

    public static function normalizarRut(string $rut): string
    {
        $limpio = ltrim(strtoupper((string) preg_replace('/[^0-9kK]/', '', $rut)), '0');
        if (strlen($limpio) < 2) {
            return '';
        }
        return substr($limpio, 0, -1) . '-' . substr($limpio, -1);
    }

    public static function rutValido(string $rut): bool
    {
        if (!preg_match('/^(\d{1,8})-([\dK])$/', $rut, $m)) {
            return false;
        }
        $suma = 0;
        $factor = 2;
        for ($i = strlen($m[1]) - 1; $i >= 0; $i--) {
            $suma += (int) $m[1][$i] * $factor;
            $factor = $factor === 7 ? 2 : $factor + 1;
        }
        $dv = 11 - ($suma % 11);
        return ($dv === 11 ? '0' : ($dv === 10 ? 'K' : (string) $dv)) === $m[2];
    }

    public function activo(): bool
    {
        return $this->base !== '' && is_dir($this->base);
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    private function carpeta(string $rut): string
    {
        return $this->base . '/' . $rut;
    }

    private function directorio(string $ruta): void
    {
        if (!is_dir($ruta) && !mkdir($ruta, 0700, true) && !is_dir($ruta)) {
            throw new RuntimeException("No se pudo crear $ruta");
        }
    }

    /** @return array{rut:string, nombre:string, certificado:string}|null */
    public function porToken(string $token): ?array
    {
        if (!$this->activo() || strlen($token) < 32) {
            return null;
        }
        $indice = $this->base . '/tokens/' . $this->hash($token);
        if (!is_file($indice)) {
            return null;
        }
        $rut = trim((string) file_get_contents($indice));
        $archivo = $this->carpeta($rut) . '/emisor.json';
        if (!self::rutValido($rut) || !is_file($archivo)) {
            return null;
        }
        $datos = json_decode((string) file_get_contents($archivo), true);
        return ['rut' => $rut, 'nombre' => (string) ($datos['nombre'] ?? ''), 'certificado' => $this->carpeta($rut) . '/certificado.p12'];
    }

    /** Crea el emisor o le cambia el token. Entrega el token nuevo: no se puede volver a consultar. */
    public function crear(string $rut, string $nombre): string
    {
        $rut = self::normalizarRut($rut);
        if (!self::rutValido($rut)) {
            throw new RuntimeException('RUT inválido.');
        }
        $this->directorio($this->base . '/tokens');
        $this->directorio($this->carpeta($rut));
        $this->revocar($rut, false);

        $token = bin2hex(random_bytes(32));
        $archivo = $this->carpeta($rut) . '/emisor.json';
        file_put_contents($archivo, json_encode(['rut' => $rut, 'nombre' => $nombre, 'token_sha256' => $this->hash($token), 'creado' => date('c')], JSON_UNESCAPED_UNICODE));
        chmod($archivo, 0600);
        $indice = $this->base . '/tokens/' . $this->hash($token);
        file_put_contents($indice, $rut);
        chmod($indice, 0600);
        return $token;
    }

    /** Quita el acceso del emisor. Su certificado queda en la carpeta. */
    public function revocar(string $rut, bool $exigir = true): void
    {
        $rut = self::normalizarRut($rut);
        $archivo = $this->carpeta($rut) . '/emisor.json';
        if (!is_file($archivo)) {
            if ($exigir) {
                throw new RuntimeException("El emisor $rut no existe.");
            }
            return;
        }
        $datos = json_decode((string) file_get_contents($archivo), true);
        $hash = (string) ($datos['token_sha256'] ?? '');
        if (preg_match('/^[0-9a-f]{64}$/', $hash) && is_file($this->base . '/tokens/' . $hash)) {
            unlink($this->base . '/tokens/' . $hash);
        }
        unset($datos['token_sha256']);
        file_put_contents($archivo, json_encode($datos, JSON_UNESCAPED_UNICODE));
    }

    /** @return list<array{rut:string, nombre:string, activo:bool, certificado:bool}> */
    public function listar(): array
    {
        $lista = [];
        foreach (glob($this->base . '/*/emisor.json') ?: [] as $archivo) {
            $datos = json_decode((string) file_get_contents($archivo), true);
            $lista[] = [
                'rut' => (string) ($datos['rut'] ?? ''),
                'nombre' => (string) ($datos['nombre'] ?? ''),
                'activo' => !empty($datos['token_sha256']),
                'certificado' => is_file(dirname($archivo) . '/certificado.p12'),
            ];
        }
        return $lista;
    }
}
