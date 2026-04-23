<?php
/**
 * This file is part of AsientosPredefinidos plugin for FacturaScripts
 * Copyright (C) 2025 Carlos Garcia Gomez <carlos@facturascripts.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace FacturaScripts\Plugins\AsientosPredefinidos\Model;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;

class AsientoPredefinidoAyuda extends ModelClass
{
    use ModelTrait;

    /** @var int */
    public $id;

    /** @var int */
    public $idasientopre;

    /** @var string */
    public $cuando;

    /** @var string */
    public $ejemplo;

    /** @var string|null */
    public $nota;

    /**
     * 0 = ayuda del CSV original (se puede sobreescribir al actualizar)
     * 1 = editada manualmente por el usuario (nunca se sobreescribe)
     *
     * @var int
     */
    public $personalizada = 0;

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public static function tableName(): string
    {
        return 'asientospre_ayudas';
    }

    public function test(): bool
    {
        // Al guardar desde la interfaz, marcar como personalizada
        $this->personalizada = 1;

        $this->cuando  = strip_tags($this->cuando  ?? '');
        $this->ejemplo = strip_tags($this->ejemplo ?? '');
        $this->nota    = strip_tags($this->nota    ?? '');
        return parent::test();
    }

    /**
     * Devuelve la ayuda para un asiento predefinido.
     * Usa DataBase directamente para evitar checkTable() prematuro.
     */
    public static function getByAsiento(int $idasientopre): ?self
    {
        $db = new DataBase();
        if (!$db->tableExists('asientospre_ayudas')) {
            return null;
        }

        $sql  = 'SELECT * FROM asientospre_ayudas WHERE idasientopre = '
            . $db->var2str($idasientopre) . ' LIMIT 1';
        $rows = $db->select($sql);
        if (empty($rows)) {
            return null;
        }

        $obj = new self();
        $obj->id            = (int)($rows[0]['id'] ?? 0);
        $obj->idasientopre  = (int)($rows[0]['idasientopre'] ?? 0);
        $obj->cuando        = $rows[0]['cuando']  ?? '';
        $obj->ejemplo       = $rows[0]['ejemplo'] ?? '';
        $obj->nota          = $rows[0]['nota']    ?? null;
        $obj->personalizada = (int)($rows[0]['personalizada'] ?? 0);
        return $obj;
    }
}
