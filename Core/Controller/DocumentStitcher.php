<?php
/**
 * This file is part of FacturaScripts
 * Copyright (C) 2017-2024 Carlos Garcia Gomez <carlos@facturascripts.com>
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

namespace FacturaScripts\Core\Controller;

use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\Base\ControllerPermissions;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Model\Base\BusinessDocumentLine;
use FacturaScripts\Core\Model\Base\TransformerDocument;
use FacturaScripts\Core\Response;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Lib\BusinessDocumentGenerator;
use FacturaScripts\Dinamic\Model\CodeModel;
use FacturaScripts\Dinamic\Model\EstadoDocumento;
use FacturaScripts\Dinamic\Model\User;

/**
 * Class DocumentStitcher
 *
 * @author Carlos García Gómez      <carlos@facturascripts.com>
 * @author Francesc Pineda Segarra  <francesc.pineda.segarra@gmail.com>
 */
class DocumentStitcher extends Controller
{
    const MODEL_NAMESPACE = '\\FacturaScripts\\Dinamic\\Model\\';

    /** @var array */
    public $codes = [];

    /** @var TransformerDocument[] */
    public $documents = [];

    /** @var string */
    public $modelName;

    /** @var TransformerDocument[] */
    public $moreDocuments = [];

    public function getAvailableStatus(): array
    {
        $status = [];
        $where = [
            new DataBaseWhere('activo', true),
            new DataBaseWhere('tipodoc', $this->modelName)
        ];
        foreach (EstadoDocumento::all($where) as $docState) {
            if ($docState->generadoc) {
                $status[] = $docState;
            }
        }

        return $status;
    }

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'sales';
        $data['title'] = 'group-or-split';
        $data['icon'] = 'fa-solid fa-wand-magic-sparkles';
        $data['showonmenu'] = false;
        return $data;
    }

    public function getSeries(): array
    {
        return CodeModel::all('series', 'codserie', 'descripcion', false);
    }

    /**
     * Runs the controller's private logic.
     *
     * @param Response $response
     * @param User $user
     * @param ControllerPermissions $permissions
     */
    public function privateCore(&$response, $user, $permissions)
    {
        parent::privateCore($response, $user, $permissions);

        $this->codes = $this->getCodes();
        $this->modelName = $this->getModelName();

        // no se pueden agrupar o partir facturas
        if (in_array($this->modelName, ['FacturaCliente', 'FacturaProveedor'])) {
            $this->redirect('List' . $this->modelName);
            return;
        }

        $this->loadDocuments();
        $this->loadMoreDocuments();

        $statusCode = $this->request->request->get('status', '');
        if ($statusCode) {
            // validate form request?
            if (false === $this->validateFormToken()) {
                return;
            }

            // si el $statusCode empieza por close:, cerramos
            if (0 === strpos($statusCode, 'close:')) {
                $status = substr($statusCode, 6);
                $this->closeDocuments((int)$status);
            } else {
                $this->generateNewDocument((int)$statusCode);
            }
        }
    }

    /**
     * @param array $newLines
     * @param TransformerDocument $doc
     */
    protected function addBlankLine(array &$newLines, $doc): void
    {
        $blankLine = $doc->getNewLine([
            'cantidad' => 0,
            'mostrar_cantidad' => false,
            'mostrar_precio' => false
        ]);

        $this->pipe('addBlankLine', $blankLine);
        $newLines[] = $blankLine;
    }

    /**
     * @param TransformerDocument $newDoc
     *
     * @return bool
     */
    protected function addDocument($newDoc): bool
    {
        foreach ($this->documents as $doc) {
            if ($doc->codalmacen != $newDoc->codalmacen ||
                $doc->coddivisa != $newDoc->coddivisa ||
                $doc->idempresa != $newDoc->idempresa ||
                $doc->dtopor1 != $newDoc->dtopor1 ||
                $doc->dtopor2 != $newDoc->dtopor2 ||
                $doc->subjectColumnValue() != $newDoc->subjectColumnValue()) {
                Tools::log()->warning('incompatible-document', ['%code%' => $newDoc->codigo]);
                return false;
            }
        }

        $this->documents[] = $newDoc;
        return true;
    }

    /**
     * @param array $newLines
     * @param TransformerDocument $doc
     */
    protected function addInfoLine(array &$newLines, $doc): void
    {
        $infoLine = $doc->getNewLine([
            'cantidad' => 0,
            'descripcion' => $this->getDocInfoLineDescription($doc),
            'mostrar_cantidad' => false,
            'mostrar_precio' => false
        ]);
        $this->pipe('addInfoLine', $infoLine);
        $newLines[] = $infoLine;
    }

    /**
     * @param TransformerDocument $doc
     * @param BusinessDocumentLine $docLines
     * @param array $newLines
     * @param array $quantities
     * @param int $idestado
     */
    protected function breakDownLines(&$doc, &$docLines, &$newLines, &$quantities, $idestado): void
    {
        $full = true;
        foreach ($docLines as $line) {
            $quantity = (float)$this->request->request->get('approve_quant_' . $line->primaryColumnValue(), '0');
            $quantities[$line->primaryColumnValue()] = $quantity;

            if (empty($quantity) && $line->cantidad) {
                $full = $full && $line->servido >= $line->cantidad;
                continue;
            } elseif (($quantity + $line->servido) < $line->cantidad) {
                $full = false;
            }

            $this->pipe('breakDownLines', $line);
            $newLines[] = $line;
        }

        if ($full) {
            $doc->setDocumentGeneration(false);
            $doc->idestado = $idestado;
            if (false === $doc->save()) {
                $this->dataBase->rollback();
                Tools::log()->error('record-save-error');
                return;
            }
        }

        // we get the lines again in case they have been updated
        foreach ($doc->getLines() as $line) {
            $line->servido += $quantities[$line->primaryColumnValue()];
            if (false === $line->save()) {
                $this->dataBase->rollback();
                Tools::log()->error('record-save-error');
                return;
            }
        }
    }

    protected function closeDocuments(int $idestado): void
    {
        $this->dataBase->beginTransaction();

        foreach ($this->documents as $doc) {
            $doc->setDocumentGeneration(false);
            $doc->idestado = $idestado;
            if (false === $doc->save()) {
                $this->dataBase->rollback();
                Tools::log()->error('record-save-error');
                return;
            }
        }

        $this->dataBase->commit();
        Tools::log()->notice('record-updated-correctly');
    }

    /**
     * Generates a new document with this data.
     *
     * @param int $idestado
     */
    protected function generateNewDocument(int $idestado): void
    {
        $this->dataBase->beginTransaction();

        // group needed data
        $newLines = [];
        $properties = ['fecha' => $this->request->request->get('fecha', '')];
        $prototype = null;
        $quantities = [];
        foreach ($this->documents as $doc) {
            $lines = $doc->getLines();

            if (null === $prototype) {
                $prototype = clone $doc;
                $prototype->codserie = $this->request->request->get('codserie', $doc->codserie);
            } elseif ('true' === $this->request->request->get('extralines', '') && !empty($lines)) {
                $this->addBlankLine($newLines, $doc);
            }

            if ('true' === $this->request->request->get('extralines', '') && !empty($lines)) {
                $this->addInfoLine($newLines, $doc);
            }

            // we break down quantities and lines
            $this->breakDownLines($doc, $lines, $newLines, $quantities, $idestado);
        }

        if (null === $prototype || empty($newLines)) {
            $this->dataBase->rollback();
            return;
        }

        // allow plugins to do stuff on the prototype before save
        if (false === $this->pipe('checkPrototype', $prototype, $newLines)) {
            $this->dataBase->rollback();
            return;
        }

        // generate new document
        $generator = new BusinessDocumentGenerator();
        $newClass = $this->getGenerateClass($idestado);
        if (empty($newClass)) {
            $this->dataBase->rollback();
            return;
        }

        if (false === $generator->generate($prototype, $newClass, $newLines, $quantities, $properties)) {
            $this->dataBase->rollback();
            Tools::log()->error('record-save-error');
            return;
        }

        $this->dataBase->commit();

        // redirect to the new document
        foreach ($generator->getLastDocs() as $doc) {
            $this->redirect($doc->url());
            Tools::log()->notice('record-updated-correctly');
            break;
        }
    }

    /**
     * Returns documents keys.
     *
     * @return array
     */
    protected function getCodes(): array
    {
        $codes = $this->request->request->getArray('codes');
        if ($codes) {
            return $codes;
        }

        $codes = explode(',', $this->request->get('codes', ''));
        $newcodes = $this->request->getArray('newcodes');
        return empty($newcodes) ? $codes : array_merge($codes, $newcodes);
    }

    /**
     * @param TransformerDocument $doc
     *
     * @return string
     */
    protected function getDocInfoLineDescription($doc): string
    {
        $description = Tools::lang()->trans($doc->modelClassName() . '-min') . ' ' . $doc->codigo;

        if (isset($doc->numero2) && $doc->numero2) {
            $description .= ' (' . $doc->numero2 . ')';
        } elseif (isset($doc->numproveedor) && $doc->numproveedor) {
            $description .= ' (' . $doc->numproveedor . ')';
        }

        $description .= ', ' . $doc->fecha . "\n--------------------";
        return $description;
    }

    /**
     * Returns the name of the new class to generate from this status.
     *
     * @param int $idestado
     *
     * @return ?string
     */
    protected function getGenerateClass(int $idestado): ?string
    {
        $estado = new EstadoDocumento();
        $estado->loadFromCode($idestado);
        return $estado->generadoc;
    }

    /**
     * Returns model name.
     *
     * @return string
     */
    protected function getModelName(): string
    {
        $model = $this->request->get('model', '');
        return $this->request->request->get('model', $model);
    }

    /**
     * Loads selected documents.
     */
    protected function loadDocuments(): void
    {
        if (empty($this->codes) || empty($this->modelName)) {
            return;
        }

        $modelClass = self::MODEL_NAMESPACE . $this->modelName;
        foreach ($this->codes as $code) {
            $doc = new $modelClass();
            if ($doc->loadFromCode($code)) {
                $this->addDocument($doc);
            }
        }

        // sort by date
        uasort($this->documents, function ($doc1, $doc2) {
            if (strtotime($doc1->fecha . ' ' . $doc1->hora) > strtotime($doc2->fecha . ' ' . $doc2->hora)) {
                return 1;
            } elseif (strtotime($doc1->fecha . ' ' . $doc1->hora) < strtotime($doc2->fecha . ' ' . $doc2->hora)) {
                return -1;
            }

            return 0;
        });
    }

    protected function loadMoreDocuments(): void
    {
        if (empty($this->documents) || empty($this->modelName)) {
            return;
        }

        $modelClass = self::MODEL_NAMESPACE . $this->modelName;
        $model = new $modelClass();
        $where = [
            new DataBaseWhere('codalmacen', $this->documents[0]->codalmacen),
            new DataBaseWhere('coddivisa', $this->documents[0]->coddivisa),
            new DataBaseWhere('codserie', $this->documents[0]->codserie),
            new DataBaseWhere('dtopor1', $this->documents[0]->dtopor1),
            new DataBaseWhere('dtopor2', $this->documents[0]->dtopor2),
            new DataBaseWhere('editable', true),
            new DataBaseWhere('idempresa', $this->documents[0]->idempresa),
            new DataBaseWhere($model->subjectColumn(), $this->documents[0]->subjectColumnValue())
        ];
        $orderBy = ['fecha' => 'ASC', 'hora' => 'ASC'];
        foreach ($model->all($where, $orderBy, 0, 0) as $doc) {
            if (false === in_array($doc->primaryColumnValue(), $this->getCodes())) {
                $this->moreDocuments[] = $doc;
            }
        }
    }
}
