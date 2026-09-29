<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * What the REM rows join: their labels and their establishments (Slice 4).
 * estadistica_rem and estadistica_ingest are left as Slice 2 made them.
 *
 * - estadistica_code: one row per (año, serie, código) from that year's
 *   DICCIONARIO CODIGOS (Catalog). columnas is the código's column labels as
 *   a JSON object, col → label: a row reads its own, and a section's are the
 *   union of its rows'.
 * - estadistica_dictionary: one row per (año, serie) a dictionary was looked
 *   for. It holds the member's identity for the daily check, how many códigos
 *   it labelled and how many of the rows' códigos it left without a label,
 *   and why it could not be read.
 * - estadistica_establishment: DEIS's Base de Establecimientos, one row per
 *   establishment (Register). Everything but the code is nullable, because the
 *   register leaves cells empty (2025: 78 without a nivel).
 *
 * Each table has one writer, a raw statement that names every NOT NULL column
 * (Catalog, Register). The NOT NULL columns without a default are those, and
 * there is no Entity for lesson #74 to bite. No foreign keys, hasTable()
 * guards.
 */
class Version000004Date20260928000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('estadistica_code')) {
			$table = $schema->createTable('estadistica_code');
			$table->addColumn('anio', Types::SMALLINT, ['notnull' => true]);
			$table->addColumn('serie', Types::STRING, ['notnull' => true, 'length' => 1]);
			$table->addColumn('codigo', Types::STRING, ['notnull' => true, 'length' => 16]);
			// The row's place in the workbook: hojas, secciones and rows in the planilla's order.
			$table->addColumn('orden', Types::INTEGER, ['notnull' => true]);
			$table->addColumn('hoja', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('seccion', Types::TEXT, ['notnull' => true]);
			$table->addColumn('prestacion', Types::TEXT, ['notnull' => true]);
			$table->addColumn('columnas', Types::TEXT, ['notnull' => true]);
			$table->setPrimaryKey(['anio', 'serie', 'codigo']);
		}

		if (!$schema->hasTable('estadistica_dictionary')) {
			$table = $schema->createTable('estadistica_dictionary');
			$table->addColumn('anio', Types::SMALLINT, ['notnull' => true]);
			$table->addColumn('serie', Types::STRING, ['notnull' => true, 'length' => 1]);
			// The member's file name, printable. Null when the zip carries none this app reads.
			$table->addColumn('name', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('member_crc', Types::BIGINT, ['notnull' => false]);
			$table->addColumn('member_size', Types::BIGINT, ['notnull' => false]);
			// JSON, hoja → its title («REM-A03. …»).
			$table->addColumn('sheets', Types::TEXT, ['notnull' => false]);
			$table->addColumn('codes', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('unmapped', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('error', Types::TEXT, ['notnull' => false]);
			// Unix seconds.
			$table->addColumn('checked_at', Types::BIGINT, ['notnull' => true]);
			$table->setPrimaryKey(['anio', 'serie']);
		}

		if (!$schema->hasTable('estadistica_establishment')) {
			$table = $schema->createTable('estadistica_establishment');
			// The six-digit code, as estadistica_rem.estab carries it.
			$table->addColumn('estab', Types::INTEGER, ['notnull' => true]);
			$table->addColumn('nombre', Types::TEXT, ['notnull' => false]);
			$table->addColumn('tipo', Types::TEXT, ['notnull' => false]);
			$table->addColumn('dependencia', Types::TEXT, ['notnull' => false]);
			$table->addColumn('nivel', Types::TEXT, ['notnull' => false]);
			$table->addColumn('comuna_cut', Types::STRING, ['notnull' => false, 'length' => 5]);
			$table->addColumn('comuna', Types::TEXT, ['notnull' => false]);
			$table->addColumn('servicio_codigo', Types::STRING, ['notnull' => false, 'length' => 2]);
			$table->addColumn('servicio', Types::TEXT, ['notnull' => false]);
			$table->addColumn('region_codigo', Types::STRING, ['notnull' => false, 'length' => 2]);
			$table->addColumn('region', Types::TEXT, ['notnull' => false]);
			$table->addColumn('estado', Types::TEXT, ['notnull' => false]);
			$table->setPrimaryKey(['estab']);
		}

		return $schema;
	}
}
