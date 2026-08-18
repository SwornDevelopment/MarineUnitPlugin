/**
 * Marine Unit — patrol calendar.
 *
 * Vanilla JS, no dependencies. All markup comes from the server as HTML
 * fragments, so this file handles interaction and never templating.
 */
( function () {
	'use strict';

	var config = window.mupCalendar || {};
	var i18n = config.i18n || {};

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
					var message = json && json.data && json.data.message
						? json.data.message
						: i18n.genericError;

					throw new Error( message );
				}

				return json.data || {};
			} );
	}

	function Calendar( root ) {
		this.root = root;
		this.grid = root.querySelector( '[data-mup-grid]' );
		this.title = root.querySelector( '[data-mup-title]' );
		this.modal = root.querySelector( '[data-mup-modal]' );
		this.panel = this.modal ? this.modal.querySelector( '.mup-modal__panel' ) : null;
		this.content = root.querySelector( '[data-mup-modal-content]' );
		this.live = root.querySelector( '[data-mup-live]' );
		this.lastFocus = null;

		this.year = parseInt( root.dataset.year, 10 );
		this.month = parseInt( root.dataset.month, 10 );

		this.bind();
	}

	Calendar.prototype.announce = function ( message ) {
		if ( this.live && message ) {
			this.live.textContent = message;
		}
	};

	Calendar.prototype.bind = function () {
		var self = this;

		this.root.addEventListener( 'click', function ( event ) {
			var nav = event.target.closest( '[data-mup-nav]' );

			if ( nav && self.root.contains( nav ) ) {
				event.preventDefault();
				self.navigate( nav.dataset.mupNav );
				return;
			}

			var chip = event.target.closest( '[data-mup-patrol]' );

			if ( chip && self.root.contains( chip ) ) {
				event.preventDefault();
				self.openPatrol( chip.dataset.mupPatrol, chip );
				return;
			}

			if ( event.target.closest( '[data-mup-new-patrol]' ) ) {
				event.preventDefault();
				self.openForm( 0, event.target );
				return;
			}

			if ( event.target.closest( '[data-mup-close]' ) ) {
				event.preventDefault();
				self.closeModal();
				return;
			}

			var action = event.target.closest( '[data-mup-action]' );

			if ( action && self.root.contains( action ) ) {
				event.preventDefault();
				self.runAction( action );
			}
		} );

		this.root.addEventListener( 'submit', function ( event ) {
			var form = event.target.closest( '[data-mup-patrol-form]' );

			if ( form ) {
				event.preventDefault();
				self.savePatrol( form );
			}
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( ! self.isOpen() ) {
				return;
			}

			if ( 'Escape' === event.key ) {
				self.closeModal();
				return;
			}

			if ( 'Tab' === event.key ) {
				self.trapFocus( event );
			}
		} );
	};

	Calendar.prototype.navigate = function ( direction ) {
		var year = this.year;
		var month = this.month;

		if ( 'today' === direction ) {
			var now = new Date();
			year = now.getFullYear();
			month = now.getMonth() + 1;
		} else if ( 'prev' === direction ) {
			month -= 1;
			if ( month < 1 ) {
				month = 12;
				year -= 1;
			}
		} else {
			month += 1;
			if ( month > 12 ) {
				month = 1;
				year += 1;
			}
		}

		var self = this;

		this.grid.setAttribute( 'aria-busy', 'true' );

		request( 'month', { year: year, month: month } )
			.then( function ( data ) {
				self.year = data.year;
				self.month = data.month;
				self.root.dataset.year = data.year;
				self.root.dataset.month = data.month;
				self.grid.innerHTML = data.html;
				self.title.textContent = data.title;
			} )
			.catch( function ( error ) {
				self.announce( error.message );
			} )
			.finally( function () {
				self.grid.removeAttribute( 'aria-busy' );
			} );
	};

	Calendar.prototype.isOpen = function () {
		return this.modal && ! this.modal.hidden;
	};

	Calendar.prototype.openModal = function ( html, trigger ) {
		this.lastFocus = trigger || document.activeElement;
		this.content.innerHTML = html;
		this.modal.hidden = false;
		document.body.classList.add( 'mup-modal-open' );

		var focusable = this.focusable();

		( focusable[ 0 ] || this.panel ).focus();
	};

	Calendar.prototype.closeModal = function () {
		if ( ! this.isOpen() ) {
			return;
		}

		this.modal.hidden = true;
		this.content.innerHTML = '';
		document.body.classList.remove( 'mup-modal-open' );

		if ( this.lastFocus && document.contains( this.lastFocus ) ) {
			this.lastFocus.focus();
		}
	};

	Calendar.prototype.focusable = function () {
		return Array.prototype.slice.call(
			this.panel.querySelectorAll(
				'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
			)
		).filter( function ( element ) {
			return null !== element.offsetParent;
		} );
	};

	/** Keep Tab inside the dialog while it is open. */
	Calendar.prototype.trapFocus = function ( event ) {
		var focusable = this.focusable();

		if ( ! focusable.length ) {
			return;
		}

		var first = focusable[ 0 ];
		var last = focusable[ focusable.length - 1 ];

		if ( event.shiftKey && document.activeElement === first ) {
			event.preventDefault();
			last.focus();
		} else if ( ! event.shiftKey && document.activeElement === last ) {
			event.preventDefault();
			first.focus();
		}
	};

	Calendar.prototype.openPatrol = function ( patrolId, trigger ) {
		var self = this;

		this.openModal( '<p class="mup-loading">' + ( i18n.loading || '' ) + '</p>', trigger );

		request( 'patrol', { patrol_id: patrolId } )
			.then( function ( data ) {
				self.content.innerHTML = data.html;
				( self.focusable()[ 0 ] || self.panel ).focus();
			} )
			.catch( function ( error ) {
				self.content.innerHTML = '<p class="mup-error"></p>';
				self.content.querySelector( '.mup-error' ).textContent = error.message;
			} );
	};

	Calendar.prototype.openForm = function ( patrolId, trigger ) {
		var self = this;

		this.openModal( '<p class="mup-loading">' + ( i18n.loading || '' ) + '</p>', trigger );

		request( 'patrol_form', { patrol_id: patrolId || 0 } )
			.then( function ( data ) {
				self.content.innerHTML = data.html;
				( self.focusable()[ 0 ] || self.panel ).focus();
			} )
			.catch( function ( error ) {
				self.content.innerHTML = '<p class="mup-error"></p>';
				self.content.querySelector( '.mup-error' ).textContent = error.message;
			} );
	};

	Calendar.prototype.runAction = function ( button ) {
		var action = button.dataset.mupAction;
		var patrolId = button.dataset.mupPatrolId;
		var self = this;

		if ( 'edit' === action ) {
			this.openForm( patrolId, button );
			return;
		}

		var confirmations = {
			withdraw: i18n.confirmWithdraw,
			remove: i18n.confirmRemove,
			cancel: i18n.confirmCancel
		};

		if ( confirmations[ action ] && ! window.confirm( confirmations[ action ] ) ) {
			return;
		}

		var endpoint = action;
		var payload = { patrol_id: patrolId };

		if ( 'remove' === action ) {
			endpoint = 'remove_crew';
			payload.user_id = button.dataset.mupUserId;
		} else if ( 'cancel' === action || 'reinstate' === action ) {
			endpoint = 'cancel_patrol';
			payload.mode = action;
		}

		button.disabled = true;

		request( endpoint, payload )
			.then( function ( data ) {
				if ( data.html ) {
					self.content.innerHTML = data.html;
				}

				self.announce( data.message );
				self.flashMessages( data.message, data.warnings );
				self.refreshGrid();
			} )
			.catch( function ( error ) {
				button.disabled = false;
				self.flashMessages( '', [], error.message );
			} );
	};

	Calendar.prototype.savePatrol = function ( form ) {
		var self = this;
		var payload = {};

		new FormData( form ).forEach( function ( value, key ) {
			payload[ key ] = value;
		} );

		var submit = form.querySelector( '[type="submit"]' );
		var messages = form.querySelector( '[data-mup-form-messages]' );

		submit.disabled = true;
		messages.textContent = i18n.saving || '';

		request( 'save_patrol', payload )
			.then( function ( data ) {
				self.announce( data.message );
				self.refreshGrid();

				if ( data.warnings && data.warnings.length ) {
					// Warnings are informational — the patrol saved. Show them
					// on the detail view rather than swallowing them.
					self.openPatrol( data.patrol_id, null );
					window.setTimeout( function () {
						self.flashMessages( data.message, data.warnings );
					}, 250 );
					return;
				}

				self.closeModal();
			} )
			.catch( function ( error ) {
				submit.disabled = false;
				messages.textContent = '';

				var box = document.createElement( 'p' );
				box.className = 'mup-error';
				box.textContent = error.message;
				messages.appendChild( box );
			} );
	};

	Calendar.prototype.flashMessages = function ( message, warnings, error ) {
		var host = this.content.querySelector( '[data-mup-detail]' ) || this.content;

		if ( ! host ) {
			return;
		}

		var existing = host.querySelector( '.mup-flash' );

		if ( existing ) {
			existing.remove();
		}

		var wrap = document.createElement( 'div' );
		wrap.className = 'mup-flash';

		if ( error ) {
			wrap.appendChild( line( 'mup-error', error ) );
		}

		if ( message ) {
			wrap.appendChild( line( 'mup-success', message ) );
		}

		( warnings || [] ).forEach( function ( text ) {
			wrap.appendChild( line( 'mup-warning', text ) );
		} );

		if ( wrap.childNodes.length ) {
			host.insertBefore( wrap, host.firstChild );
		}

		function line( className, text ) {
			var p = document.createElement( 'p' );
			p.className = className;
			// textContent, never innerHTML — these strings can carry patrol
			// details that came from user input.
			p.textContent = text;
			return p;
		}
	};

	Calendar.prototype.refreshGrid = function () {
		var self = this;

		request( 'month', { year: this.year, month: this.month } )
			.then( function ( data ) {
				self.grid.innerHTML = data.html;
			} )
			.catch( function () {
				// A stale grid is not worth an error message; the next
				// navigation will correct it.
			} );
	};

	function init() {
		document.querySelectorAll( '.mup-calendar' ).forEach( function ( root ) {
			if ( ! root.dataset.mupReady ) {
				root.dataset.mupReady = '1';
				new Calendar( root );
			}
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
