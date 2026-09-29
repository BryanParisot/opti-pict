( function () {
	'use strict';

	const app = document.getElementById( 'opti-pict-app' );
	if ( ! app || typeof window.optiPict === 'undefined' ) {
		return;
	}

	const config = window.optiPict;
	const elements = {
		idle: document.getElementById( 'opti-pict-idle' ),
		progress: document.getElementById( 'opti-pict-progress' ),
		progressLabel: document.getElementById( 'opti-pict-progress-label' ),
		progressValue: document.getElementById( 'opti-pict-progress-value' ),
		progressBar: document.getElementById( 'opti-pict-progress-bar' ),
		progressDetail: document.getElementById( 'opti-pict-progress-detail' ),
		scanResult: document.getElementById( 'opti-pict-scan-result' ),
		final: document.getElementById( 'opti-pict-final' ),
		finalTitle: document.getElementById( 'opti-pict-final-title' ),
		error: document.getElementById( 'opti-pict-error' ),
		live: document.getElementById( 'opti-pict-live' ),
		scan: document.getElementById( 'opti-pict-scan' ),
		rescan: document.getElementById( 'opti-pict-rescan' ),
		newScan: document.getElementById( 'opti-pict-new-scan' ),
		optimize: document.getElementById( 'opti-pict-optimize' ),
		restore: document.getElementById( 'opti-pict-restore' ),
		restoreCard: document.getElementById( 'opti-pict-restore-card' )
	};

	let scanState = resetScanState();

	function resetScanState() {
		return { ids: [], bytes: 0, estimatedBytes: 0, totalPosts: 0 };
	}

	function request( action, data = {} ) {
		const body = new URLSearchParams( { action, nonce: config.nonce, ...data } );
		return fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		} ).then( async ( response ) => {
			let payload;
			try {
				payload = await response.json();
			} catch ( error ) {
				throw new Error( config.strings.networkError );
			}
			if ( ! response.ok || ! payload.success ) {
				throw new Error( payload.data && payload.data.message ? payload.data.message : config.strings.networkError );
			}
			return payload.data;
		} );
	}

	function formatBytes( bytes ) {
		if ( ! bytes ) return '0 Ko';
		const units = [ 'o', 'Ko', 'Mo', 'Go' ];
		const index = Math.min( Math.floor( Math.log( bytes ) / Math.log( 1024 ) ), units.length - 1 );
		return `${ new Intl.NumberFormat( 'fr-FR', { maximumFractionDigits: 1 } ).format( bytes / Math.pow( 1024, index ) ) } ${ units[ index ] }`;
	}

	function secondsFor( bytes ) {
		return ( bytes * 8 ) / ( config.speedMbps * 1000 * 1000 );
	}

	function formatDuration( seconds ) {
		if ( seconds < 0.05 ) return '< 0,1 s';
		if ( seconds < 60 ) return `${ new Intl.NumberFormat( 'fr-FR', { maximumFractionDigits: 1 } ).format( seconds ) } s`;
		return `${ new Intl.NumberFormat( 'fr-FR', { maximumFractionDigits: 1 } ).format( seconds / 60 ) } min`;
	}

	function setView( name ) {
		elements.idle.hidden = name !== 'idle';
		elements.progress.hidden = name !== 'progress';
		elements.scanResult.hidden = name !== 'result';
		elements.final.hidden = name !== 'final';
		hideError();
	}

	function setProgress( label, current, total, detail ) {
		const percent = total > 0 ? Math.min( 100, Math.round( ( current / total ) * 100 ) ) : 0;
		elements.progressLabel.textContent = label;
		elements.progressValue.textContent = `${ percent } %`;
		elements.progressBar.value = percent;
		elements.progressBar.textContent = `${ percent } %`;
		elements.progressDetail.textContent = detail || '';
	}

	function showError( message ) {
		elements.error.querySelector( 'p' ).textContent = message;
		elements.error.hidden = false;
		elements.live.textContent = message;
	}

	function hideError() {
		elements.error.hidden = true;
		elements.error.querySelector( 'p' ).textContent = '';
	}

	async function scan() {
		scanState = resetScanState();
		setView( 'progress' );
		setProgress( config.strings.scanInProgress, 0, 1, 'Préparation…' );

		try {
			let page = 1;
			let totalPages = 1;
			do {
				const data = await request( 'opti_pict_scan', { page } );
				totalPages = Math.max( 1, data.totalPages );
				scanState.ids.push( ...data.ids );
				scanState.bytes += data.bytes;
				scanState.estimatedBytes += data.estimatedBytes;
				scanState.totalPosts = data.totalPosts;
				setProgress( config.strings.scanInProgress, page, totalPages, `${ Math.min( page * config.batchSize, data.totalPosts ) } image(s) examinée(s)` );
				page++;
			} while ( page <= totalPages );

			showScanResult();
		} catch ( error ) {
			setView( 'idle' );
			showError( error.message );
		}
	}

	function showScanResult() {
		if ( scanState.ids.length === 0 ) {
			setView( 'idle' );
			showError( config.strings.noImage );
			return;
		}

		const saving = Math.max( 0, scanState.bytes - scanState.estimatedBytes );
		document.getElementById( 'opti-pict-scan-summary' ).textContent = `${ scanState.ids.length } image(s) peuvent être traitée(s). Confirmez pour créer et utiliser les versions WebP.`;
		document.getElementById( 'opti-pict-before' ).textContent = formatBytes( scanState.bytes );
		document.getElementById( 'opti-pict-estimated-saving' ).textContent = `≈ ${ formatBytes( saving ) }`;
		document.getElementById( 'opti-pict-estimated-time' ).textContent = `${ formatDuration( secondsFor( scanState.bytes ) ) } → ${ formatDuration( secondsFor( scanState.estimatedBytes ) ) }`;
		setView( 'result' );
		elements.live.textContent = `Analyse terminée. ${ scanState.ids.length } image(s) à optimiser.`;
		elements.optimize.focus();
	}

	async function optimize() {
		setView( 'progress' );
		const totals = { before: 0, after: 0, saved: 0, converted: 0, skipped: 0, errors: 0, optimizedIds: [] };

		for ( let index = 0; index < scanState.ids.length; index++ ) {
			setProgress( config.strings.optimizeProgress, index, scanState.ids.length, `Image ${ index + 1 } sur ${ scanState.ids.length }` );
			try {
				const data = await request( 'opti_pict_optimize', { attachmentId: scanState.ids[ index ] } );
				[ 'before', 'after', 'saved', 'converted', 'skipped' ].forEach( ( key ) => { totals[ key ] += Number( data[ key ] || 0 ); } );
				if ( data.converted > 0 ) totals.optimizedIds.push( data.attachmentId );
			} catch ( error ) {
				totals.errors++;
			}
		}

		setProgress( config.strings.optimizeProgress, scanState.ids.length, scanState.ids.length, config.strings.complete );
		try {
			await request( 'opti_pict_save_report', {
				before: totals.before,
				after: totals.after,
				saved: totals.saved,
				images: scanState.ids.length - totals.errors
			} );
		} catch ( error ) {
			// The visible result remains accurate even if its historical copy cannot be saved.
		}
		showFinal( totals );
	}

	function showFinal( totals ) {
		if ( totals.errors === scanState.ids.length ) {
			setView( 'result' );
			showError( 'Aucune image n’a pu être traitée. Vous pouvez réessayer ou vérifier les droits d’écriture du serveur.' );
			elements.optimize.focus();
			return;
		}

		const reduction = totals.before > 0 ? ( totals.saved / totals.before ) * 100 : 0;
		document.getElementById( 'opti-pict-saved' ).textContent = formatBytes( totals.saved );
		document.getElementById( 'opti-pict-percent' ).textContent = `${ new Intl.NumberFormat( 'fr-FR', { maximumFractionDigits: 1 } ).format( reduction ) } %`;
		document.getElementById( 'opti-pict-time-saved' ).textContent = formatDuration( secondsFor( totals.saved ) );
		document.getElementById( 'opti-pict-final-summary' ).textContent = `${ totals.converted } fichier(s) WebP créé(s) pour ${ scanState.ids.length - totals.errors } image(s).${ totals.errors ? ` ${ totals.errors } image(s) n’ont pas pu être traitée(s).` : '' }`;
		setView( 'final' );
		if ( totals.errors ) {
			showError( `${ totals.errors } image(s) n’ont pas pu être traitée(s). Relancez une analyse pour réessayer.` );
		}
		elements.live.textContent = `Optimisation terminée. ${ formatBytes( totals.saved ) } économisés.`;
		elements.finalTitle.focus();

		if ( totals.optimizedIds.length ) {
			const existing = elements.restore ? JSON.parse( elements.restore.dataset.ids || '[]' ) : [];
			const ids = [ ...new Set( existing.concat( totals.optimizedIds ) ) ];
			elements.restore.dataset.ids = JSON.stringify( ids );
			elements.restore.textContent = `Restaurer ${ ids.length } image${ ids.length > 1 ? 's' : '' }`;
			elements.restoreCard.hidden = false;
		}
	}

	async function restore() {
		if ( ! window.confirm( config.strings.restoreConfirm ) ) {
			return;
		}

		let ids;
		try {
			ids = JSON.parse( elements.restore.dataset.ids || '[]' );
		} catch ( error ) {
			ids = [];
		}
		if ( ! ids.length ) return;

		elements.restore.disabled = true;
		setView( 'progress' );
		const failedIds = [];
		for ( let index = 0; index < ids.length; index++ ) {
			setProgress( config.strings.restoreProgress, index, ids.length, `Image ${ index + 1 } sur ${ ids.length }` );
			try {
				await request( 'opti_pict_restore', { attachmentId: ids[ index ] } );
			} catch ( error ) {
				failedIds.push( ids[ index ] );
			}
		}

		elements.restore.dataset.ids = JSON.stringify( failedIds );
		elements.restore.textContent = `Restaurer ${ failedIds.length } image${ failedIds.length > 1 ? 's' : '' }`;
		elements.restoreCard.hidden = failedIds.length === 0;
		setView( 'idle' );
		elements.live.textContent = failedIds.length ? `Restauration terminée avec ${ failedIds.length } erreur(s).` : 'Les images d’origine ont été restaurées.';
		if ( failedIds.length ) showError( `${ failedIds.length } image(s) n’ont pas pu être restaurée(s). Vous pouvez réessayer.` );
		elements.restore.disabled = false;
		elements.scan.focus();
	}

	elements.scan?.addEventListener( 'click', scan );
	elements.rescan?.addEventListener( 'click', scan );
	elements.newScan?.addEventListener( 'click', scan );
	elements.optimize?.addEventListener( 'click', optimize );
	elements.restore?.addEventListener( 'click', restore );
}() );
