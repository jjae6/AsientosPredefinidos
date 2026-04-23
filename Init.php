<?php

/**
 * This file is part of AsientosPredefinidos plugin for FacturaScripts
 * Copyright (C) 2022-2025 Carlos Garcia Gomez <carlos@facturascripts.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace FacturaScripts\Plugins\AsientosPredefinidos;

use FacturaScripts\Core\Template\InitClass;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Base\DataBase;
use Throwable;

require_once __DIR__ . '/vendor/autoload.php';

class Init extends InitClass
{
    public function init(): void
    {
        $this->loadExtension(new Extension\Controller\ListAsiento());
    }

    public function uninstall(): void {}

    public function update(): void
    {
        $db = new DataBase();

        // ── PASO 1: Garantizar tabla asientospre_ayudas ───────────────────────
        if (!$db->tableExists('asientospre_ayudas')) {
            $this->createAyudasTable($db);
        }

        // ── PASO 2: Garantizar columnas de protección ─────────────────────────
        // Primero personalizado en asientospre (protección a nivel de asiento)
        $this->ensureColumn($db, 'asientospre', 'personalizado', 'TINYINT(1) NOT NULL DEFAULT 0');

        // ── PASO 3: Importar asientospre con UPSERT ───────────────────────────
        // - NO usar REPLACE INTO: haría DELETE+INSERT activando FK CASCADE
        //   y borrando las ayudas del usuario.
        // - Solo actualiza descripcion/concepto si el asiento NO está protegido.
        $this->importAsientosUpsert($db);

        // ── PASO 4: Leer asientos protegidos (personalizado=1) ────────────────
        $protegidos = $this->getAsientosProtegidos($db);

        // ── PASO 5: Importar líneas y variables excluyendo protegidos ─────────
        foreach (['asientospre_lineas', 'asientospre_variables'] as $table) {
            $columns = $table === 'asientospre_lineas'
                ? ['codsubcuenta', 'concepto', 'codcontrapartida', 'debe', 'haber', 'id', 'idasientopre', 'orden']
                : ['codigo', 'mensaje', 'id', 'idasientopre'];

            if (!$db->tableExists($table)) continue;
            $file = $this->csvPath($table);
            if (!file_exists($file)) continue;

            if (!$this->importCsvReplaceFiltered($db, $table, $columns, $file, $protegidos)) {
                Tools::log()->error('asientospredefinidos-import-error: ' . $table);
            }
        }

        // ── PASO 6: Importar ayudas ─────────────────────────────────────────
        if ($db->tableExists('asientospre_ayudas')) {
            $file = $this->csvPath('asientospre_ayudas');
            if (file_exists($file)) {
                if (!$this->importAyudasRespetandoPersonalizadas($db, $file, $protegidos)) {
                    Tools::log()->error('asientospredefinidos-import-error: asientospre_ayudas');
                }
            }
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Añade una columna a una tabla si no existe.
     */
    private function ensureColumn(DataBase $db, string $table, string $column, string $definition): void
    {
        if (!$db->tableExists($table)) return;
        try {
            $db->select("SELECT `{$column}` FROM `{$table}` LIMIT 1");
        } catch (Throwable $e) {
            try {
                $db->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
            } catch (Throwable $e2) {
                Tools::log()->warning("asientospredefinidos-alter-{$column}: " . $e2->getMessage());
            }
        }
    }

    /**
     * Devuelve los IDs de asientos marcados como personalizado=1.
     */
    private function getAsientosProtegidos(DataBase $db): array
    {
        if (!$db->tableExists('asientospre')) return [];
        try {
            $rows = $db->select('SELECT `id` FROM `asientospre` WHERE `personalizado` = 1');
            return array_column($rows, 'id');
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Importa asientospre sin DELETE (para no disparar FK CASCADE).
     * Solo actualiza descripcion/concepto si el asiento NO está protegido.
     */
    private function importAsientosUpsert(DataBase $db): void
    {
        if (!$db->tableExists('asientospre')) return;
        $file = $this->csvPath('asientospre');
        if (!file_exists($file)) return;

        $handle = fopen($file, 'r');
        if ($handle === false) return;
        fgetcsv($handle);

        $batch     = [];
        $batchSize = 50;

        $flush = function (array $b) use ($db): void {
            $vals = implode(', ', $b);
            // Si personalizado=0: actualiza descripcion y concepto desde CSV
            // Si personalizado=1: no toca nada (preserva el asiento del usuario)
            $sql = "INSERT INTO `asientospre` (`id`, `descripcion`, `concepto`)
                    VALUES {$vals}
                    ON DUPLICATE KEY UPDATE
                        `descripcion` = IF(COALESCE(`personalizado`,0)=0, VALUES(`descripcion`), `descripcion`),
                        `concepto`    = IF(COALESCE(`personalizado`,0)=0, VALUES(`concepto`),    `concepto`);";
            if (!$db->exec($sql)) {
                Tools::log()->error('asientospredefinidos-import-error: asientospre');
            }
        };

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < 3) continue;
            $batch[] = '(' . $db->var2str($row[0]) . ','
                           . $db->var2str($row[1]) . ','
                           . $db->var2str($row[2]) . ')';
            if (count($batch) >= $batchSize) {
                $flush($batch);
                $batch = [];
            }
        }
        if (!empty($batch)) $flush($batch);
        fclose($handle);
    }

    /**
     * Importa un CSV con REPLACE INTO, saltando los idasientopre protegidos.
     */
    private function importCsvReplaceFiltered(
        DataBase $db, string $table, array $columns, string $file, array $protegidos
    ): bool {
        $handle = fopen($file, 'r');
        if ($handle === false) return false;
        fgetcsv($handle);

        $idxPadre  = array_search('idasientopre', $columns);
        $protSet   = array_flip($protegidos);
        $colList   = '`' . implode('`, `', $columns) . '`';
        $ok        = true;
        $batch     = [];
        $batchSize = 50;

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < count($columns)) continue;
            // Saltar si el asiento padre está protegido
            if ($idxPadre !== false && isset($protSet[$row[$idxPadre]])) continue;

            $values = [];
            foreach ($row as $i => $val) {
                if ($i >= count($columns)) break;
                $values[] = $db->var2str($val);
            }
            $batch[] = '(' . implode(', ', $values) . ')';

            if (count($batch) >= $batchSize) {
                if (!$db->exec("REPLACE INTO `{$table}` ({$colList}) VALUES " . implode(', ', $batch) . ';')) $ok = false;
                $batch = [];
            }
        }
        if (!empty($batch)) {
            if (!$db->exec("REPLACE INTO `{$table}` ({$colList}) VALUES " . implode(', ', $batch) . ';')) $ok = false;
        }

        fclose($handle);
        return $ok;
    }

    /**
     * Importa ayudas con dos pasos:
     * 1. INSERT IGNORE — inserta solo las que no existen
     * 2. UPDATE CASE WHEN — actualiza las que existen
     *    y cuyo asiento padre no está protegido (personalizado=0)
     */
    private function importAyudasRespetandoPersonalizadas(
        DataBase $db, string $file, array $protegidos
    ): bool {
        $handle = fopen($file, 'r');
        if ($handle === false) return false;
        fgetcsv($handle);

        $protSet     = array_flip($protegidos);
        $insertBatch = [];
        $updateItems = [];
        $batchSize   = 50;
        $ok          = true;

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < 5) continue;

            $idasientopre = $db->var2str($row[1]);
            $rawId        = $row[1];

            // Si el asiento padre está protegido, no tocar la ayuda
            if (isset($protSet[$rawId])) continue;

            $cuando  = $db->var2str($row[2]);
            $ejemplo = $db->var2str($row[3]);
            $nota    = $db->var2str($row[4]);

            $insertBatch[] = "({$idasientopre}, {$cuando}, {$ejemplo}, {$nota})";
            $updateItems[] = [
                'idasientopre' => $idasientopre,
                'cuando'       => $cuando,
                'ejemplo'      => $ejemplo,
                'nota'         => $nota,
            ];

            if (count($insertBatch) >= $batchSize) {
                if (!$this->flushInsertIgnore($db, $insertBatch))        $ok = false;
                if (!$this->flushUpdateNoPersonalizadas($db, $updateItems)) $ok = false;
                $insertBatch = [];
                $updateItems = [];
            }
        }

        if (!empty($insertBatch)) {
            if (!$this->flushInsertIgnore($db, $insertBatch))        $ok = false;
            if (!$this->flushUpdateNoPersonalizadas($db, $updateItems)) $ok = false;
        }

        fclose($handle);
        return $ok;
    }

    private function flushInsertIgnore(DataBase $db, array $batch): bool
    {
        $cols = '(`idasientopre`, `cuando`, `ejemplo`, `nota`)';
        $vals = implode(', ', $batch);
        return $db->exec("INSERT IGNORE INTO `asientospre_ayudas` {$cols} VALUES {$vals};");
    }

    private function flushUpdateNoPersonalizadas(DataBase $db, array $items): bool
    {
        if (empty($items)) return true;

        $caseCuando  = '';
        $caseEjemplo = '';
        $caseNota    = '';
        $inList      = [];

        foreach ($items as $item) {
            $id           = $item['idasientopre'];
            $caseCuando  .= " WHEN {$id} THEN {$item['cuando']}";
            $caseEjemplo .= " WHEN {$id} THEN {$item['ejemplo']}";
            $caseNota    .= " WHEN {$id} THEN {$item['nota']}";
            $inList[]     = $id;
        }

        $inClause = implode(', ', $inList);
        $sql = "UPDATE `asientospre_ayudas`
                SET
                    `cuando`  = CASE `idasientopre` {$caseCuando}  END,
                    `ejemplo` = CASE `idasientopre` {$caseEjemplo} END,
                    `nota`    = CASE `idasientopre` {$caseNota}    END
                WHERE `idasientopre` IN ({$inClause});";

        return $db->exec($sql);
    }

    private function createAyudasTable(DataBase $db): void
    {
        $engine = 'mysql';
        try {
            $rows = $db->select('SELECT version() AS v');
            if (strpos(strtolower($rows[0]['v'] ?? ''), 'postgre') !== false) $engine = 'postgresql';
        } catch (Throwable $e) {}

        try {
            if ($engine === 'postgresql') {
                $db->exec("CREATE TABLE IF NOT EXISTS asientospre_ayudas (
                    id SERIAL NOT NULL, idasientopre INTEGER NOT NULL,
                    cuando TEXT, ejemplo TEXT, nota TEXT,
                                        CONSTRAINT asientospre_ayudas_pkey PRIMARY KEY (id),
                    CONSTRAINT uniq_asientospre_ayudas_idasientopre UNIQUE (idasientopre),
                    CONSTRAINT ca_asientospre_ayudas_asientospre
                        FOREIGN KEY (idasientopre) REFERENCES asientospre (id)
                        ON DELETE CASCADE ON UPDATE CASCADE);");
            } else {
                $db->exec("CREATE TABLE IF NOT EXISTS `asientospre_ayudas` (
                    `id` INT NOT NULL AUTO_INCREMENT, `idasientopre` INT NOT NULL,
                    `cuando` TEXT, `ejemplo` TEXT, `nota` TEXT,
                                PRIMARY KEY (`id`),
                    UNIQUE KEY `uniq_asientospre_ayudas_idasientopre` (`idasientopre`),
                    CONSTRAINT `ca_asientospre_ayudas_asientospre`
                        FOREIGN KEY (`idasientopre`) REFERENCES `asientospre` (`id`)
                        ON DELETE CASCADE ON UPDATE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            }
        } catch (Throwable $e) {
            Tools::log()->warning('asientospredefinidos-create-ayudas: ' . $e->getMessage());
        }
    }

    private function csvPath(string $table): string
    {
        return __DIR__ . DIRECTORY_SEPARATOR . 'Data' . DIRECTORY_SEPARATOR
            . 'Codpais' . DIRECTORY_SEPARATOR . 'ESP' . DIRECTORY_SEPARATOR
            . $table . '.csv';
    }
}
