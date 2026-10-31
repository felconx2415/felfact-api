<?php

declare(strict_types=1);

namespace FelServ\LibreDteWorker;

use Derafu\Certificate\Contract\CertificateInterface;
use DOMDocument;
use RuntimeException;

/**
 * Cliente de la API REST de boleta electrónica del SII.
 *
 * Las boletas (39/41) no se envían por el servicio SOAP de upload de DTE sino
 * por servicios REST propios, que LibreDTE Core no implementa:
 *
 *   semilla  GET  https://{api}/recursos/v1/boleta.electronica.semilla
 *   token    POST https://{api}/recursos/v1/boleta.electronica.token   (semilla firmada)
 *   envío    POST https://{envio}/recursos/v1/boleta.electronica.envio (multipart, cookie TOKEN)
 *   estado   GET  https://{api}/recursos/v1/boleta.electronica.envio/{rut}-{dv}-{trackid}
 *
 * api: apicert.sii.cl (certificación) / api.sii.cl (producción).
 * envio: pangal.sii.cl (certificación) / rahue.sii.cl (producción).
 * Documentación del SII: https://www4c.sii.cl/bolcoreinternetui/api/
 */
class SiiBoleta
{
    const HOSTS = [
        'certificacion' => ['api' => 'apicert.sii.cl', 'envio' => 'pangal.sii.cl'],
        'produccion' => ['api' => 'api.sii.cl', 'envio' => 'rahue.sii.cl'],
    ];

    // El SII exige un User-Agent "de navegador" en estos servicios
    const USER_AGENT = 'Mozilla/4.0 ( compatible; PROG 1.0; Windows NT)';

    // Vigencia que se le da al token en caché (el SII lo invalida antes si no se usa)
    const TOKEN_TTL = 1800;

    public function __construct(
        private string $ambiente,
        private CertificateInterface $certificado,
        private string $dirCache = ''
    ) {
        if (!isset(self::HOSTS[$ambiente])) {
            throw new RuntimeException('Ambiente inválido para boletas.');
        }
        $this->dirCache = $dirCache !== '' ? $dirCache : sys_get_temp_dir();
    }

    private function url(string $host, string $ruta): string
    {
        return 'https://' . self::HOSTS[$this->ambiente][$host] . '/recursos/v1/' . $ruta;
    }

    /**
     * Solicitud HTTP al SII.
     * @return array{0:int,1:string} [código HTTP, cuerpo]
     */
    private function http(string $metodo, string $url, array $headers = [], mixed $cuerpo = null): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $metodo,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => array_merge(['User-Agent: ' . self::USER_AGENT], $headers),
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
        ]);
        if ($cuerpo !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $cuerpo);
        }
        $respuesta = curl_exec($ch);
        $codigo = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        unset($ch);
        if ($respuesta === false) {
            throw new RuntimeException("No hay conexión con el SII ($url): $error");
        }

        return [$codigo, (string) $respuesta];
    }

    /** Valor de una etiqueta en las respuestas XML del SII (con o sin prefijo SII:). */
    private static function etiqueta(string $xml, string $nombre): ?string
    {
        return preg_match('#<(?:SII:)?' . $nombre . '>\s*([^<]*?)\s*</(?:SII:)?' . $nombre . '>#', $xml, $m) ? $m[1] : null;
    }

    /**
     * Firma la semilla en el formato del manual de autenticación del SII:
     * <getToken><item><Semilla/></item><Signature/></getToken>, firma envolvente
     * sobre todo el documento (URI=""), C14N, RSA-SHA1, con RSAKeyValue y
     * X509Certificate.
     */
    public function firmarSemilla(string $semilla): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $raiz = $doc->appendChild($doc->createElement('getToken'));
        $item = $raiz->appendChild($doc->createElement('item'));
        $item->appendChild($doc->createElement('Semilla', $semilla));

        // Digest del documento sin la firma (transformación enveloped-signature)
        $digest = base64_encode(sha1($raiz->C14N(), true));

        $ds = 'http://www.w3.org/2000/09/xmldsig#';
        $firma = $raiz->appendChild($doc->createElementNS($ds, 'Signature'));
        $signedInfo = $firma->appendChild($doc->createElementNS($ds, 'SignedInfo'));
        $signedInfo->appendChild($doc->createElementNS($ds, 'CanonicalizationMethod'))->setAttribute('Algorithm', 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315');
        $signedInfo->appendChild($doc->createElementNS($ds, 'SignatureMethod'))->setAttribute('Algorithm', $ds . 'rsa-sha1');
        $ref = $signedInfo->appendChild($doc->createElementNS($ds, 'Reference'));
        $ref->setAttribute('URI', '');
        $ref->appendChild($doc->createElementNS($ds, 'Transforms'))
            ->appendChild($doc->createElementNS($ds, 'Transform'))->setAttribute('Algorithm', $ds . 'enveloped-signature');
        $ref->appendChild($doc->createElementNS($ds, 'DigestMethod'))->setAttribute('Algorithm', $ds . 'sha1');
        $ref->appendChild($doc->createElementNS($ds, 'DigestValue', $digest));

        $llave = openssl_pkey_get_private($this->certificado->getPrivateKey());
        if ($llave === false || !openssl_sign($signedInfo->C14N(), $valor, $llave, OPENSSL_ALGO_SHA1)) {
            throw new RuntimeException('No se pudo firmar la semilla con el certificado.');
        }
        $firma->appendChild($doc->createElementNS($ds, 'SignatureValue', base64_encode($valor)));

        $detalles = openssl_pkey_get_details($llave);
        $keyInfo = $firma->appendChild($doc->createElementNS($ds, 'KeyInfo'));
        $rsa = $keyInfo->appendChild($doc->createElementNS($ds, 'KeyValue'))->appendChild($doc->createElementNS($ds, 'RSAKeyValue'));
        $rsa->appendChild($doc->createElementNS($ds, 'Modulus', base64_encode($detalles['rsa']['n'])));
        $rsa->appendChild($doc->createElementNS($ds, 'Exponent', base64_encode($detalles['rsa']['e'])));
        $pem = $this->certificado->getCertificate();
        $der = preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem);
        $keyInfo->appendChild($doc->createElementNS($ds, 'X509Data'))->appendChild($doc->createElementNS($ds, 'X509Certificate', $der));

        return $doc->saveXML();
    }

    private function archivoToken(): string
    {
        return $this->dirCache . '/libredte-boleta-token-' . $this->ambiente . '-' . sha1($this->certificado->getId() . $this->certificado->getTo());
    }

    /** Token de autenticación (en caché mientras esté vigente). */
    public function token(bool $renovar = false): string
    {
        $archivo = $this->archivoToken();
        if (!$renovar && is_file($archivo) && filemtime($archivo) > time() - self::TOKEN_TTL) {
            $token = trim((string) file_get_contents($archivo));
            if ($token !== '') {
                return $token;
            }
        }

        [$codigo, $xml] = $this->http('GET', $this->url('api', 'boleta.electronica.semilla'), ['Accept: application/xml']);
        $semilla = self::etiqueta($xml, 'SEMILLA');
        if ($codigo !== 200 || self::etiqueta($xml, 'ESTADO') !== '00' || !$semilla) {
            throw new RuntimeException("El SII no entregó la semilla para boletas (HTTP $codigo).");
        }

        [$codigo, $xml] = $this->http('POST', $this->url('api', 'boleta.electronica.token'), ['Content-Type: application/xml', 'Accept: application/xml'], $this->firmarSemilla($semilla));
        $token = self::etiqueta($xml, 'TOKEN');
        if ($codigo !== 200 || self::etiqueta($xml, 'ESTADO') !== '00' || !$token) {
            $glosa = self::etiqueta($xml, 'GLOSA') ?? "HTTP $codigo";
            throw new RuntimeException('El SII rechazó la autenticación para boletas: ' . self::utf8($glosa));
        }

        file_put_contents($archivo, $token);
        @chmod($archivo, 0600);

        return $token;
    }

    private static function utf8(string $s): string
    {
        return mb_check_encoding($s, 'UTF-8') ? $s : mb_convert_encoding($s, 'UTF-8', 'ISO-8859-1');
    }

    /** Separa un RUT 12345678-9 en número y DV. */
    private static function rut(string $rut): array
    {
        $rut = strtoupper(str_replace('.', '', trim($rut)));
        if (!preg_match('/^(\d{1,8})-([\dK])$/', $rut, $m)) {
            throw new RuntimeException("RUT inválido: $rut");
        }

        return [$m[1], $m[2]];
    }

    /** Solicitud autenticada; si el token venció, se renueva una vez. */
    private function autenticada(string $metodo, string $url, mixed $cuerpo = null): array
    {
        foreach ([false, true] as $renovar) {
            [$codigo, $respuesta] = $this->http($metodo, $url, ['Cookie: TOKEN=' . $this->token($renovar), 'Accept: application/json'], $cuerpo);
            if ($codigo !== 401 && $codigo !== 403) {
                break;
            }
        }

        return [$codigo, $respuesta];
    }

    /** Sube el EnvioBOLETA y entrega el Track ID. */
    public function enviar(string $rutEmisor, string $xml): int
    {
        [$rutEnvia, $dvEnvia] = self::rut($this->certificado->getId());
        [$rutEmp, $dvEmp] = self::rut($rutEmisor);
        $tmp = tempnam($this->dirCache, 'envio-boleta-');
        file_put_contents($tmp, $xml);
        try {
            [$codigo, $respuesta] = $this->autenticada('POST', $this->url('envio', 'boleta.electronica.envio'), [
                'rutSender' => $rutEnvia,
                'dvSender' => $dvEnvia,
                'rutCompany' => $rutEmp,
                'dvCompany' => $dvEmp,
                'archivo' => new \CURLFile($tmp, 'application/xml', 'envio_boleta.xml'),
            ]);
        } finally {
            @unlink($tmp);
        }
        $json = json_decode($respuesta, true);
        $trackId = (int) ($json['trackid'] ?? 0);
        if ($codigo !== 200 || !$trackId) {
            $detalle = is_array($json) ? json_encode($json, JSON_UNESCAPED_UNICODE) : mb_substr(strip_tags(self::utf8($respuesta)), 0, 300);
            throw new RuntimeException("El SII no aceptó el envío de la boleta (HTTP $codigo): $detalle");
        }

        return $trackId;
    }

    /**
     * Estado del envío. Si el SII ya lo procesó (EPR), el estado se ajusta según
     * la estadística: RCH si hay boletas rechazadas, RPR si hay reparos.
     * @return array{estado:string, revision:string, detalle:?string}
     */
    public function estado(string $rutEmisor, int $trackId): array
    {
        [$rut, $dv] = self::rut($rutEmisor);
        [$codigo, $respuesta] = $this->autenticada('GET', $this->url('api', "boleta.electronica.envio/$rut-$dv-$trackId"));
        $json = json_decode($respuesta, true);
        if ($codigo !== 200 || !is_array($json)) {
            throw new RuntimeException("El SII no entregó el estado del envío $trackId (HTTP $codigo).");
        }

        $estado = strtoupper((string) ($json['estado'] ?? ''));
        $aceptados = $rechazados = $reparos = 0;
        foreach ((array) ($json['estadistica'] ?? []) as $e) {
            $aceptados += (int) ($e['aceptados'] ?? 0);
            $rechazados += (int) ($e['rechazados'] ?? 0);
            $reparos += (int) ($e['reparos'] ?? 0);
        }
        if ($estado === 'EPR' && $rechazados > 0) {
            $estado = 'RCH';
        } elseif ($estado === 'EPR' && $reparos > 0) {
            $estado = 'RPR';
        }

        $detalles = [];
        foreach ((array) ($json['detalle_rep_rech'] ?? []) as $d) {
            foreach ((array) ($d['error'] ?? []) as $err) {
                $detalles[] = trim(($err['descripcion'] ?? '') . ' ' . ($err['detalle'] ?? ''));
            }
        }

        $revision = trim(($json['estado'] ?? '') . " · aceptadas $aceptados, rechazadas $rechazados, con reparos $reparos");

        return [
            'estado' => $estado ?: 'DESCONOCIDO',
            'revision' => $revision,
            'detalle' => $detalles ? self::utf8(implode(' | ', array_filter($detalles))) : null,
        ];
    }
}
