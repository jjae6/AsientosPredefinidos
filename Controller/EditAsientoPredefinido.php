<?php
/**
 * This file is part of AsientoPredefinido plugin for FacturaScripts
 * Copyright (C) 2021-2025 Carlos Garcia Gomez <carlos@facturascripts.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace FacturaScripts\Plugins\AsientosPredefinidos\Controller;

use FacturaScripts\Core\Where;
use FacturaScripts\Core\Lib\ExtendedController\EditController;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Plugins\AsientosPredefinidos\Model\AsientoPredefinidoAyuda;

class EditAsientoPredefinido extends EditController
{
    /** @var AsientoPredefinidoAyuda|null */
    public $ayuda = null;

    public function getModelClassName(): string
    {
        return 'AsientoPredefinido';
    }

    public function getPageData(): array
    {
        $page          = parent::getPageData();
        $page['menu']  = 'accounting';
        $page['title'] = 'predefined-acc-entry';
        $page['icon']  = 'fa-solid fa-blender';
        return $page;
    }

    protected function createViews()
    {
        parent::createViews();
        $this->setTabsPosition('bottom');
        $this->createViewsInfo();
        $this->createViewsGenerar();
        $this->createViewsLineas();
        $this->createViewsVariables();
        $this->createViewsAyuda();
        $this->createViewsAsientos();
    }

    protected function createViewsGenerar(string $viewName = 'Generar'): void
    {
        $this->addHtmlView($viewName, 'AsientoPredefinidoGenerar', 'AsientoPredefinido', 'generate', 'fa-solid fa-wand-magic-sparkles');
    }

    protected function createViewsInfo(string $viewName = 'Info'): void
    {
        $this->addHtmlView($viewName, 'AsientoPredefinidoInfo', 'AsientoPredefinido', 'help', 'fa-solid fa-info-circle');
    }

    protected function createViewsLineas(string $viewName = 'EditAsientoPredefinidoLinea'): void
    {
        $this->addEditListView($viewName, 'AsientoPredefinidoLinea', 'lines')
            ->setInLine(true);
    }

    protected function createViewsVariables(string $viewName = 'EditAsientoPredefinidoVariable'): void
    {
        $this->addEditListView($viewName, 'AsientoPredefinidoVariable', 'variables', 'fa-solid fa-tools')
            ->setInLine(true);
    }

    protected function createViewsAyuda(string $viewName = 'EditAsientoPredefinidoAyuda'): void
    {
        $this->addHtmlView($viewName, 'AsientoPredefinidoAyuda', 'AsientoPredefinido', 'help-example', 'fa-solid fa-circle-info');
    }

    protected function createViewsAsientos(string $viewName = 'ListAsiento'): void
    {
        $this->addListView($viewName, 'Asiento', 'generated-acc-entries', 'fa-solid fa-balance-scale')
            ->addSearchFields(['concepto', 'numero'])
            ->addOrderBy(['fecha', 'numero'], 'date', 2)
            ->addOrderBy(['numero'], 'number')
            ->addOrderBy(['importe'], 'amount')
            ->setSettings('btnNew', false);
    }

    protected function execAfterAction($action)
    {
        switch ($action) {
            case 'gen-accounting':
                $this->generateAccountingAction();
                return;

            case 'save-ayuda':
                $this->saveAyudaAction();
                return;

            case 'restore-asiento':
                $this->restoreAsientoAction();
                return;

            case 'insert':
            case 'save':
            case 'delete':
                // Al insertar, guardar o borrar líneas, variables o descripción,
                // marcar el asiento completo como personalizado.
                parent::execAfterAction($action);
                $this->marcarPersonalizado();
                return;
        }

        parent::execAfterAction($action);
    }

    /**
     * Marca el asiento como personalizado=1 al guardar cualquier cambio.
     */
    protected function marcarPersonalizado(): void
    {
        // Intentar obtener el id del asiento por múltiples vías
        $id = $this->getViewModelValue($this->getMainViewName(), 'id');

        if (!$id && isset($this->views[$this->getMainViewName()])) {
            $id = $this->views[$this->getMainViewName()]->model->id ?? null;
        }

        // Desde la URL: ?code=157 (el asiento padre)
        if (!$id) {
            $id = $this->request->query->get('code') ?? null;
        }

        // Desde el POST: idasientopre viene en las líneas y variables
        if (!$id) {
            $id = $this->request->request->get('idasientopre') ?? null;
        }

        if (!$id) return;

        $db = new DataBase();
        $db->exec(
            'UPDATE `asientospre` SET `personalizado` = 1'
            . ' WHERE `id` = ' . $db->var2str($id)
            . ' AND COALESCE(`personalizado`, 0) = 0'
        );

        if (isset($this->views[$this->getMainViewName()])) {
            $this->views[$this->getMainViewName()]->model->personalizado = 1;
        }
    }

    protected function generateAccountingAction(): void
    {
        $form = $this->request->request->all();
        if (false === $this->validateFormToken()) return;

        if (empty($form['idempresa'])) {
            Tools::log()->warning('required-field', ['%field%' => Tools::lang()->trans('company')]);
            return;
        }
        if (empty($form['fecha'])) {
            Tools::log()->warning('required-field', ['%field%' => Tools::lang()->trans('date')]);
            return;
        }

        $asiento = $this->getModel()->generate($form);
        if ($asiento->exists()) {
            Tools::log()->notice('generated-accounting-entries', ['%quantity%' => 1]);
            $this->redirect($asiento->url() . '&action=save-ok', 1);
            return;
        }

        Tools::log()->warning('record-save-error');
    }

    /**
     * Guarda la ayuda editada y marca el asiento como personalizado.
     */
    protected function saveAyudaAction(): void
    {
        if (false === $this->validateFormToken()) return;

        $idasientopre = (int)$this->request->request->get('idasientopre', 0);
        if ($idasientopre <= 0) {
            Tools::log()->warning('invalid-data-error');
            return;
        }

        $cuando  = strip_tags($this->request->request->get('cuando',  ''));
        $ejemplo = strip_tags($this->request->request->get('ejemplo', ''));
        $nota    = strip_tags($this->request->request->get('nota',    ''));

        $db  = new DataBase();
        $sql = "INSERT INTO `asientospre_ayudas`
                    (`idasientopre`, `cuando`, `ejemplo`, `nota`)
                VALUES ("
            . $db->var2str($idasientopre) . ', '
            . $db->var2str($cuando)       . ', '
            . $db->var2str($ejemplo)      . ', '
            . $db->var2str($nota)         . ')
                ON DUPLICATE KEY UPDATE
                    `cuando`  = ' . $db->var2str($cuando)  . ',
                    `ejemplo` = ' . $db->var2str($ejemplo) . ',
                    `nota`    = ' . $db->var2str($nota)    . ';';

        if ($db->exec($sql)) {
            // Marcar el asiento como personalizado
            $db->exec(
                'UPDATE `asientospre` SET `personalizado` = 1'
                . ' WHERE `id` = ' . $db->var2str($idasientopre)
                . ' AND COALESCE(`personalizado`, 0) = 0'
            );
            if (isset($this->views[$this->getMainViewName()])) {
                $this->views[$this->getMainViewName()]->model->personalizado = 1;
            }
            $this->ayuda = AsientoPredefinidoAyuda::getByAsiento($idasientopre);
            Tools::log()->notice('record-updated-correctly');
        } else {
            Tools::log()->error('record-save-error');
        }
    }

    /**
     * Restaura el asiento al estado del plugin:
     * - personalizado=0 → el próximo Actualizar repone líneas, variables y ayuda
     * - personalizado=0 → el próximo Actualizar repone líneas, variables y ayuda
     */
    protected function restoreAsientoAction(): void
    {
        if (false === $this->validateFormToken()) return;

        // El botón de la barra principal envía 'code'; el form oculto envía 'idasientopre'
        $id = (int)$this->request->request->get('idasientopre', 0)
           ?: (int)$this->request->request->get('code', 0);
        if ($id <= 0) return;

        $db  = new DataBase();
        $idS = $db->var2str($id);

        // Verificar que el asiento existe en el CSV antes de restaurar.
        // Si es un asiento creado por el usuario (no está en el CSV), no hacer nada.
        $csvPath = implode(DIRECTORY_SEPARATOR, [
            \FacturaScripts\Core\Tools::folder('Plugins'),
            'AsientosPredefinidos', 'Data', 'Codpais', 'ESP', 'asientospre.csv'
        ]);
        $existeEnCsv = false;
        if (file_exists($csvPath)) {
            $handle = fopen($csvPath, 'r');
            if ($handle) {
                fgetcsv($handle); // cabecera
                while (($row = fgetcsv($handle)) !== false) {
                    if (isset($row[0]) && (int)$row[0] === $id) {
                        $existeEnCsv = true;
                        break;
                    }
                }
                fclose($handle);
            }
        }

        if (!$existeEnCsv) {
            Tools::log()->warning('restore-entry-not-in-csv');
            return;
        }

        // 1. Quitar protección
        $db->exec("UPDATE `asientospre` SET `personalizado` = 0 WHERE `id` = {$idS}");

        // 2. Borrar líneas actuales y reimportar desde CSV
        $db->exec("DELETE FROM `asientospre_lineas` WHERE `idasientopre` = {$idS}");
        $this->importFromCsvForAsiento(
            $db, 'asientospre_lineas',
            ['codsubcuenta','concepto','codcontrapartida','debe','haber','id','idasientopre','orden'],
            6, $id
        );

        // 3. Borrar variables actuales y reimportar desde CSV
        $db->exec("DELETE FROM `asientospre_variables` WHERE `idasientopre` = {$idS}");
        $this->importFromCsvForAsiento(
            $db, 'asientospre_variables',
            ['codigo','mensaje','id','idasientopre'],
            3, $id
        );

        // 4. Restaurar ayuda desde CSV (sobreescribe lo editado)
        $this->restoreAyudaFromCsv($db, $id);

        Tools::log()->notice('predefined-entry-restored');

        // Redirigir manteniendo la pestaña activa y mostrando el mensaje
        $model      = $this->getModel();
        $model->loadFromCode($id);
        $activeTab  = $this->request->request->get('activetab', '');
        $url        = $model->url() . ($activeTab ? '&activetab=' . $activeTab : '');
        $this->redirect($url, 5);
    }

    /**
     * Reimporta desde CSV solo las filas de un asiento concreto.
     * $idxPadre = índice de columna que contiene idasientopre en el CSV.
     */
    private function importFromCsvForAsiento(
        DataBase $db, string $table, array $columns, int $idxPadre, int $id
    ): void {
        $base = implode(DIRECTORY_SEPARATOR, [
            \FacturaScripts\Core\Tools::folder('Plugins'),
            'AsientosPredefinidos', 'Data', 'Codpais', 'ESP', $table . '.csv'
        ]);
        if (!file_exists($base)) return;

        $handle = fopen($base, 'r');
        if (!$handle) return;
        fgetcsv($handle); // cabecera

        $colList = '`' . implode('`,`', $columns) . '`';
        $batch   = [];

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < count($columns)) continue;
            if ((int)$row[$idxPadre] !== $id) continue;

            $vals = [];
            foreach ($columns as $i => $_) {
                $vals[] = $db->var2str($row[$i]);
            }
            $batch[] = '(' . implode(',', $vals) . ')';
        }
        fclose($handle);

        if (!empty($batch)) {
            $db->exec("INSERT INTO `{$table}` ({$colList}) VALUES " . implode(',', $batch) . ';');
        }
    }

    /**
     * Restaura la ayuda desde el CSV.
     */
    private function restoreAyudaFromCsv(DataBase $db, int $id): void
    {
        $base = implode(DIRECTORY_SEPARATOR, [
            \FacturaScripts\Core\Tools::folder('Plugins'),
            'AsientosPredefinidos', 'Data', 'Codpais', 'ESP', 'asientospre_ayudas.csv'
        ]);
        if (!file_exists($base)) return;

        $handle = fopen($base, 'r');
        if (!$handle) return;
        fgetcsv($handle);

        $cuando = $ejemplo = $nota = null;
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < 5 || (int)$row[1] !== $id) continue;
            $cuando  = $row[2];
            $ejemplo = $row[3];
            $nota    = $row[4];
            break;
        }
        fclose($handle);

        if ($cuando === null) {
            // Si no hay ayuda en el CSV, al menos quitar la protección
            return;
        }

        $idS     = $db->var2str($id);
        $cS      = $db->var2str($cuando);
        $eS      = $db->var2str($ejemplo);
        $nS      = $db->var2str($nota);

        $sql = 'INSERT INTO `asientospre_ayudas`'
             . ' (`idasientopre`,`cuando`,`ejemplo`,`nota`)'
             . ' VALUES (' . $idS . ',' . $cS . ',' . $eS . ',' . $nS . ')'
             . ' ON DUPLICATE KEY UPDATE'
             . ' `cuando`  = ' . $cS . ','
             . ' `ejemplo` = ' . $eS . ','
             . ' `nota`    = ' . $nS . ';';
        $db->exec($sql);
    }





    protected function loadData($viewName, $view)
    {
        $id = $this->getViewModelValue($this->getMainViewName(), 'id');

        switch ($viewName) {
            case 'EditAsientoPredefinidoLinea':
                $where = [Where::eq('idasientopre', $id)];
                $view->loadData('', $where, ['orden' => 'ASC', 'idasientopre' => 'ASC']);
                break;

            case 'EditAsientoPredefinidoVariable':
                $where = [Where::eq('idasientopre', $id)];
                $view->loadData('', $where, ['idasientopre' => 'ASC', 'codigo' => 'ASC']);
                break;

            case 'ListAsiento':
                $where = [Where::eq('idasientopre', $id)];
                $view->loadData('', $where);
                break;

            default:
                parent::loadData($viewName, $view);
                if ($id && $this->ayuda === null) {
                    $this->ayuda = AsientoPredefinidoAyuda::getByAsiento((int)$id);
                }


                break;
        }
    }
}
