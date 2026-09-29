<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Tests\Unit\Piv;

/**
 * Synthetic FONASA «Inscritos APS» exports, in the shape research measured on
 * the 2025 file: comma-delimited, CRLF line ends, and no BOM. The header is all
 * quoted; in the rows only PERIODO, COD_CENTRO and EDAD_TRAMO are, and
 * DEPENDENCIA_ADMINISTRATIVA keeps FONASA's trailing space. Center codes are
 * 9000xx, outside the register (a2). Nothing is written to disk here: callers
 * stream the text, or hand it to ZipFixture::file().
 */
final class PivFixture {
	public const HEADER = '"PERIODO","SERVICIO_SALUD","REGIÓN","COMUNA","COD_CENTRO","NOMBRE_CENTRO","DEPENDENCIA_ADMINISTRATIVA","TRAMO_FONASA","EDAD_TRAMO","SEXO","TOTAL_INSCRITOS"';

	/** One row, as FONASA writes it. */
	public static function row(string $periodo, int|string $centro, string $tramo, string $edad, string $sexo, int|string $inscritos): string {
		return sprintf(
			'"%s",Servicio De Salud Uno,Región Uno,Comuna Uno,"%s",Centro De Salud Familiar Uno,Municipal ,%s,"%s",%s,%s',
			$periodo,
			$centro,
			$tramo,
			$edad,
			$sexo,
			$inscritos,
		);
	}

	/** @param list<string> $rows */
	public static function csv(array $rows, string $header = self::HEADER): string {
		return implode("\r\n", [$header, ...$rows]) . "\r\n";
	}

	/** @return resource the text as a stream, the way PivReader reads a file */
	public static function stream(string $csv) {
		$stream = fopen('php://memory', 'r+b');
		fwrite($stream, $csv);
		rewind($stream);

		return $stream;
	}
}
