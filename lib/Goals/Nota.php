<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Goals;

use APS\Common\Text;
use OCA\Estadistica\AppInfo\Application;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\Task;
use OCP\TaskProcessing\TaskTypes\TextToText;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The nota (CONTEXT.md): a short Spanish text a model writes about what
 * changed in the stored Resumen, through Nextcloud's TaskProcessing. The
 * model's answer is untrusted text (docs/threat-model.md).
 *
 * - **Asked once per change.** request() schedules one text-to-text task,
 *   only when a provider for it is available and a figure changed. The task
 *   carries the fixed INSTRUCTION and the figures that changed, before and
 *   after, as the Resumen screen writes them: aggregates only. It answers
 *   later, to NotaListener, with the fingerprint it was asked for.
 * - **Checked number by number.** check() keeps the answer only when every
 *   number in it, as es-CL writes it, is a figure the Resumen screen shows,
 *   or a year the Resumen names, or a month it names with its year
 *   («11/2099»), never alone, and none is a word of NUMBER_WORDS («dos», «el
 *   doble»); an answer it cannot read is no nota.
 * - **Never in the way.** A provider that fails is logged and leaves no nota;
 *   the rebuild that asked carries on.
 *
 * @psalm-import-type Respuesta from Resumen
 * @psalm-import-type Tarjeta from Resumen
 */
class Nota {
	public const INSTRUCTION = 'Escribe una nota breve en español sobre lo que cambió en el Resumen de las Metas Sanitarias. '
		. 'Di solo lo que cambió, con las cifras exactas tal como aparecen abajo, sin causas y sin consejos. '
		. 'No calcules ni redondees cifras. '
		. 'Escribe cada cifra con dígitos, tal como aparece abajo, nunca con palabras.';

	/**
	 * The number words check() drops, as Text::fold writes them: the cardinals
	 * from «dos» up, and the multiplicative and fraction words. Never «un»,
	 * «una» or «uno», which are also articles and pronouns.
	 */
	private const NUMBER_WORDS = [
		'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve', 'diez',
		'once', 'doce', 'trece', 'catorce', 'quince', 'dieciseis', 'diecisiete', 'dieciocho', 'diecinueve',
		'veinte', 'veintiun', 'veintiuno', 'veintiuna', 'veintidos', 'veintitres', 'veinticuatro',
		'veinticinco', 'veintiseis', 'veintisiete', 'veintiocho', 'veintinueve',
		'treinta', 'cuarenta', 'cincuenta', 'sesenta', 'setenta', 'ochenta', 'noventa',
		'cien', 'ciento', 'doscientos', 'doscientas', 'trescientos', 'trescientas', 'cuatrocientos', 'cuatrocientas',
		'quinientos', 'quinientas', 'seiscientos', 'seiscientas', 'setecientos', 'setecientas',
		'ochocientos', 'ochocientas', 'novecientos', 'novecientas', 'mil', 'millon', 'millones',
		'doble', 'triple', 'mitad', 'tercio',
	];

	private const MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

	public function __construct(
		private IManager $taskProcessing,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Asks for the nota of the change from $before to $after, the Resumen
	 * whose fingerprint is $fingerprint.
	 *
	 * @param Respuesta $before
	 * @param Respuesta $after
	 */
	public function request(array $before, array $after, string $fingerprint): void {
		try {
			$changes = self::changes($before, $after);
			if ($changes === [] || !in_array(TextToText::ID, $this->taskProcessing->getAvailableTaskTypeIds(), true)) {
				return;
			}
			$input = self::INSTRUCTION . "\n\n" . implode("\n", $changes);
			$this->taskProcessing->scheduleTask(new Task(TextToText::ID, ['input' => $input], Application::APP_ID, null, $fingerprint));
		} catch (Throwable $e) {
			$this->logger->warning('Asking for the nota of the Resumen failed', ['exception' => $e]);
		}
	}

	/**
	 * $answer, trimmed, when every number in it is one $resumen shows or
	 * names, and it writes no word of NUMBER_WORDS; else null.
	 *
	 * @param Respuesta $resumen
	 */
	public static function check(string $answer, array $resumen): ?string {
		$answer = trim($answer);
		if (preg_match_all('/\d{1,2}\/\d{4}|\d(?:[\d.,]*\d)?(?:\s*%)?/u', $answer, $numbers) === false
			|| array_intersect(preg_split('/\P{L}+/u', Text::fold($answer)) ?: [], self::NUMBER_WORDS) !== []) {
			return null;
		}
		$allowed = self::allowed($resumen);
		foreach ($numbers[0] as $number) {
			if (!isset($allowed[preg_replace('/\s*%$/u', ' %', $number)])) {
				return null;
			}
		}

		return $answer === '' ? null : $answer;
	}

	/**
	 * One line per figure of a card that differs, before and after.
	 *
	 * @param Respuesta $before
	 * @param Respuesta $after
	 * @return list<string>
	 */
	private static function changes(array $before, array $after): array {
		$cards = static function (array $resumen): array {
			$shown = [];
			foreach ($resumen['metas'] as $meta) {
				$shown["Meta {$meta['codigo']} {$resumen['anio']}"] = self::shown($meta);
			}

			return $shown;
		};
		$was = $cards($before);
		$is = $cards($after);
		$lines = [];
		foreach (array_keys($is + $was) as $title) {
			foreach (array_keys(($is[$title] ?? []) + ($was[$title] ?? [])) as $name) {
				$old = $was[$title][$name] ?? 'sin cifra';
				$new = $is[$title][$name] ?? 'sin cifra';
				if ($old !== $new) {
					$lines[] = "- $title, $name: antes $old, ahora $new.";
				}
			}
		}

		return $lines;
	}

	/**
	 * A card's figures by name, as Resumen.vue writes them: what a meta
	 * waiting for its ruta or its denominador shows none of.
	 *
	 * @param Tarjeta $meta
	 * @return array<string, string>
	 */
	private static function shown(array $meta): array {
		$shown = [];
		if ($meta['estado'] === null && $meta['porcentaje'] !== null) {
			if ($meta['manual']) {
				$shown['cumplimiento informado'] = self::percent($meta['porcentaje']);
			} else {
				$shown['porcentaje'] = self::percent($meta['porcentaje']);
				$shown['numerador'] = self::figure($meta['numerador']);
				$shown['denominador'] = self::figure($meta['denominador']);
				$shown['datos al'] = self::month($meta['datos_al']);
			}
		}
		if ($meta['cumple'] !== null) {
			$shown[$meta['meta_comprometida'] === null ? 'meta nacional' : 'meta comprometida'] = self::percent($meta['meta_comprometida'] ?? $meta['meta_nacional']);
		}
		if (($meta['meta_minima']['porcentaje'] ?? null) !== null) {
			$shown['meta mínima'] = number_format(round($meta['meta_minima']['porcentaje'], 1), 1, ',', '.') . ' %';
		}

		return $shown;
	}

	/**
	 * Every number $resumen shows, as its screen writes it, every year it
	 * names, and every month it names as «11/2099», as keys.
	 *
	 * @param Respuesta $resumen
	 * @return array<string, true>
	 */
	private static function allowed(array $resumen): array {
		$allowed = [(string)$resumen['anio'] => true];
		$named = static function (?string $date) use (&$allowed): void {
			if ($date !== null) {
				$allowed[(string)(int)substr($date, 0, 4)] = true;
				$allowed[(int)substr($date, 5, 2) . '/' . substr($date, 0, 4)] = true;
			}
		};
		foreach ($resumen['metas'] as $meta) {
			// A month's name («noviembre de 2099») matches no number; its year does, and
			// the month only with it («11/2099»): a histórico names every month.
			$allowed += array_fill_keys(array_values(self::shown($meta)), true);
			array_map($named, [$meta['mes'], $meta['datos_al'], $meta['fecha_corte']]);
			if (($meta['meta_minima']['base'] ?? null) !== null) {
				$allowed[(string)$meta['meta_minima']['base']] = true;
			}
			foreach ($meta['historico'] as $year) {
				$allowed[(string)$year['anio']] = true;
				if ($year['cierre'] !== null) {
					$allowed[self::percent($year['cierre'])] = true;
				}
				foreach ($year['meses'] as $mes) {
					$named($mes['mes']);
					if ($mes['porcentaje'] !== null) {
						$allowed[self::percent($mes['porcentaje'])] = true;
						$allowed[self::figure($mes['numerador'])] = true;
						$allowed[self::figure($mes['denominador'])] = true;
					}
				}
			}
		}

		return $allowed;
	}

	/** A figure as formatValue (src/charts.js) writes it: es-CL, at most one decimal. */
	private static function figure(?float $value): string {
		$rounded = round((float)$value, 1);

		return number_format($rounded, floor($rounded) === $rounded ? 0 : 1, ',', '.');
	}

	private static function percent(float $value): string {
		return self::figure($value) . ' %';
	}

	/** 'AAAA-MM' as monthName (src/query.js) writes it: «agosto de 2026». */
	private static function month(?string $yearMonth): string {
		return $yearMonth === null ? 'sin cifra' : self::MONTHS[(int)substr($yearMonth, 5, 2) - 1] . ' de ' . substr($yearMonth, 0, 4);
	}
}
