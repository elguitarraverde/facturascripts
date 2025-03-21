<?php
/**
 * This file is part of FacturaScripts
 * Copyright (C) 2021-2024 Carlos Garcia Gomez <carlos@facturascripts.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace FacturaScripts\Core\Lib\AjaxForms;

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Contract\SalesLineModInterface;
use FacturaScripts\Core\DataSrc\Impuestos;
use FacturaScripts\Core\Model\Base\SalesDocument;
use FacturaScripts\Core\Model\Base\SalesDocumentLine;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\Stock;
use FacturaScripts\Dinamic\Model\Variante;

/**
 * Description of SalesLineHTML
 *
 * @author Carlos Garcia Gomez           <carlos@facturascripts.com>
 * @author Jose Antonio Cuello Principal <yopli2000@gmail.com>
 * @author Daniel Fernández Giménez      <hola@danielfg.es>
 */
class SalesLineHTML
{
    use CommonLineHTML;

    /** @var array */
    private static $deletedLines = [];

    /** @var SalesLineModInterface[] */
    private static $mods = [];

    public static function addMod(SalesLineModInterface $mod): void
    {
        self::$mods[] = $mod;
    }

    /**
     * @param SalesDocument $model
     * @param SalesDocumentLine[] $lines
     * @param array $formData
     */
    public static function apply(SalesDocument &$model, array &$lines, array $formData): void
    {
        self::$columnView = $formData['columnView'] ?? Tools::settings('default', 'columnetosubtotal', 'subtotal');

        // update or remove lines
        $rmLineId = $formData['action'] === 'rm-line' ? $formData['selectedLine'] : 0;
        foreach ($lines as $key => $value) {
            if ($value->idlinea === (int)$rmLineId || false === isset($formData['cantidad_' . $value->idlinea])) {
                self::$deletedLines[] = $value->idlinea;
                unset($lines[$key]);
                continue;
            }

            self::applyToLine($formData, $value, $value->idlinea);
        }

        // new lines
        for ($num = 1; $num < 1000; $num++) {
            if (isset($formData['cantidad_n' . $num]) && $rmLineId !== 'n' . $num) {
                $newLine = isset($formData['referencia_n' . $num]) ?
                    $model->getNewProductLine($formData['referencia_n' . $num]) : $model->getNewLine();
                $idNewLine = 'n' . $num;
                self::applyToLine($formData, $newLine, $idNewLine);
                $lines[] = $newLine;
            }
        }

        // add new line
        if ($formData['action'] === 'add-product' || $formData['action'] === 'fast-product') {
            $lines[] = $model->getNewProductLine($formData['selectedLine']);
        } elseif ($formData['action'] === 'fast-line') {
            $newLine = self::getFastLine($model, $formData);
            if ($newLine) {
                $lines[] = $newLine;
            }
        } elseif ($formData['action'] === 'new-line') {
            $lines[] = $model->getNewLine();
        }

        // mods
        foreach (self::$mods as $mod) {
            $mod->apply($model, $lines, $formData);
        }
    }

    public static function assets(): void
    {
        // mods
        foreach (self::$mods as $mod) {
            $mod->assets();
        }
    }

    public static function getDeletedLines(): array
    {
        return self::$deletedLines;
    }

    public static function map(array $lines, SalesDocument $model): array
    {
        $map = [];
        foreach ($lines as $line) {
            self::$num++;
            $idlinea = $line->idlinea ?? 'n' . self::$num;

            // codimpuesto
            $map['iva_' . $idlinea] = $line->iva;

            // total
            $map['linetotal_' . $idlinea] = self::subtotalValue($line, $model);

            // neto
            $map['lineneto_' . $idlinea] = $line->pvptotal;
        }

        // mods
        foreach (self::$mods as $mod) {
            foreach ($mod->map($lines, $model) as $key => $value) {
                $map[$key] = $value;
            }
        }

        return $map;
    }

    public static function render(array $lines, SalesDocument $model): string
    {
        if (empty(self::$columnView)) {
            self::$columnView = Tools::settings('default', 'columnetosubtotal', 'subtotal');
        }

        self::$numlines = count($lines);
        self::loadProducts($lines, $model);

        $html = '';
        foreach ($lines as $line) {
            $html .= self::renderLine($line, $model);
        }
        if (empty($html)) {
            $html .= '<div class="container-fluid"><div class="row g-3 table-warning"><div class="col p-3 text-center">'
                . Tools::lang()->trans('new-invoice-line-p') . '</div></div></div>';
        }
        return empty($model->codcliente) ? '' : self::renderTitles($model) . $html;
    }

    public static function renderLine(SalesDocumentLine $line, SalesDocument $model): string
    {
        self::$num++;
        $idlinea = $line->idlinea ?? 'n' . self::$num;
        return '<div class="container-fluid fs-line"><div class="row g-3 align-items-center border-bottom pb-3 pb-lg-0">'
            . self::renderField($idlinea, $line, $model, 'referencia')
            . self::renderField($idlinea, $line, $model, 'descripcion')
            . self::renderField($idlinea, $line, $model, 'cantidad')
            . self::renderNewFields($idlinea, $line, $model)
            . self::renderField($idlinea, $line, $model, 'pvpunitario')
            . self::renderField($idlinea, $line, $model, 'dtopor')
            . self::renderField($idlinea, $line, $model, 'codimpuesto')
            . self::renderField($idlinea, $line, $model, '_total')
            . self::renderExpandButton($idlinea, $model, 'salesFormAction')
            . '</div>'
            . self::renderLineModal($line, $idlinea, $model) . '</div>';
    }

    private static function applyToLine(array $formData, SalesDocumentLine &$line, string $id): void
    {
        $line->orden = (int)$formData['orden_' . $id];
        $line->cantidad = (float)$formData['cantidad_' . $id];
        $line->coste = floatval($formData['coste_' . $id] ?? $line->coste);
        $line->dtopor = (float)$formData['dtopor_' . $id];
        $line->dtopor2 = (float)$formData['dtopor2_' . $id];
        $line->descripcion = $formData['descripcion_' . $id];
        $line->excepcioniva = $formData['excepcioniva_' . $id] ?? null;
        $line->irpf = (float)($formData['irpf_' . $id] ?? '0');
        $line->mostrar_cantidad = (bool)($formData['mostrar_cantidad_' . $id] ?? '0');
        $line->mostrar_precio = (bool)($formData['mostrar_precio_' . $id] ?? '0');
        $line->salto_pagina = (bool)($formData['salto_pagina_' . $id] ?? '0');
        $line->suplido = (bool)($formData['suplido_' . $id] ?? '0');
        $line->pvpunitario = (float)$formData['pvpunitario_' . $id];

        // ¿Cambio de impuesto?
        if (isset($formData['codimpuesto_' . $id]) && $formData['codimpuesto_' . $id] !== $line->codimpuesto) {
            $impuesto = Impuestos::get($formData['codimpuesto_' . $id]);
            $line->codimpuesto = $impuesto->codimpuesto;
            $line->iva = $impuesto->iva;
            if ($line->recargo) {
                // si la línea ya tenía recargo, le asignamos el nuevo
                $line->recargo = $impuesto->recargo;
            }
        } else {
            $line->recargo = (float)($formData['recargo_' . $id] ?? '0');
        }

        // mods
        foreach (self::$mods as $mod) {
            $mod->applyToLine($formData, $line, $id);
        }
    }

    private static function cantidad(string $idlinea, SalesDocumentLine $line, SalesDocument $model, string $jsFunc): string
    {
        if (false === $model->editable) {
            return '<div class="col-sm-2 col-lg-1 order-3">'
                . '<div class="d-lg-none mt-2 small">' . Tools::lang()->trans('quantity') . '</div>'
                . '<div class="input-group input-group-sm">'
                . self::cantidadRestante($line, $model)
                . '<input type="number" class="form-control text-lg-end border-0" value="' . $line->cantidad . '" disabled=""/>'
                . '</div>'
                . '</div>';
        }

        return '<div class="col-sm-2 col-lg-1 order-3">'
            . '<div class="d-lg-none mt-2 small">' . Tools::lang()->trans('quantity') . '</div>'
            . '<div class="input-group input-group-sm">'
            . self::cantidadRestante($line, $model)
            . '<input type="number" name="cantidad_' . $idlinea . '" value="' . $line->cantidad
            . '" class="form-control text-lg-end border-0 doc-line-qty" onkeyup="return ' . $jsFunc . '(\'recalculate-line\', \'0\', event);"/>'
            . self::cantidadStock($line, $model)
            . '</div>'
            . '</div>';
    }

    private static function cantidadStock(SalesDocumentLine $line, SalesDocument $model): string
    {
        $html = '';
        if (empty($line->referencia) || $line->modelClassName() === 'LineaFacturaCliente' || false === $model->editable) {
            return $html;
        }

        $product = $line->getProducto();
        if ($product->nostock) {
            return $html;
        }

        // buscamos el stock de este producto en este almacén
        $stock = self::$stocks[$line->referencia] ?? new Stock();
        switch ($line->actualizastock) {
            case -1:
            case -2:
                $html = $stock->disponible > 0 ?
                    '<a href="' . $stock->url() . '" target="_Blank" class="btn btn-outline-success">' . $stock->disponible . '</a>' :
                    '<a href="' . $stock->url() . '" target="_Blank" class="btn btn-outline-danger">' . $stock->disponible . '</a>';
                break;

            default:
                $html = $line->cantidad <= $stock->cantidad ?
                    '<a href="' . $stock->url() . '" target="_Blank" class="btn btn-outline-success">' . $stock->cantidad . '</a>' :
                    '<a href="' . $stock->url() . '" target="_Blank" class="btn btn-outline-danger">' . $stock->cantidad . '</a>';
                break;
        }

        return empty($html) ? $html :
            '<div class="input-group-prepend" title="' . Tools::lang()->trans('stock') . '">' . $html . '</div>';
    }

    private static function coste(string $idlinea, SalesDocumentLine $line, SalesDocument $model, string $field): string
    {
        if (false === SalesHeaderHTML::checkLevel(Tools::settings('default', 'levelcostsales', 0))) {
            return '';
        }

        $attributes = $model->editable ?
            'name="' . $field . '_' . $idlinea . '" min="0" step="any"' :
            'disabled=""';

        return '<div class="col-6">'
            . '<div class="mb-2">' . Tools::lang()->trans('cost')
            . '<input type="number" ' . $attributes . ' value="' . $line->{$field} . '" class="form-control"/>'
            . '</div>'
            . '</div>';
    }

    private static function getFastLine(SalesDocument $model, array $formData): ?SalesDocumentLine
    {
        if (empty($formData['fastli'])) {
            return $model->getNewLine();
        }

        // buscamos el código de barras en las variantes
        $variantModel = new Variante();
        $whereBarcode = [new DataBaseWhere('codbarras', $formData['fastli'])];
        foreach ($variantModel->all($whereBarcode) as $variante) {
            return $model->getNewProductLine($variante->referencia);
        }

        // buscamos el código de barras con los mods
        foreach (self::$mods as $mod) {
            $line = $mod->getFastLine($model, $formData);
            if ($line) {
                return $line;
            }
        }

        Tools::log()->warning('product-not-found', ['%ref%' => $formData['fastli']]);
        return null;
    }

    private static function precio(string $idlinea, SalesDocumentLine $line, SalesDocument $model, string $jsFunc): string
    {
        if (false === $model->editable) {
            return '<div class="col-sm col-lg-1 order-4">'
                . '<span class="d-lg-none small">' . Tools::lang()->trans('price') . '</span>'
                . '<input type="number" value="' . $line->pvpunitario . '" class="form-control form-control-sm text-lg-end border-0" disabled/>'
                . '</div>';
        }

        $attributes = 'name="pvpunitario_' . $idlinea . '" onkeyup="return ' . $jsFunc . '(\'recalculate-line\', \'0\', event);"';
        return '<div class="col-sm col-lg-1 order-4">'
            . '<span class="d-lg-none small">' . Tools::lang()->trans('price') . '</span>'
            . '<input type="number" ' . $attributes . ' value="' . $line->pvpunitario . '" class="form-control form-control-sm text-lg-end border-0"/>'
            . '</div>';
    }

    private static function renderField(string $idlinea, SalesDocumentLine $line, SalesDocument $model, string $field): ?string
    {
        foreach (self::$mods as $mod) {
            $html = $mod->renderField($idlinea, $line, $model, $field);
            if ($html !== null) {
                return $html;
            }
        }

        switch ($field) {
            case '_total':
                return self::lineTotal($idlinea, $line, $model, 'salesLineTotalWithTaxes', 'salesLineTotalWithoutTaxes');

            case 'cantidad':
                return self::cantidad($idlinea, $line, $model, 'salesFormActionWait');

            case 'codimpuesto':
                return self::codimpuesto($idlinea, $line, $model, 'salesFormAction');

            case 'coste':
                return self::coste($idlinea, $line, $model, 'coste');

            case 'descripcion':
                return self::descripcion($idlinea, $line, $model);

            case 'dtopor':
                return self::dtopor($idlinea, $line, $model, 'salesFormActionWait');

            case 'dtopor2':
                return self::dtopor2($idlinea, $line, $model, 'dtopor2', 'salesFormActionWait');

            case 'excepcioniva':
                return self::excepcioniva($idlinea, $line, $model, 'excepcioniva', 'salesFormActionWait');

            case 'irpf':
                return self::irpf($idlinea, $line, $model, 'salesFormAction');

            case 'mostrar_cantidad':
                return self::genericBool($idlinea, $line, $model, 'mostrar_cantidad', 'print-quantity');

            case 'mostrar_precio':
                return self::genericBool($idlinea, $line, $model, 'mostrar_precio', 'print-price');

            case 'pvpunitario':
                return self::precio($idlinea, $line, $model, 'salesFormActionWait');

            case 'recargo':
                return self::recargo($idlinea, $line, $model, 'salesFormActionWait');

            case 'referencia':
                return self::referencia($idlinea, $line, $model);

            case 'salto_pagina':
                return self::genericBool($idlinea, $line, $model, 'salto_pagina', 'page-break');

            case 'suplido':
                return self::suplido($idlinea, $line, $model, 'salesFormAction');
        }

        return null;
    }

    private static function renderLineModal(SalesDocumentLine $line, string $idlinea, SalesDocument $model): string
    {
        return '<div class="modal fade" id="lineModal-' . $idlinea . '" tabindex="-1" aria-labelledby="lineModal-' . $idlinea . 'Label" aria-hidden="true">'
            . '<div class="modal-dialog modal-dialog-centered">'
            . '<div class="modal-content">'
            . '<div class="modal-header">'
            . '<h5 class="modal-title"><i class="fa-solid fa-edit fa-fw" aria-hidden="true"></i> ' . $line->referencia . '</h5>'
            . '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close">'
            . ''
            . '</button>'
            . '</div>'
            . '<div class="modal-body">'
            . '<div class="row g-3">'
            . self::renderField($idlinea, $line, $model, 'dtopor2')
            . self::renderField($idlinea, $line, $model, 'recargo')
            . self::renderField($idlinea, $line, $model, 'irpf')
            . self::renderField($idlinea, $line, $model, 'excepcioniva')
            . self::renderField($idlinea, $line, $model, 'suplido')
            . self::renderField($idlinea, $line, $model, 'coste')
            . self::renderField($idlinea, $line, $model, 'mostrar_cantidad')
            . self::renderField($idlinea, $line, $model, 'mostrar_precio')
            . self::renderField($idlinea, $line, $model, 'salto_pagina')
            . self::renderNewModalFields($idlinea, $line, $model)
            . '</div>'
            . '</div>'
            . '<div class="modal-footer">'
            . '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">' . Tools::lang()->trans('close') . '</button>'
            . '<button type="button" class="btn btn-primary" data-bs-dismiss="modal">' . Tools::lang()->trans('accept') . '</button>'
            . '</div>'
            . '</div>'
            . '</div>'
            . '</div>';
    }

    private static function renderNewModalFields(string $idlinea, SalesDocumentLine $line, SalesDocument $model): string
    {
        // cargamos los nuevos campos
        $newFields = [];
        foreach (self::$mods as $mod) {
            foreach ($mod->newModalFields() as $field) {
                if (false === in_array($field, $newFields)) {
                    $newFields[] = $field;
                }
            }
        }

        // renderizamos los campos
        $html = '';
        foreach ($newFields as $field) {
            foreach (self::$mods as $mod) {
                $fieldHtml = $mod->renderField($idlinea, $line, $model, $field);
                if ($fieldHtml !== null) {
                    $html .= $fieldHtml;
                    break;
                }
            }
        }
        return $html;
    }

    private static function renderNewFields(string $idlinea, SalesDocumentLine $line, SalesDocument $model): string
    {
        // cargamos los nuevos campos
        $newFields = [];
        foreach (self::$mods as $mod) {
            foreach ($mod->newFields() as $field) {
                if (false === in_array($field, $newFields)) {
                    $newFields[] = $field;
                }
            }
        }

        // renderizamos los campos
        $html = '';
        foreach ($newFields as $field) {
            foreach (self::$mods as $mod) {
                $fieldHtml = $mod->renderField($idlinea, $line, $model, $field);
                if ($fieldHtml !== null) {
                    $html .= $fieldHtml;
                    break;
                }
            }
        }
        return $html;
    }

    private static function renderNewTitles(SalesDocument $model): string
    {
        // cargamos los nuevos campos
        $newFields = [];
        foreach (self::$mods as $mod) {
            foreach ($mod->newTitles() as $field) {
                if (false === in_array($field, $newFields)) {
                    $newFields[] = $field;
                }
            }
        }

        // renderizamos los campos
        $html = '';
        foreach ($newFields as $field) {
            foreach (self::$mods as $mod) {
                $fieldHtml = $mod->renderTitle($model, $field);
                if ($fieldHtml !== null) {
                    $html .= $fieldHtml;
                    break;
                }
            }
        }
        return $html;
    }

    private static function renderTitle(SalesDocument $model, string $field): ?string
    {
        foreach (self::$mods as $mod) {
            $html = $mod->renderTitle($model, $field);
            if ($html !== null) {
                return $html;
            }
        }

        switch ($field) {
            case '_actionsButton':
                return self::titleActionsButton($model);

            case '_total':
                return self::titleTotal();

            case 'cantidad':
                return self::titleCantidad();

            case 'codimpuesto':
                return self::titleCodimpuesto();

            case 'descripcion':
                return self::titleDescripcion();

            case 'dtopor':
                return self::titleDtopor();

            case 'pvpunitario':
                return self::titlePrecio();

            case 'referencia':
                return self::titleReferencia();
        }

        return null;
    }

    private static function renderTitles(SalesDocument $model): string
    {
        return '<div class="container-fluid d-none d-lg-block titles"><div class="row g-3 border-bottom">'
            . self::renderTitle($model, 'referencia')
            . self::renderTitle($model, 'descripcion')
            . self::renderTitle($model, 'cantidad')
            . self::renderNewTitles($model)
            . self::renderTitle($model, 'pvpunitario')
            . self::renderTitle($model, 'dtopor')
            . self::renderTitle($model, 'codimpuesto')
            . self::renderTitle($model, '_total')
            . self::renderTitle($model, '_actionsButton')
            . '</div></div>';
    }
}
