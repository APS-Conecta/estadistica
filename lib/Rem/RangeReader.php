<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Estadistica\Rem;

use OCA\Estadistica\Exception\RefusedException;

/**
 * Bytes of a zip at an offset, wherever the zip is.
 *
 * The importer reads the central directory and then only the member it needs
 * (acceptance a3), so it never needs the zip whole. From DEIS that is two
 * ≈ 66 KB Range requests plus one Serie A or P member (HttpRange, Slice 3);
 * from an admin's drop it is a local copy (LocalRange). One importer serves
 * both (docs/adr/0004).
 *
 * Every failure is a RefusedException whose message is the sentence the admin
 * section's state table shows.
 */
interface RangeReader {
	/** @throws RefusedException */
	public function size(): int;

	/**
	 * Exactly $length bytes from $offset.
	 *
	 * @throws RefusedException
	 */
	public function read(int $offset, int $length): string;

	/**
	 * A readable stream that starts at $offset. The caller reads at most
	 * $length bytes from it and closes it. For a network reader that is one
	 * Range request for those bytes, never the zip.
	 *
	 * @return resource
	 * @throws RefusedException
	 */
	public function stream(int $offset, int $length);
}
