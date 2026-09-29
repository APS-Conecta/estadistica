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
	 */
	public static function workbook(array $sheets, array $merges = []): string {
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

		return self::raw($parts);
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
	 */
	public static function register(array $establishments, array $leaveOut = []): string {
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

		return self::workbook(['Base de Establecimientos' => $rows]);
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
