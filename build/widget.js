/**
 * AI Site Assistant — public floating chat widget (vanilla JS v1).
 */
( function () {
	if ( typeof window.nfdAISiteAssistant === 'undefined' ) {
		return;
	}

	const config = window.nfdAISiteAssistant;
	const SESSION_KEY = 'nfd_aia_session';
	const TABS_KEY = 'nfd_aia_tabs';
	const LEGACY_CONVERSATION_KEY = 'nfd_aia_conversation_id';
	const TAB_STALE_MS = 15000;
	const TAB_HEARTBEAT_MS = 5000;
	const DEFAULT_BRAND = '#005FA3';

	const existingTabId = sessionStorage.getItem( 'nfd_aia_tab_id' );
	const tabId = existingTabId || generateId();
	if ( ! existingTabId ) {
		sessionStorage.setItem( 'nfd_aia_tab_id', tabId );
	}

	const state = {
		open: false,
		busy: false,
		conversationId: '',
		messages: [],
	};

	const root = document.createElement( 'div' );
	root.id = 'nfd-ai-assistant-root';
	root.innerHTML = `
		<button type="button" class="nfd-aia-bubble" aria-expanded="false" aria-controls="nfd-aia-panel" aria-label="Open site assistant">
			<svg width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3h11A2.5 2.5 0 0 1 20 5.5v7A2.5 2.5 0 0 1 17.5 15H9l-4 4v-4H6.5A2.5 2.5 0 0 1 4 12.5v-7Z" stroke="currentColor" stroke-width="1.5"/></svg>
		</button>
		<div id="nfd-aia-panel" class="nfd-aia-panel" hidden>
			<header class="nfd-aia-header">
				<div class="nfd-aia-header__title">
					<span class="nfd-aia-header__icon" aria-hidden="true">
						<svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M4 5.5A2.5 2.5 0 0 1 6.5 3h11A2.5 2.5 0 0 1 20 5.5v7A2.5 2.5 0 0 1 17.5 15H9l-4 4v-4H6.5A2.5 2.5 0 0 1 4 12.5v-7Z" stroke="currentColor" stroke-width="1.5"/></svg>
					</span>
					<strong class="nfd-aia-header__label">Site Assistant</strong>
				</div>
				<button type="button" class="nfd-aia-close" aria-label="Close assistant">&times;</button>
			</header>
			<div class="nfd-aia-messages" role="log" aria-live="polite" aria-relevant="additions"></div>
			<form class="nfd-aia-form">
				<label class="screen-reader-text" for="nfd-aia-input">Ask a question</label>
				<input id="nfd-aia-input" type="text" maxlength="500" placeholder="Ask a question..." autocomplete="off" />
				<button type="submit" class="nfd-aia-send">Send</button>
			</form>
		</div>
	`;

	document.body.appendChild( root );

	const bubble = root.querySelector( '.nfd-aia-bubble' );
	const panel = root.querySelector( '#nfd-aia-panel' );
	const closeBtn = root.querySelector( '.nfd-aia-close' );
	const messagesEl = root.querySelector( '.nfd-aia-messages' );
	const form = root.querySelector( '.nfd-aia-form' );
	const input = root.querySelector( '#nfd-aia-input' );
	const sendBtn = root.querySelector( '.nfd-aia-send' );
	const headerLabel = root.querySelector( '.nfd-aia-header__label' );

	function hexToRgb( hex ) {
		let value = ( hex || '' ).replace( '#', '' );
		if ( value.length === 3 ) {
			value = value.split( '' ).map( ( char ) => char + char ).join( '' );
		}
		if ( value.length !== 6 ) {
			return null;
		}
		return {
			r: parseInt( value.slice( 0, 2 ), 16 ),
			g: parseInt( value.slice( 2, 4 ), 16 ),
			b: parseInt( value.slice( 4, 6 ), 16 ),
		};
	}

	function relativeLuminance( hex ) {
		const rgb = hexToRgb( hex );
		if ( ! rgb ) {
			return 1;
		}
		const channels = [ rgb.r, rgb.g, rgb.b ].map( ( channel ) => {
			const normalized = channel / 255;
			return normalized <= 0.03928
				? normalized / 12.92
				: Math.pow( ( normalized + 0.055 ) / 1.055, 2.4 );
		} );
		return ( 0.2126 * channels[ 0 ] ) + ( 0.7152 * channels[ 1 ] ) + ( 0.0722 * channels[ 2 ] );
	}

	function darkenHex( hex, amount ) {
		const rgb = hexToRgb( hex );
		if ( ! rgb ) {
			return DEFAULT_BRAND;
		}
		const factor = 1 - amount;
		const toHex = ( channel ) => Math.max( 0, Math.min( 255, Math.round( channel * factor ) ) )
			.toString( 16 )
			.padStart( 2, '0' );
		return `#${ toHex( rgb.r ) }${ toHex( rgb.g ) }${ toHex( rgb.b ) }`;
	}

	function normalizeBrandColor( color ) {
		const candidate = ( color || DEFAULT_BRAND ).trim();
		if ( relativeLuminance( candidate ) > 0.45 ) {
			return darkenHex( candidate, 0.35 );
		}
		return candidate;
	}

	const brandColor = normalizeBrandColor( config.brandColor );
	root.style.setProperty( '--nfd-aia-brand', brandColor );
	root.style.setProperty( '--nfd-aia-brand-soft', `${ brandColor }14` );

	if ( config.siteName && headerLabel ) {
		headerLabel.textContent = decodeHtmlEntities( config.siteName );
	}

	function generateId() {
		if ( window.crypto && crypto.randomUUID ) {
			return crypto.randomUUID();
		}

		return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace( /[xy]/g, ( char ) => {
			const random = Math.random() * 16 | 0;
			const value = char === 'x' ? random : ( random & 0x3 | 0x8 );
			return value.toString( 16 );
		} );
	}

	function getOpenTabs() {
		try {
			const tabs = JSON.parse( localStorage.getItem( TABS_KEY ) || '{}' );
			return tabs && typeof tabs === 'object' ? tabs : {};
		} catch ( error ) {
			return {};
		}
	}

	function saveOpenTabs( tabs ) {
		localStorage.setItem( TABS_KEY, JSON.stringify( tabs ) );
	}

	function pruneStaleTabs( tabs ) {
		const now = Date.now();
		Object.keys( tabs ).forEach( ( id ) => {
			if ( now - tabs[ id ] > TAB_STALE_MS ) {
				delete tabs[ id ];
			}
		} );
	}

	function touchTab() {
		const tabs = getOpenTabs();
		pruneStaleTabs( tabs );
		tabs[ tabId ] = Date.now();
		saveOpenTabs( tabs );
	}

	function unregisterTab() {
		const tabs = getOpenTabs();
		delete tabs[ tabId ];
		saveOpenTabs( tabs );
	}

	function clearSessionStorage() {
		localStorage.removeItem( SESSION_KEY );
		localStorage.removeItem( LEGACY_CONVERSATION_KEY );
	}

	function normalizeAssistantPayload( payload ) {
		return {
			answer: payload.answer || '',
			sources: payload.sources || [],
			suggestions: payload.suggestions || [],
			ctas: payload.ctas || [],
			needs_human: !! payload.needs_human,
		};
	}

	function persistSession() {
		localStorage.setItem(
			SESSION_KEY,
			JSON.stringify( {
				conversationId: state.conversationId,
				messages: state.messages,
			} )
		);
	}

	function loadSession() {
		try {
			const raw = localStorage.getItem( SESSION_KEY );
			if ( ! raw ) {
				return;
			}

			const session = JSON.parse( raw );
			if ( ! session || ! Array.isArray( session.messages ) ) {
				return;
			}

			state.conversationId = session.conversationId || '';
			state.messages = session.messages;
		} catch ( error ) {
			clearSessionStorage();
		}
	}

	function migrateLegacyStorage() {
		const legacyId = localStorage.getItem( LEGACY_CONVERSATION_KEY );
		if ( legacyId && ! state.conversationId ) {
			state.conversationId = legacyId;
			persistSession();
		}
		localStorage.removeItem( LEGACY_CONVERSATION_KEY );
	}

	function initSession() {
		const tabs = getOpenTabs();
		pruneStaleTabs( tabs );
		const hadActiveTabs = Object.keys( tabs ).length > 0;

		touchTab();

		if ( ! hadActiveTabs && ! existingTabId ) {
			clearSessionStorage();
		}

		loadSession();
		migrateLegacyStorage();
		renderAllMessagesFromState();
	}

	function decodeHtmlEntities( text ) {
		if ( ! text ) {
			return '';
		}
		const el = document.createElement( 'textarea' );
		el.innerHTML = text;
		return el.value;
	}

	function scrollMessageIntoView( element ) {
		if ( ! element || ! messagesEl ) {
			return;
		}

		const scrollToMessage = () => {
			const padding = 17;
			const containerRect = messagesEl.getBoundingClientRect();
			const elementRect = element.getBoundingClientRect();
			const relativeTop = elementRect.top - containerRect.top + messagesEl.scrollTop;

			messagesEl.scrollTop = Math.max( 0, relativeTop - padding );
		};

		requestAnimationFrame( () => {
			requestAnimationFrame( scrollToMessage );
		} );
	}

	function setBusy( busy ) {
		state.busy = busy;
		input.disabled = busy;
		sendBtn.disabled = busy;
		sendBtn.classList.toggle( 'is-busy', busy );
		sendBtn.textContent = busy ? 'Sending…' : 'Send';
	}

	function clearWelcomeState() {
		const empty = messagesEl.querySelector( '.nfd-aia-empty-state' );
		if ( empty ) {
			empty.remove();
		}
	}

	function renderWelcomeState() {
		if ( state.messages.length > 0 ) {
			return;
		}

		clearWelcomeState();
		messagesEl.innerHTML = '';

		const empty = document.createElement( 'div' );
		empty.className = 'nfd-aia-empty-state';

		const welcome = document.createElement( 'p' );
		welcome.className = 'nfd-aia-welcome';
		welcome.textContent = config.welcomeMessage || 'Hi! How can I help you today?';
		empty.appendChild( welcome );

		const hint = document.createElement( 'p' );
		hint.className = 'nfd-aia-welcome-hint';
		hint.textContent = 'Choose a suggestion or type your own question.';
		empty.appendChild( hint );

		if ( config.suggestions && config.suggestions.length ) {
			const chips = document.createElement( 'div' );
			chips.className = 'nfd-aia-suggestions nfd-aia-suggestions--welcome';
			config.suggestions.forEach( ( suggestion ) => {
				const chip = document.createElement( 'button' );
				chip.type = 'button';
				chip.className = 'nfd-aia-chip';
				chip.textContent = decodeHtmlEntities( suggestion );
				chip.addEventListener( 'click', () => ask( suggestion ) );
				chips.appendChild( chip );
			} );
			empty.appendChild( chips );
		}

		messagesEl.appendChild( empty );
	}

	function setOpen( open ) {
		state.open = open;
		panel.hidden = ! open;
		bubble.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		bubble.classList.toggle( 'is-open', open );

		if ( open ) {
			if ( state.messages.length === 0 ) {
				renderWelcomeState();
			}
			input.focus();
		}
	}

	function buildAssistantMessageElement( payload ) {
		const wrap = document.createElement( 'div' );
		wrap.className = 'nfd-aia-message nfd-aia-message-assistant';

		const answer = document.createElement( 'div' );
		answer.className = 'nfd-aia-answer';
		answer.textContent = decodeHtmlEntities( payload.answer || '' );
		wrap.appendChild( answer );

		if ( payload.sources && payload.sources.length ) {
			const sources = document.createElement( 'div' );
			sources.className = 'nfd-aia-sources';
			sources.textContent = 'From: ';
			payload.sources.forEach( ( source, index ) => {
				if ( index > 0 ) {
					sources.appendChild( document.createTextNode( ', ' ) );
				}
				const link = document.createElement( 'a' );
				link.href = source.url;
				link.textContent = decodeHtmlEntities( source.title );
				link.target = '_blank';
				link.rel = 'noopener nofollow';
				sources.appendChild( link );
			} );
			wrap.appendChild( sources );
		}

		if ( payload.suggestions && payload.suggestions.length ) {
			const chips = document.createElement( 'div' );
			chips.className = 'nfd-aia-suggestions';
			payload.suggestions.forEach( ( suggestion ) => {
				const chip = document.createElement( 'button' );
				chip.type = 'button';
				chip.className = 'nfd-aia-chip';
				chip.textContent = decodeHtmlEntities( suggestion );
				chip.addEventListener( 'click', () => ask( suggestion ) );
				chips.appendChild( chip );
			} );
			wrap.appendChild( chips );
		}

		if ( payload.ctas && payload.ctas.length ) {
			const ctaWrap = document.createElement( 'div' );
			ctaWrap.className = 'nfd-aia-ctas';
			payload.ctas.forEach( ( cta, index ) => {
				const btn = document.createElement( 'a' );
				btn.className = index === 0 ? 'nfd-aia-cta nfd-aia-cta-primary' : 'nfd-aia-cta';
				btn.href = cta.url;
				btn.textContent = decodeHtmlEntities( cta.label );
				btn.target = '_blank';
				btn.rel = 'noopener nofollow';
				ctaWrap.appendChild( btn );
			} );
			wrap.appendChild( ctaWrap );
		}

		if ( payload.needs_human && config.contactPageUrl ) {
			const handoff = document.createElement( 'a' );
			handoff.className = 'nfd-aia-cta nfd-aia-cta-primary';
			handoff.textContent = 'Contact us';
			handoff.href = config.contactPageUrl;
			handoff.target = '_blank';
			handoff.rel = 'noopener nofollow';
			wrap.appendChild( handoff );
		}

		return wrap;
	}

	function renderAllMessagesFromState() {
		clearWelcomeState();
		messagesEl.innerHTML = '';

		if ( state.messages.length === 0 ) {
			return;
		}

		state.messages.forEach( ( message ) => {
			if ( message.role === 'user' ) {
				const el = document.createElement( 'div' );
				el.className = 'nfd-aia-message nfd-aia-message-user';
				el.textContent = message.text;
				messagesEl.appendChild( el );
				return;
			}

			if ( message.role === 'assistant' && message.payload ) {
				messagesEl.appendChild( buildAssistantMessageElement( message.payload ) );
			}
		} );

		messagesEl.scrollTop = messagesEl.scrollHeight;
	}

	function appendUserMessage( text, track = true ) {
		clearWelcomeState();

		const el = document.createElement( 'div' );
		el.className = 'nfd-aia-message nfd-aia-message-user';
		el.textContent = text;
		messagesEl.appendChild( el );
		messagesEl.scrollTop = messagesEl.scrollHeight;

		if ( track ) {
			state.messages.push( { role: 'user', text } );
			persistSession();
		}
	}

	function appendAssistantMessage( payload, track = true ) {
		clearWelcomeState();

		const wrap = buildAssistantMessageElement( payload );
		const answer = wrap.querySelector( '.nfd-aia-answer' );
		messagesEl.appendChild( wrap );
		scrollMessageIntoView( answer );

		if ( track ) {
			state.messages.push( {
				role: 'assistant',
				payload: normalizeAssistantPayload( payload ),
			} );
			persistSession();
		}
	}

	async function ask( question ) {
		const trimmed = ( question || '' ).trim();
		if ( ! trimmed || state.busy ) {
			return;
		}

		setBusy( true );
		appendUserMessage( trimmed );
		input.value = '';

		try {
			const response = await fetch( config.apiRoot + '/ask', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': config.nonce,
				},
				body: JSON.stringify( {
					question: trimmed,
					conversation_id: state.conversationId || undefined,
				} ),
			} );

			const data = await response.json();
			if ( ! response.ok ) {
				throw new Error( data.message || 'Request failed' );
			}

			if ( data.conversation_id ) {
				state.conversationId = data.conversation_id;
				persistSession();
			}

			appendAssistantMessage( data );
		} catch ( error ) {
			appendAssistantMessage( {
				answer: config.contactPageUrl
					? 'The assistant is temporarily unavailable. Please try again shortly or contact us for help.'
					: 'The assistant is temporarily unavailable. Please try again shortly.',
				suggestions: config.suggestions || [],
				ctas: [],
				sources: [],
				needs_human: !! config.contactPageUrl,
			} );
		} finally {
			setBusy( false );
			input.focus();
		}
	}

	bubble.addEventListener( 'click', () => setOpen( ! state.open ) );
	closeBtn.addEventListener( 'click', () => setOpen( false ) );
	document.addEventListener( 'keydown', ( event ) => {
		if ( event.key === 'Escape' && state.open ) {
			setOpen( false );
		}
	} );

	form.addEventListener( 'submit', ( event ) => {
		event.preventDefault();
		ask( input.value );
	} );

	initSession();
	setInterval( touchTab, TAB_HEARTBEAT_MS );
	window.addEventListener( 'pagehide', unregisterTab );
	window.addEventListener( 'storage', ( event ) => {
		if ( event.key !== SESSION_KEY || ! event.newValue || state.busy ) {
			return;
		}

		loadSession();
		renderAllMessagesFromState();
	} );
}() );
