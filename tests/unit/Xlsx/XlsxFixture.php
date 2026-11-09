<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Tests\Unit\Xlsx;

use OCA\Estadistica\Register\RegisterParser;
use ZipArchive;

/**
 * Synthetic workbooks, written to sys_get_temp_dir() at test time. No DEIS
 * dictionary or register ships in the repo (acceptance a2). The shapes are
 * DEIS's, as measured on the real files (design, 2026-09-28), but every label
 * is invented, and every establishment is fabricated (900001 and up, in an
 * invented región 99).
 */
final class XlsxFixture {
	private const MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

	private const RELATIONSHIPS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

	/** @var list<string> */
	private static array $made = [];

	/**
	 * A zip of exactly these parts, for what a well-formed workbook cannot
	 * show (XlsxReaderTest).
	 *
	 * @param array<string, string> $parts path inside the zip → content
	 */
	public static function raw(array $parts): string {
		$path = self::path();
		$zip = new ZipArchive();
		$zip->open($path, ZipArchive::OVERWRITE);
		foreach ($parts as $name => $content) {
			$zip->addFromString($name, $content);
		}
		$zip->close();

		return $path;
	}

	/**
	 * A workbook. Each sheet is row number → column letter → value: a string
	 * is written as a shared string, an int as a number.
	 *
	 * @param array<string, array<int, array<string, string|int>>> $sheets name → rows
	 * @param array<string, list<string>> $merges name → ranges («B9:C10»)
	 * @param array<string, string> $extra more parts, as they go inside the zip (xl/vbaProject.bin)
	 */
	public static function workbook(array $sheets, array $merges = [], array $extra = []): string {
		$strings = [];
		$parts = [];
		$entries = '';
		$relations = '';
		$n = 0;

		foreach ($sheets as $name => $rows) {
			$n++;
			ksort($rows);
			$data = '';
			foreach ($rows as $row => $cells) {
				$data .= sprintf('<row r="%d">', $row);
				foreach ($cells as $column => $value) {
					if (is_int($value)) {
						$data .= sprintf('<c r="%s%d"><v>%d</v></c>', $column, $row, $value);
					} else {
						$strings[$value] ??= count($strings);
						$data .= sprintf('<c r="%s%d" t="s"><v>%d</v></c>', $column, $row, $strings[$value]);
					}
				}
				$data .= '</row>';
			}

			$ranges = $merges[$name] ?? [];
			$mergeCells = $ranges === [] ? '' : sprintf(
				'<mergeCells count="%d">%s</mergeCells>',
				count($ranges),
				implode('', array_map(static fn (string $range): string => sprintf('<mergeCell ref="%s"/>', $range), $ranges)),
			);
			$parts["xl/worksheets/sheet$n.xml"] = self::xml(sprintf('<worksheet xmlns="%s"><sheetData>%s</sheetData>%s</worksheet>', self::MAIN, $data, $mergeCells));
			$entries .= sprintf('<sheet name="%s" sheetId="%d" r:id="rId%d"/>', self::escape($name), $n, $n);
			$relations .= sprintf('<Relationship Id="rId%d" Type="%s/worksheet" Target="worksheets/sheet%d.xml"/>', $n, self::RELATIONSHIPS, $n);
		}

		$relations .= sprintf('<Relationship Id="rId%d" Type="%s/sharedStrings" Target="sharedStrings.xml"/>', $n + 1, self::RELATIONSHIPS);
		$parts['xl/workbook.xml'] = self::xml(sprintf('<workbook xmlns="%s" xmlns:r="%s"><sheets>%s</sheets></workbook>', self::MAIN, self::RELATIONSHIPS, $entries));
		$parts['xl/_rels/workbook.xml.rels'] = self::xml(sprintf('<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">%s</Relationships>', $relations));
		$parts['xl/sharedStrings.xml'] = self::xml(sprintf(
			'<sst xmlns="%s" count="%2$d" uniqueCount="%2$d">%3$s</sst>',
			self::MAIN,
			count($strings),
			implode('', array_map(static fn (string $text): string => sprintf('<si><t xml:space="preserve">%s</t></si>', self::escape($text)), array_keys($strings))),
		));

		return self::raw($parts + $extra);
	}

	/**
	 * A DICCIONARIO CODIGOS of Serie A, one hoja, shaped the way DEIS's are:
	 * the NOMBRE and Control sheets beside it, the planilla's form above the
	 * title, merged header blocks, a label merged down over two rows, a
	 * «Total» row, a group row, a código typed as a number, a código listed
	 * twice, a second section whose markers start one column earlier, and the
	 * «CODIGOS ELIMINADOS» list at the foot.
	 *
	 * Its códigos are REM's form (02010420 and 03500366 are ZipFixture's
	 * default ones); 09000001 is left out on purpose, for «sin descripción».
	 */
	public static function dictionary(): string {
		$markers = static fn (string ...$columns): array => array_combine(
			$columns,
			array_map(static fn (int $i): string => sprintf('COL%02d', $i), range(1, count($columns))),
		);

		return self::workbook([
			'NOMBRE' => [1 => ['A' => 'Nombre del establecimiento']],
			'A03' => [
				1 => ['B' => 'SERVICIO DE SALUD'],
				5 => ['B' => 'AÑO: 2099'],
				6 => ['B' => 'REM-A03. HOJA DE PRUEBA'],
				8 => ['B' => 'SECCIÓN A: PRIMERA SECCIÓN'],
				9 => ['B' => 'PRESTACIÓN', 'D' => 'TOTAL', 'G' => 'POR EDAD'],
				10 => ['G' => 'Menos de 1 año', 'I' => '1 año'],
				11 => ['D' => 'Ambos sexos', 'E' => 'Hombres', 'F' => 'Mujeres', 'G' => 'Hombres', 'H' => 'Mujeres', 'I' => 'Hombres', 'J' => 'Mujeres'],
				12 => ['A' => '02010420', 'B' => 'Evaluación', 'C' => 'Normal', ...$markers('D', 'E', 'F', 'G', 'H', 'I', 'J')],
				13 => ['A' => '03500366', 'C' => 'Alterada', ...$markers('D', 'E', 'F', 'G', 'H', 'I', 'J')],
				14 => ['B' => 'Total', 'D' => 0, 'E' => 0, 'F' => 0],
				15 => ['B' => 'Controles de fin de semana'],
				16 => ['A' => 6900300, 'B' => 'Consulta', ...$markers('D', 'E')],
				17 => ['A' => '02010420', 'B' => 'Repetida', ...$markers('D')],
				19 => ['B' => 'SECCIÓN B: SEGUNDA SECCIÓN'],
				20 => ['B' => 'ACTIVIDAD', 'C' => 'Realizadas'],
				21 => ['A' => '03500400', 'B' => 'Visita', ...$markers('C')],
				23 => ['A' => 'CODIGOS ELIMINADOS'],
				24 => ['A' => '09999999'],
			],
			'Control' => [1 => ['A' => 'control']],
		], ['A03' => ['B9:C11', 'D9:F10', 'G9:J9', 'G10:H10', 'I10:J10', 'B12:B13']]);
	}

	/**
	 * DEIS's 2026 layouts as each serie's DICCIONARIO CODIGOS draws them: the
	 * form, with the código inserted as column A and a «COLnn» marker in the
	 * cell where each figure goes. The hojas, rows and columns are the real
	 * dictionaries' (SA A01 and A05, SP P4); the labels and códigos are
	 * invented.
	 */
	private const LAYOUT_2026 = [
		'A' => [
			'A01' => [
				6 => ['B' => 'REM-A01. CONTROLES DE SALUD'],
				8 => ['B' => 'SECCIÓN A: CONTROLES DE PRUEBA'],
				9 => ['B' => 'TIPO DE CONTROL', 'C' => 'PROFESIONAL', 'D' => 'TOTAL', 'E' => 'RANGO ETARIO'],
				10 => ['E' => 'Menos de 4 años', 'F' => '5 - 9 años'],
				11 => ['A' => '01010101', 'B' => 'Preconcepcional', 'C' => 'Médico/a', 'D' => 'COL01', 'E' => 'COL02', 'F' => 'COL03'],
				12 => ['A' => '01010201', 'C' => 'Matrón/a', 'D' => 'COL01', 'E' => 'COL02', 'F' => 'COL03'],
			],
			'A05' => [
				6 => ['B' => 'REM-A05. INGRESOS'],
				8 => ['B' => 'SECCIÓN A: INGRESOS DE PRUEBA'],
				9 => ['B' => 'CONCEPTO', 'C' => 'TOTAL'],
				10 => ['A' => '05225303', 'B' => 'Ingreso', 'C' => 'COL01'],
			],
		],
		'P' => [
			'P4' => [
				6 => ['B' => 'REM-P4. PROGRAMA DE PRUEBA'],
				8 => ['B' => 'SECCIÓN A: PERSONAS EN CONTROL'],
				10 => ['B' => 'CONCEPTO', 'C' => 'TOTAL', 'D' => 'Hombres', 'E' => 'Mujeres'],
				12 => ['A' => 'P4150100', 'B' => 'Personas en control', 'C' => 'COL01', 'D' => 'COL02', 'E' => 'COL03'],
			],
		],
	];

	/** The 2026 versions, as NOMBRE!A9 of each serie's form and dictionary writes them. */
	private const VERSION_2026 = ['A' => 'Versión 1.2: Febrero 2026', 'P' => 'Versión 1.2: Junio 2026'];

	/** The 2026 DICCIONARIO CODIGOS of Serie $serie (A or P), in DEIS's layout. */
	public static function dictionary2026(string $serie): string {
		return self::workbook(['NOMBRE' => self::nombre($serie, []), ...self::LAYOUT_2026[$serie]]);
	}

	/**
	 * A 2026 REM form as an establishment fills it (form()), in DEIS's 2026 layout.
	 *
	 * @param array<string, array<int, int|string>> $figures código → column number → value; any other cell is blank
	 * @param array<string, int|string|null> $nombre NOMBRE's cells to change («B6» => 'JUNIO'), null to blank one
	 * @param array<string, string> $extra more zip parts (xl/vbaProject.bin)
	 */
	public static function form2026(string $serie, array $figures, array $nombre = [], array $extra = []): string {
		return self::form($serie, self::LAYOUT_2026[$serie], $figures, $nombre, $extra);
	}

	/**
	 * A REM form as an establishment fills it: the dictionary's hojas without
	 * their código column, so every cell sits one column left of its marker,
	 * and NOMBRE says who, when and which form (one digit of the code per cell
	 * in C3:H3, the month by name in B6, the year in B7, the version in A9,
	 * the serie in B17), as DEIS's form does.
	 *
	 * @param array<string, array<int, array<string, string>>> $layout the dictionary's hojas: the código in column A, a «COLnn» marker where each figure goes
	 * @param array<string, array<int, int|string>> $figures código → column number → value; any other cell is blank
	 * @param array<string, int|string|null> $nombre NOMBRE's cells to change («B6» => 'JUNIO'), null to blank one
	 * @param array<string, string> $extra more zip parts (xl/vbaProject.bin)
	 */
	public static function form(string $serie, array $layout, array $figures, array $nombre = [], array $extra = []): string {
		$sheets = ['NOMBRE' => self::nombre($serie, $nombre)];
		foreach ($layout as $hoja => $rows) {
			foreach ($rows as $row => $cells) {
				$codigo = $cells['A'] ?? '';
				unset($cells['A']);
				foreach ($cells as $column => $value) {
					if (preg_match('/^COL(\d{2})$/', $value, $marker) === 1) {
						$value = $figures[$codigo][(int)$marker[1]] ?? null;
					}
					if ($value !== null) {
						$sheets[$hoja][$row][chr(ord($column) - 1)] = $value;
					}
				}
			}
		}

		return self::workbook($sheets, [], $extra);
	}

	/**
	 * @param array<string, int|string|null> $changes
	 * @return array<int, array<string, int|string>>
	 */
	private static function nombre(string $serie, array $changes): array {
		$cells = [
			'A1' => 'DATOS DEL ESTABLECIMIENTO', 'C1' => 'CÓDIGOS',
			'A3' => 'NOMBRE ESTABLECIMIENTO/ESTRATEGIA', 'B3' => 'Cesfam de Prueba',
			'C3' => 9, 'D3' => 0, 'E3' => 0, 'F3' => 0, 'G3' => 0, 'H3' => 1,
			'A6' => 'MES:', 'B6' => 'MARZO',
			'A7' => 'AÑO:', 'B7' => 2026,
			'A9' => self::VERSION_2026[$serie],
			'B17' => "SERIE $serie",
			...$changes,
		];

		$rows = [];
		foreach (array_filter($cells, static fn (int|string|null $value): bool => $value !== null) as $reference => $value) {
			$rows[(int)substr($reference, 1)][$reference[0]] = $value;
		}

		return $rows;
	}

	/**
	 * One establishment of a Base de Establecimientos, every field filled
	 * with invented values unless $fields says otherwise.
	 *
	 * @param array<string, string|int|null> $fields RegisterParser::COLUMNS keys
	 * @return array<string, string|int|null>
	 */
	public static function establishment(int $code, array $fields = []): array {
		return $fields + [
			'estab' => $code,
			'nombre' => "Establecimiento $code",
			'tipo' => 'Centro de Salud Familiar (CESFAM)',
			'dependencia' => 'Municipal',
			'nivel' => 'Primario',
			'comuna_cut' => '99001',
			'comuna' => 'Comuna Uno',
			'servicio_codigo' => '91',
			'servicio' => 'Servicio de Salud Uno',
			'region_codigo' => '99',
			'region' => 'Región Uno',
			'estado' => 'Vigente en Operación Habitual',
		];
	}

	/**
	 * A Base de Establecimientos with DEIS's 2025 header, its columns shuffled
	 * and one it does not need first, as the real file has thirty-three of
	 * them. A null field is an empty cell.
	 *
	 * @param list<array<string, string|int|null>> $establishments XlsxFixture::establishment()
	 * @param list<string> $leaveOut row keys whose column the file lacks
	 * @param bool $headerOnlyFirst a sheet before it with the same header and no rows (a summary tab)
	 */
	public static function register(array $establishments, array $leaveOut = [], bool $headerOnlyFirst = false): string {
		$keys = array_values(array_diff(array_reverse(array_keys(RegisterParser::COLUMNS)), $leaveOut));
		$letters = array_map(static fn (int $i): string => chr(ord('B') + $i), array_keys($keys));

		$rows = [1 => ['A' => 'Latitud', ...array_combine($letters, array_map(static fn (string $key): string => RegisterParser::COLUMNS[$key], $keys))]];
		foreach ($establishments as $i => $establishment) {
			$cells = ['A' => '-33.4'];
			foreach ($keys as $j => $key) {
				if (($establishment[$key] ?? null) !== null) {
					$cells[$letters[$j]] = $establishment[$key];
				}
			}
			$rows[$i + 2] = $cells;
		}

		return self::workbook([...($headerOnlyFirst ? ['Resumen' => [1 => $rows[1]]] : []), 'Base de Establecimientos' => $rows]);
	}

	/** Removes every file this class wrote. Called from tearDown(). */
	public static function cleanup(): void {
		foreach (self::$made as $path) {
			if (is_file($path)) {
				unlink($path);
			}
		}
		self::$made = [];
	}

	private static function xml(string $body): string {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . $body;
	}

	private static function escape(string $text): string {
		return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
	}

	private static function path(): string {
		$path = (string)tempnam(sys_get_temp_dir(), 'estadistica-xlsx-');
		self::$made[] = $path;

		return $path;
	}
}
