<?php

declare(strict_types=1);

namespace FelServ\LibreDteWorker;

use libredte\lib\Core\Package\Billing\Component\TradingParties\Contract\EmisorFactoryInterface;
use libredte\lib\Core\Package\Billing\Component\TradingParties\Contract\EmisorInterface;
use libredte\lib\Core\Package\Billing\Component\TradingParties\Contract\EmisorProviderInterface;

/**
 * Proveedor de datos del emisor que no agrega nada.
 *
 * El proveedor por defecto de LibreDTE (FakeEmisorProvider) completa los datos
 * faltantes con datos de ejemplo (teléfono, correo, código de sucursal SII
 * 123456, vendedor "libredte"), que no deben llegar a un documento real. Aquí
 * el emisor lleva solo los datos que vienen en el documento.
 */
class EmisorProvider implements EmisorProviderInterface
{
    public function __construct(private EmisorFactoryInterface $emisorFactory)
    {
    }

    public function retrieve(int|string|EmisorInterface $emisor): EmisorInterface
    {
        if (is_int($emisor) || is_string($emisor)) {
            $emisor = $this->emisorFactory->create(['rut' => $emisor]);
        }

        return $emisor;
    }
}
