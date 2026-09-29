<?php
declare(strict_types=1);
/**
 * Admin section host. The establishment block is rendered here, server-side,
 * rather than by the bundle: the notice that it is missing has to stand even
 * when the script fails to load, and smoke reads it (acceptance a1). The rest
 * of the section mounts on #estadistica-admin.
 *
 * @var array{establishment: array{deisCode: string, type: string, comunaCut: string, configured: bool}} $_
 */
$establishment = $_['establishment'];
?>
<div class="section" id="estadistica-establishment">
	<h2>Establecimiento</h2>
	<?php if ($establishment['configured']): ?>
		<p>
			Esta instalación corresponde al establecimiento con código DEIS
			<strong><?php p($establishment['deisCode']); ?></strong><?php if ($establishment['type'] !== ''): ?>
			(<?php p($establishment['type']); ?>)<?php endif; ?>. Lo definió el instalador al elegir
			el centro; se cambia desde el instalador, no desde aquí.
		</p>
	<?php else: ?>
		<p role="status">
			<strong>Todavía no se ha configurado el establecimiento de esta instalación</strong>, o su
			código DEIS no es válido. Las cifras nacionales y de pares se muestran igual; las propias
			aparecerán cuando el instalador configure el establecimiento.
		</p>
	<?php endif; ?>
</div>
<div id="estadistica-admin">
	<div class="aps-mount-loading" role="status" aria-live="polite">Cargando…</div>
	<noscript><p class="aps-mount-noscript">Esta aplicación necesita JavaScript activado.</p></noscript>
</div>
