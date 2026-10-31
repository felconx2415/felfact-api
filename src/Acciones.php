<?php

declare(strict_types=1);

namespace FelServ\LibreDteWorker;

use Derafu\Certificate\Contract\CertificateInterface;
use Derafu\Certificate\Service\CertificateLoader;
use Derafu\Xml\XmlDocument;
use libredte\lib\Core\Application;
use libredte\lib\Core\Package\Billing\Component\Document\Support\DocumentEnvelope;
use libredte\lib\Core\Package\Billing\Component\Exchange\Enum\TipoDocumentoRespuesta;
use libredte\lib\Core\Package\Billing\Component\Exchange\Support\ExchangeDocumentBag;
use libredte\lib\Core\Package\Billing\Component\Integration\Enum\SiiEnvironment;
use libredte\lib\Core\Package\Billing\Component\Integration\Support\SiiRequest;
use libredte\lib\Core\Package\Billing\Component\TradingParties\Entity\AutorizacionDte;
use libredte\lib\Core\Package\Billing\Component\TradingParties\Entity\Receptor;
use RuntimeException;

/**
 * Acciones de FelFact API.
 *
 * El certificado digital vive solo en este servidor: la API lo valida y lo
 * guarda junto a su clave cifrada con la llave maestra del .env. El cliente envía
 * en cada solicitud el contenido del CAF, del documento o del sobre, en base64
 * cuando es un archivo.
 */
class Acciones
{
    const RUT_SII = '60803000-K';

    private ?CertificateInterface $certificado = null;

    /**
     * @param string $certificadoRuta Ruta del .p12 (el directorio debe ser escribible para guardarlo).
     * @param string $claveEnv Clave del certificado por variable de entorno (si no se subió por la API).
     * @param string $claveMaestra Llave para cifrar la clave del certificado guardada en disco.
     */
    public function __construct(
        private string $certificadoRuta,
        private string $claveEnv,
        private string $claveMaestra
    ) {
    }

    private function rutaClave(): string
    {
        return dirname($this->certificadoRuta) . '/clave.enc';
    }

    private function llave(): string
    {
        if (strlen($this->claveMaestra) < 32) {
            throw new RuntimeException('La API no tiene llave maestra configurada (LIBREDTE_CLAVE_MAESTRA).');
        }
        return sodium_crypto_generichash($this->claveMaestra, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    /** Clave del certificado: la guardada (cifrada) o, si no hay, la del .env. */
    private function claveCertificado(): string
    {
        $archivo = $this->rutaClave();
        if (!is_file($archivo)) {
            return $this->claveEnv;
        }
        $datos = base64_decode((string) file_get_contents($archivo), true);
        $nonce = substr((string) $datos, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $clave = sodium_crypto_secretbox_open(substr((string) $datos, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $this->llave());
        if ($clave === false) {
            throw new RuntimeException('No se pudo descifrar la clave del certificado (¿cambió LIBREDTE_CLAVE_MAESTRA?). Vuelve a subir el certificado.');
        }
        return $clave;
    }

    /** Escribe un archivo de forma atómica, legible solo por el usuario del contenedor. */
    private function escribir(string $ruta, string $contenido): void
    {
        $tmp = $ruta . '.tmp-' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $contenido) === false) {
            throw new RuntimeException('No se pudo guardar en el directorio del certificado (¿es escribible?).');
        }
        chmod($tmp, 0600);
        rename($tmp, $ruta);
    }

    private function infoCertificado(CertificateInterface $c): array
    {
        return [
            'rut' => $c->getId(),
            'nombre' => $c->getName(),
            'email' => $c->getEmail(),
            'desde' => $c->getFrom(),
            'vence' => $c->getTo(),
            'vigente' => $c->isActive(),
            'dias' => (int) floor((strtotime($c->getTo()) - time()) / 86400),
        ];
    }

    /** Ejecuta una acción y entrega su resultado. Lanza RuntimeException con un mensaje legible si falla. */
    public function ejecutar(string $accion, array $in): array
    {
        return match ($accion) {
            'caf_info' => $this->cafInfo($in),
            'generar' => $this->generar($in),
            'generar_lote' => $this->generarLote($in),
            'generar_libro' => $this->generarLibro($in),
            'pdf' => $this->pdf($in),
            'intercambio' => $this->intercambio($in),
            'enviar' => $this->enviar($in),
            'estado' => $this->estado($in),
            'probar' => $this->probar($in),
            'certificado_info' => $this->certificadoInfo(),
            'certificado_guardar' => $this->certificadoGuardar($in),
            default => throw new RuntimeException('Acción desconocida.'),
        };
    }

    /** Datos del certificado instalado (sin conectarse al SII). */
    private function certificadoInfo(): array
    {
        if (!is_file($this->certificadoRuta)) {
            return ['instalado' => false];
        }
        try {
            return ['instalado' => true, 'certificado' => $this->infoCertificado($this->certificado())];
        } catch (\Throwable $e) {
            return ['instalado' => true, 'error' => $e->getMessage()];
        }
    }

    /** Valida y guarda el certificado subido. El anterior queda como respaldo. */
    private function certificadoGuardar(array $in): array
    {
        $p12 = $this->archivo($in, 'p12');
        $clave = (string) ($in['clave'] ?? '');
        try {
            $c = (new CertificateLoader())->loadFromData($p12, $clave);
        } catch (\Throwable $e) {
            throw new RuntimeException('No se pudo abrir el certificado: revisa que sea el archivo .p12/.pfx correcto y su clave.');
        }
        if (!$c->isActive()) {
            throw new RuntimeException('El certificado no está vigente (válido del ' . $c->getFrom() . ' al ' . $c->getTo() . ').');
        }

        // Cifrar la clave antes de tocar nada: si falta la llave maestra, no se reemplaza el certificado actual.
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cifrada = base64_encode($nonce . sodium_crypto_secretbox($clave, $nonce, $this->llave()));

        if (is_file($this->certificadoRuta)) {
            @copy($this->certificadoRuta, $this->certificadoRuta . '.anterior');
            if (is_file($this->rutaClave())) {
                @copy($this->rutaClave(), $this->rutaClave() . '.anterior');
            }
        }
        $this->escribir($this->certificadoRuta, $p12);
        $this->escribir($this->rutaClave(), $cifrada);
        $this->certificado = null;

        return ['certificado' => $this->infoCertificado($c)];
    }

    private function billing()
    {
        return Application::getInstance('prod', false)->getPackageRegistry()->getBillingPackage();
    }

    private function ambiente(array $in): SiiEnvironment
    {
        return match ($in['ambiente'] ?? '') {
            'certificacion' => SiiEnvironment::STAGING,
            'produccion' => SiiEnvironment::PRODUCTION,
            default => throw new RuntimeException('Ambiente inválido: use certificacion o produccion.'),
        };
    }

    private function certificado(): CertificateInterface
    {
        if ($this->certificado === null) {
            if ($this->certificadoRuta === '' || !is_readable($this->certificadoRuta)) {
                throw new RuntimeException('La API no tiene certificado digital: súbelo con certificado_guardar.');
            }
            $this->certificado = (new CertificateLoader())->loadFromFile($this->certificadoRuta, $this->claveCertificado());
        }

        return $this->certificado;
    }

    /** Contenido de un archivo enviado en base64. */
    private function archivo(array $in, string $clave): string
    {
        $contenido = base64_decode((string) ($in[$clave] ?? ''), true);
        if ($contenido === false || $contenido === '') {
            throw new RuntimeException("Falta el archivo {$clave} (base64).");
        }

        return $contenido;
    }

    private function sii(): object
    {
        return $this->billing()->getIntegrationComponent()->getSiiDteWorker();
    }

    /** Datos de un CAF, para validarlo al subirlo desde el panel. */
    private function cafInfo(array $in): array
    {
        $caf = $this->billing()->getIdentifierComponent()->getCafLoaderWorker()->load($this->archivo($in, 'caf'));
        $emisor = $caf->getEmisor();
        $env = $caf->getEnvironment();

        return [
            'tipo' => $caf->getTipoDocumento(),
            'desde' => $caf->getFolioDesde(),
            'hasta' => $caf->getFolioHasta(),
            'rut' => $emisor['rut'] ?? null,
            'razon_social' => $emisor['razon_social'] ?? null,
            'fecha_autorizacion' => $caf->getFechaAutorizacion(),
            'fecha_vencimiento' => $caf->getFechaVencimiento(),
            'vigente' => $caf->isVigente(),
            'ambiente' => $env === null ? null : ($env->isStaging() ? 'certificacion' : 'produccion'),
        ];
    }

    private function resolucion(array $in): array
    {
        $resolucion = $in['resolucion'] ?? [];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($resolucion['fecha'] ?? ''))) {
            throw new RuntimeException('Falta la fecha de resolución del emisor (AAAA-MM-DD).');
        }
        return ['fecha' => (string) $resolucion['fecha'], 'numero' => (int) ($resolucion['numero'] ?? 0)];
    }

    private function certificadoVigente(): CertificateInterface
    {
        $certificate = $this->certificado();
        if (!$certificate->isActive()) {
            throw new RuntimeException('El certificado digital no está vigente (vence ' . $certificate->getTo() . ').');
        }
        return $certificate;
    }

    /** Genera, timbra y firma un DTE con su CAF (contenido XML del CAF). */
    private function emitirDocumento(array $data, string $cafXml, CertificateInterface $certificate)
    {
        $caf = $this->billing()->getIdentifierComponent()->getCafLoaderWorker()->load($cafXml);
        $tipo = (int) ($data['Encabezado']['IdDoc']['TipoDTE'] ?? 0);
        $folio = (int) ($data['Encabezado']['IdDoc']['Folio'] ?? 0);
        if ($caf->getTipoDocumento() !== $tipo) {
            throw new RuntimeException("El CAF es de tipo {$caf->getTipoDocumento()} y el documento es de tipo {$tipo}.");
        }
        if (!$caf->isEnRango($folio)) {
            throw new RuntimeException("El folio {$folio} no está en el rango del CAF ({$caf->getFolioDesde()} a {$caf->getFolioHasta()}).");
        }

        return $this->billing()->getDocumentComponent()->bill($data, $caf, $certificate);
    }

    /**
     * Arma, firma y valida (XSD) el sobre con uno o más documentos. El receptor
     * de la carátula es siempre el SII. Boletas van en EnvioBOLETA y el resto en
     * EnvioDTE: no se pueden mezclar en un mismo sobre.
     */
    private function armarSobre(array $bags, array $resolucion, CertificateInterface $certificate): string
    {
        $documentComponent = $this->billing()->getDocumentComponent();
        $envelope = new DocumentEnvelope();
        foreach ($bags as $bag) {
            $documentComponent->getDocumentBagManagerWorker()->normalize($bag, true);
            $bag->getEmisor()->setAutorizacionDte(new AutorizacionDte($resolucion['fecha'], $resolucion['numero']));
            $envelope->addDocument($bag);
        }
        $envelope->setCertificate($certificate)->setReceptor(new Receptor(self::RUT_SII));
        $dispatcher = $documentComponent->getDispatcherWorker();
        $dispatcher->normalize($envelope);
        $dispatcher->validateSchema($envelope);

        return $envelope->getXmlDocument()->saveXml();
    }

    /**
     * PDF de un DTE ya emitido (desde su XML): copia tributaria y, si se pide y el tipo lo admite
     * (facturas, guías), copia cedible con el acuse de recibo. Sirve para las muestras impresas.
     * Entrada: dte_xml (base64), resolucion, cedible (bool). No firma ni envía nada.
     */
    private function pdf(array $in): array
    {
        $xml = $this->archivo($in, 'dte_xml');
        $component = $this->billing()->getDocumentComponent();
        $bag = $component->getLoaderWorker()->loadXml($xml);
        $resolucion = $this->resolucion($in);
        $bag->getEmisor()?->setAutorizacionDte(new AutorizacionDte($resolucion['fecha'], $resolucion['numero']));
        $pdfs = [];
        foreach ($this->renderizar($bag, $in, !empty($in['cedible']), ($in['formato'] ?? '') === 'html') as $label => $contenido) {
            $pdfs[$label] = base64_encode($contenido);
        }
        return ['pdfs' => $pdfs];
    }

    /**
     * Intercambio de información (etapa de certificación y recepción de DTE de proveedores).
     * Lee un EnvioDTE recibido y, si se pide (generar), arma y firma las tres respuestas:
     *   - recepcion: RespuestaDTE con RecepcionEnvio (acuse de recibo del envío y de cada DTE)
     *   - resultado: RespuestaDTE con ResultadoDTE (aprobación o rechazo comercial)
     *   - recibos:   EnvioRecibos (recibo de mercaderías o servicios, Ley 19.983) de los aceptados
     * Entrada: envio_xml (base64), rut (receptor, nosotros), nombre_archivo, generar (bool),
     * decisiones ["T33F1" => "aceptar"|"rechazar"], contacto {nombre, email, fono}, recinto, id_respuesta.
     */
    private function intercambio(array $in): array
    {
        $xml = $this->archivo($in, 'envio_xml');
        $rut = strtoupper(str_replace('.', '', trim((string) ($in['rut'] ?? ''))));
        if (!preg_match('/^\d{1,8}-[\dK]$/', $rut)) {
            throw new RuntimeException('Falta el RUT del receptor (el tuyo).');
        }
        $dom = new \DOMDocument();
        if (!@$dom->loadXML($xml)) {
            throw new RuntimeException('El archivo no es un XML válido.');
        }
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('s', 'http://www.sii.cl/SiiDte');
        $xp->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
        $txt = fn (string $q, ?\DOMNode $ctx = null) => trim((string) ($xp->query($q, $ctx)->item(0)?->textContent ?? ''));
        if ($dom->documentElement?->localName !== 'EnvioDTE') {
            throw new RuntimeException('El XML no es un EnvioDTE (sobre de documentos tributarios).');
        }

        $rutEmisorEnvio = strtoupper($txt('/s:EnvioDTE/s:SetDTE/s:Caratula/s:RutEmisor'));
        $rutReceptorEnvio = strtoupper($txt('/s:EnvioDTE/s:SetDTE/s:Caratula/s:RutReceptor'));
        $setId = (string) ($xp->query('/s:EnvioDTE/s:SetDTE')->item(0)?->getAttribute('ID') ?? '');
        $digest = $txt('/s:EnvioDTE/ds:Signature/ds:SignedInfo/ds:Reference/ds:DigestValue');
        $envioOk = $rutReceptorEnvio === $rut;

        $documentos = [];
        foreach ($xp->query('/s:EnvioDTE/s:SetDTE/s:DTE/s:Documento') as $doc) {
            $d = [
                'TipoDTE' => (int) $txt('s:Encabezado/s:IdDoc/s:TipoDTE', $doc),
                'Folio' => (int) $txt('s:Encabezado/s:IdDoc/s:Folio', $doc),
                'FchEmis' => $txt('s:Encabezado/s:IdDoc/s:FchEmis', $doc),
                'RUTEmisor' => strtoupper($txt('s:Encabezado/s:Emisor/s:RUTEmisor', $doc)),
                'RUTRecep' => strtoupper($txt('s:Encabezado/s:Receptor/s:RUTRecep', $doc)),
                'MntTotal' => (int) $txt('s:Encabezado/s:Totales/s:MntTotal', $doc),
            ];
            $d['clave'] = "T{$d['TipoDTE']}F{$d['Folio']}";
            $d['razon_social'] = $txt('s:Encabezado/s:Emisor/s:RznSoc', $doc) ?: $txt('s:Encabezado/s:Emisor/s:RznSocEmisor', $doc);
            $d['receptor_ok'] = $d['RUTRecep'] === $rut;
            $d['sugerido'] = $d['receptor_ok'] ? 'aceptar' : 'rechazar';
            $documentos[] = $d;
        }
        if (!$documentos) {
            throw new RuntimeException('El EnvioDTE no trae documentos.');
        }
        $info = [
            'envio' => ['rut_emisor' => $rutEmisorEnvio, 'rut_receptor' => $rutReceptorEnvio, 'set_id' => $setId, 'receptor_ok' => $envioOk],
            'documentos' => $documentos,
        ];
        if (empty($in['generar'])) {
            return $info;
        }

        // Respuestas firmadas
        $certificate = $this->certificadoVigente();
        $worker = $this->billing()->getExchangeComponent()->getDocumentResponseWorker();
        $contacto = (array) ($in['contacto'] ?? []);
        $idRespuesta = max(1, (int) ($in['id_respuesta'] ?? date('His')));
        $caratula = array_filter([
            'RutResponde' => $rut,
            'RutRecibe' => $rutEmisorEnvio,
            'IdRespuesta' => $idRespuesta,
            'NmbContacto' => mb_substr(trim((string) ($contacto['nombre'] ?? '')), 0, 40, 'UTF-8') ?: false,
            'FonoContacto' => mb_substr(trim((string) ($contacto['fono'] ?? '')), 0, 40, 'UTF-8') ?: false,
            'MailContacto' => mb_substr(trim((string) ($contacto['email'] ?? '')), 0, 80, 'UTF-8') ?: false,
        ], fn ($v) => $v !== false);
        $decisiones = (array) ($in['decisiones'] ?? []);
        $decision = fn (array $d) => ($decisiones[$d['clave']] ?? $d['sugerido']) === 'aceptar' && $d['receptor_ok'];

        // 1) Acuse de recibo del envío y de cada DTE
        $recepcionDte = array_map(fn ($d) => [
            'TipoDTE' => $d['TipoDTE'], 'Folio' => $d['Folio'], 'FchEmis' => $d['FchEmis'],
            'RUTEmisor' => $d['RUTEmisor'], 'RUTRecep' => $d['RUTRecep'], 'MntTotal' => $d['MntTotal'],
            'EstadoRecepDTE' => $d['receptor_ok'] ? 0 : 3,
            'RecepDTEGlosa' => $d['receptor_ok'] ? 'DTE Recibido OK' : 'DTE No Recibido - Error en RUT Receptor',
        ], $documentos);
        $recepcion = $worker->buildRespuestaEnvio(new ExchangeDocumentBag(TipoDocumentoRespuesta::RESPUESTA_ENVIO, $caratula, ['recepcion_envio' => [array_filter([
            'NmbEnvio' => mb_substr(basename((string) ($in['nombre_archivo'] ?? 'envio.xml')), 0, 80, 'UTF-8'),
            'FchRecep' => date('Y-m-d\TH:i:s'),
            'CodEnvio' => $idRespuesta,
            'EnvioDTEID' => $setId,
            'Digest' => $digest ?: false,
            'RutEmisor' => $rutEmisorEnvio,
            'RutReceptor' => $rutReceptorEnvio,
            'EstadoRecepEnv' => $envioOk ? 0 : 3,
            'RecepEnvGlosa' => $envioOk ? 'Envio Recibido Conforme' : 'Envio Rechazado - RUT Receptor No Corresponde',
            'NroDTE' => count($documentos),
            'RecepcionDTE' => $envioOk ? $recepcionDte : false,
        ], fn ($v) => $v !== false)]], $certificate));

        // 2) Resultado comercial de cada DTE
        $resultado = $worker->buildRespuestaEnvio(new ExchangeDocumentBag(TipoDocumentoRespuesta::RESPUESTA_ENVIO, $caratula, ['resultado_dte' => array_map(fn ($d) => array_filter([
            'TipoDTE' => $d['TipoDTE'], 'Folio' => $d['Folio'], 'FchEmis' => $d['FchEmis'],
            'RUTEmisor' => $d['RUTEmisor'], 'RUTRecep' => $d['RUTRecep'], 'MntTotal' => $d['MntTotal'],
            'CodEnvio' => $idRespuesta,
            'EstadoDTE' => $decision($d) ? 0 : 2,
            'EstadoDTEGlosa' => $decision($d) ? 'ACEPTADO OK' : 'DTE Rechazado',
            'CodRchDsc' => $decision($d) ? false : -1,
        ], fn ($v) => $v !== false), $documentos)], $certificate));

        // 3) Recibo de mercaderías o servicios de los aceptados
        $aceptados = array_values(array_filter($documentos, $decision));
        $recibos = null;
        if ($aceptados) {
            $recibos = $worker->buildEnvioRecibos(new ExchangeDocumentBag(TipoDocumentoRespuesta::ENVIO_RECIBOS, array_diff_key($caratula, ['IdRespuesta' => 0]), array_map(fn ($d) => [
                'TipoDoc' => $d['TipoDTE'], 'Folio' => $d['Folio'], 'FchEmis' => $d['FchEmis'],
                'RUTEmisor' => $d['RUTEmisor'], 'RUTRecep' => $d['RUTRecep'], 'MntTotal' => $d['MntTotal'],
                'Recinto' => mb_substr(trim((string) ($in['recinto'] ?? '')) ?: 'Oficina central', 0, 80, 'UTF-8'),
                'RutFirma' => strtoupper(str_replace('.', '', (string) $certificate->getId())),
            ], $aceptados), $certificate));
        }

        $salida = ['recepcion' => $recepcion, 'resultado' => $resultado] + ($recibos ? ['recibos' => $recibos] : []);
        $xmls = [];
        foreach ($salida as $k => $documento) {
            $worker->validateSchema($documento); // XSD del SII
            $xmls[$k] = base64_encode($documento->saveXml());
        }
        return $info + ['xmls' => $xmls, 'aceptados' => count($aceptados)];
    }

    /** Papel del PDF: 'carta' (por defecto) o rollo térmico de 80 o 58 mm (ancho en mm). */
    private function papel(array $in): int
    {
        return match ((string) ($in['papel'] ?? 'carta')) {
            '80', 't80' => 80,
            '58', 't58' => 58,
            default => 0,
        };
    }

    /**
     * Genera las copias del documento (tributaria y, si se pide, cedible) en el papel indicado.
     * En carta se usa la estrategia de LibreDTE con la plantilla FelFact; en rollo térmico se arma el
     * HTML con la plantilla de ticket y el PDF con mPDF directamente, con el largo justo del contenido
     * (LibreDTE solo acepta tamaños de página con nombre, como "Letter").
     * @return array<string,string> [presentación => contenido]
     */
    private function renderizar($bag, array $in, bool $cedible = false, bool $html = false): array
    {
        $this->opcionesPdf($bag, $in);
        $ancho = $this->papel($in);
        $opciones = $bag->getOptions();
        $opciones->set('renderer.renderings', ['tributaria' => 1] + ($cedible ? ['cedible' => 1] : []));
        if ($ancho) {
            $plantilla = dirname(__DIR__) . '/plantillas/felfact-ticket.html.twig';
            $opciones->set('renderer.template', preg_replace('#^[A-Za-z]:#', '', str_replace('\\', '/', $plantilla)));
            $opciones->set('renderer.ancho', $ancho);
        }
        if ($ancho || $html) {
            $opciones->set('renderer.format', 'html');
        }
        $salida = [];
        foreach ($this->billing()->getDocumentComponent()->getRendererWorker()->render($bag)->getRenderings() as $r) {
            $salida[$r->getLabel()] = ($ancho && !$html) ? $this->ticketPdf($r->getContent(), $ancho) : $r->getContent();
        }
        return $salida;
    }

    /** PDF de rollo térmico: se escribe una vez en una página muy larga para medir y luego con el largo exacto. */
    private function ticketPdf(string $html, int $ancho): string
    {
        $crear = fn (float $alto) => new \Mpdf\Mpdf([
            'mode' => 'utf-8',
            'format' => [$ancho, $alto],
            'margin_left' => 2, 'margin_right' => 2, 'margin_top' => 3, 'margin_bottom' => 3,
            'margin_header' => 0, 'margin_footer' => 0,
            'tempDir' => sys_get_temp_dir(),
        ]);
        $medida = $crear(2000);
        $medida->WriteHTML($html);
        $alto = $medida->page > 1 ? 2000 : max(80, ceil($medida->y + 6));
        $pdf = $crear($alto);
        $pdf->WriteHTML($html);
        return $pdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }

    /**
     * Opciones del PDF: plantilla FelFact (plantillas/felfact.html.twig, con la marca de FelServ),
     * oficina del SII (dirección regional o unidad de Mi SII) y datos de la marca.
     * Si la plantilla no está, se usa la estándar de LibreDTE.
     */
    private function opcionesPdf($bag, array $in): void
    {
        $dir = dirname(__DIR__) . '/plantillas';
        $opciones = $bag->getOptions();
        if (is_file("$dir/felfact.html.twig")) {
            // La estrategia exige una ruta absoluta que empiece con "/" (en Windows, sin la unidad)
            $opciones->set('renderer.template', preg_replace('#^[A-Za-z]:#', '', str_replace('\\', '/', "$dir/felfact.html.twig")));
            // Logo: el enviado en la solicitud (PNG/JPG en data URI, máx. ~300 KB) o la marca FS de FelServ
            $logo = (string) ($in['pdf_logo'] ?? '');
            if (preg_match('#^data:image/(png|jpeg);base64,[A-Za-z0-9+/=]+$#', $logo) && strlen($logo) < 450000) {
                $opciones->set('renderer.logo', $logo);
            } elseif (is_file("$dir/felserv-marca.png")) {
                $opciones->set('renderer.logo', 'data:image/png;base64,' . base64_encode((string) file_get_contents("$dir/felserv-marca.png")));
            }
            $opciones->set('renderer.marca', mb_substr(trim((string) ($in['pdf_marca'] ?? 'FelServ')), 0, 40, 'UTF-8'));
            $opciones->set('renderer.web', mb_substr(trim((string) ($in['pdf_web'] ?? 'www.felserv.cl')), 0, 60, 'UTF-8'));
            // Sitio donde se verifican las boletas (lo exige el SII en su representación impresa)
            $opciones->set('renderer.verificacion', mb_substr(trim((string) ($in['pdf_verificacion'] ?? '')), 0, 80, 'UTF-8'));
        }
        $unidad = trim((string) ($in['sii_unidad'] ?? ''));
        if ($unidad !== '') {
            $opciones->set('renderer.sii_unidad', mb_strtoupper(mb_substr($unidad, 0, 40, 'UTF-8'), 'UTF-8'));
        }
        // Datos del emisor que solo se imprimen: ciudad, teléfono, email y vendedor
        $emisor = [];
        foreach (['ciudad' => 20, 'telefono' => 20, 'email' => 80, 'vendedor' => 60] as $k => $max) {
            $v = trim((string) ($in['pdf_emisor'][$k] ?? ''));
            if ($v !== '') {
                $emisor[$k] = mb_substr($v, 0, $max, 'UTF-8');
            }
        }
        $opciones->set('renderer.emisor', $emisor);
    }

    /** Datos de un DTE generado: folio, totales, XML y PDF (con la resolución, que se imprime). */
    private function datosDocumento($bag, ?array $resolucion = null, array $in = []): array
    {
        $dte = $bag->getDocument();
        if ($resolucion) {
            $bag->getEmisor()?->setAutorizacionDte(new AutorizacionDte($resolucion['fecha'], $resolucion['numero']));
        }
        $xml = new XmlDocument();
        $xml->loadXml($dte->saveXml());
        $totales = $xml->query('//Encabezado/Totales') ?? [];

        // Boletas: el papel configurado para ellas (carta o rollo térmico); el resto, carta
        $tipoDte = (int) $xml->query('//Encabezado/IdDoc/TipoDTE');
        $pdf = current($this->renderizar($bag, ['papel' => in_array($tipoDte, [39, 41], true) ? ($in['papel_boleta'] ?? 'carta') : 'carta'] + $in));

        return [
            'tipo' => (int) $xml->query('//Encabezado/IdDoc/TipoDTE'),
            'folio' => (int) $xml->query('//Encabezado/IdDoc/Folio'),
            'fecha_emision' => $xml->query('//Encabezado/IdDoc/FchEmis'),
            'totales' => [
                'neto' => (int) ($totales['MntNeto'] ?? 0),
                'exento' => (int) ($totales['MntExe'] ?? 0),
                'iva' => (int) ($totales['IVA'] ?? 0),
                'total' => (int) ($totales['MntTotal'] ?? 0),
            ],
            'dte_xml' => base64_encode($dte->saveXml()),
            'pdf' => base64_encode($pdf),
        ];
    }

    /**
     * Genera, timbra y firma el DTE, arma y firma su sobre (validado contra el
     * XSD del SII) y genera el PDF. No envía nada al SII.
     */
    private function generar(array $in): array
    {
        $data = $in['documento'] ?? null;
        if (!is_array($data)) {
            throw new RuntimeException('Falta el documento a generar.');
        }
        $resolucion = $this->resolucion($in);
        $certificate = $this->certificadoVigente();

        $bag = $this->emitirDocumento($data, $this->archivo($in, 'caf'), $certificate);
        $sobre = $this->armarSobre([$bag], $resolucion, $certificate);

        return $this->datosDocumento($bag, $resolucion, $in) + ['sobre_xml' => base64_encode($sobre)];
    }

    /**
     * Genera varios DTE y los pone en un solo sobre (set de pruebas del SII).
     * Entrada: documentos = [{documento, caf (base64)}, ...]. Si falla uno, no
     * se entrega ninguno.
     */
    private function generarLote(array $in): array
    {
        $lista = $in['documentos'] ?? null;
        if (!is_array($lista) || !$lista) {
            throw new RuntimeException('Falta la lista de documentos del lote.');
        }
        if (count($lista) > 50) {
            throw new RuntimeException('Máximo 50 documentos por envío.');
        }
        $resolucion = $this->resolucion($in);
        $certificate = $this->certificadoVigente();

        $bags = [];
        $boletas = 0;
        foreach (array_values($lista) as $i => $item) {
            if (!is_array($item['documento'] ?? null)) {
                throw new RuntimeException('Documento ' . ($i + 1) . ' del lote sin datos.');
            }
            $tipo = (int) ($item['documento']['Encabezado']['IdDoc']['TipoDTE'] ?? 0);
            $boletas += in_array($tipo, [39, 41], true) ? 1 : 0;
            try {
                $bags[] = $this->emitirDocumento($item['documento'], $this->archivo($item, 'caf'), $certificate);
            } catch (\Throwable $e) {
                throw new RuntimeException('Documento ' . ($i + 1) . " (tipo $tipo): " . $e->getMessage());
            }
        }
        if ($boletas && $boletas !== count($bags)) {
            throw new RuntimeException('Las boletas no se pueden enviar en el mismo sobre que otros documentos.');
        }

        return [
            'documentos' => array_map(fn ($bag) => $this->datosDocumento($bag, $resolucion, $in), $bags),
            'sobre_xml' => base64_encode($this->armarSobre($bags, $resolucion, $certificate)),
        ];
    }

    /**
     * Genera, firma y valida (XSD) un libro de ventas o de compras (IECV).
     * Entrada: tipo (ventas|compras), rut_emisor, razon_social, caratula (PeriodoTributario, TipoLibro,
     * TipoEnvio, FolioNotificacion), detalle (registros IECV: TpoDoc, NroDoc, FchDoc, RUTDoc, MntNeto...),
     * resolucion. Los IVA y totales que falten los calcula LibreDTE.
     */
    private function generarLibro(array $in): array
    {
        $tipo = match ($in['tipo'] ?? '') {
            'ventas' => \libredte\lib\Core\Package\Billing\Component\Book\Enum\TipoLibro::VENTAS,
            'compras' => \libredte\lib\Core\Package\Billing\Component\Book\Enum\TipoLibro::COMPRAS,
            // RCOF: reporte de consumo de folios de boletas (ConsumoFolios), lo pide la certificación de boletas
            'consumo_folios' => \libredte\lib\Core\Package\Billing\Component\Book\Enum\TipoLibro::RVD,
            default => throw new RuntimeException('Tipo de libro inválido: use ventas, compras o consumo_folios.'),
        };
        $resolucion = $this->resolucion($in);
        $certificate = $this->certificadoVigente();
        $detalle = array_values(array_filter((array) ($in['detalle'] ?? []), 'is_array'));
        if (!$detalle) {
            throw new RuntimeException('El libro no tiene detalle.');
        }
        $rcof = $tipo === \libredte\lib\Core\Package\Billing\Component\Book\Enum\TipoLibro::RVD;
        $caratula = (array) ($in['caratula'] ?? []);
        if ($rcof) {
            $caratula = ['FchResol' => $resolucion['fecha'], 'NroResol' => $resolucion['numero']] + array_intersect_key($caratula, array_flip(['Correlativo', 'SecEnvio']));
        }

        $emisor = (new \libredte\lib\Core\Package\Billing\Component\TradingParties\Factory\EmisorFactory())->create([
            'rut' => (string) ($in['rut_emisor'] ?? ''),
            'razon_social' => (string) ($in['razon_social'] ?? ''),
            'autorizacion_dte' => ['fecha_resolucion' => $resolucion['fecha'], 'numero_resolucion' => $resolucion['numero']],
        ]);
        $bag = new \libredte\lib\Core\Package\Billing\Component\Book\Support\BookBag(
            tipo: $tipo,
            caratula: $caratula,
            detalle: $detalle,
            certificate: $certificate,
            emisor: $emisor,
        );
        $book = $this->billing()->getBookComponent();
        $bag = $book->getLoaderWorker()->load($bag);
        $book->getBuilderWorker()->build($bag);
        $book->getValidatorWorker()->validateSchema($bag);
        $xml = $bag->getBook()->saveXml();

        // Resumen por tipo de documento, para mostrarlo en el panel
        $doc = new \DOMDocument();
        $doc->loadXML($xml);
        $resumen = [];
        // RCOF: mismas claves que el libro (TpoDoc, TotDoc, TotMnt...) para mostrarlo igual
        foreach ($rcof ? $doc->getElementsByTagNameNS('*', 'Resumen') : [] as $t) {
            $fila = [];
            foreach (['TpoDoc' => 'TipoDocumento', 'TotDoc' => 'FoliosEmitidos', 'TotMntExe' => 'MntExento', 'TotMntNeto' => 'MntNeto', 'TotMntIVA' => 'MntIva', 'TotMntTotal' => 'MntTotal'] as $clave => $tag) {
                $n = $t->getElementsByTagNameNS('*', $tag)->item(0);
                $fila[$clave] = $n ? (int) $n->textContent : 0;
            }
            $resumen[] = $fila;
        }
        foreach ($doc->getElementsByTagNameNS('*', 'TotalesPeriodo') as $t) {
            $fila = [];
            foreach (['TpoDoc', 'TotDoc', 'TotMntExe', 'TotMntNeto', 'TotMntIVA', 'TotMntTotal'] as $tag) {
                $n = $t->getElementsByTagNameNS('*', $tag)->item(0);
                $fila[$tag] = $n ? (int) $n->textContent : 0;
            }
            $resumen[] = $fila;
        }

        return ['libro_xml' => base64_encode($xml), 'resumen' => $resumen];
    }

    /** Las boletas (39 y 41) van por la API REST de boletas del SII; el resto por el servicio SOAP de DTE. */
    private static function esBoleta(array $in): bool
    {
        return in_array((int) ($in['tipo'] ?? 33), [39, 41], true);
    }

    private function siiBoleta(array $in): SiiBoleta
    {
        $this->ambiente($in); // valida
        return new SiiBoleta((string) $in['ambiente'], $this->certificado(), dirname($this->certificadoRuta));
    }

    /** Sube el sobre (EnvioDTE o EnvioBOLETA) al SII y entrega el Track ID. */
    private function enviar(array $in): array
    {
        if (self::esBoleta($in)) {
            return ['track_id' => $this->siiBoleta($in)->enviar((string) ($in['rut_emisor'] ?? ''), $this->archivo($in, 'sobre'))];
        }

        $xml = new XmlDocument();
        $xml->loadXml($this->archivo($in, 'sobre'));

        $request = new SiiRequest(certificate: $this->certificado(), options: ['environment' => $this->ambiente($in)]);
        $response = $this->sii()->sendXmlDocument($request, $xml, (string) ($in['rut_emisor'] ?? ''));

        return ['track_id' => $response->getTrackId()];
    }

    /** Estado de un envío (Track ID) en el SII. */
    private function estado(array $in): array
    {
        if (self::esBoleta($in)) {
            return $this->siiBoleta($in)->estado((string) ($in['rut_emisor'] ?? ''), (int) ($in['track_id'] ?? 0));
        }

        $request = new SiiRequest(certificate: $this->certificado(), options: ['environment' => $this->ambiente($in)]);
        $response = $this->sii()->checkXmlDocumentSentStatus(
            $request,
            (int) ($in['track_id'] ?? 0),
            (string) ($in['rut_emisor'] ?? '')
        );
        $revision = $response->getReviewStatus();

        return [
            'estado' => strtoupper(substr(trim($revision), 0, 3)),
            'revision' => $revision,
            'detalle' => $response->getReviewDetail(),
        ];
    }

    /**
     * Carga el certificado y se autentica en los dos canales del SII: el de
     * facturas (SOAP, firma de LibreDTE) y el de boletas (REST, firma propia).
     * Responde ok aunque falle uno: cada canal informa su resultado.
     */
    private function probar(array $in): array
    {
        $certificate = $this->certificado();
        $resultado = ['certificado' => $this->infoCertificado($certificate), 'php' => PHP_VERSION];

        try {
            $request = new SiiRequest(certificate: $certificate, options: ['environment' => $this->ambiente($in)]);
            $this->billing()->getIntegrationComponent()->getSiiLazyWorker()->authenticate($request);
            $resultado['facturas'] = ['ok' => true];
        } catch (\Throwable $e) {
            $resultado['facturas'] = ['ok' => false, 'error' => $e->getMessage()];
        }

        try {
            $this->siiBoleta($in)->token(true);
            $resultado['boletas'] = ['ok' => true];
        } catch (\Throwable $e) {
            $resultado['boletas'] = ['ok' => false, 'error' => $e->getMessage()];
        }

        return $resultado;
    }
}
