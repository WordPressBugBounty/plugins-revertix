/**
 * Revertix admin JS.
 *
 * Responsibilities:
 *   1. Resumable export (simple + advanced) driven by an AJAX poll loop.
 *   2. Resumable import (upload a package, or import a saved one) on the same loop.
 *   3. Saved-packages list (live add, import, delete).
 *   4. Pricing modal for locked features.
 *
 * No framework - vanilla JS, enqueued in the footer.
 *
 * @package Revertix
 */
( function () {
	'use strict';

	var data = window.RevertixData || {};
	var i18n = data.i18n || {};

	/* ----------------------------------------------------------------- *
	 * AJAX helpers
	 * ----------------------------------------------------------------- */
	/**
	 * Parse a fetch Response, turning HTTP errors and non-JSON bodies (e.g. a
	 * bare "0"/"-1" from admin-ajax, or a PHP fatal) into descriptive throws.
	 *
	 * @param {Response} res Fetch response.
	 * @return {Promise<Object>}
	 */
	function parseResponse( res ) {
		return res.text().then( function ( text ) {
			var trimmed = ( text || '' ).trim();

			if ( ! res.ok ) {
				throw new Error( 'HTTP ' + res.status + ( trimmed ? ': ' + trimmed.slice( 0, 180 ) : '' ) );
			}
			if ( '0' === trimmed || '-1' === trimmed || '' === trimmed ) {
				throw new Error( i18n.sessionLost || i18n.failed );
			}
			try {
				return JSON.parse( text );
			} catch ( e ) {
				throw new Error( trimmed.slice( 0, 180 ) );
			}
		} );
	}

	function post( action, params ) {
		var body = new URLSearchParams();
		body.append( 'action', action );
		body.append( 'nonce', data.nonce );

		Object.keys( params || {} ).forEach( function ( key ) {
			var value = params[ key ];
			if ( Array.isArray( value ) ) {
				value.forEach( function ( v ) {
					body.append( key + '[]', v );
				} );
			} else {
				body.append( key, value );
			}
		} );

		return fetch( data.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} ).then( parseResponse );
	}

	/**
	 * Upload a single Blob chunk of a package.
	 *
	 * @param {string} token Upload session token.
	 * @param {number} index Zero-based chunk index.
	 * @param {number} total Total number of chunks.
	 * @param {Blob}   blob  The chunk data.
	 * @return {Promise<Object>}
	 */
	function uploadChunk( token, index, total, blob ) {
		var form = new FormData();
		form.append( 'action', 'revertix_upload_chunk' );
		form.append( 'nonce', data.nonce );
		form.append( 'token', token );
		form.append( 'index', index );
		form.append( 'total', total );
		form.append( 'chunk', blob );

		return fetch( data.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: form
		} ).then( parseResponse );
	}

	function randomToken() {
		var rnd = Math.random().toString( 36 ).slice( 2, 10 );
		return 'upload-' + ( new Date().getTime() ).toString( 36 ) + rnd;
	}

	function el( id ) {
		return document.getElementById( id );
	}

	/**
	 * Put text on the clipboard, with a fallback for non-secure contexts.
	 *
	 * @param {string} text Text to copy.
	 */
	function copyText( text ) {
		var done = function () {
			UI.toast( i18n.copied, 'success' );
		};
		var failed = function () {
			UI.toast( i18n.copyFailed, 'error' );
		};

		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( text ).then( done, failed );
			return;
		}

		var scratch = document.createElement( 'textarea' );
		scratch.value = text;
		scratch.setAttribute( 'readonly', '' );
		scratch.style.position = 'fixed';
		scratch.style.opacity = '0';
		document.body.appendChild( scratch );
		scratch.select();

		try {
			document.execCommand( 'copy' ) ? done() : failed();
		} catch ( e ) {
			failed();
		}

		document.body.removeChild( scratch );
	}

	function escapeHtml( str ) {
		return String( str || '' ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	/* ----------------------------------------------------------------- *
	 * Styled dialogs + toasts (replace native alert/confirm).
	 * ----------------------------------------------------------------- */
	var UI = {
		ensure: function () {
			if ( this.root ) {
				return;
			}
			this.root = document.createElement( 'div' );
			this.root.className = 'revertix-ui-root';
			this.toasts = document.createElement( 'div' );
			this.toasts.className = 'revertix-toasts';
			this.root.appendChild( this.toasts );
			document.body.appendChild( this.root );
		},

		toast: function ( message, type ) {
			this.ensure();
			type = type || 'info';

			var icon = 'dashicons-info';
			if ( 'error' === type ) {
				icon = 'dashicons-warning';
			} else if ( 'success' === type ) {
				icon = 'dashicons-yes-alt';
			}

			var t = document.createElement( 'div' );
			t.className = 'revertix-toast revertix-toast--' + type;
			t.innerHTML = '<span class="dashicons ' + icon + '"></span><span class="revertix-toast__msg"></span>';
			t.querySelector( '.revertix-toast__msg' ).textContent = message || '';
			this.toasts.appendChild( t );

			window.requestAnimationFrame( function () {
				t.classList.add( 'is-in' );
			} );

			var remove = function () {
				t.classList.remove( 'is-in' );
				window.setTimeout( function () {
					if ( t.parentNode ) {
						t.parentNode.removeChild( t );
					}
				}, 250 );
			};

			window.setTimeout( remove, 'error' === type ? 6500 : 4000 );
			t.addEventListener( 'click', remove );
		},

		confirm: function ( message, opts ) {
			opts = opts || {};
			opts.confirm = true;
			return this.dialog( message, opts );
		},

		alert: function ( message, opts ) {
			opts = opts || {};
			opts.confirm = false;
			return this.dialog( message, opts );
		},

		dialog: function ( message, opts ) {
			this.ensure();
			opts = opts || {};
			var self = this;

			return new Promise( function ( resolve ) {
				var overlay = document.createElement( 'div' );
				overlay.className = 'revertix-dialog';
				overlay.innerHTML =
					'<div class="revertix-dialog__overlay"></div>' +
					'<div class="revertix-dialog__box" role="dialog" aria-modal="true">' +
						'<h3 class="revertix-dialog__title"></h3>' +
						'<p class="revertix-dialog__msg"></p>' +
						'<div class="revertix-dialog__actions">' +
							( opts.confirm ? '<button type="button" class="revertix-btn revertix-btn--ghost revertix-dialog__cancel"></button>' : '' ) +
							'<button type="button" class="revertix-btn revertix-btn--primary revertix-dialog__ok"></button>' +
						'</div>' +
					'</div>';

				var title = overlay.querySelector( '.revertix-dialog__title' );
				if ( opts.title ) {
					title.textContent = opts.title;
				} else {
					title.parentNode.removeChild( title );
				}

				var msg = overlay.querySelector( '.revertix-dialog__msg' );
				if ( opts.bodyNode ) {
					// A caller-supplied body replaces the plain message paragraph, so
					// the dialog can host real controls instead of only text.
					msg.parentNode.replaceChild( opts.bodyNode, msg );
				} else {
					msg.textContent = message || '';
				}

				var okBtn = overlay.querySelector( '.revertix-dialog__ok' );
				okBtn.textContent = opts.okLabel || ( opts.confirm ? ( i18n.confirm || 'Confirm' ) : ( i18n.ok || 'OK' ) );
				if ( opts.danger ) {
					okBtn.classList.remove( 'revertix-btn--primary' );
					okBtn.classList.add( 'revertix-btn--danger' );
				}

				var cancelBtn = overlay.querySelector( '.revertix-dialog__cancel' );
				if ( cancelBtn ) {
					cancelBtn.textContent = opts.cancelLabel || ( i18n.cancel || 'Cancel' );
				}

				self.root.appendChild( overlay );
				window.requestAnimationFrame( function () {
					overlay.classList.add( 'is-in' );
				} );
				okBtn.focus();

				function close( result ) {
					overlay.classList.remove( 'is-in' );
					window.setTimeout( function () {
						if ( overlay.parentNode ) {
							overlay.parentNode.removeChild( overlay );
						}
					}, 200 );
					document.removeEventListener( 'keydown', onKey );
					resolve( result );
				}

				function onKey( e ) {
					if ( 'Escape' === e.key ) {
						close( false );
					} else if ( 'Enter' === e.key ) {
						close( true );
					}
				}

				okBtn.addEventListener( 'click', function () {
					close( true );
				} );
				if ( cancelBtn ) {
					cancelBtn.addEventListener( 'click', function () {
						close( false );
					} );
				}
				overlay.querySelector( '.revertix-dialog__overlay' ).addEventListener( 'click', function () {
					close( false );
				} );
				document.addEventListener( 'keydown', onKey );
			} );
		}
	};

	/**
	 * Generic resumable job poller. Calls run_job until complete/error.
	 *
	 * @param {string} jobId    Job id.
	 * @param {Object} handlers { onTick(status), onComplete(status), onError(msg) }.
	 */
	function pollJob( jobId, token, handlers ) {
		var stopped = false;

		function tick() {
			if ( stopped ) {
				return;
			}
			post( 'revertix_run_job', { job: jobId, token: token || '' } )
				.then( function ( res ) {
					if ( stopped ) {
						return;
					}
					if ( ! res || ! res.success ) {
						return handlers.onError( res && res.data && res.data.message );
					}
					var status = res.data;
					handlers.onTick( status );

					if ( status.status === 'complete' ) {
						handlers.onComplete( status );
					} else if ( status.status === 'error' ) {
						handlers.onError( status.message );
					} else {
						tick();
					}
				} )
				.catch( function ( e ) {
					if ( ! stopped ) {
						handlers.onError( e && e.message );
					}
				} );
		}
		tick();

		return {
			stop: function () {
				stopped = true;
			}
		};
	}

	/* ----------------------------------------------------------------- *
	 * Progress bar helper bound to a set of elements.
	 * ----------------------------------------------------------------- */
	function Progress( target ) {
		// Accept either an id prefix (inline use) or a DOM element (modal use).
		this.root = ( typeof target === 'string' ) ? el( target + '-progress' ) : target;
		this.built = false;
		this.timer = null;
		this.startedAt = 0;
		this.onCancel = null;
	}

	Progress.prototype._build = function () {
		if ( this.built || ! this.root ) {
			return;
		}
		this.root.innerHTML =
			'<div class="revertix-proc">' +
				'<div class="revertix-proc__head">' +
					'<span class="revertix-proc__spinner" aria-hidden="true"></span>' +
					'<div class="revertix-proc__titles">' +
						'<span class="revertix-proc__title"></span>' +
						'<span class="revertix-proc__detail"></span>' +
					'</div>' +
					'<div class="revertix-proc__stats">' +
						'<span class="revertix-proc__time">0:00</span>' +
						'<span class="revertix-proc__pct">0%</span>' +
					'</div>' +
				'</div>' +
				'<div class="revertix-proc__bar"><div class="revertix-proc__fill"></div></div>' +
				'<ol class="revertix-proc__steps"></ol>' +
				'<div class="revertix-proc__foot"><button type="button" class="revertix-btn revertix-btn--ghost revertix-btn--sm revertix-proc__cancel" hidden>' + escapeHtml( i18n.cancel || 'Cancel' ) + '</button></div>' +
			'</div>';

		this.elTitle = this.root.querySelector( '.revertix-proc__title' );
		this.elDetail = this.root.querySelector( '.revertix-proc__detail' );
		this.elTime = this.root.querySelector( '.revertix-proc__time' );
		this.elPct = this.root.querySelector( '.revertix-proc__pct' );
		this.elFill = this.root.querySelector( '.revertix-proc__fill' );
		this.elSteps = this.root.querySelector( '.revertix-proc__steps' );
		this.elCancel = this.root.querySelector( '.revertix-proc__cancel' );

		var self = this;
		this.elCancel.addEventListener( 'click', function () {
			if ( self.onCancel ) {
				self.elCancel.disabled = true;
				self.onCancel();
			}
		} );

		this.built = true;
	};

	Progress.prototype.show = function () {
		this._build();
		if ( this.root ) {
			this.root.hidden = false;
		}
	};

	Progress.prototype.hide = function () {
		this._stopTimer();
		if ( this.root ) {
			this.root.hidden = true;
		}
	};

	/**
	 * Begin a job run: render the step list, start the timer, wire Cancel.
	 *
	 * @param {Array}    steps    [{key,label}] from the start response.
	 * @param {Function} onCancel Optional; shows a Cancel button when provided.
	 */
	Progress.prototype.begin = function ( steps, onCancel ) {
		this.show();
		this.steps = steps || [];
		this.onCancel = onCancel || null;

		this.elSteps.innerHTML = this.steps.map( function ( s ) {
			return '<li class="revertix-proc__step" data-key="' + escapeHtml( s.key ) + '">' +
				'<span class="revertix-proc__step-ico"></span>' +
				'<span class="revertix-proc__step-label">' + escapeHtml( s.label ) + '</span>' +
				'</li>';
		} ).join( '' );

		this.elCancel.hidden = ! this.onCancel;
		this.elCancel.disabled = false;

		this.startedAt = ( new Date() ).getTime();
		this._startTimer();
		this.set( 0, ( this.steps[ 0 ] && this.steps[ 0 ].label ) || i18n.starting );
	};

	/**
	 * Update from a job status payload (percent, stage, message).
	 *
	 * @param {Object} status Job status.
	 */
	Progress.prototype.update = function ( status ) {
		var pct = clampPct( status.percent );
		this._fill( pct );

		var steps = this.steps || [];
		var activeIndex = -1;
		for ( var i = 0; i < steps.length; i++ ) {
			if ( steps[ i ].key === status.stage ) {
				activeIndex = i;
				break;
			}
		}

		var nodes = this.elSteps ? this.elSteps.children : [];
		for ( var j = 0; j < nodes.length; j++ ) {
			nodes[ j ].classList.toggle( 'is-done', activeIndex > -1 && j < activeIndex );
			nodes[ j ].classList.toggle( 'is-active', j === activeIndex );
		}

		var title = activeIndex > -1 ? steps[ activeIndex ].label : ( status.message || i18n.working );
		if ( this.elTitle ) {
			this.elTitle.textContent = title;
		}
		if ( this.elDetail ) {
			this.elDetail.textContent = status.message || '';
		}
	};

	/** Simple mode (no steps): set percent + a single label (e.g. uploading). */
	Progress.prototype.set = function ( pct, label ) {
		this._build();
		pct = clampPct( pct );
		this._fill( pct );
		if ( this.elTitle && label ) {
			this.elTitle.textContent = label;
		}
	};

	Progress.prototype._fill = function ( pct ) {
		if ( this.elFill ) {
			this.elFill.style.width = pct + '%';
		}
		if ( this.elPct ) {
			this.elPct.textContent = pct + '%';
		}
	};

	Progress.prototype._startTimer = function () {
		this._stopTimer();
		var self = this;
		this.timer = window.setInterval( function () {
			var secs = Math.floor( ( ( new Date() ).getTime() - self.startedAt ) / 1000 );
			var m = Math.floor( secs / 60 );
			var s = secs % 60;
			if ( self.elTime ) {
				self.elTime.textContent = m + ':' + ( s < 10 ? '0' + s : s );
			}
		}, 1000 );
	};

	Progress.prototype._stopTimer = function () {
		if ( this.timer ) {
			window.clearInterval( this.timer );
			this.timer = null;
		}
	};

	function clampPct( pct ) {
		return Math.max( 0, Math.min( 100, parseInt( pct, 10 ) || 0 ) );
	}

	/* ----------------------------------------------------------------- *
	 * Full-screen process modal (loading + live progress + confetti)
	 * ----------------------------------------------------------------- */
	var ProcModal = {
		ensure: function () {
			if ( this.overlay ) {
				return;
			}
			this.overlay = document.createElement( 'div' );
			this.overlay.className = 'revertix-procmodal';
			this.overlay.hidden = true;
			this.overlay.innerHTML =
				'<div class="revertix-procmodal__overlay"></div>' +
				'<div class="revertix-procmodal__dialog" role="dialog" aria-modal="true">' +
					'<h3 class="revertix-procmodal__title"></h3>' +
					'<div class="revertix-procmodal__body"></div>' +
					'<canvas class="revertix-procmodal__confetti" aria-hidden="true"></canvas>' +
				'</div>';
			document.body.appendChild( this.overlay );

			this.titleEl = this.overlay.querySelector( '.revertix-procmodal__title' );
			this.bodyEl = this.overlay.querySelector( '.revertix-procmodal__body' );
			this.dialogEl = this.overlay.querySelector( '.revertix-procmodal__dialog' );
			this.confettiEl = this.overlay.querySelector( '.revertix-procmodal__confetti' );
			this.progress = new Progress( this.bodyEl );
		},

		open: function ( title, steps, onCancel ) {
			this.ensure();
			this.titleEl.textContent = title || '';
			this.titleEl.hidden = false;
			this.overlay.hidden = false;
			document.body.style.overflow = 'hidden';

			// Rebuild the progress UI fresh inside the modal body.
			this.progress.built = false;
			this.progress.begin( steps, onCancel );
		},

		update: function ( status ) {
			this.progress.update( status );
		},

		done: function ( message, url ) {
			this.progress._stopTimer();
			this.titleEl.hidden = true;

			this.bodyEl.innerHTML =
				'<div class="revertix-proc-done">' +
					'<div class="revertix-proc-done__check"></div>' +
					'<div class="revertix-proc-done__title">' + escapeHtml( i18n.allDone || 'All done!' ) + '</div>' +
					'<div class="revertix-proc-done__msg">' + escapeHtml( message || '' ) + '</div>' +
					'<div class="revertix-proc-done__actions">' +
						( url ? '<a class="revertix-btn revertix-btn--primary" href="' + url + '" target="_blank" rel="noopener">' + escapeHtml( i18n.openStaging || 'Open' ) + '</a>' : '' ) +
						'<button type="button" class="revertix-btn revertix-btn--ghost revertix-procmodal__close">' + escapeHtml( i18n.close || 'Close' ) + '</button>' +
					'</div>' +
				'</div>';

			this._wireClose( true );
			this.confetti();
		},

		fail: function ( message ) {
			this.progress._stopTimer();
			this.titleEl.hidden = true;

			this.bodyEl.innerHTML =
				'<div class="revertix-proc-done revertix-proc-done--error">' +
					'<div class="revertix-proc-done__title">' + escapeHtml( i18n.failed || 'Something went wrong.' ) + '</div>' +
					'<div class="revertix-proc-done__msg">' + escapeHtml( message || '' ) + '</div>' +
					'<div class="revertix-proc-done__actions">' +
						'<button type="button" class="revertix-btn revertix-btn--ghost revertix-procmodal__close">' + escapeHtml( i18n.close || 'Close' ) + '</button>' +
					'</div>' +
				'</div>';

			this._wireClose( true );
		},

		_wireClose: function ( reload ) {
			var self = this;
			var btn = this.bodyEl.querySelector( '.revertix-procmodal__close' );
			if ( btn ) {
				btn.addEventListener( 'click', function () {
					if ( reload ) {
						window.location.reload();
					} else {
						self.close();
					}
				} );
			}
		},

		close: function () {
			if ( this.overlay ) {
				this.overlay.hidden = true;
			}
			document.body.style.overflow = '';
		},

		/** Lightweight canvas confetti burst inside the dialog. */
		confetti: function () {
			var canvas = this.confettiEl;
			if ( ! canvas || ! canvas.getContext ) {
				return;
			}
			var rect = this.dialogEl.getBoundingClientRect();
			canvas.width = rect.width;
			canvas.height = rect.height;

			var ctx = canvas.getContext( '2d' );
			var colors = [ '#4f8cff', '#3fb950', '#d97706', '#e0a83d', '#ef5b6a', '#a371f7', '#ffffff' ];
			var pieces = [];
			for ( var i = 0; i < 150; i++ ) {
				pieces.push( {
					x: canvas.width / 2,
					y: canvas.height * 0.3,
					vx: ( Math.random() - 0.5 ) * 9,
					vy: ( Math.random() * -8 ) - 3,
					size: 4 + Math.random() * 7,
					color: colors[ ( Math.random() * colors.length ) | 0 ],
					rot: Math.random() * Math.PI,
					vr: ( Math.random() - 0.5 ) * 0.35
				} );
			}

			var start = null;
			function frame( t ) {
				if ( null === start ) {
					start = t;
				}
				var elapsed = t - start;
				ctx.clearRect( 0, 0, canvas.width, canvas.height );
				for ( var k = 0; k < pieces.length; k++ ) {
					var p = pieces[ k ];
					p.vy += 0.2;
					p.vx *= 0.99;
					p.x += p.vx;
					p.y += p.vy;
					p.rot += p.vr;
					ctx.save();
					ctx.translate( p.x, p.y );
					ctx.rotate( p.rot );
					ctx.globalAlpha = Math.max( 0, 1 - elapsed / 2800 );
					ctx.fillStyle = p.color;
					ctx.fillRect( -p.size / 2, -p.size / 2, p.size, p.size * 0.6 );
					ctx.restore();
				}
				if ( elapsed < 3000 ) {
					window.requestAnimationFrame( frame );
				} else {
					ctx.clearRect( 0, 0, canvas.width, canvas.height );
				}
			}
			window.requestAnimationFrame( frame );
		}
	};

	/* ----------------------------------------------------------------- *
	 * Saved packages list
	 * ----------------------------------------------------------------- */
	var Packages = {
		init: function () {
			this.list = el( 'revertix-packages-list' );
			this.empty = el( 'revertix-packages-empty' );
			if ( ! this.list ) {
				return;
			}

			var self = this;
			this.list.addEventListener( 'click', function ( e ) {
				var del = e.target.closest( '.revertix-package-delete' );
				if ( del ) {
					self.remove( del.closest( '.revertix-package' ), del );
					return;
				}

				var share = e.target.closest( '.revertix-package-share' );
				if ( share ) {
					self.share( share.closest( '.revertix-package' ), share );
					return;
				}

				var showShare = e.target.closest( '.revertix-package-showshare' );
				if ( showShare ) {
					self.showExisting( showShare.closest( '.revertix-package' ), showShare );
					return;
				}

				var unshare = e.target.closest( '.revertix-package-unshare' );
				if ( unshare ) {
					self.unshare( unshare.closest( '.revertix-package' ), unshare );
				}
			} );
		},

		/**
		 * Ask for a public download link.
		 *
		 * Opens a dialog holding whatever controls this install offers - the real
		 * ones with PRO, a disabled preview without - and shows the finished link
		 * in the same dialog, because it is only ever shown once.
		 *
		 * @param {HTMLElement} row Package row.
		 * @param {HTMLElement} btn The button that was pressed.
		 */
		share: function ( row, btn ) {
			if ( ! row ) {
				return;
			}

			var tpl = document.getElementById( 'revertix-share-form-template' );
			if ( ! tpl || ! tpl.content ) {
				return;
			}

			var body = document.createElement( 'div' );
			body.appendChild( tpl.content.cloneNode( true ) );

			UI.dialog( '', {
				title: i18n.shareTitle || 'Share this package',
				confirm: true,
				bodyNode: body,
				okLabel: i18n.shareCreate || 'Create link',
				cancelLabel: i18n.cancel || 'Cancel'
			} ).then( function ( ok ) {
				if ( ! ok ) {
					return;
				}

				// Disabled fields (the free preview) are skipped, so the server
				// falls back to its own defaults - which it enforces regardless.
				var payload = { id: row.getAttribute( 'data-id' ) };
				Array.prototype.forEach.call(
					body.querySelectorAll( '[data-share-opt]' ),
					function ( field ) {
						if ( field.disabled || '' === field.value ) {
							return;
						}
						payload[ field.getAttribute( 'data-share-opt' ) ] = field.value;
					}
				);

				btn.disabled = true;

				return post( 'revertix_share_package', payload )
					.then( function ( res ) {
						btn.disabled = false;

						if ( ! res || ! res.success ) {
							UI.toast( ( res && res.data && res.data.message ) || i18n.failed, 'error' );
							return;
						}

						btn.hidden = true;
						row.querySelector( '.revertix-package-showshare' ).hidden = false;
						row.querySelector( '.revertix-package-unshare' ).hidden = false;

						Packages.showLink( res.data.url, res.data.expires );
					} )
					.catch( function ( err ) {
						btn.disabled = false;
						UI.toast( ( err && err.message ) || i18n.failed, 'error' );
					} );
			} );
		},

		/**
		 * Show a freshly created link, with a copy button. This is the only time
		 * it can be read: the server keeps a hash of it, never the link itself.
		 *
		 * @param {string} url     The share URL.
		 * @param {string} expires Human-readable expiry, or ''.
		 */
		showLink: function ( url, expires, existing ) {
			var body = document.createElement( 'div' );
			body.className = 'revertix-share-result';

			var warn = document.createElement( 'p' );
			warn.className = 'revertix-hint revertix-hint--warn';
			warn.textContent = i18n.shareCaution || '';

			var input = document.createElement( 'input' );
			input.type = 'text';
			input.className = 'revertix-input revertix-share-result__link';
			input.readOnly = true;
			input.value = url;
			input.addEventListener( 'focus', function () {
				input.select();
			} );

			body.appendChild( warn );
			body.appendChild( input );

			if ( expires ) {
				var exp = document.createElement( 'span' );
				exp.className = 'revertix-share-result__exp';
				exp.textContent = ( i18n.shareExpires || 'Expires' ) + ': ' + expires;
				body.appendChild( exp );
			}

			UI.dialog( '', {
				title: i18n.shareReady || 'Your download link',
				confirm: false,
				bodyNode: body,
				okLabel: i18n.copy || 'Copy'
			} ).then( function () {
				copyText( url );
			} );

			window.setTimeout( function () {
				input.focus();
			}, 60 );
		},

		/**
		 * Show the link that already exists for this package.
		 *
		 * @param {HTMLElement} row Package row.
		 * @param {HTMLElement} btn The button that was pressed.
		 */
		showExisting: function ( row, btn ) {
			if ( ! row ) {
				return;
			}

			btn.disabled = true;

			post( 'revertix_show_share', { id: row.getAttribute( 'data-id' ) } )
				.then( function ( res ) {
					btn.disabled = false;

					if ( ! res || ! res.success ) {
						UI.toast( ( res && res.data && res.data.message ) || i18n.failed, 'error' );
						return;
					}

					Packages.showLink( res.data.url, res.data.expires, true );
				} )
				.catch( function ( err ) {
					btn.disabled = false;
					UI.toast( ( err && err.message ) || i18n.failed, 'error' );
				} );
		},

		/**
		 * Withdraw the public link.
		 *
		 * @param {HTMLElement} row Package row.
		 * @param {HTMLElement} btn The button that was pressed.
		 */
		unshare: function ( row, btn ) {
			if ( ! row ) {
				return;
			}

			btn.disabled = true;

			post( 'revertix_unshare_package', { id: row.getAttribute( 'data-id' ) } )
				.then( function () {
					btn.disabled = false;
					btn.hidden = true;
					row.querySelector( '.revertix-package-showshare' ).hidden = true;
					row.querySelector( '.revertix-package-share' ).hidden = false;
					UI.toast( i18n.linkRevoked || i18n.done, 'success' );
				} )
				.catch( function ( err ) {
					btn.disabled = false;
					UI.toast( ( err && err.message ) || i18n.failed, 'error' );
				} );
		},

		remove: function ( row, btn ) {
			if ( ! row ) {
				return;
			}

			UI.confirm( i18n.confirmDelete, { danger: true, okLabel: i18n.delete } ).then( function ( ok ) {
				if ( ! ok ) {
					return;
				}

				var id = row.getAttribute( 'data-id' );
				btn.disabled = true;

				post( 'revertix_delete_package', { id: id } )
					.then( function ( res ) {
						if ( res && res.success ) {
							row.parentNode.removeChild( row );
							Packages.refreshEmpty();
						} else {
							btn.disabled = false;
							UI.toast( ( res && res.data && res.data.message ) || i18n.failed, 'error' );
						}
					} )
					.catch( function ( e ) {
						btn.disabled = false;
						UI.toast( ( e && e.message ) || i18n.failed, 'error' );
					} );
			} );
		},

		prepend: function ( pkg ) {
			if ( ! this.list ) {
				return;
			}

			var row = document.createElement( 'div' );
			row.className = 'revertix-package';
			row.setAttribute( 'data-id', pkg.id );

			var meta = [ pkg.size, pkg.parts_label, pkg.created ].filter( Boolean ).join( ' · ' );

			row.innerHTML =
				'<span class="dashicons dashicons-media-archive"></span>' +
				'<div class="revertix-package__info">' +
					'<strong class="revertix-package__name"></strong>' +
					'<span class="revertix-package__meta"></span>' +
				'</div>' +
				'<div class="revertix-package__actions">' +
					'<a class="revertix-btn revertix-btn--sm" href="#"><span class="dashicons dashicons-download"></span>' + escapeHtml( i18n.download ) + '</a>' +
					'<button type="button" class="revertix-btn revertix-btn--sm revertix-package-share"><span class="dashicons dashicons-admin-links"></span>' + escapeHtml( i18n.shareLink ) + '</button>' +
					'<button type="button" class="revertix-btn revertix-btn--sm revertix-package-unshare" hidden><span class="dashicons dashicons-hidden"></span>' + escapeHtml( i18n.revokeLink ) + '</button>' +
					'<button type="button" class="revertix-btn revertix-btn--sm revertix-btn--danger revertix-package-delete"><span class="dashicons dashicons-trash"></span>' + escapeHtml( i18n.delete ) + '</button>' +
				'</div>';

			row.querySelector( '.revertix-package__name' ).textContent = pkg.filename || '';
			row.querySelector( '.revertix-package__meta' ).textContent = meta;
			row.querySelector( 'a.revertix-btn' ).href = pkg.download_url;

			this.list.insertBefore( row, this.list.firstChild );
			this.refreshEmpty();
		},

		refreshEmpty: function () {
			if ( this.empty ) {
				this.empty.hidden = this.list.children.length > 0;
			}
		}
	};

	/* ----------------------------------------------------------------- *
	 * Export controller
	 * ----------------------------------------------------------------- */
	var Export = {
		jobId: null,

		init: function () {
			this.root = el( 'revertix-export' );
			if ( ! this.root ) {
				return;
			}

			this.start = el( 'revertix-export-start' );
			this.startAdvanced = el( 'revertix-export-start-advanced' );
			this.advancedToggle = el( 'revertix-export-advanced-toggle' );
			this.advanced = el( 'revertix-advanced' );
			this.done = el( 'revertix-export-done' );
			this.meta = el( 'revertix-export-meta' );
			this.progress = new Progress( 'revertix-export' );

			var self = this;

			if ( this.start ) {
				this.start.addEventListener( 'click', function () {
					self.begin( { advanced: '0' } );
				} );
			}
			if ( this.startAdvanced ) {
				this.startAdvanced.addEventListener( 'click', function () {
					self.begin( self.collectAdvanced() );
				} );
			}
			if ( this.advancedToggle ) {
				this.advancedToggle.addEventListener( 'click', function () {
					var open = self.advanced.hidden;
					self.advanced.hidden = ! open;
					self.advancedToggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
				} );
			}

			this.bindAdvancedUI();
		},

		bindAdvancedUI: function () {
			if ( ! this.advanced ) {
				return;
			}

			var tablePicker = el( 'revertix-tables' );
			this.advanced.querySelectorAll( 'input[name="revertix_db_mode"]' ).forEach( function ( radio ) {
				radio.addEventListener( 'change', function () {
					if ( tablePicker ) {
						tablePicker.hidden = this.value !== 'selected';
					}
				} );
			} );

			this.toggleGroup( 'revertix-include-db', '[data-db-options]' );
			this.toggleGroup( 'revertix-include-files', '[data-file-options]' );

			this.bindPickers();
		},

		bindPickers: function () {
			var self = this;

			this.advanced.querySelectorAll( '.revertix-picker' ).forEach( function ( picker ) {
				var area = picker.getAttribute( 'data-picker' );
				var boxes = picker.querySelectorAll( '.revertix-item' );
				var count = picker.querySelector( '[data-picker-count]' );
				var allBtn = picker.querySelector( '[data-picker-all]' );
				var noneBtn = picker.querySelector( '[data-picker-none]' );

				function refresh() {
					var checked = 0;
					boxes.forEach( function ( b ) {
						if ( b.checked ) {
							checked++;
						}
					} );
					if ( count ) {
						count.textContent = checked + '/' + boxes.length;
					}
				}

				function setAll( state ) {
					boxes.forEach( function ( b ) {
						b.checked = state;
					} );
					refresh();
				}

				if ( allBtn ) {
					allBtn.addEventListener( 'click', function () {
						setAll( true );
					} );
				}
				if ( noneBtn ) {
					noneBtn.addEventListener( 'click', function () {
						setAll( false );
					} );
				}
				boxes.forEach( function ( b ) {
					b.addEventListener( 'change', refresh );
				} );

				// Disable the picker when its parent area is unchecked.
				var areaCb = self.advanced.querySelector( '.revertix-area[data-area="' + area + '"]' );
				if ( areaCb ) {
					var sync = function () {
						picker.classList.toggle( 'is-disabled', ! areaCb.checked );
					};
					areaCb.addEventListener( 'change', sync );
					sync();
				}

				refresh();
			} );
		},

		collectItems: function ( params, area ) {
			var areaCb = this.advanced.querySelector( '.revertix-area[data-area="' + area + '"]' );
			if ( ! areaCb || ! areaCb.checked ) {
				return; // Area excluded entirely - handled via file_areas.
			}

			var boxes = Array.prototype.slice.call( this.advanced.querySelectorAll( '.revertix-item--' + area ) );
			if ( ! boxes.length ) {
				return;
			}

			var checked = boxes.filter( function ( b ) {
				return b.checked;
			} );

			if ( checked.length === boxes.length ) {
				params[ area + '_mode' ] = 'all';
			} else {
				params[ area + '_mode' ] = 'selected';
				params[ area ] = checked.map( function ( b ) {
					return b.value;
				} );
			}
		},

		toggleGroup: function ( checkboxId, selector ) {
			var box = el( checkboxId );
			var group = this.advanced.querySelector( selector );
			if ( box && group ) {
				box.addEventListener( 'change', function () {
					group.classList.toggle( 'is-disabled', ! this.checked );
				} );
			}
		},

		collectAdvanced: function () {
			var params = { advanced: '1' };

			var dbInclude = el( 'revertix-include-db' );
			params.include_db = dbInclude && dbInclude.checked ? '1' : '0';

			var mode = this.advanced.querySelector( 'input[name="revertix_db_mode"]:checked' );
			params.db_mode = mode ? mode.value : 'all';

			if ( params.db_mode === 'selected' ) {
				params.db_tables = [];
				this.advanced.querySelectorAll( '.revertix-table:checked' ).forEach( function ( cb ) {
					params.db_tables.push( cb.value );
				} );
			}

			var filesInclude = el( 'revertix-include-files' );
			params.include_files = filesInclude && filesInclude.checked ? '1' : '0';

			params.file_areas = [];
			this.advanced.querySelectorAll( '.revertix-area:checked' ).forEach( function ( cb ) {
				params.file_areas.push( cb.value );
			} );

			// Per-item plugin/theme selection.
			this.collectItems( params, 'plugins' );
			this.collectItems( params, 'themes' );

			return params;
		},

		begin: function ( params ) {
			var self = this;

			this.setButtons( true );
			this.done.hidden = true;
			this.progress.show();
			this.progress.set( 0, i18n.starting );

			post( 'revertix_start_export', params )
				.then( function ( res ) {
					if ( ! res || ! res.success ) {
						return self.fail( res && res.data && res.data.message );
					}
					self.jobId = res.data.id;
					self.progress.begin( res.data.steps, function () {
						self.cancel();
					} );
					self.poller = pollJob( self.jobId, res.data.secret, {
						onTick: function ( status ) {
							self.progress.update( status );
						},
						onComplete: function ( status ) {
							self.finish( status );
						},
						onError: function ( msg ) {
							self.fail( msg );
						}
					} );
				} )
				.catch( function ( e ) {
					self.fail( e && e.message );
				} );
		},

		cancel: function () {
			var self = this;
			if ( this.poller ) {
				this.poller.stop();
			}
			if ( this.jobId ) {
				post( 'revertix_cancel_job', { job: this.jobId } ).catch( function () {} );
			}
			this.progress.hide();
			this.setButtons( false );
			UI.toast( i18n.cancelled || i18n.done, 'info' );
		},

		finish: function ( status ) {
			this.progress.set( 100, i18n.done );
			this.progress.hide();
			this.done.hidden = false;
			this.setButtons( false );

			var result = status.result || {};
			if ( this.meta ) {
				this.meta.textContent = [ result.filename, result.size ].filter( Boolean ).join( ' · ' );
			}
			if ( result.id ) {
				Packages.prepend( result );
			}
		},

		fail: function ( msg ) {
			this.progress.hide();
			this.setButtons( false );
			UI.toast( msg || i18n.failed, 'error' );
		},

		setButtons: function ( busy ) {
			[ this.start, this.startAdvanced, this.advancedToggle ].forEach( function ( b ) {
				if ( b ) {
					b.disabled = busy;
				}
			} );
		}
	};

	/* ----------------------------------------------------------------- *
	 * Import controller
	 * ----------------------------------------------------------------- */
	var Import = {
		init: function () {
			this.root = el( 'revertix-import' );
			if ( ! this.root ) {
				return;
			}

			this.file = el( 'revertix-import-file' );
			this.start = el( 'revertix-import-start' );
			this.done = el( 'revertix-import-done' );
			this.meta = el( 'revertix-import-meta' );
			this.progress = new Progress( 'revertix-import' );

			var self = this;
			if ( this.start ) {
				this.start.addEventListener( 'click', function () {
					self.fromUpload();
				} );
			}
		},

		// Chunk size for uploads - small enough to stay under PHP upload limits.
		chunkSize: 2 * 1024 * 1024,

		fromUpload: function () {
			var self = this;
			var file = this.file && this.file.files ? this.file.files[ 0 ] : null;

			if ( ! file ) {
				UI.toast( i18n.noFile, 'error' );
				return;
			}

			UI.confirm( i18n.confirmImport, { danger: true } ).then( function ( ok ) {
				if ( ! ok ) {
					return;
				}

				self.busy( true );
				self.progress.show();
				self.progress.set( 0, i18n.uploading );

				var token = randomToken();
				var total = Math.max( 1, Math.ceil( file.size / self.chunkSize ) );

				self.sendChunk( file, token, total, 0 );
			} );
		},

		sendChunk: function ( file, token, total, index ) {
			var self = this;
			var startByte = index * this.chunkSize;
			var blob = file.slice( startByte, startByte + this.chunkSize );

			uploadChunk( token, index, total, blob )
				.then( function ( res ) {
					if ( ! res || ! res.success ) {
						return self.fail( res && res.data && res.data.message );
					}

					// Show upload progress as a percentage of chunks sent.
					self.progress.set( Math.round( ( index + 1 ) / total * 100 ), i18n.uploading );

					if ( res.data.done ) {
						// Whole file is on the server - kick off the import job.
						self.progress.set( 0, i18n.starting );
						post( 'revertix_start_import', { source: 'upload', token: token } )
							.then( function ( startRes ) {
								self.run( startRes );
							} )
							.catch( function ( e ) {
								self.fail( e && e.message );
							} );
					} else {
						self.sendChunk( file, token, total, index + 1 );
					}
				} )
				.catch( function ( e ) {
					self.fail( e && e.message );
				} );
		},

		run: function ( startRes ) {
			var self = this;
			if ( ! startRes || ! startRes.success ) {
				return self.fail( startRes && startRes.data && startRes.data.message );
			}

			self.progress.begin( startRes.data.steps, null );
			pollJob( startRes.data.id, startRes.data.secret, {
				onTick: function ( status ) {
					self.progress.update( status );
				},
				onComplete: function ( status ) {
					self.finish( status );
				},
				onError: function ( msg ) {
					self.fail( msg );
				}
			} );
		},

		finish: function ( status ) {
			this.progress.set( 100, i18n.importDone );
			this.progress.hide();
			if ( this.done ) {
				this.done.hidden = false;
			}
			var r = status.result || {};

			if ( this.meta ) {
				this.meta.textContent = r.notice ? r.notice : i18n.importDone;
			}

			// The DB/files changed underneath us - reload to a clean admin state.
			// When there is something to read, leave it on screen long enough to
			// actually be read; it is also in the log either way.
			window.setTimeout( function () {
				window.location.reload();
			}, r.notice ? 9000 : 1500 );
		},

		fail: function ( msg ) {
			this.progress.hide();
			this.busy( false );
			UI.toast( msg || i18n.failed, 'error' );
		},

		busy: function ( state ) {
			if ( this.start ) {
				this.start.disabled = state;
			}
			if ( this.file ) {
				this.file.disabled = state;
			}
		}
	};

	/* ----------------------------------------------------------------- *
	 * Pricing modal controller
	 * ----------------------------------------------------------------- */
	var Pricing = {
		init: function () {
			this.modal = el( 'revertix-pricing-modal' );
			if ( ! this.modal ) {
				return;
			}

			var self = this;
			document.addEventListener( 'click', function ( e ) {
				if ( e.target.closest( '.revertix-close-pricing' ) ) {
					e.preventDefault();
					self.close();
					return;
				}
				var opener = e.target.closest( '.revertix-open-pricing, [data-revertix-locked]' );
				if ( opener ) {
					e.preventDefault();
					self.open();
				}
			} );

			document.addEventListener( 'keydown', function ( e ) {
				if ( ( e.key === 'Enter' || e.key === ' ' ) && e.target.matches && e.target.matches( '[data-revertix-locked]' ) ) {
					e.preventDefault();
					self.open();
				}
				if ( e.key === 'Escape' && ! self.modal.hidden ) {
					self.close();
				}
			} );
		},

		open: function () {
			this.modal.hidden = false;
			this.modal.setAttribute( 'aria-hidden', 'false' );
			document.body.style.overflow = 'hidden';
		},

		close: function () {
			this.modal.hidden = true;
			this.modal.setAttribute( 'aria-hidden', 'true' );
			document.body.style.overflow = '';
		}
	};

	/* ----------------------------------------------------------------- *
	 * Staging controller
	 * ----------------------------------------------------------------- */
	var Staging = {
		init: function () {
			this.root = el( 'revertix-staging' );
			if ( ! this.root ) {
				return;
			}

			this.start = el( 'revertix-staging-start' );
			this.name = el( 'revertix-staging-name' );
			this.list = el( 'revertix-staging-list' );
			this.progress = new Progress( 'revertix-staging' );

			var self = this;

			if ( this.start ) {
				this.start.addEventListener( 'click', function () {
					self.create();
				} );
			}

			if ( this.list ) {
				this.list.addEventListener( 'click', function ( e ) {
					var del = e.target.closest( '.revertix-staging-delete' );
					if ( del ) {
						self.remove( del.closest( '.revertix-package' ), del );
						return;
					}
					var reset = e.target.closest( '.revertix-staging-reset' );
					if ( reset ) {
						self.reset( reset.closest( '.revertix-package' ) );
					}
				} );
			}
		},

		create: function () {
			this.run( 'revertix_start_staging', { name: this.name ? this.name.value : '' }, i18n.creatingStaging );
		},

		reset: function ( row ) {
			if ( ! row ) {
				return;
			}
			var self = this;
			UI.confirm( i18n.confirmResetStaging, { danger: true, okLabel: i18n.reset } ).then( function ( ok ) {
				if ( ok ) {
					self.run( 'revertix_reset_staging', { slug: row.getAttribute( 'data-slug' ) }, i18n.resettingStaging );
				}
			} );
		},

		/**
		 * Shared driver for create + reset: open the modal and poll the job.
		 */
		run: function ( action, params, title ) {
			var self = this;
			this.busy( true );

			post( action, params )
				.then( function ( res ) {
					if ( ! res || ! res.success ) {
						self.busy( false );
						UI.toast( ( res && res.data && res.data.message ) || i18n.failed, 'error' );
						return;
					}
					self.jobId = res.data.id;
					ProcModal.open( title || i18n.working, res.data.steps, function () {
						self.cancel();
					} );
					self.poller = pollJob( res.data.id, res.data.secret, {
						onTick: function ( status ) {
							ProcModal.update( status );
						},
						onComplete: function ( status ) {
							var url = status.result && status.result.url ? status.result.url : '';
							ProcModal.done( i18n.stagingReady, url );
						},
						onError: function ( msg ) {
							ProcModal.fail( msg || i18n.failed );
						}
					} );
				} )
				.catch( function ( e ) {
					self.busy( false );
					UI.toast( ( e && e.message ) || i18n.failed, 'error' );
				} );
		},

		cancel: function () {
			if ( this.poller ) {
				this.poller.stop();
			}
			if ( this.jobId ) {
				post( 'revertix_cancel_job', { job: this.jobId } ).catch( function () {} );
			}
			window.location.reload();
		},

		remove: function ( row, btn ) {
			if ( ! row ) {
				return;
			}

			UI.confirm( i18n.confirmDeleteStaging, { danger: true, okLabel: i18n.delete } ).then( function ( ok ) {
				if ( ! ok ) {
					return;
				}

				btn.disabled = true;
				post( 'revertix_delete_staging', { slug: row.getAttribute( 'data-slug' ) } )
					.then( function ( res ) {
						if ( res && res.success ) {
							window.location.reload();
						} else {
							btn.disabled = false;
							UI.toast( ( res && res.data && res.data.message ) || i18n.failed, 'error' );
						}
					} )
					.catch( function ( e ) {
						btn.disabled = false;
						UI.toast( ( e && e.message ) || i18n.failed, 'error' );
					} );
			} );
		},

		fail: function ( msg ) {
			this.progress.hide();
			this.busy( false );
			UI.toast( msg || i18n.failed, 'error' );
		},

		busy: function ( state ) {
			if ( this.start ) {
				this.start.disabled = state;
			}
			if ( this.name ) {
				this.name.disabled = state;
			}
		}
	};

	/* ----------------------------------------------------------------- *
	 * Diagnostic log controller (Logs tab)
	 * ----------------------------------------------------------------- */
	var Log = {
		init: function () {
			this.view = el( 'revertix-log-view' );
			this.copyBtn = el( 'revertix-log-copy' );
			this.clearBtn = el( 'revertix-log-clear' );

			var self = this;

			if ( this.copyBtn ) {
				this.copyBtn.addEventListener( 'click', function () {
					self.copy();
				} );
			}

			if ( this.clearBtn ) {
				this.clearBtn.addEventListener( 'click', function () {
					self.clear();
				} );
			}

			this.form = el( 'revertix-log-settings' );
			this.autoPrune = el( 'revertix-log-autoprune' );
			this.retention = el( 'revertix-log-retention' );

			if ( this.autoPrune && this.retention ) {
				this.autoPrune.addEventListener( 'change', function () {
					self.retention.disabled = ! self.autoPrune.checked;
				} );
			}

			if ( this.form ) {
				this.form.addEventListener( 'submit', function ( e ) {
					e.preventDefault();
					self.saveSettings();
				} );
			}
		},

		copy: function () {
			if ( ! this.view ) {
				return;
			}

			var text = this.view.value;
			var done = function () {
				UI.toast( i18n.copied, 'success' );
			};
			var failed = function () {
				UI.toast( i18n.copyFailed, 'error' );
			};

			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( text ).then( done, failed );
				return;
			}

			// Fallback for older/non-secure contexts.
			this.view.focus();
			this.view.select();
			try {
				document.execCommand( 'copy' ) ? done() : failed();
			} catch ( e ) {
				failed();
			}
		},

		clear: function () {
			var self = this;

			UI.confirm( i18n.confirmClearLog, { danger: true } ).then( function ( ok ) {
				if ( ! ok ) {
					return;
				}

				post( 'revertix_clear_log', {} ).then( function () {
					UI.toast( i18n.logCleared, 'success' );
					window.location.reload();
				} ).catch( function ( err ) {
					UI.toast( ( err && err.message ) || i18n.failed, 'error' );
				} );
			} );
		},

		saveSettings: function () {
			if ( ! this.autoPrune || ! this.retention ) {
				return;
			}

			var btn = el( 'revertix-log-save' );
			if ( btn ) {
				btn.disabled = true;
			}

			post( 'revertix_save_log_settings', {
				auto_prune: this.autoPrune.checked ? '1' : '0',
				retention_days: this.retention.value
			} ).then( function () {
				UI.toast( i18n.settingsSaved, 'success' );
			} ).catch( function ( err ) {
				UI.toast( ( err && err.message ) || i18n.failed, 'error' );
			} ).then( function () {
				if ( btn ) {
					btn.disabled = false;
				}
			} );
		}
	};

	/* ----------------------------------------------------------------- *
	 * Backups controller
	 * ----------------------------------------------------------------- */
	var Backups = {
		init: function () {
			this.root = el( 'revertix-backups' );
			if ( ! this.root ) {
				return;
			}

			this.start = el( 'revertix-backup-start' );
			this.name = el( 'revertix-backup-name' );
			this.list = el( 'revertix-backups-list' );

			var self = this;
			if ( this.start ) {
				this.start.addEventListener( 'click', function () {
					self.create();
				} );
			}
			if ( this.list ) {
				this.list.addEventListener( 'click', function ( e ) {
					var r = e.target.closest( '.revertix-backup-restore' );
					if ( r ) {
						self.restore( r.closest( '.revertix-package' ) );
						return;
					}
					var d = e.target.closest( '.revertix-backup-delete' );
					if ( d ) {
						self.remove( d.closest( '.revertix-package' ), d );
						return;
					}
					if ( e.target.closest( '.revertix-backup-delete-locked' ) ) {
						self.explainLockedDelete();
					}
				} );
			}
		},

		// The newest backup is kept as a safety net for everyone — explain why.
		explainLockedDelete: function () {
			UI.confirm( i18n.lockedDeleteMsg, {
				title: i18n.lockedDeleteTitle,
				okLabel: i18n.ok,
				cancelLabel: i18n.close
			} );
		},

		busy: function ( state ) {
			if ( this.start ) {
				this.start.disabled = state;
			}
			if ( this.name ) {
				this.name.disabled = state;
			}
		},

		collectOptions: function () {
			var box = function ( id ) {
				var e = el( id );
				return e && e.checked ? '1' : '0';
			};
			return {
				custom: '1',
				name: this.name ? this.name.value : '',
				backup_uploads: box( 'revertix-backup-uploads' ),
				backup_themes: box( 'revertix-backup-themes' ),
				backup_mu: box( 'revertix-backup-mu' ),
				backup_plugins: box( 'revertix-backup-plugins' ),
				backup_wpcontent: box( 'revertix-backup-wpcontent' ),
				backup_root: box( 'revertix-backup-root' ),
				backup_db: box( 'revertix-backup-db' )
			};
		},

		create: function () {
			var self = this;
			this.busy( true );

			post( 'revertix_start_backup', this.collectOptions() )
				.then( function ( res ) {
					if ( ! res || ! res.success ) {
						self.busy( false );
						UI.toast( ( res && res.data && res.data.message ) || i18n.failed, 'error' );
						return;
					}
					ProcModal.open( i18n.creatingBackup, res.data.steps, null );
					pollJob( res.data.id, res.data.secret, {
						onTick: function ( s ) {
							ProcModal.update( s );
						},
						onComplete: function () {
							ProcModal.done( i18n.backupDone, '' );
						},
						onError: function ( m ) {
							ProcModal.fail( m || i18n.failed );
						}
					} );
				} )
				.catch( function ( e ) {
					self.busy( false );
					UI.toast( ( e && e.message ) || i18n.failed, 'error' );
				} );
		},

		restore: function ( row ) {
			if ( ! row ) {
				return;
			}
			var self = this;
			var id = row.getAttribute( 'data-id' );

			UI.confirm( i18n.confirmRestore, { danger: true, okLabel: i18n.restore } ).then( function ( ok ) {
				if ( ! ok ) {
					return;
				}
				// Phase 1 — automatic safety backup of the current site.
				ProcModal.open( i18n.safetyBackup, [], null );
				post( 'revertix_start_backup', { name: i18n.safetyName, safety: '1' } )
					.then( function ( res ) {
						if ( ! res || ! res.success ) {
							return ProcModal.fail( ( res && res.data && res.data.message ) || i18n.failed );
						}
						ProcModal.open( i18n.safetyBackup, res.data.steps, null );
						pollJob( res.data.id, res.data.secret, {
							onTick: function ( s ) {
								ProcModal.update( s );
							},
							onComplete: function () {
								self.runRestore( id );
							},
							onError: function ( m ) {
								ProcModal.fail( m || i18n.failed );
							}
						} );
					} )
					.catch( function ( e ) {
						ProcModal.fail( ( e && e.message ) || i18n.failed );
					} );
			} );
		},

		// Phase 2 — the actual restore (import) of the chosen backup.
		runRestore: function ( id ) {
			post( 'revertix_start_restore', { id: id } )
				.then( function ( res ) {
					if ( ! res || ! res.success ) {
						return ProcModal.fail( ( res && res.data && res.data.message ) || i18n.failed );
					}
					ProcModal.open( i18n.restoring, res.data.steps, null );
					pollJob( res.data.id, res.data.secret, {
						onTick: function ( s ) {
							ProcModal.update( s );
						},
						onComplete: function () {
							ProcModal.done( i18n.restoreDone, '' );
							window.setTimeout( function () {
								window.location.reload();
							}, 2500 );
						},
						onError: function ( m ) {
							ProcModal.fail( m || i18n.failed );
						}
					} );
				} )
				.catch( function ( e ) {
					ProcModal.fail( ( e && e.message ) || i18n.failed );
				} );
		},

		remove: function ( row, btn ) {
			if ( ! row ) {
				return;
			}
			UI.confirm( i18n.confirmDeleteBackup, { danger: true, okLabel: i18n.delete } ).then( function ( ok ) {
				if ( ! ok ) {
					return;
				}
				btn.disabled = true;
				post( 'revertix_delete_backup', { id: row.getAttribute( 'data-id' ) } )
					.then( function ( res ) {
						if ( res && res.success ) {
							window.location.reload();
						} else {
							btn.disabled = false;
							UI.toast( ( res && res.data && res.data.message ) || i18n.failed, 'error' );
						}
					} )
					.catch( function ( e ) {
						btn.disabled = false;
						UI.toast( ( e && e.message ) || i18n.failed, 'error' );
					} );
			} );
		}
	};

	/* ----------------------------------------------------------------- */
	/* ----------------------------------------------------------------- *
	 * Settings controller
	 * ----------------------------------------------------------------- */
	var Settings = {
		init: function () {
			this.form = el( 'revertix-settings-form' );
			if ( ! this.form ) {
				return;
			}

			var self = this;
			this.form.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				self.save();
			} );

			this.initRolePicker();
		},

		// Custom multi-select dropdown for the staging access roles.
		initRolePicker: function () {
			var wrap = el( 'revertix-set-access-roles' );
			if ( ! wrap ) {
				return;
			}

			var toggle = wrap.querySelector( '.revertix-msel__toggle' );
			var panel = wrap.querySelector( '.revertix-msel__panel' );
			var label = wrap.querySelector( '.revertix-msel__label' );
			if ( ! toggle || ! panel || ! label ) {
				return;
			}

			var placeholder = wrap.getAttribute( 'data-placeholder' ) || '';

			function refreshLabel() {
				var picked = Array.prototype.slice.call( wrap.querySelectorAll( '.revertix-role-cb:checked' ) );
				if ( ! picked.length ) {
					label.textContent = placeholder;
					label.classList.add( 'is-placeholder' );
					return;
				}
				label.textContent = picked.map( function ( cb ) {
					var span = cb.parentNode.querySelector( 'span' );
					return span ? span.textContent.trim() : cb.value;
				} ).join( ', ' );
				label.classList.remove( 'is-placeholder' );
			}

			function close() {
				wrap.classList.remove( 'is-open' );
				panel.hidden = true;
				toggle.setAttribute( 'aria-expanded', 'false' );
			}

			if ( toggle.disabled ) {
				return; // Locked (free): the row handles the click → pricing modal.
			}

			toggle.addEventListener( 'click', function ( e ) {
				e.stopPropagation();
				var open = wrap.classList.toggle( 'is-open' );
				panel.hidden = ! open;
				toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			} );

			panel.addEventListener( 'change', refreshLabel );

			document.addEventListener( 'click', function ( e ) {
				if ( ! wrap.contains( e.target ) ) {
					close();
				}
			} );

			document.addEventListener( 'keydown', function ( e ) {
				if ( e.key === 'Escape' ) {
					close();
				}
			} );
		},

		// Map of POST key -> element id. Checkboxes send '1'/'0'.
		fields: {
			text: {
				safety_keep: 'revertix-set-safety-keep',
				rollback_keep: 'revertix-set-rb-keep',
				staging_bar_color: 'revertix-set-bar-color',
				staging_access_users: 'revertix-set-access-users',
				notify_email_address: 'revertix-set-notify-email'
			},
			bool: {
				staging_disable_auth: 'revertix-set-disable-auth',
				remove_data_on_uninstall: 'revertix-set-remove-data',
				notify_email_errors: 'revertix-set-notify-errors',
				notify_email_warnings: 'revertix-set-notify-warnings',
				notify_email_status: 'revertix-set-notify-status',
				notify_email_html: 'revertix-set-notify-html'
			}
		},

		save: function () {
			var params = {};
			var key;

			for ( key in this.fields.text ) {
				if ( this.fields.text.hasOwnProperty( key ) ) {
					var input = el( this.fields.text[ key ] );
					if ( input && ! input.disabled ) {
						params[ key ] = input.value;
					}
				}
			}
			for ( key in this.fields.bool ) {
				if ( this.fields.bool.hasOwnProperty( key ) ) {
					var box = el( this.fields.bool[ key ] );
					if ( box && ! box.disabled ) {
						params[ key ] = box.checked ? '1' : '0';
					}
				}
			}
			// Access-permission roles: a set of checkboxes. Only send when editable
			// (Pro), and always send the key - even empty (via '') - so it can be
			// cleared.
			var rolesWrap = el( 'revertix-set-access-roles' );
			if ( rolesWrap ) {
				var cbs = Array.prototype.slice.call( rolesWrap.querySelectorAll( '.revertix-role-cb' ) );
				var editable = cbs.some( function ( cb ) {
					return ! cb.disabled;
				} );
				if ( editable ) {
					var roles = cbs.filter( function ( cb ) {
						return cb.checked;
					} ).map( function ( cb ) {
						return cb.value;
					} );
					params.staging_access_roles = roles.length ? roles : [ '' ];
				}
			}

			var btn = el( 'revertix-settings-save' );
			if ( btn ) {
				btn.disabled = true;
			}

			post( 'revertix_save_settings', params ).then( function () {
				UI.toast( i18n.settingsSaved, 'success' );
			} ).catch( function ( err ) {
				UI.toast( ( err && err.message ) || i18n.failed, 'error' );
			} ).then( function () {
				if ( btn ) {
					btn.disabled = false;
				}
			} );
		}
	};

	/* ----------------------------------------------------------------- *
	 * Rollback controller (plugin/theme versions)
	 * ----------------------------------------------------------------- */
	var Rollback = {
		init: function () {
			this.root = el( 'revertix-rollback' );
			if ( ! this.root ) {
				return;
			}
			this.premium = this.root.getAttribute( 'data-premium' ) === '1';
			this.detail = el( 'revertix-rb-detail' );

			var self = this;

			// Hide broken icons → show the dashicon placeholder.
			this.root.querySelectorAll( '.revertix-rb-row__icon' ).forEach( function ( img ) {
				self.bindIcon( img );
			} );

			this.root.addEventListener( 'click', function ( e ) {
				var tab = e.target.closest( '.revertix-rb-tab' );
				if ( tab ) {
					self.switchGroup( tab.getAttribute( 'data-group' ) );
					return;
				}
				var row = e.target.closest( '.revertix-rb-row' );
				if ( row ) {
					self.select( row );
				}
			} );

			var filter = el( 'revertix-rb-filter' );
			if ( filter ) {
				filter.addEventListener( 'input', function () {
					self.filter( filter.value );
				} );
			}
		},

		// Show the dashicon placeholder when an icon fails to load. Also covers the
		// case where the (server-rendered) image already errored before this ran -
		// the error event won't fire again, so check complete/naturalWidth too.
		bindIcon: function ( img ) {
			function broken() {
				var media = img.closest( '.revertix-rb-row__media, .revertix-rb-preview__media' );
				if ( media ) {
					media.classList.add( 'is-broken' );
				}
			}
			img.addEventListener( 'error', broken );
			// Already-loaded-and-failed case (server-rendered images whose error
			// event fired before this handler was attached). Only when src is set.
			if ( img.src && img.complete && img.naturalWidth === 0 ) {
				broken();
			}
		},

		switchGroup: function ( group ) {
			this.root.querySelectorAll( '.revertix-rb-tab' ).forEach( function ( t ) {
				t.classList.toggle( 'is-active', t.getAttribute( 'data-group' ) === group );
			} );
			this.root.querySelectorAll( '.revertix-rb-list__group' ).forEach( function ( g ) {
				g.hidden = g.getAttribute( 'data-group' ) !== group;
			} );
			var filter = el( 'revertix-rb-filter' );
			if ( filter && filter.value ) {
				this.filter( filter.value );
			}
		},

		make: function ( tag, cls, text ) {
			var node = document.createElement( tag );
			if ( cls ) {
				node.className = cls;
			}
			if ( text != null ) {
				node.textContent = text;
			}
			return node;
		},

		filter: function ( q ) {
			q = ( q || '' ).toLowerCase();
			this.root.querySelectorAll( '.revertix-rb-row' ).forEach( function ( row ) {
				var name = ( row.getAttribute( 'data-name' ) || '' ).toLowerCase();
				row.style.display = ( ! q || name.indexOf( q ) !== -1 ) ? '' : 'none';
			} );
		},

		select: function ( row ) {
			this.root.querySelectorAll( '.revertix-rb-row.is-active' ).forEach( function ( r ) {
				r.classList.remove( 'is-active' );
			} );
			row.classList.add( 'is-active' );
			this.load( row );
		},

		load: function ( row ) {
			var self = this;
			this.detail.innerHTML = '';
			this.detail.appendChild( this.make( 'p', 'revertix-hint', i18n.rbLoading ) );

			post( 'revertix_rollback_versions', {
				type: row.getAttribute( 'data-type' ),
				id: row.getAttribute( 'data-id' ),
				slug: row.getAttribute( 'data-slug' ),
				current: row.getAttribute( 'data-version' )
			} ).then( function ( res ) {
				if ( ! res || ! res.success ) {
					self.detail.innerHTML = '';
					self.detail.appendChild( self.make( 'p', 'revertix-hint revertix-hint--error', ( res && res.data && res.data.message ) || i18n.failed ) );
					return;
				}
				self.renderDetail( row, res.data );
			} ).catch( function ( err ) {
				self.detail.innerHTML = '';
				self.detail.appendChild( self.make( 'p', 'revertix-hint revertix-hint--error', ( err && err.message ) || i18n.failed ) );
			} );
		},

		// Big preview header for the selected item.
		preview: function ( row, isCustom ) {
			var wrap = this.make( 'div', 'revertix-rb-preview' );

			var media = this.make( 'div', 'revertix-rb-preview__media' );
			var icon = row.getAttribute( 'data-icon' );
			if ( icon ) {
				var img = document.createElement( 'img' );
				img.className = 'revertix-rb-preview__icon';
				img.alt = '';
				// Attach the error handler BEFORE setting src so a fast 404 isn't missed.
				this.bindIcon( img );
				img.src = icon;
				media.appendChild( img );
			} else {
				media.classList.add( 'is-broken' );
			}
			media.appendChild( this.make( 'span', 'revertix-rb-preview__fallback dashicons ' + ( row.getAttribute( 'data-fallback' ) || 'dashicons-admin-plugins' ) ) );
			wrap.appendChild( media );

			var body = this.make( 'div', 'revertix-rb-preview__body' );
			body.appendChild( this.make( 'h3', 'revertix-rb-preview__name', row.getAttribute( 'data-name' ) ) );

			var meta = this.make( 'div', 'revertix-rb-preview__meta' );
			meta.appendChild( this.make( 'span', 'revertix-rb-preview__ver', 'v' + row.getAttribute( 'data-version' ) ) );
			if ( row.getAttribute( 'data-active' ) === '1' ) {
				meta.appendChild( this.make( 'span', 'revertix-rb-item__active', i18n.rbActive || 'Active' ) );
			}
			var author = row.getAttribute( 'data-author' );
			if ( author ) {
				meta.appendChild( this.make( 'span', 'revertix-rb-preview__author', '· ' + author ) );
			}
			if ( isCustom ) {
				meta.appendChild( this.make( 'span', 'revertix-rb-item__custom', i18n.rbCustomTag ) );
			}
			body.appendChild( meta );
			wrap.appendChild( body );

			return wrap;
		},

		renderDetail: function ( row, data ) {
			var self = this;
			var isCustom = ( data.source !== 'wporg' ) || ! data.versions.length;

			this.detail.innerHTML = '';
			this.detail.appendChild( this.preview( row, isCustom ) );

			if ( ! isCustom ) {
				this.detail.appendChild( this.make( 'div', 'revertix-rb-detail__label', i18n.rbVersions || 'Versions' ) );

				var selected = null;
				var applyBtn = this.make( 'button', 'revertix-btn revertix-btn--primary revertix-rb-apply', i18n.rbApply || 'Select a version' );
				applyBtn.type = 'button';
				applyBtn.disabled = true;

				var vList = this.make( 'div', 'revertix-rb-vlist' );
				data.versions.forEach( function ( v ) {
					var rowEl = self.make( 'button', 'revertix-rb-vrow' );
					rowEl.type = 'button';

					var left = self.make( 'span', 'revertix-rb-vrow__main' );
					left.appendChild( self.make( 'span', 'revertix-rb-vrow__dot' ) );
					left.appendChild( self.make( 'span', 'revertix-rb-vrow__ver', 'v' + v.version ) );
					if ( v.installed ) {
						left.appendChild( self.make( 'span', 'revertix-rb-ver__tag', i18n.rbInstalled || 'installed' ) );
					} else if ( v.latest ) {
						left.appendChild( self.make( 'span', 'revertix-rb-ver__tag', i18n.rbLatest ) );
					} else if ( v.previous ) {
						left.appendChild( self.make( 'span', 'revertix-rb-ver__tag', i18n.rbPrevious ) );
					}
					rowEl.appendChild( left );

					if ( v.installed ) {
						// The current version stays in the list with its radio
						// pre-selected as a non-actionable "you are here" marker (it
						// is the version you would be rolling back FROM).
						rowEl.classList.add( 'is-installed', 'is-selected' );
						rowEl.disabled = true;
					} else {
						rowEl.addEventListener( 'click', function () {
							vList.querySelectorAll( '.revertix-rb-vrow.is-selected' ).forEach( function ( r ) {
								r.classList.remove( 'is-selected' );
							} );
							rowEl.classList.add( 'is-selected' );
							selected = v.version;
							applyBtn.disabled = false;
							applyBtn.textContent = ( i18n.rbSwitch || 'Switch to' ) + ' v' + v.version;
						} );
					}
					vList.appendChild( rowEl );
				} );
				this.detail.appendChild( vList );

				applyBtn.addEventListener( 'click', function () {
					if ( selected ) {
						self.run( row, { source: 'wporg', version: selected } );
					}
				} );
				this.detail.appendChild( applyBtn );
			} else {
				this.detail.appendChild( this.make( 'p', 'revertix-hint revertix-hint--warn',
					data.premium ? i18n.rbCustomPro : i18n.rbCustomFree ) );

				if ( ! data.premium ) {
					var up = this.make( 'button', 'revertix-btn revertix-btn--sm revertix-btn--upgrade revertix-open-pricing', i18n.upgradePro );
					up.type = 'button';
					this.detail.appendChild( up );
				}
			}

			// Changelog from wordpress.org (collapsible).
			if ( data.changelog ) {
				var det = this.make( 'details', 'revertix-rb-changelog' );
				det.appendChild( this.make( 'summary', null, i18n.rbChangelog || 'Changelog' ) );
				var clog = this.make( 'div', 'revertix-rb-changelog__body' );
				clog.innerHTML = data.changelog;
				det.appendChild( clog );
				this.detail.appendChild( det );
			}

			if ( data.premium ) {
				this.detail.appendChild( this.buildSnapshots( row, data.snapshots || [] ) );

				var snapBtn = this.make( 'button', 'revertix-btn revertix-btn--sm revertix-rb-snap', i18n.rbSnapshot );
				snapBtn.type = 'button';
				snapBtn.addEventListener( 'click', function () {
					self.snapshot( row, snapBtn );
				} );
				this.detail.appendChild( snapBtn );
			}
		},

		buildSnapshots: function ( row, snaps ) {
			var self = this;
			var wrap = this.make( 'div', 'revertix-rb-snaps' );
			wrap.appendChild( this.make( 'div', 'revertix-rb-snaps__title', i18n.rbSnapshots ) );

			if ( ! snaps.length ) {
				wrap.appendChild( this.make( 'p', 'revertix-hint', i18n.rbNoSnapshots ) );
				return wrap;
			}

			snaps.forEach( function ( s ) {
				var rowEl = self.make( 'div', 'revertix-rb-snap-row' );
				rowEl.appendChild( self.make( 'span', 'revertix-rb-snap-row__ver', 'v' + s.version ) );
				rowEl.appendChild( self.make( 'span', 'revertix-rb-snap-row__meta', s.created + ' · ' + s.size ) );
				var btn = self.make( 'button', 'revertix-btn revertix-btn--sm', i18n.rbRestore );
				btn.type = 'button';
				btn.addEventListener( 'click', function () {
					self.run( row, { source: 'snapshot', version: s.version, snapshot: s.file } );
				} );
				rowEl.appendChild( btn );
				wrap.appendChild( rowEl );
			} );
			return wrap;
		},

		run: function ( row, opts ) {
			var self = this;
			var name = row.getAttribute( 'data-name' );
			var msg = ( i18n.rbConfirm || 'Roll back %1$s to %2$s?' )
				.replace( '%1$s', name ).replace( '%2$s', opts.version );

			UI.confirm( msg, { danger: true } ).then( function ( ok ) {
				if ( ! ok ) {
					return;
				}
				UI.toast( i18n.rbRollingBack, 'info' );
				post( 'revertix_rollback_run', {
					type: row.getAttribute( 'data-type' ),
					id: row.getAttribute( 'data-id' ),
					slug: row.getAttribute( 'data-slug' ),
					current: row.getAttribute( 'data-version' ),
					source: opts.source,
					version: opts.version,
					snapshot: opts.snapshot || ''
				} ).then( function ( res ) {
					if ( ! res || ! res.success ) {
						UI.toast( ( res && res.data && res.data.message ) || i18n.failed, 'error' );
						return;
					}
					UI.toast( ( res.data && res.data.message ) || i18n.rbDone, 'success' );
					setTimeout( function () {
						window.location.reload();
					}, 1400 );
				} ).catch( function ( err ) {
					UI.toast( ( err && err.message ) || i18n.failed, 'error' );
				} );
			} );
		},

		snapshot: function ( row, btn ) {
			var self = this;
			btn.disabled = true;
			UI.toast( i18n.rbSnapping, 'info' );
			post( 'revertix_rollback_snapshot', {
				type: row.getAttribute( 'data-type' ),
				id: row.getAttribute( 'data-id' ),
				current: row.getAttribute( 'data-version' )
			} ).then( function ( res ) {
				if ( ! res || ! res.success ) {
					UI.toast( ( res && res.data && res.data.message ) || i18n.failed, 'error' );
					return;
				}
				UI.toast( i18n.rbSnapDone, 'success' );
				var fresh = self.buildSnapshots( row, ( res.data && res.data.snapshots ) || [] );
				var existing = self.detail.querySelector( '.revertix-rb-snaps' );
				if ( existing ) {
					existing.parentNode.replaceChild( fresh, existing );
				} else {
					self.detail.insertBefore( fresh, btn );
				}
			} ).catch( function ( err ) {
				UI.toast( ( err && err.message ) || i18n.failed, 'error' );
			} ).then( function () {
				btn.disabled = false;
			} );
		}
	};

	document.addEventListener( 'DOMContentLoaded', function () {
		Packages.init();
		Export.init();
		Import.init();
		Staging.init();
		Backups.init();
		Pricing.init();
		Log.init();
		Settings.init();
		Rollback.init();
	} );
} )();
