( function () {
	'use strict';

	const app = document.getElementById( 'opti-pict-seo-app' );
	if ( ! app || typeof window.optiPictSeo === 'undefined' ) {
		return;
	}

	const config = window.optiPictSeo;
	const elements = {
		filter: document.getElementById( 'opti-pict-seo-filter' ),
		scan: document.getElementById( 'opti-pict-seo-scan' ),
		status: document.getElementById( 'opti-pict-seo-status' ),
		error: document.getElementById( 'opti-pict-seo-error' ),
		results: document.getElementById( 'opti-pict-seo-results' ),
		count: document.getElementById( 'opti-pict-seo-count' ),
		list: document.getElementById( 'opti-pict-seo-list' ),
		previous: document.getElementById( 'opti-pict-seo-previous' ),
		next: document.getElementById( 'opti-pict-seo-next' ),
		page: document.getElementById( 'opti-pict-seo-page' ),
		apiKey: document.getElementById( 'opti-pict-api-key' ),
		saveKey: document.getElementById( 'opti-pict-save-key' ),
		removeKey: document.getElementById( 'opti-pict-remove-key' ),
		aiState: document.getElementById( 'opti-pict-ai-state' )
	};

	const state = {
		page: 1,
		totalPages: 1,
		hasKey: Boolean( config.hasKey ),
		loading: false
	};

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

	function showError( message ) {
		elements.error.querySelector( 'p' ).textContent = message;
		elements.error.hidden = false;
	}

	function hideError() {
		elements.error.hidden = true;
		elements.error.querySelector( 'p' ).textContent = '';
	}

	function setStatus( message ) {
		elements.status.textContent = message || '';
	}

	function setKeyState( hasKey ) {
		state.hasKey = hasKey;
		if ( elements.aiState ) {
			elements.aiState.textContent = hasKey ? 'Configurée' : 'À configurer';
			elements.aiState.classList.toggle( 'is-ready', hasKey );
		}
		if ( elements.removeKey ) elements.removeKey.disabled = ! hasKey;
		elements.list.querySelectorAll( '.opti-pict-seo__generate' ).forEach( ( button ) => {
			button.disabled = ! hasKey;
		} );
	}

	async function saveKey() {
		const apiKey = elements.apiKey.value.trim();
		if ( ! apiKey ) {
			showError( 'Saisissez une clé API avant de l’enregistrer.' );
			elements.apiKey.focus();
			return;
		}

		hideError();
		elements.saveKey.disabled = true;
		try {
			const data = await request( 'opti_pict_seo_save_key', { operation: 'save', apiKey } );
			elements.apiKey.value = '';
			elements.apiKey.placeholder = 'Nouvelle clé pour la remplacer';
			setKeyState( data.hasKey );
			setStatus( config.strings.keySaved );
		} catch ( error ) {
			showError( error.message );
		} finally {
			elements.saveKey.disabled = false;
		}
	}

	async function removeKey() {
		if ( ! window.confirm( 'Supprimer la clé API enregistrée par Opti Pict ?' ) ) return;

		hideError();
		elements.removeKey.disabled = true;
		try {
			const data = await request( 'opti_pict_seo_save_key', { operation: 'remove' } );
			setKeyState( data.hasKey );
			setStatus( config.strings.keyRemoved );
		} catch ( error ) {
			showError( error.message );
		} finally {
			elements.removeKey.disabled = ! state.hasKey;
		}
	}

	async function scan( page = 1 ) {
		if ( state.loading ) return;
		state.loading = true;
		elements.scan.disabled = true;
		hideError();
		setStatus( 'Analyse de la médiathèque…' );

		try {
			const data = await request( 'opti_pict_seo_scan', { page, filter: elements.filter.value } );
			state.page = data.page;
			state.totalPages = Math.max( 1, data.totalPages );
			setKeyState( data.hasKey );
			renderResults( data );
			setStatus( data.totalItems ? 'Analyse terminée.' : 'Aucune image ne correspond à ce filtre.' );
		} catch ( error ) {
			showError( error.message );
			setStatus( '' );
		} finally {
			state.loading = false;
			elements.scan.disabled = false;
		}
	}

	function renderResults( data ) {
		elements.list.replaceChildren();
		data.items.forEach( ( item ) => elements.list.appendChild( createImageCard( item ) ) );
		elements.count.textContent = `${ data.totalItems } image${ data.totalItems > 1 ? 's' : '' } trouvée${ data.totalItems > 1 ? 's' : '' }`;
		elements.page.textContent = `Page ${ state.page } sur ${ state.totalPages }`;
		elements.previous.disabled = state.page <= 1;
		elements.next.disabled = state.page >= state.totalPages;
		elements.results.hidden = false;
	}

	function createElement( tag, className, text ) {
		const element = document.createElement( tag );
		if ( className ) element.className = className;
		if ( typeof text === 'string' ) element.textContent = text;
		return element;
	}

	function createField( labelText, className, value, maxLength ) {
		const wrapper = createElement( 'label', 'opti-pict-seo__field' );
		wrapper.appendChild( createElement( 'span', '', labelText ) );
		const input = document.createElement( 'input' );
		input.type = 'text';
		input.className = className;
		input.value = value || '';
		if ( maxLength ) input.maxLength = maxLength;
		wrapper.appendChild( input );
		return { wrapper, input };
	}

	function createImageCard( item ) {
		const card = createElement( 'article', 'opti-pict-seo-item' );
		card.dataset.attachmentId = String( item.id );

		const media = createElement( 'div', 'opti-pict-seo-item__media' );
		const image = document.createElement( 'img' );
		image.src = item.thumbnail;
		image.alt = '';
		image.loading = 'lazy';
		media.appendChild( image );

		const filename = createElement( 'strong', '', item.filename );
		const context = createElement( 'span', '', item.context );
		media.appendChild( filename );
		media.appendChild( context );
		if ( item.reviewed ) media.appendChild( createElement( 'span', 'opti-pict-badge', 'Déjà vérifiée' ) );

		const editor = createElement( 'div', 'opti-pict-seo-item__editor' );
		const alt = createField( 'Texte alternatif', 'opti-pict-seo__alt', item.altText, config.maxAltLength );
		const title = createField( 'Titre du média', 'opti-pict-seo__title', item.mediaTitle, 80 );
		const slug = createField( 'Identifiant SEO', 'opti-pict-seo__slug', item.slug, 80 );
		editor.appendChild( alt.wrapper );

		const columns = createElement( 'div', 'opti-pict-seo__fields-row' );
		columns.appendChild( title.wrapper );
		columns.appendChild( slug.wrapper );
		editor.appendChild( columns );

		const decorativeLabel = createElement( 'label', 'opti-pict-seo__decorative' );
		const decorative = document.createElement( 'input' );
		decorative.type = 'checkbox';
		decorative.className = 'opti-pict-seo__decorative-input';
		decorativeLabel.appendChild( decorative );
		decorativeLabel.appendChild( document.createTextNode( ' Image décorative : conserver un texte alternatif vide' ) );
		editor.appendChild( decorativeLabel );

		decorative.addEventListener( 'change', () => {
			if ( decorative.checked ) {
				alt.input.dataset.previousValue = alt.input.value;
				alt.input.value = '';
			}
			alt.input.disabled = decorative.checked;
			if ( ! decorative.checked && ! alt.input.value ) alt.input.value = alt.input.dataset.previousValue || '';
		} );

		const reason = createElement( 'p', 'opti-pict-seo__reason' );
		reason.hidden = true;
		editor.appendChild( reason );

		const actions = createElement( 'div', 'opti-pict-actions' );
		const generate = createElement( 'button', 'button opti-pict-seo__generate', 'Proposer avec l’IA' );
		generate.type = 'button';
		generate.disabled = ! state.hasKey;
		const apply = createElement( 'button', 'button button-primary opti-pict-seo__apply', 'Valider et enregistrer' );
		apply.type = 'button';
		actions.appendChild( generate );
		actions.appendChild( apply );

		if ( item.canRestore ) {
			const restore = createElement( 'button', 'button button-link opti-pict-seo__restore', 'Restaurer les valeurs précédentes' );
			restore.type = 'button';
			restore.addEventListener( 'click', () => restoreItem( card, restore ) );
			actions.appendChild( restore );
		}

		const itemStatus = createElement( 'p', 'opti-pict-seo-item__status' );
		itemStatus.setAttribute( 'role', 'status' );
		itemStatus.setAttribute( 'aria-live', 'polite' );
		editor.appendChild( actions );
		editor.appendChild( itemStatus );

		generate.addEventListener( 'click', () => generateSuggestion( card, generate ) );
		apply.addEventListener( 'click', () => applyItem( card, apply ) );

		card.appendChild( media );
		card.appendChild( editor );
		return card;
	}

	async function generateSuggestion( card, button ) {
		if ( ! state.hasKey ) {
			showError( config.strings.keyRequired );
			return;
		}

		const itemStatus = card.querySelector( '.opti-pict-seo-item__status' );
		button.disabled = true;
		card.setAttribute( 'aria-busy', 'true' );
		itemStatus.textContent = config.strings.generating;
		hideError();

		try {
			const data = await request( 'opti_pict_seo_generate', { attachmentId: card.dataset.attachmentId } );
			const suggestion = data.suggestion;
			const altInput = card.querySelector( '.opti-pict-seo__alt' );
			if ( suggestion.decorative ) altInput.dataset.previousValue = altInput.value;
			altInput.value = suggestion.altText;
			card.querySelector( '.opti-pict-seo__title' ).value = suggestion.mediaTitle;
			card.querySelector( '.opti-pict-seo__slug' ).value = suggestion.slug;
			const decorative = card.querySelector( '.opti-pict-seo__decorative-input' );
			decorative.checked = suggestion.decorative;
			altInput.disabled = suggestion.decorative;
			const reason = card.querySelector( '.opti-pict-seo__reason' );
			reason.textContent = `Pourquoi : ${ suggestion.reason }`;
			reason.hidden = false;
			itemStatus.textContent = 'Proposition prête. Vérifiez-la avant de l’enregistrer.';
			( suggestion.decorative ? card.querySelector( '.opti-pict-seo__title' ) : altInput ).focus();
		} catch ( error ) {
			itemStatus.textContent = '';
			showError( error.message );
		} finally {
			button.disabled = ! state.hasKey;
			card.removeAttribute( 'aria-busy' );
		}
	}

	async function applyItem( card, button ) {
		const decorative = card.querySelector( '.opti-pict-seo__decorative-input' ).checked;
		const itemStatus = card.querySelector( '.opti-pict-seo-item__status' );
		button.disabled = true;
		card.setAttribute( 'aria-busy', 'true' );
		itemStatus.textContent = config.strings.applying;
		hideError();

		try {
			const item = await request( 'opti_pict_seo_apply', {
				attachmentId: card.dataset.attachmentId,
				altText: card.querySelector( '.opti-pict-seo__alt' ).value,
				mediaTitle: card.querySelector( '.opti-pict-seo__title' ).value,
				slug: card.querySelector( '.opti-pict-seo__slug' ).value,
				decorative: decorative ? '1' : '0'
			} );
			card.querySelector( '.opti-pict-seo__alt' ).value = item.altText;
			card.querySelector( '.opti-pict-seo__title' ).value = item.mediaTitle;
			card.querySelector( '.opti-pict-seo__slug' ).value = item.slug;
			itemStatus.textContent = config.strings.applied;
			if ( ! card.querySelector( '.opti-pict-badge' ) ) {
				card.querySelector( '.opti-pict-seo-item__media' ).appendChild( createElement( 'span', 'opti-pict-badge', 'Vérifiée' ) );
			}
			if ( ! card.querySelector( '.opti-pict-seo__restore' ) ) {
				const restore = createElement( 'button', 'button button-link opti-pict-seo__restore', 'Restaurer les valeurs précédentes' );
				restore.type = 'button';
				restore.addEventListener( 'click', () => restoreItem( card, restore ) );
				card.querySelector( '.opti-pict-actions' ).appendChild( restore );
			}
		} catch ( error ) {
			itemStatus.textContent = '';
			showError( error.message );
		} finally {
			button.disabled = false;
			card.removeAttribute( 'aria-busy' );
		}
	}

	async function restoreItem( card, button ) {
		if ( ! window.confirm( config.strings.restoreConfirm ) ) return;

		const itemStatus = card.querySelector( '.opti-pict-seo-item__status' );
		button.disabled = true;
		card.setAttribute( 'aria-busy', 'true' );
		try {
			const item = await request( 'opti_pict_seo_restore', { attachmentId: card.dataset.attachmentId } );
			card.querySelector( '.opti-pict-seo__alt' ).value = item.altText;
			card.querySelector( '.opti-pict-seo__title' ).value = item.mediaTitle;
			card.querySelector( '.opti-pict-seo__slug' ).value = item.slug;
			card.querySelector( '.opti-pict-seo__decorative-input' ).checked = false;
			card.querySelector( '.opti-pict-seo__alt' ).disabled = false;
			card.querySelectorAll( '.opti-pict-badge' ).forEach( ( badge ) => badge.remove() );
			const reason = card.querySelector( '.opti-pict-seo__reason' );
			reason.textContent = '';
			reason.hidden = true;
			button.remove();
			itemStatus.textContent = config.strings.restored;
		} catch ( error ) {
			showError( error.message );
			button.disabled = false;
		} finally {
			card.removeAttribute( 'aria-busy' );
		}
	}

	elements.saveKey?.addEventListener( 'click', saveKey );
	elements.removeKey?.addEventListener( 'click', removeKey );
	elements.scan.addEventListener( 'click', () => scan( 1 ) );
	elements.filter.addEventListener( 'change', () => scan( 1 ) );
	elements.previous.addEventListener( 'click', () => scan( state.page - 1 ) );
	elements.next.addEventListener( 'click', () => scan( state.page + 1 ) );
}() );
