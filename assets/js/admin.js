( function () {
	'use strict';

	const config = window.phbapAdmin;
	if ( ! config ) {
		return;
	}

	function request( action, values ) {
		const body = new URLSearchParams( Object.assign( { action: action, nonce: config.nonce }, values || {} ) );
		return fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		} ).then( function ( response ) {
			return response.json().then( function ( data ) {
				if ( ! response.ok || ! data.success ) {
					throw new Error( data.data && data.data.message ? data.data.message : config.strings.requestFailed );
				}
				return data.data;
			} );
		} );
	}

	function setStatus( element, message, state ) {
		if ( ! element ) {
			return;
		}
		element.textContent = message;
		element.className = 'phbap-live' + ( state ? ' is-' + state : '' );
	}

	const connectionButton = document.getElementById( 'phbap-test-connection' );
	if ( connectionButton ) {
		connectionButton.addEventListener( 'click', function () {
			const status = document.getElementById( 'phbap-connection-status' );
			connectionButton.disabled = true;
			setStatus( status, config.strings.working, 'working' );
			const organisation = document.getElementById( 'phbap-org' );
			request( 'phbap_test_connection', { organisation_id: organisation ? organisation.value : '' } ).then( function ( data ) {
				setStatus( status, data.message, 'success' );
				window.setTimeout( function () { window.location.reload(); }, 900 );
			} ).catch( function ( error ) {
				setStatus( status, error.message, 'error' );
				connectionButton.disabled = false;
			} );
		} );
	}

	const chooseOverlay = document.getElementById( 'phbap-choose-overlay' );
	if ( chooseOverlay && window.wp && wp.media ) {
		chooseOverlay.addEventListener( 'click', function () {
			const frame = wp.media( { title: config.strings.chooseOverlay, library: { type: 'image/png' }, multiple: false } );
			frame.on( 'select', function () {
				const attachment = frame.state().get( 'selection' ).first().toJSON();
				if ( attachment.mime !== 'image/png' ) {
					return;
				}
				document.getElementById( 'phbap-overlay-id' ).value = attachment.id;
				const preview = document.getElementById( 'phbap-overlay-preview' );
				preview.replaceChildren();
				const image = document.createElement( 'img' );
				image.src = attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url;
				image.alt = attachment.alt || attachment.title || '';
				preview.appendChild( image );
			} );
			frame.open();
		} );
	}

	const removeOverlay = document.getElementById( 'phbap-remove-overlay' );
	if ( removeOverlay ) {
		removeOverlay.addEventListener( 'click', function () {
			document.getElementById( 'phbap-overlay-id' ).value = '0';
			document.getElementById( 'phbap-overlay-preview' ).textContent = config.strings.noOverlay;
		} );
	}

	const modeInputs = document.querySelectorAll( 'input[name="phbap[delivery_mode]"]' );
	const timeField = document.getElementById( 'phbap-daily-time' );
	function syncTimeField() {
		if ( ! timeField ) {
			return;
		}
		const selected = document.querySelector( 'input[name="phbap[delivery_mode]"]:checked' );
		timeField.disabled = ! selected || selected.value !== 'daily';
	}
	modeInputs.forEach( function ( input ) { input.addEventListener( 'change', syncTimeField ); } );
	syncTimeField();

	const searchButton = document.getElementById( 'phbap-search-properties' );
	const propertySelect = document.getElementById( 'phbap-property-id' );
	const eventSelect = document.getElementById( 'phbap-event-type' );
	const previousField = document.getElementById( 'phbap-previous-price-field' );
	const previewButton = document.getElementById( 'phbap-preview-test' );
	const sendButton = document.getElementById( 'phbap-send-test' );
	const testStatus = document.getElementById( 'phbap-test-status' );

	function testValues() {
		return {
			property_id: propertySelect ? propertySelect.value : '',
			event_type: eventSelect ? eventSelect.value : '',
			previous_price: document.getElementById( 'phbap-previous-price' ) ? document.getElementById( 'phbap-previous-price' ).value : ''
		};
	}

	if ( eventSelect ) {
		eventSelect.addEventListener( 'change', function () {
			previousField.hidden = eventSelect.value !== 'price_reduction';
			if ( sendButton ) { sendButton.disabled = true; }
		} );
	}
	if ( propertySelect ) {
		propertySelect.addEventListener( 'change', function () { if ( sendButton ) { sendButton.disabled = true; } } );
	}
	const previousPrice = document.getElementById( 'phbap-previous-price' );
	if ( previousPrice ) {
		previousPrice.addEventListener( 'input', function () { if ( sendButton ) { sendButton.disabled = true; } } );
	}

	if ( searchButton ) {
		searchButton.addEventListener( 'click', function () {
			searchButton.disabled = true;
			setStatus( testStatus, config.strings.working, 'working' );
			request( 'phbap_property_search', { query: document.getElementById( 'phbap-property-query' ).value } ).then( function ( data ) {
				propertySelect.replaceChildren();
				if ( ! data.items.length ) {
					const option = new Option( config.strings.noProperties, '' );
					propertySelect.appendChild( option );
				} else {
					propertySelect.appendChild( new Option( config.strings.selectProperty, '' ) );
					data.items.forEach( function ( item ) { propertySelect.appendChild( new Option( item.title, item.id ) ); } );
				}
				setStatus( testStatus, data.items.length ? config.strings.chooseProperty : config.strings.noProperties, data.items.length ? 'success' : '' );
			} ).catch( function ( error ) {
				setStatus( testStatus, error.message, 'error' );
			} ).finally( function () { searchButton.disabled = false; } );
		} );
	}

	function renderPreview( data ) {
		document.getElementById( 'phbap-preview-caption' ).textContent = data.caption;
		const images = document.getElementById( 'phbap-preview-images' );
		images.replaceChildren();
		data.images.slice( 0, 10 ).forEach( function ( url, index ) {
			const frame = document.createElement( 'div' );
			frame.className = 'phbap-preview-image';
			const image = document.createElement( 'img' );
			image.src = url;
			image.alt = config.strings.imageAlt.replace( '%d', index + 1 );
			frame.appendChild( image );
			if ( index === 0 && data.overlay ) {
				const overlay = document.createElement( 'img' );
				overlay.src = data.overlay;
				overlay.alt = '';
				overlay.className = 'phbap-preview-overlay';
				frame.appendChild( overlay );
			}
			images.appendChild( frame );
		} );
		document.getElementById( 'phbap-test-preview' ).hidden = false;
	}

	if ( previewButton ) {
		previewButton.addEventListener( 'click', function () {
			previewButton.disabled = true;
			setStatus( testStatus, config.strings.working, 'working' );
			request( 'phbap_preview', testValues() ).then( function ( data ) {
				renderPreview( data );
				sendButton.disabled = false;
				setStatus( testStatus, config.strings.previewReady, 'success' );
			} ).catch( function ( error ) {
				setStatus( testStatus, error.message, 'error' );
			} ).finally( function () { previewButton.disabled = false; } );
		} );
	}

	if ( sendButton ) {
		sendButton.addEventListener( 'click', function () {
			sendButton.disabled = true;
			setStatus( testStatus, config.strings.working, 'working' );
			request( 'phbap_send_test', testValues() ).then( function ( data ) {
				setStatus( testStatus, data.message, 'success' );
			} ).catch( function ( error ) {
				setStatus( testStatus, error.message, 'error' );
				sendButton.disabled = false;
			} );
		} );
	}
}() );
