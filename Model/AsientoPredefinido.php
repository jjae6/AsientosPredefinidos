<?php
/**
 * This file is part of AsientoPredefinido plugin for FacturaScripts
 * Copyright (C) 2021-2025 Carlos Garcia Gomez <carlos@facturascripts.com>
 */

namespace FacturaScripts\Plugins\AsientosPredefinidos\Model;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Where;
use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\Asiento;
use FacturaScripts\Plugins\AsientosPredefinidos\Lib\AsientoPredefinidoGenerator;

class AsientoPredefinido extends ModelClass
{
    use ModelTrait;

    /** @var string */
    public $concepto;

    /** @var string */
    public $descripcion;

    /** @var int */
    public $id;

    /**
     * 0 = asiento del plugin (se actualiza con el CSV)
     * 1 = modificado por el usuario (protegido)
     * @var int
     */
    public $personalizado = 0;

    public function generate(array $form): Asiento
    {
        return AsientoPredefinidoGenerator::generate($this, $form);
    }

    public function getLines(): array
    {
        $line  = new AsientoPredefinidoLinea();
        $where = [Where::eq('idasientopre', $this->id)];
        return $line->all($where);
    }

    public function getVariables(): array
    {
        $variable = new AsientoPredefinidoVariable();
        $where    = [Where::eq('idasientopre', $this->id)];
        return $variable->all($where);
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public static function tableName(): string
    {
        return 'asientospre';
    }

    public function test(): bool
    {
        $this->concepto    = Tools::noHtml($this->concepto);
        $this->descripcion = Tools::noHtml($this->descripcion);

        if (!$this->id) {
            // Registro nuevo creado por el usuario:
            // Asignar un id alto (>= 10000) para evitar colisiones con los ids
            // del CSV del plugin, que nunca superarán ese rango.
            $db      = new DataBase();
            $rows    = $db->select('SELECT MAX(`id`) as maxid FROM `asientospre`');
            $maxId   = (int)($rows[0]['maxid'] ?? 0);
            $this->id = max($maxId + 1, 10000);

            // Marcarlo como personalizado para que el Init.php nunca lo toque
            $this->personalizado = 1;
        }

        return parent::test();
    }

    public function url(string $type = 'auto', string $list = 'ListAsiento?activetab=List'): string
    {
        return parent::url($type, $list);
    }
}
