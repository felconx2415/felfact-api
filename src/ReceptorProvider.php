<?php

declare(strict_types=1);

namespace FelServ\LibreDteWorker;

use libredte\lib\Core\Package\Billing\Component\TradingParties\Contract\ReceptorFactoryInterface;
use libredte\lib\Core\Package\Billing\Component\TradingParties\Contract\ReceptorInterface;
use libredte\lib\Core\Package\Billing\Component\TradingParties\Contract\ReceptorProviderInterface;

/**
 * Proveedor de datos del receptor que no agrega nada.
 *
 * El proveedor por defecto de LibreDTE (FakeReceptorProvider) completa los
 * datos faltantes con los del SII (correo, teléfono, dirección), que no deben
 * llegar a un documento real. Aquí el receptor lleva solo los datos que vienen
 * en el documento.
 */
class ReceptorProvider implements ReceptorProviderInterface
{
    public function __construct(private ReceptorFactoryInterface $receptorFactory)
    {
    }

    public function retrieve(int|string|ReceptorInterface $receptor): ReceptorInterface
    {
        if (is_int($receptor) || is_string($receptor)) {
            $receptor = $this->receptorFactory->create(['rut' => $receptor]);
        }

        return $receptor;
    }
}
