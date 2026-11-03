<?php

declare(strict_types=1);

namespace FelServ\LibreDteWorker;

use DOMDocument;
use DOMXPath;
use RuntimeException;

/**
 * Consulta y solicitud de folios (CAF) en el sitio de timbraje electrónico del SII.
 *
 * El SII no tiene una API para esto: se recorre el mismo formulario web que usa una persona
 * (Timbraje electrónico → Solicitar folios), autenticándose con el certificado digital del emisor.
 * Flujo y textos de error basados en devlas-cl/dte-sii (MIT, https://github.com/devlas-cl/dte-sii).
 *
 * Si el SII cambia su sitio, esto deja de funcionar: los errores traen el texto visible de la página
 * para poder adaptarlo y, mientras, los folios se piden a mano.
 */
class SiiFolios
{
    const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0 Safari/537.36';
    const LOGIN = 'https://herculesr.sii.cl/cgi_AUT2000/CAutInicio.cgi';

    /** Respuestas del SII que impiden seguir, con el mensaje que se muestra. */
    const RECHAZOS = [
        '/superado el m[aá]ximo/i' => 'El SII indica que hay demasiadas sesiones abiertas con este certificado. Espera unos 30 minutos.',
        '/acceso restringido|recaptcha/i' => 'El SII bloqueó el acceso automático (protección del sitio). Pide los folios a mano por ahora.',
        '/no registra Verificaci[oó]n de Actividades/i' => 'El SII indica que la empresa no tiene la verificación de actividades: debe regularizarla antes de pedir folios.',
        '/NO\s+(SE\s+)?AUTORIZA\s+TIMBRAJE/i' => 'El SII no autoriza timbraje para esta empresa. Revisa su situación en el SII.',
        '/situaciones\s+pendientes\s+con\s+el\s+SII/i' => 'El SII indica que hay situaciones pendientes con el SII: deben regularizarse antes de pedir folios.',
        '/deber[aá] presentarse en la oficina del SII/i' => 'El SII pide hacer el trámite en una oficina del SII.',
        '/empresa no est[aá] autorizada para operar/i' => 'El SII indica que la empresa no está autorizada para operar como emisor electrónico.',
        '/no est[aá] autorizado para ingresar a esta opci[oó]n/i' => 'El usuario del certificado no tiene permiso para solicitar folios de esta empresa en el SII.',
    ];

    private string $host;
    private string $cookies;
    private string $certArchivo;
    private string $llaveArchivo;
    /** @var list<array<string, mixed>> */
    private array $pasos = [];

    /** Diagnóstico: guarda el texto visible y los campos de cada página (para adaptar el flujo al sitio del SII) */
    public bool $detalle = false;

    public function __construct(string $certPem, string $llavePem, bool $produccion)
    {
        $this->host = $produccion ? 'palena.sii.cl' : 'maullin.sii.cl';
        // curl necesita el certificado y la llave en archivos: viven solo durante la consulta
        $this->cookies = $this->temporal('');
        $this->certArchivo = $this->temporal($certPem);
        $this->llaveArchivo = $this->temporal($llavePem);
    }

    public function __destruct()
    {
        foreach ([$this->cookies, $this->certArchivo, $this->llaveArchivo] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
    }

    private function temporal(string $contenido): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'sii');
        chmod($ruta, 0600);
        file_put_contents($ruta, $contenido);
        return $ruta;
    }

    /** @return list<array{paso:string, url:string, codigo:int}> */
    public function pasos(): array
    {
        return $this->pasos;
    }

    /**
     * Abre la solicitud de folios hasta el paso en que el SII informa cuántos autoriza, sin pedir nada.
     * @return array{tipo:int, max_autorizado:?int, folios_disponibles:?int, tipos_habilitados:list<int>}
     */
    public function consultar(string $rut, int $tipo): array
    {
        try {
            [$pagina, , $tipos] = $this->abrirSolicitud($rut, $tipo, 1);
            $campos = $this->campos($pagina);
            return [
                'tipo' => $tipo,
                'max_autorizado' => isset($campos['MAX_AUTOR']) ? (int) $campos['MAX_AUTOR'] : null,
                'folios_disponibles' => isset($campos['FOLIOS_DISP']) ? (int) $campos['FOLIOS_DISP'] : null,
                'tipos_habilitados' => $tipos,
            ];
        } finally {
            $this->cerrarSesion();
        }
    }

    /**
     * Pide folios y devuelve el CAF. Pide como máximo lo que el SII autoriza en esta solicitud.
     * @return array{tipo:int, cantidad:int, caf:string}
     */
    public function solicitar(string $rut, int $tipo, int $cantidad): array
    {
        try {
            [$pagina, $url] = $this->abrirSolicitud($rut, $tipo, $cantidad);
            [$numero, $dv] = $this->rut($rut);
            $max = $this->campos($pagina)['MAX_AUTOR'] ?? null;
            $cantidad = max(1, $max !== null && $max !== '' ? min($cantidad, (int) $max) : $cantidad);

            // Solicitar numeración
            [$pagina, $url] = $this->enviarFormulario($pagina, $url, 'solicitar numeración', [
                'RUT_EMP' => $numero, 'DV_EMP' => $dv, 'COD_DOCTO' => (string) $tipo, 'CANT_DOCTOS' => (string) $cantidad,
                'ACEPTAR' => 'Solicitar Numeración',
            ], '/cvc_cgi/dte/of_confirma_folio');

            // Confirmar el folio inicial que propone el SII
            if (str_contains($pagina, 'of_confirma_folio')) {
                [$pagina, $url] = $this->enviarFormulario($pagina, $url, 'confirmar folio inicial', ['ACEPTAR' => 'Confirmar Folio Inicial'], '/cvc_cgi/dte/of_confirma_folio');
            }

            // Obtener folios
            if (str_contains($pagina, 'of_genera_folio')) {
                $campos = $this->campos($pagina);
                $inicial = (int) ($campos['FOLIO_INI'] ?? $campos['FOLIO_INICIAL'] ?? 0);
                $extra = ['CANT_DOCTOS' => (string) $cantidad, 'ACEPTAR' => 'Obtener Folios'];
                if ($inicial > 0) {
                    $extra['FOLIO_FIN'] = (string) ($inicial + $cantidad - 1);
                }
                [$pagina, $url] = $this->enviarFormulario($pagina, $url, 'obtener folios', $extra, '/cvc_cgi/dte/of_genera_folio');
            }

            // Descargar el archivo (el SII a veces tarda en tenerlo listo: DTE-OFGA)
            for ($intento = 0; $intento < 4 && !str_contains($pagina, '<AUTORIZACION'); $intento++) {
                if ($intento > 0) {
                    sleep(3 * $intento);
                }
                if (!str_contains($pagina, 'of_genera_archivo') && $intento === 0) {
                    break;
                }
                [$respuesta] = $this->enviarFormulario($pagina, $url, 'descargar CAF', ['ACEPTAR' => 'AQUI'], '/cvc_cgi/dte/of_genera_archivo');
                if (str_contains($respuesta, '<AUTORIZACION')) {
                    $pagina = $respuesta;
                    break;
                }
                if (!str_contains($respuesta, 'DTE-OFGA')) {
                    $pagina = $respuesta;
                    break;
                }
            }

            if (!str_contains($pagina, '<AUTORIZACION')) {
                if (preg_match('/ya fue autorizado el rango/i', $this->texto($pagina))) {
                    throw new RuntimeException('El SII indica que ese rango ya fue autorizado. Recupéralo desde «Reobtención de folios» en el SII.');
                }
                throw new RuntimeException('El SII no entregó el archivo de folios. Respuesta: ' . $this->extracto($pagina));
            }
            $inicio = strpos($pagina, '<?xml') !== false ? strpos($pagina, '<?xml') : strpos($pagina, '<AUTORIZACION');
            $fin = strrpos($pagina, '</AUTORIZACION>');
            $xml = substr($pagina, (int) $inicio, (int) $fin + strlen('</AUTORIZACION>') - (int) $inicio);

            return ['tipo' => $tipo, 'cantidad' => $cantidad, 'caf' => base64_encode($xml)];
        } finally {
            $this->cerrarSesion();
        }
    }

    /**
     * Entra con el certificado y avanza hasta la página que muestra el máximo autorizado para el tipo.
     * @return array{0:string, 1:string, 2:list<int>} página, su URL y tipos habilitados
     */
    private function abrirSolicitud(string $rut, int $tipo, int $cantidad): array
    {
        [$numero, $dv] = $this->rut($rut);
        [$pagina, $url] = $this->sesion('/cvc_cgi/dte/of_solicita_folios');

        // Paso 1: RUT de la empresa
        [$pagina, $url] = $this->enviarFormulario($pagina, $url, 'empresa', [
            'RUT_EMP' => $numero, 'DV_EMP' => $dv, 'COD_DOCTO' => (string) $tipo, 'CANTIDAD' => (string) $cantidad,
        ], '/cvc_cgi/dte/of_solicita_folios');

        if (str_contains($pagina, 'of_solicita_folios_dcto') && !$this->tieneSelect($pagina, 'COD_DOCTO')) {
            [$pagina, $url] = $this->enviarFormulario($pagina, $url, 'documentos', ['RUT_EMP' => $numero, 'DV_EMP' => $dv], '/cvc_cgi/dte/of_solicita_folios_dcto');
        }

        // Paso 2: tipo de documento (solo los que el SII habilita para la empresa)
        $tipos = [];
        if ($this->tieneSelect($pagina, 'COD_DOCTO')) {
            $tipos = $this->opciones($pagina, 'COD_DOCTO');
            if (!in_array($tipo, $tipos, true)) {
                throw new RuntimeException("El SII no habilita timbraje del tipo {$tipo} para esta empresa (habilitados: " . ($tipos ? implode(', ', $tipos) : 'ninguno') . ').');
            }
            [$pagina, $url] = $this->enviarFormulario($pagina, $url, 'tipo de documento', [
                'RUT_EMP' => $numero, 'DV_EMP' => $dv, 'COD_DOCTO' => (string) $tipo, 'CANT_DOCTOS' => '',
            ], '/cvc_cgi/dte/of_solicita_folios_dcto');
        }

        return [$pagina, $url, $tipos];
    }

    /** Abre una página del SII; si pide autenticación, entra con el certificado. @return array{0:string, 1:string} */
    private function sesion(string $ruta): array
    {
        $destino = "https://{$this->host}{$ruta}";
        [$pagina, $url] = $this->http('GET', $destino, null, 'abrir');
        if (stripos($pagina, 'Autenticaci') !== false) {
            $referencia = (string) parse_url($url, PHP_URL_QUERY);
            if ($referencia === '') {
                throw new RuntimeException('El SII pidió autenticación pero no indicó a dónde volver.');
            }
            $this->http('POST', self::LOGIN . '?' . $referencia, ['referencia' => $referencia], 'autenticación con certificado', $url);
            [$pagina, $url] = $this->http('GET', $destino, null, 'abrir con sesión');
        }
        if (stripos($pagina, 'ESCOJA COMO DESEA INGRESAR') !== false && preg_match('/<a[^>]+href="([^"]+)"[^>]*>\s*Continuar/i', $pagina, $m)) {
            [$pagina, $url] = $this->http('GET', $this->absoluta($url, html_entity_decode($m[1])), null, 'continuar');
        }
        if (stripos($pagina, 'Autenticaci') !== false) {
            throw new RuntimeException('El SII no aceptó el certificado para entrar. Revisa que esté vigente y que su titular tenga permisos en la empresa.');
        }
        $this->revisar($pagina);
        return [$pagina, $url];
    }

    /**
     * Envía el primer formulario de la página con sus campos (ocultos, de texto y listas) más los indicados.
     * @return array{0:string, 1:string}
     */
    private function enviarFormulario(string $pagina, string $url, string $paso, array $extra, string $accionPorDefecto): array
    {
        [$accion, $campos] = $this->formulario($pagina);
        $destino = $this->absoluta($url, $accion !== '' ? $accion : $accionPorDefecto);
        [$respuesta, $final] = $this->http('POST', $destino, array_merge($campos, $extra), $paso, $url);
        $this->revisar($respuesta);
        return [$respuesta, $final];
    }

    /** Detiene el flujo si el SII respondió con un rechazo conocido. */
    private function revisar(string $pagina): void
    {
        $texto = $this->texto($pagina);
        foreach (self::RECHAZOS as $patron => $mensaje) {
            if (preg_match($patron, $texto)) {
                throw new RuntimeException($mensaje);
            }
        }
    }

    /** @return array{0:string, 1:string} cuerpo (en UTF-8) y URL final tras redirecciones */
    private function http(string $metodo, string $url, ?array $campos, string $paso, ?string $referer = null): array
    {
        $ch = curl_init($url);
        $headers = ['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8', 'Accept-Language: es-CL,es;q=0.9'];
        if ($referer) {
            $headers[] = 'Referer: ' . $referer;
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $metodo,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 8,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_COOKIEFILE => $this->cookies,
            CURLOPT_COOKIEJAR => $this->cookies,
            CURLOPT_SSLCERT => $this->certArchivo,
            CURLOPT_SSLKEY => $this->llaveArchivo,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 45,
        ]);
        if ($campos !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($campos));
        }
        $cuerpo = curl_exec($ch);
        $codigo = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $final = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $error = curl_error($ch);
        curl_close($ch);

        // Se registra el camino (sin datos) para poder adaptar el flujo si el SII cambia
        $this->pasos[] = ['paso' => $paso, 'url' => (string) preg_replace('/\?.*/', '?…', $final), 'codigo' => $codigo];
        if ($cuerpo === false) {
            throw new RuntimeException("No se pudo conectar con el SII ({$paso}): {$error}");
        }
        if ($codigo === 403) {
            throw new RuntimeException('El SII bloqueó el acceso automático (protección del sitio). Pide los folios a mano por ahora.');
        }
        if ($codigo >= 500) {
            throw new RuntimeException("El SII respondió {$codigo} en «{$paso}». Inténtalo más tarde.");
        }
        $cuerpo = (string) $cuerpo;
        // El sitio de timbraje responde en ISO-8859-1; el CAF (XML) se deja tal cual
        if (!str_contains($cuerpo, '<AUTORIZACION') && !mb_check_encoding($cuerpo, 'UTF-8')) {
            $cuerpo = mb_convert_encoding($cuerpo, 'UTF-8', 'ISO-8859-1');
        }
        // Diagnóstico (solo si se pide): texto visible, campos y botones de la página, para adaptar el flujo
        if ($this->detalle && !str_contains($cuerpo, '<AUTORIZACION') && !str_contains($paso, 'autenticación')) {
            $x = $this->dom($cuerpo);
            $ultimo = array_key_last($this->pasos);
            $this->pasos[$ultimo]['texto'] = mb_substr($this->texto($cuerpo), 0, 1500);
            $this->pasos[$ultimo]['campos'] = $this->campos($cuerpo);
            $this->pasos[$ultimo]['formularios'] = array_map(fn ($f) => $f->getAttribute('action'), iterator_to_array($x->query('//form')));
            $this->pasos[$ultimo]['botones'] = array_map(fn ($b) => $b->getAttribute('name') . '=' . $b->getAttribute('value'), iterator_to_array($x->query("//input[@type='submit' or @type='button']")));
        }
        return [$cuerpo, $final];
    }

    private function cerrarSesion(): void
    {
        foreach (["https://{$this->host}/cgi_AUT2000/autLogout.cgi", 'https://herculesr.sii.cl/cgi_AUT2000/autLogout.cgi'] as $salida) {
            try {
                $this->http('GET', $salida, null, 'cerrar sesión');
            } catch (RuntimeException) {
                // cerrar la sesión es una cortesía con el SII: si falla, la sesión vence sola
            }
        }
    }

    /** @return array{0:string, 1:string} número y dígito verificador */
    private function rut(string $rut): array
    {
        $limpio = strtoupper((string) preg_replace('/[^0-9kK]/', '', $rut));
        if (strlen($limpio) < 2) {
            throw new RuntimeException('RUT inválido.');
        }
        return [substr($limpio, 0, -1), substr($limpio, -1)];
    }

    private function dom(string $pagina): DOMXPath
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $pagina);
        libxml_clear_errors();
        return new DOMXPath($doc);
    }

    /** Acción y campos del primer formulario (sin los botones, que se agregan según el paso). @return array{0:string, 1:array<string,string>} */
    private function formulario(string $pagina): array
    {
        $x = $this->dom($pagina);
        $form = $x->query('//form')->item(0);
        if ($form === null) {
            throw new RuntimeException('El SII respondió sin el formulario esperado: ' . $this->extracto($pagina));
        }
        $campos = [];
        foreach ($x->query('.//input', $form) as $input) {
            $nombre = $input->getAttribute('name');
            $tipo = strtolower($input->getAttribute('type'));
            if ($nombre === '' || in_array($tipo, ['submit', 'button', 'image', 'reset'], true)) {
                continue;
            }
            if (in_array($tipo, ['radio', 'checkbox'], true) && !$input->hasAttribute('checked')) {
                continue;
            }
            $campos[$nombre] = $input->getAttribute('value');
        }
        foreach ($x->query('.//select', $form) as $select) {
            $elegida = $x->query('.//option[@selected]', $select)->item(0) ?? $x->query('.//option', $select)->item(0);
            if ($elegida !== null && $select->getAttribute('name') !== '') {
                $campos[$select->getAttribute('name')] = $elegida->getAttribute('value');
            }
        }
        return [$form->getAttribute('action'), $campos];
    }

    /** @return array<string,string> todos los inputs de la página */
    private function campos(string $pagina): array
    {
        $campos = [];
        foreach ($this->dom($pagina)->query('//input[@name]') as $input) {
            $campos[$input->getAttribute('name')] = $input->getAttribute('value');
        }
        return $campos;
    }

    private function tieneSelect(string $pagina, string $nombre): bool
    {
        return $this->dom($pagina)->query("//select[@name='{$nombre}']")->length > 0;
    }

    /** @return list<int> */
    private function opciones(string $pagina, string $nombre): array
    {
        $tipos = [];
        foreach ($this->dom($pagina)->query("//select[@name='{$nombre}']/option") as $opcion) {
            $valor = (int) $opcion->getAttribute('value');
            if ($valor > 0) {
                $tipos[] = $valor;
            }
        }
        return $tipos;
    }

    private function absoluta(string $base, string $ruta): string
    {
        if (preg_match('#^https?://#i', $ruta)) {
            return $ruta;
        }
        $partes = parse_url($base);
        $origen = ($partes['scheme'] ?? 'https') . '://' . ($partes['host'] ?? $this->host);
        if (str_starts_with($ruta, '/')) {
            return $origen . $ruta;
        }
        $dir = rtrim(dirname($partes['path'] ?? '/'), '/');
        return "{$origen}{$dir}/{$ruta}";
    }

    private function texto(string $pagina): string
    {
        return trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($pagina), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    private function extracto(string $pagina): string
    {
        return mb_substr($this->texto($pagina), 0, 600);
    }
}
