/**
 * Marine Unit — mission report form.
 *
 * Live meter totals and GAR banding, the optional crew autofill, and
 * submission. Every figure computed here is recomputed on the server; this is
 * feedback for the person filling the form in, not the source of truth.
 */
( function () {
	'use strict';

	var config = window.mupCalendar || {};
	var i18n = config.i18n || {};

	// Thresholds mirror GarBand on the server. Kept in step by tests there and
	// by the server recomputing the band on save.
	var BANDS = [
		{ max: 0, key: 'unscored' },
		{ max: 23, key: 'green' },
		{ max: 44, key: 'amber' },
		{ max: 60, key: 'red' }
	];

	function bandFor( total ) {
		for ( var i = 0; i < BANDS.length; i++ ) {
			if ( total <= BANDS[ i ].max ) {
				return BANDS[ i ].key;
			}
		}

		return 'red';
	}

	function request( action, payload ) {
		var body = new FormData();

		body.append( 'action', 'mup_' + action );
		body.append( 'nonce', config.nonce || '' );

		Object.keys( payload || {} ).forEach( function ( key ) {
			body.append( key, payload[ key ] );
		} );

		return fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} )
			.then( function ( response ) {
				return response.json().catch( function () {
					throw new Error( i18n.genericError );
				} );
			} )
			.then( function ( json ) {
				if ( ! json || ! json.success ) {
					var error = new Error(
						json && json.data && json.data.message ? json.data.message : i18n.genericError
					);

					error.details = json && json.data ? json.data.errors : null;
					throw error;
				}

				return json.data || {};
			} );
	}

	function ReportForm( form ) {
		this.form = form;
		this.messages = form.querySelector( '[data-mup-report-messages]' );
		this.sources = [];

		this.bindMeters();
		this.bindGar();
		this.bindCrewFill();
		this.bindSubmit();

		this.updateGar();
	}

	/* --- Engine and generator hour totals --------------------------------- */

	ReportForm.prototype.bindMeters = function () {
		var self = this;

		this.form.querySelectorAll( '[data-mup-meter]' ).forEach( function ( input ) {
			input.addEventListener( 'input', function () {
				self.updateMeter( input.dataset.mupMeter );
			} );
		} );
	};

	ReportForm.prototype.updateMeter = function ( meter ) {
		var start = this.form.querySelector( '[data-mup-meter="' + meter + '"][data-mup-end="start"]' );
		var finish = this.form.querySelector( '[data-mup-meter="' + meter + '"][data-mup-end="finish"]' );
		var output = this.form.querySelector( '[data-mup-meter-total="' + meter + '"]' );

		if ( ! start || ! finish || ! output ) {
			return;
		}

		var a = parseFloat( start.value );
		var b = parseFloat( finish.value );

		output.classList.remove( 'is-invalid' );

		if ( isNaN( a ) || isNaN( b ) ) {
			output.textContent = '—';
			return;
		}

		if ( b < a ) {
			// Flagged rather than shown as a negative; the server rejects it.
			output.textContent = i18n.meterBackwards || 'Finish is below start';
			output.classList.add( 'is-invalid' );
			return;
		}

		// Rounded for the same reason the server rounds: raw subtraction of
		// decimal readings drifts (1000.3 - 1000.0 is 0.29999999999995).
		output.textContent = ( Math.round( ( b - a ) * 100 ) / 100 ).toString();
	};

	/* --- GAR worksheet ---------------------------------------------------- */

	ReportForm.prototype.bindGar = function () {
		var self = this;

		this.form.querySelectorAll( '[data-mup-gar]' ).forEach( function ( input ) {
			input.addEventListener( 'input', function () {
				self.updateGar();
			} );
		} );
	};

	ReportForm.prototype.updateGar = function () {
		var inputs = this.form.querySelectorAll( '[data-mup-gar]' );
		var totalEl = this.form.querySelector( '[data-mup-gar-total]' );
		var bandEl = this.form.querySelector( '[data-mup-gar-band]' );
		var wrap = this.form.querySelector( '[data-mup-gar-result]' );
		var total = 0;

		inputs.forEach( function ( input ) {
			var value = parseInt( input.value, 10 );

			if ( isNaN( value ) ) {
				return;
			}

			// Clamp for display; the server clamps for storage.
			value = Math.max( 0, Math.min( 10, value ) );
			total += value;
		} );

		if ( totalEl ) {
			totalEl.textContent = String( total );
		}

		var band = bandFor( total );

		if ( wrap ) {
			wrap.dataset.band = band;
		}

		if ( bandEl && i18n.garBands && i18n.garBands[ band ] ) {
			// Band is named in text as well as colour, so the meaning does not
			// depend on seeing the colour.
			bandEl.textContent = i18n.garBands[ band ];
		}
	};

	/* --- Crew autofill ---------------------------------------------------- */

	ReportForm.prototype.bindCrewFill = function () {
		var select = this.form.querySelector( '[data-mup-crew-source]' );
		var button = this.form.querySelector( '[data-mup-fill-crew]' );
		var self = this;

		if ( ! select || ! button ) {
			return;
		}

		request( 'report_crew_sources', {} )
			.then( function ( data ) {
				self.sources = data.patrols || [];
				select.innerHTML = '';

				var blank = document.createElement( 'option' );
				blank.value = '';
				blank.textContent = self.sources.length
					? ( i18n.choosePatrol || 'Choose a patrol' )
					: ( i18n.noPatrols || 'No patrols to fill from' );
				select.appendChild( blank );

				self.sources.forEach( function ( patrol ) {
					var option = document.createElement( 'option' );
					option.value = String( patrol.id );
					option.textContent = patrol.label;
					select.appendChild( option );
				} );
			} )
			.catch( function () {
				select.innerHTML = '';
				var blank = document.createElement( 'option' );
				blank.value = '';
				blank.textContent = i18n.noPatrols || 'No patrols to fill from';
				select.appendChild( blank );
			} );

		button.addEventListener( 'click', function () {
			var chosen = select.value;

			if ( ! chosen ) {
				return;
			}

			var patrol = self.sources.filter( function ( item ) {
				return String( item.id ) === chosen;
			} )[ 0 ];

			if ( patrol ) {
				self.fillCrew( patrol.crew || [] );
			}
		} );
	};

	ReportForm.prototype.fillCrew = function ( crew ) {
		var names = this.form.querySelectorAll( '[data-mup-crew-name]' );
		var ids = this.form.querySelectorAll( '[data-mup-crew-id]' );
		var positions = this.form.querySelectorAll( '[data-mup-crew-position]' );

		for ( var i = 0; i < names.length; i++ ) {
			var member = crew[ i ];

			if ( ! member ) {
				break;
			}

			names[ i ].value = member.name || '';

			if ( ids[ i ] ) {
				ids[ i ].value = member.id || '';
			}

			// Row 0 has no position control, so the position list is offset
			// by one relative to the name and id lists.
			if ( i > 0 && positions[ i - 1 ] && member.position ) {
				positions[ i - 1 ].value = member.position;
			}
		}
	};

	/* --- Submission ------------------------------------------------------- */

	ReportForm.prototype.bindSubmit = function () {
		var self = this;

		this.form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			self.submit();
		} );
	};

	ReportForm.prototype.submit = function () {
		var self = this;
		var submit = this.form.querySelector( '[type="submit"]' );
		var payload = {};

		new FormData( this.form ).forEach( function ( value, key ) {
			// Repeated keys (checkbox groups) arrive one at a time; FormData on
			// the request side needs them appended individually, so collect
			// them into an array-style key.
			if ( Object.prototype.hasOwnProperty.call( payload, key ) ) {
				if ( ! Array.isArray( payload[ key ] ) ) {
					payload[ key ] = [ payload[ key ] ];
				}
				payload[ key ].push( value );
			} else {
				payload[ key ] = value;
			}
		} );

		var body = new FormData( this.form );

		body.append( 'action', 'mup_submit_report' );
		body.append( 'nonce', config.nonce || '' );

		submit.disabled = true;
		this.showMessages( i18n.saving || '', [], [] );

		fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( json ) {
				if ( ! json || ! json.success ) {
					var data = ( json && json.data ) || {};
					submit.disabled = false;
					self.showMessages( '', data.errors || [ data.message || i18n.genericError ], [] );
					self.messages.scrollIntoView( { behavior: 'smooth', block: 'center' } );
					return;
				}

				self.onSubmitted( json.data || {} );
			} )
			.catch( function () {
				submit.disabled = false;
				self.showMessages( '', [ i18n.genericError ], [] );
			} );
	};

	ReportForm.prototype.onSubmitted = function ( data ) {
		this.form.querySelectorAll( 'input, select, textarea, button' ).forEach( function ( element ) {
			element.disabled = true;
		} );

		this.showMessages( data.message || '', [], data.warnings || [] );
		this.messages.scrollIntoView( { behavior: 'smooth', block: 'center' } );
	};

	ReportForm.prototype.showMessages = function ( success, errors, warnings ) {
		var host = this.messages;

		if ( ! host ) {
			return;
		}

		host.innerHTML = '';

		function line( className, text ) {
			var p = document.createElement( 'p' );
			p.className = className;
			// textContent, never innerHTML: these can echo user-entered values.
			p.textContent = text;
			return p;
		}

		if ( success ) {
			host.appendChild( line( 'mup-success', success ) );
		}

		( errors || [] ).forEach( function ( text ) {
			host.appendChild( line( 'mup-error', text ) );
		} );

		( warnings || [] ).forEach( function ( text ) {
			host.appendChild( line( 'mup-warning', text ) );
		} );
	};

	function init() {
		document.querySelectorAll( '[data-mup-report]' ).forEach( function ( form ) {
			if ( ! form.dataset.mupReady ) {
				form.dataset.mupReady = '1';
				new ReportForm( form );
			}
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
