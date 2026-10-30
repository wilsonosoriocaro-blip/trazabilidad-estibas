<?php

namespace App\Domain\BoxLabels\Exceptions;

use Carbon\CarbonInterface;
use DomainException;

/**
 * A box label request that breaks a rule. The message is meant for the
 * person at the screen.
 */
class BoxLabelRuleViolation extends DomainException
{
    public static function invalidNumber(): self
    {
        return new self('Escribe el número de la orden de fabricación (solo números).');
    }

    public static function notSynced(string $docNum): self
    {
        return new self("La orden de fabricación {$docNum} no está en la aplicación. Actualiza el Tablero de OF desde SAP y vuelve a buscarla.");
    }

    public static function notReleased(string $docNum, string $status): self
    {
        return new self("La orden de fabricación {$docNum} está «{$status}» en SAP. Solo se generan etiquetas de órdenes liberadas.");
    }

    public static function itemChanged(string $docNum): self
    {
        return new self("En SAP la orden {$docNum} tiene otro producto que el que ya tiene estibas aquí. Hay que revisarlo antes de seguir.");
    }

    public static function notConsulted(): self
    {
        return new self('Busca la orden de fabricación antes de generar etiquetas.');
    }

    public static function noBarcode(string $itemCode): self
    {
        return new self("El producto {$itemCode} no tiene código EAN-14 en SAP. Sin él no se genera la etiqueta.");
    }

    public static function invalidBarcode(string $itemCode, string $barcode): self
    {
        return new self("El código {$barcode} del producto {$itemCode} no es un EAN-13 ni un EAN-14 válido (13 o 14 dígitos con dígito de control). Hay que corregirlo en SAP.");
    }

    public static function noFactor(string $itemCode): self
    {
        return new self("El producto {$itemCode} no tiene unidades por caja vigentes (DP-02). Sin eso no se genera la etiqueta.");
    }

    public static function invalidQuantity(): self
    {
        return new self('La cantidad de etiquetas debe ser al menos 1.');
    }

    public static function lotChangeNotAllowed(): self
    {
        return new self('Solo un supervisor puede cambiar el lote de las etiquetas.');
    }

    public static function invalidLot(): self
    {
        return new self('El lote solo puede tener letras, números, punto, guion, guion bajo o /, hasta 40 caracteres.');
    }

    public static function lotAlreadyPrinted(string $lotCode): self
    {
        return new self("El lote {$lotCode} ya tiene etiquetas impresas en la Zebra. Ya no se puede cambiar por otro.");
    }

    public static function lotOfAnotherOrder(string $lotCode, string $orderRef): self
    {
        return new self("El lote {$lotCode} ya es de la OF {$orderRef}. Usa otro lote.");
    }

    public static function futureProductionDate(): self
    {
        return new self('La fecha de producción no puede ser futura.');
    }

    public static function lotExpirationMismatch(string $lotCode, CarbonInterface $expiration): self
    {
        return new self("El lote {$lotCode} ya tiene etiquetas con vencimiento {$expiration->format('d/m/Y')}. Un lote tiene una sola fecha de vencimiento.");
    }

    public static function invalidExpiration(): self
    {
        return new self('Indica una fecha de vencimiento posterior a la fecha de producción.');
    }
}
