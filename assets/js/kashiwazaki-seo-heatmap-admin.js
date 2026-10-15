/**
 * Kashiwazaki SEO Heatmap - 管理画面のヒートマップ表示。
 *
 * 依存ライブラリなしで動作する（ローカル環境でも外部 CDN を必要としない）。
 *
 * @package Kashiwazaki_SEO_Heatmap
 */
( function () {
	'use strict';

	var CFG = window.KashiwazakiSEOHeatmapAdmin;
	if ( ! CFG ) {
		return;
	}

	/* ------------------------------------------------------------------ */
	/* 配色                                                                */
	/* ------------------------------------------------------------------ */

	var PALETTES = {
		thermal: [ [ 0, 0, 255 ], [ 0, 170, 255 ], [ 0, 255, 140 ], [ 170, 255, 0 ], [ 255, 200, 0 ], [ 255, 80, 0 ], [ 255, 0, 0 ] ],
		hot: [ [ 40, 0, 0 ], [ 130, 0, 0 ], [ 220, 0, 0 ], [ 255, 120, 0 ], [ 255, 230, 0 ] ],
		cool: [ [ 10, 30, 90 ], [ 0, 90, 200 ], [ 70, 170, 255 ], [ 170, 230, 255 ] ],
		viridis: [ [ 68, 1, 84 ], [ 59, 82, 139 ], [ 33, 145, 140 ], [ 94, 201, 98 ], [ 253, 231, 37 ] ],
		mono: [ [ 120, 120, 120 ], [ 60, 60, 60 ], [ 0, 0, 0 ] ]
	};

	function lerp( a, b, t ) {
		return Math.round( a + ( b - a ) * t );
	}

	function buildRamp( scheme ) {
		var stops = PALETTES[ scheme ] || PALETTES.thermal;
		var ramp = new Array( 256 );
		var i;
		for ( i = 0; i < 256; i++ ) {
			var t = i / 255;
			var seg = t * ( stops.length - 1 );
			var idx = Math.floor( seg );
			if ( idx > stops.length - 2 ) {
				idx = stops.length - 2;
			}
			var f = seg - idx;
			ramp[ i ] = [
				lerp( stops[ idx ][ 0 ], stops[ idx + 1 ][ 0 ], f ),
				lerp( stops[ idx ][ 1 ], stops[ idx + 1 ][ 1 ], f ),
				lerp( stops[ idx ][ 2 ], stops[ idx + 1 ][ 2 ], f )
			];
		}
		return ramp;
	}

	/* ------------------------------------------------------------------ */
	/* 状態                                                                */
	/* ------------------------------------------------------------------ */

	var state = {
		pageKey: '',
		postId: 0,
		url: '',
		days: 30,
		device: '',
		type: 'click',
		render: 'heat',
		radius: 28,
		opacity: 60,
		scheme: 'thermal',
		deviceWidth: 'desktop',
		data: null,
		points: [],
		pointByKey: {},
		docW: 0,
		docH: 0,
		// 表示用の実際の文書サイズ（高さは描画上限で切らない。%指定の座標を戻すのに使う）。
		realW: 0,
		realH: 0,
		baseCanvas: null,
		hoverKey: '',
		highlightKey: '',
		loading: false,
		// 連続で切り替えたときに古い応答を捨てるための通し番号。
		reqId: 0
	};

	/* ------------------------------------------------------------------ */
	/* ユーティリティ                                                      */
	/* ------------------------------------------------------------------ */

	function $( id ) {
		return document.getElementById( id );
	}

	function fmt( n ) {
		if ( n === null || n === undefined || isNaN( n ) ) {
			return '—';
		}
		return Number( n ).toLocaleString( 'ja-JP' );
	}

	function clamp( v, min, max ) {
		return Math.max( min, Math.min( max, v ) );
	}

	/**
	 * 到達した閲覧の割合（0〜1）。分母は閲覧数。記録の時期のずれで到達数が
	 * 閲覧数を少し上回ることがあるので、1 を上限にする。
	 */
	function reachRatio( count, views ) {
		if ( ! views || views <= 0 ) {
			return 0;
		}
		return clamp( ( count || 0 ) / views, 0, 1 );
	}

	function ajax( action, params ) {
		var body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', CFG.nonce );
		Object.keys( params || {} ).forEach( function ( key ) {
			body.append( key, params[ key ] );
		} );
		return fetch( CFG.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} ).then( function ( res ) {
			return res.json();
		} );
	}

	/* ------------------------------------------------------------------ */
	/* 選択の読み書き（URL のクエリと画面の選択欄）                        */
	/* ------------------------------------------------------------------ */

	/**
	 * 選んでいるページを URL のクエリに残す。
	 *
	 * ヒートマップ画面を再読み込みしたときや、ダッシュボードとの行き来で
	 * 「さっき見ていたページ」を開き直せるようにするため。
	 */
	function syncLocation() {
		if ( ! window.history || ! window.history.replaceState ) {
			return;
		}
		var params = new URLSearchParams( window.location.search );
		if ( state.pageKey ) {
			params.set( 'page_key', state.pageKey );
		} else {
			params.delete( 'page_key' );
		}
		if ( state.postId ) {
			params.set( 'post_id', state.postId );
		} else {
			params.delete( 'post_id' );
		}
		var qs = params.toString();
		window.history.replaceState(
			null,
			'',
			window.location.pathname + ( qs ? '?' + qs : '' ) + window.location.hash
		);
	}

	/**
	 * URL のクエリで指定されたページを、画面の選び方（3 つの入力欄）に反映する。
	 *
	 * 入力欄が空のままヒートマップだけが出ていると、何を見ているのか分からない。
	 * post_id しか無いとき（ダッシュボードからのリンク）は、
	 * 一覧に無いページでも見られるように ID 欄に直接入れる。
	 */
	function applySelectionFromQuery() {
		var query = new URLSearchParams( window.location.search );
		var pageKey = query.get( 'page_key' ) || '';
		var postId = parseInt( query.get( 'post_id' ), 10 ) || 0;
		var pageSelect = $( 'kashiwazaki-seo-heatmap-page-select' );

		if ( pageKey ) {
			state.pageKey = pageKey;
			if ( pageSelect ) {
				pageSelect.value = pageKey;
			}
			var chosen = pageSelect ? pageSelect.options[ pageSelect.selectedIndex ] : null;
			if ( chosen ) {
				if ( chosen.getAttribute( 'data-url' ) ) {
					state.url = chosen.getAttribute( 'data-url' );
				}
				if ( ! postId && chosen.getAttribute( 'data-post' ) ) {
					postId = parseInt( chosen.getAttribute( 'data-post' ), 10 ) || 0;
				}
			}
			if ( postId && $( 'kashiwazaki-seo-heatmap-post-id' ) ) {
				$( 'kashiwazaki-seo-heatmap-post-id' ).value = postId;
			}
		} else if ( postId ) {
			var postSelect = $( 'kashiwazaki-seo-heatmap-post-select' );
			if ( postSelect ) {
				var matched = '';
				for ( var i = 0; i < postSelect.options.length; i++ ) {
					if ( parseInt( postSelect.options[ i ].value, 10 ) === postId ) {
						matched = postSelect.options[ i ].value;
						state.url = postSelect.options[ i ].getAttribute( 'data-url' ) || state.url;
						break;
					}
				}
				postSelect.value = matched;
			}
			if ( $( 'kashiwazaki-seo-heatmap-post-id' ) ) {
				$( 'kashiwazaki-seo-heatmap-post-id' ).value = postId;
			}
		}

		if ( postId ) {
			state.postId = postId;
		}
	}

	/* ------------------------------------------------------------------ */
	/* ヒートマップ画面                                                    */
	/* ------------------------------------------------------------------ */

	function initHeatmapPage() {
		var canvas = $( 'kashiwazaki-seo-heatmap-canvas' );
		if ( ! canvas ) {
			return;
		}

		var frame = $( 'kashiwazaki-seo-heatmap-frame' );
		var wrap = $( 'kashiwazaki-seo-heatmap-frame-wrap' );
		var inner = $( 'kashiwazaki-seo-heatmap-frame-inner' );
		var placeholder = $( 'kashiwazaki-seo-heatmap-placeholder' );
		var tooltip = document.createElement( 'div' );
		tooltip.className = 'kashiwazaki-seo-heatmap-tooltip';
		document.body.appendChild( tooltip );

		// 設定画面の値で初期化する。
		if ( CFG.settings ) {
			state.radius = parseInt( CFG.settings.heatRadius, 10 ) || state.radius;
			state.opacity = parseInt( CFG.settings.heatOpacity, 10 ) || state.opacity;
			state.scheme = CFG.settings.colorScheme || state.scheme;
		}

		// URL のクエリから初期値を反映する（ダッシュボードからのリンク用）。
		applySelectionFromQuery();

		/* ------------------------------ コントロール ------------------------------ */

		bind( 'kashiwazaki-seo-heatmap-page-select', 'change', function () {
			var select = $( 'kashiwazaki-seo-heatmap-page-select' );
			var option = select.options[ select.selectedIndex ];
			state.pageKey = select.value || '';
			state.postId = option && option.getAttribute( 'data-post' ) ? parseInt( option.getAttribute( 'data-post' ), 10 ) : 0;
			state.url = option && option.getAttribute( 'data-url' ) ? option.getAttribute( 'data-url' ) : '';
			if ( $( 'kashiwazaki-seo-heatmap-post-select' ) ) {
				$( 'kashiwazaki-seo-heatmap-post-select' ).value = '';
			}
			if ( $( 'kashiwazaki-seo-heatmap-post-id' ) ) {
				$( 'kashiwazaki-seo-heatmap-post-id' ).value = state.postId || '';
			}
			reloadAll();
		} );

		bind( 'kashiwazaki-seo-heatmap-post-select', 'change', function () {
			var select = $( 'kashiwazaki-seo-heatmap-post-select' );
			var option = select.options[ select.selectedIndex ];
			state.postId = parseInt( select.value, 10 ) || 0;
			state.pageKey = '';
			state.url = option && option.getAttribute( 'data-url' ) ? option.getAttribute( 'data-url' ) : '';
			if ( $( 'kashiwazaki-seo-heatmap-page-select' ) ) {
				$( 'kashiwazaki-seo-heatmap-page-select' ).value = '';
			}
			if ( $( 'kashiwazaki-seo-heatmap-post-id' ) ) {
				$( 'kashiwazaki-seo-heatmap-post-id' ).value = state.postId || '';
			}
			reloadAll();
		} );

		var filter = $( 'kashiwazaki-seo-heatmap-post-filter' );
		if ( filter ) {
			filter.addEventListener( 'input', function () {
				var needle = filter.value.trim().toLowerCase();
				var select = $( 'kashiwazaki-seo-heatmap-post-select' );
				var options = select.options;
				var i;
				for ( i = 0; i < options.length; i++ ) {
					if ( i === 0 ) {
						continue;
					}
					var text = options[ i ].textContent.toLowerCase();
					options[ i ].hidden = needle !== '' && text.indexOf( needle ) === -1;
					options[ i ].disabled = options[ i ].hidden;
				}
				var first = null;
				for ( i = 1; i < options.length; i++ ) {
					if ( ! options[ i ].hidden ) {
						first = options[ i ];
						break;
					}
				}
				if ( first && select.value !== '' && select.selectedOptions[ 0 ] && select.selectedOptions[ 0 ].hidden ) {
					select.value = first.value;
					select.dispatchEvent( new Event( 'change' ) );
				}
			} );
		}

		bind( 'kashiwazaki-seo-heatmap-post-id', 'change', function () {
			state.postId = parseInt( $( 'kashiwazaki-seo-heatmap-post-id' ).value, 10 ) || 0;
			state.pageKey = '';
			state.url = '';
			if ( $( 'kashiwazaki-seo-heatmap-page-select' ) ) {
				$( 'kashiwazaki-seo-heatmap-page-select' ).value = '';
			}
			if ( $( 'kashiwazaki-seo-heatmap-post-select' ) ) {
				$( 'kashiwazaki-seo-heatmap-post-select' ).value = '';
			}
			reloadAll();
		} );

		bind( 'kashiwazaki-seo-heatmap-days', 'change', function () {
			state.days = parseInt( $( 'kashiwazaki-seo-heatmap-days' ).value, 10 ) || 30;
			reloadAll();
		} );

		bind( 'kashiwazaki-seo-heatmap-device', 'change', function () {
			state.device = $( 'kashiwazaki-seo-heatmap-device' ).value;
			reloadAll();
		} );

		bind( 'kashiwazaki-seo-heatmap-heat-type', 'change', function () {
			state.type = $( 'kashiwazaki-seo-heatmap-heat-type' ).value;
			refreshDisplay();
		} );

		bind( 'kashiwazaki-seo-heatmap-render', 'change', function () {
			state.render = $( 'kashiwazaki-seo-heatmap-render' ).value;
			refreshDisplay();
		} );

		bind( 'kashiwazaki-seo-heatmap-radius', 'input', function () {
			state.radius = parseInt( $( 'kashiwazaki-seo-heatmap-radius' ).value, 10 ) || 28;
			refreshDisplay();
		} );

		bind( 'kashiwazaki-seo-heatmap-opacity', 'input', function () {
			state.opacity = parseInt( $( 'kashiwazaki-seo-heatmap-opacity' ).value, 10 ) || 60;
			refreshDisplay();
		} );

		bind( 'kashiwazaki-seo-heatmap-scheme', 'change', function () {
			state.scheme = $( 'kashiwazaki-seo-heatmap-scheme' ).value;
			refreshDisplay();
		} );

		bind( 'kashiwazaki-seo-heatmap-reload', 'click', function () {
			reloadAll();
		} );

		bind( 'kashiwazaki-seo-heatmap-csv', 'click', function () {
			var url = CFG.ajaxUrl +
				'?action=kashiwazaki_seo_heatmap_export_csv' +
				'&nonce=' + encodeURIComponent( CFG.nonce ) +
				'&post_id=' + encodeURIComponent( state.postId || 0 ) +
				'&page_key=' + encodeURIComponent( state.pageKey || '' ) +
				'&days=' + encodeURIComponent( state.days ) +
				'&device=' + encodeURIComponent( state.device || '' ) +
				'&type=' + encodeURIComponent( state.type || 'click' );
			var link = document.createElement( 'a' );
			link.href = url;
			link.style.display = 'none';
			document.body.appendChild( link );
			link.click();
			document.body.removeChild( link );
		} );

		var widthButtons = document.querySelectorAll( '.kashiwazaki-seo-heatmap-width-btn' );
		Array.prototype.forEach.call( widthButtons, function ( button ) {
			button.addEventListener( 'click', function () {
				Array.prototype.forEach.call( widthButtons, function ( other ) {
					other.classList.remove( 'is-active' );
				} );
				button.classList.add( 'is-active' );
				state.deviceWidth = button.getAttribute( 'data-width' );
				applyFrameWidth( frame, inner );
				measureAndDraw( frame, inner, tooltip );
			} );
		} );

		/* ------------------------------ iframe ------------------------------ */

		bind( 'kashiwazaki-seo-heatmap-interactive', 'change', function () {
			if ( $( 'kashiwazaki-seo-heatmap-interactive' ).checked ) {
				wrap.classList.add( 'is-interactive' );
				tooltip.style.display = 'none';
			} else {
				wrap.classList.remove( 'is-interactive' );
			}
		} );

		var frameLoaded = false;
		frame.addEventListener( 'load', function () {
			if ( ! frameLoaded ) {
				return;
			}
			onFrameLoad( frame, inner, tooltip, placeholder );
		} );

		function setFrame( url ) {
			if ( ! url ) {
				placeholder.classList.remove( 'is-hidden' );
				frame.style.display = 'none';
				$( 'kashiwazaki-seo-heatmap-canvas' ).style.display = 'none';
				return;
			}
			// プレビューに読み込むのは、管理画面と同じサイトの URL だけにする。
			// 記録された URL は訪問者の送信から来るので、「//別のサイト/…」のような
			// 値で外部のページを管理画面の中に表示させられないようにする。
			var target = null;
			try {
				target = new URL( url, window.location.href );
			} catch ( e ) {
				target = null;
			}
			if ( ! target || target.origin !== window.location.origin ) {
				frame.style.display = 'none';
				$( 'kashiwazaki-seo-heatmap-canvas' ).style.display = 'none';
				showNotice( placeholder, CFG.i18n.previewBlocked );
				placeholder.classList.remove( 'is-hidden' );
				return;
			}
			placeholder.classList.add( 'is-hidden' );
			frame.style.display = '';
			$( 'kashiwazaki-seo-heatmap-canvas' ).style.display = '';
			frameLoaded = true;
			target.searchParams.set( 'kashiwazaki_seo_heatmap_preview', '1' );
			frame.src = target.href;
		}

		/* ------------------------------ 読み込み ------------------------------ */

		/**
		 * 表示中の結果を消す（前のページのヒートマップが残らないようにする）。
		 */
		function resetDisplay() {
			state.data = null;
			state.points = [];
			state.pointByKey = {};
			state.highlightKey = '';
			state.hoverKey = '';
			state.url = '';

			var c = $( 'kashiwazaki-seo-heatmap-canvas' );
			if ( c ) {
				c.getContext( '2d' ).clearRect( 0, 0, c.width, c.height );
				c.style.display = 'none';
			}
			tooltip.style.display = 'none';
		}

		function reloadAll() {
			// 選び直した内容を URL に残す（再読み込みしても同じページに戻れるように）。
			syncLocation();

			if ( ! state.pageKey && ! state.postId ) {
				resetDisplay();
				placeholder.classList.remove( 'is-hidden' );
				frame.style.display = 'none';
				return;
			}

			// 素早く切り替えたとき、遅れて届いた古い応答で新しい表示を
			// 上書きしないように、応答の世代を確かめる。
			var reqId = ++state.reqId;
			state.loading = true;
			showNotice( placeholder, CFG.i18n.loading );
			placeholder.classList.remove( 'is-hidden' );

			ajax( 'kashiwazaki_seo_heatmap_get_data', {
				post_id: state.postId || 0,
				page_key: state.pageKey || '',
				days: state.days,
				device: state.device || '',
				url: state.url || ''
			} ).then( function ( response ) {
				if ( reqId !== state.reqId ) {
					return;
				}
				state.loading = false;
				if ( ! response || ! response.success ) {
					showNotice( placeholder, CFG.i18n.error );
					return;
				}
				state.data = response.data;
				if ( ! state.url && state.data.pageUrl ) {
					state.url = state.data.pageUrl;
				}
				if ( state.data.pageUrl ) {
					$( 'kashiwazaki-seo-heatmap-frame-url' ).textContent = state.data.pageUrl;
				} else if ( state.url ) {
					$( 'kashiwazaki-seo-heatmap-frame-url' ).textContent = state.url;
				} else {
					$( 'kashiwazaki-seo-heatmap-frame-url' ).textContent = '';
				}
				// 別のページに変わったので、選択中の要素は解除する。
				state.highlightKey = '';
				state.hoverKey = '';
				updateSummary( state.data.totals );
				renderScrollChart( state.data.scroll );
				renderElementTable( state.data );
				setFrame( state.url );
			} ).catch( function () {
				if ( reqId !== state.reqId ) {
					return;
				}
				state.loading = false;
				showNotice( placeholder, CFG.i18n.error );
			} );
		}

		function refreshDisplay() {
			if ( ! state.data ) {
				return;
			}
			renderElementTable( state.data );
			measureAndDraw( frame, inner, tooltip );
		}

		/**
		 * 選んでいる種類のデータが 1 件も無いときは、その旨を出す。
		 *
		 * 何も描かれないと「壊れている」「読み込みに失敗した」ように見えるため。
		 * スクロール到達率は帯で画面全体を塗るので、この案内は出さない。
		 */
		function updateEmptyNotice() {
			var notice = $( 'kashiwazaki-seo-heatmap-frame-notice' );
			if ( ! notice ) {
				return;
			}

			if ( ! state.data || 'scroll' === state.type || state.points.length ) {
				notice.hidden = true;
				notice.textContent = '';
				return;
			}

			var typeSelect = $( 'kashiwazaki-seo-heatmap-heat-type' );
			var label = '';
			if ( typeSelect && typeSelect.selectedIndex >= 0 ) {
				label = typeSelect.options[ typeSelect.selectedIndex ].textContent.trim();
			}
			notice.textContent = ( CFG.i18n.noDataForType || 'この期間の「%s」は 1 件も記録されていません。' ).replace( '%s', label );
			notice.hidden = false;
		}

		function onFrameLoad( frameEl, innerEl, tip, ph ) {
			var doc;
			try {
				doc = frameEl.contentDocument;
			} catch ( e ) {
				doc = null;
			}
			if ( ! doc ) {
				showNotice( ph, 'プレビューを読み込めませんでした。' );
				return;
			}
			ph.classList.add( 'is-hidden' );
			applyFrameWidth( frameEl, innerEl );

			// プレビューの中をスクロールしたら、重ねたヒートも同じだけ動かす。
			// 遅延読み込みでページが伸びたら測り直す。
			var win = doc.defaultView;
			if ( win ) {
				var lastHeight = 0;
				var remeasure = 0;
				var refloat = 0;
				var refloatEnd = 0;
				win.addEventListener( 'scroll', function () {
					window.requestAnimationFrame( paint );
					// 画面に固定される要素の点があれば、スクロールの間も一定の間隔で位置を測り直す
					// （固定の要素は窓に残るので、文書の座標がスクロールのたびに変わる）。
					if ( state.hasFloating && ! refloat ) {
						refloat = window.setTimeout( function () {
							refloat = 0;
							measureAndDraw( frameEl, innerEl, tip );
						}, 120 );
					}
					// 止まったところでは、固定の要素の点が無くても、すべての点の位置を測り直す。
					// テーマのスクリプトがスクロールに合わせてヘッダーを後から固定する（クラスを付ける）
					// ことがあり、読み込んだ時点では固定でなかった要素も、窓の中の別の場所に移るため。
					// 位置が変わった点があるときだけ描き直す。
					window.clearTimeout( refloatEnd );
					refloatEnd = window.setTimeout( function () {
						redrawIfMoved( doc, frameEl );
					}, 200 );
					var h = doc.documentElement ? doc.documentElement.scrollHeight : 0;
					if ( lastHeight && h > lastHeight ) {
						window.clearTimeout( remeasure );
						remeasure = window.setTimeout( function () {
							measureAndDraw( frameEl, innerEl, tip );
						}, 400 );
					}
					lastHeight = h;
				}, { passive: true } );
			}

			waitForImages( doc, 4000 ).then( function () {
				measureAndDraw( frameEl, innerEl, tip );
				// 遅れて伸びるページ（遅延読み込み）にも追従する。
				window.setTimeout( function () {
					measureAndDraw( frameEl, innerEl, tip );
				}, 1200 );
			} );
		}

		/* ------------------------------ 描画 ------------------------------ */

		/**
		 * プレビューの窓の高さ（端末ごとの一般的な画面の高さ。管理画面に収まる大きさまで）。
		 *
		 * プレビューを文書の高さまで伸ばすと、vh 単位（画面の高さに合わせる区画）を使う
		 * テーマでは、伸ばした分だけ区画も伸びて、測った高さと実際の配置が食い違う。
		 * 実際の訪問者と同じように「画面の高さの窓」の中をスクロールさせ、
		 * ヒートはその窓に合わせて重ねる。
		 */
		function frameViewportHeight() {
			var deviceHeight = 900;
			if ( state.deviceWidth === 'tablet' ) {
				deviceHeight = 1024;
			} else if ( state.deviceWidth === 'mobile' ) {
				deviceHeight = 844;
			}
			var available = Math.round( window.innerHeight * 0.78 );
			return Math.max( 320, Math.min( available, deviceHeight ) );
		}

		function applyFrameWidth( frameEl, innerEl ) {
			var width = '';
			if ( state.deviceWidth === 'tablet' ) {
				width = '768px';
			} else if ( state.deviceWidth === 'mobile' ) {
				width = '390px';
			}
			frameEl.style.width = width || '100%';
			innerEl.style.width = width || '100%';
			innerEl.style.maxWidth = width ? width : '';
			var height = frameViewportHeight() + 'px';
			frameEl.style.height = height;
			innerEl.style.height = height;
		}

		function measureAndDraw( frameEl, innerEl, tip ) {
			var canvas = $( 'kashiwazaki-seo-heatmap-canvas' );
			var doc;
			try {
				doc = frameEl.contentDocument;
			} catch ( e ) {
				return;
			}
			if ( ! doc || ! doc.documentElement ) {
				return;
			}

			var docEl = doc.documentElement;
			var body = doc.body;
			var viewH = frameViewportHeight();
			frameEl.style.height = viewH + 'px';
			innerEl.style.height = viewH + 'px';

			// 計測側と同じ基準（文書全体の幅と高さ）を使う。窓の高さは固定なので、
			// vh 単位の区画も実際の画面と同じ大きさで測れる。
			var realW = Math.max(
				docEl.scrollWidth,
				body ? body.scrollWidth : 0,
				docEl.clientWidth || 0,
				1
			);
			var realH = Math.max(
				docEl.scrollHeight,
				body ? body.scrollHeight : 0,
				docEl.offsetHeight,
				viewH
			);

			state.realW = realW;
			state.realH = realH;

			// 極端に長いページは描画負荷を抑えるために上限を設ける。
			state.docW = realW;
			state.docH = Math.min( realH, 30000 );

			// 見える canvas は窓の大きさ。右端のスクロールバーは覆わない（ドラッグできるように）。
			var viewW = docEl.clientWidth || frameEl.clientWidth || realW;
			canvas.width = viewW;
			canvas.height = viewH;
			canvas.style.width = viewW + 'px';
			canvas.style.height = viewH + 'px';

			preparePoints( doc, frameEl );
			draw( canvas );
		}

		/**
		 * 点の位置を測り直し、どれかの点が動いていたら描き直す（スクロールが止まったときに呼ぶ）。
		 */
		function redrawIfMoved( doc, frameEl ) {
			var before = state.points;
			preparePoints( doc, frameEl );
			var after = state.points;
			var moved = before.length !== after.length;
			for ( var i = 0; ! moved && i < after.length; i++ ) {
				if ( Math.abs( before[ i ].x - after[ i ].x ) > 0.5 || Math.abs( before[ i ].y - after[ i ].y ) > 0.5 ) {
					moved = true;
				}
			}
			if ( moved ) {
				draw( $( 'kashiwazaki-seo-heatmap-canvas' ) );
			}
		}

		/**
		 * プレビューの中のスクロール位置。
		 */
		function frameScroll() {
			var win = null;
			try {
				win = frame.contentWindow;
			} catch ( e ) {
				win = null;
			}
			return {
				x: win ? ( win.pageXOffset || 0 ) : 0,
				y: win ? ( win.pageYOffset || 0 ) : 0
			};
		}

		function preparePoints( doc, frameEl ) {
			var data = state.data;
			state.points = [];
			state.pointByKey = {};
			state.hasFloating = false;
			if ( ! data ) {
				return;
			}

			var rows = data.clicks || [];
			if ( state.type === 'rage' ) {
				rows = data.rage || [];
			} else if ( state.type === 'dead' ) {
				rows = data.dead || [];
			} else if ( state.type === 'outbound' ) {
				rows = data.outbound || [];
			} else if ( state.type === 'move' ) {
				rows = data.moves || [];
			} else if ( state.type === 'scroll' ) {
				rows = [];
			}

			var i;
			for ( i = 0; i < rows.length; i++ ) {
				var row = rows[ i ];
				var pos = resolvePosition( doc, frameEl, row );
				if ( pos === null ) {
					continue;
				}
				if ( pos.floating ) {
					state.hasFloating = true;
				}
				var point = {
					key: row.key || ( 'p' + i ),
					count: row.count || 1,
					x: pos.x,
					y: pos.y,
					text: row.text || '',
					tag: row.tag || '',
					selector: row.selector || ''
				};
				state.points.push( point );
				if ( row.key ) {
					state.pointByKey[ row.key ] = point;
				}
			}
		}

		/**
		 * 要素か、その祖先が画面に固定される（position: fixed / sticky）か。
		 *
		 * 固定の要素は、スクロールしても窓の同じ場所に残る（MDN position「fixed ... The element
		 * is positioned relative to its initial containing block, which is the viewport」）ので、
		 * 文書の座標はスクロールのたびに変わる。そういう点があれば、スクロールに合わせて測り直す。
		 */
		function isFloating( el, win ) {
			var node = el;
			while ( node && node.nodeType === 1 ) {
				var style = null;
				try {
					style = win.getComputedStyle( node );
				} catch ( e ) {
					style = null;
				}
				if ( style && ( 'fixed' === style.position || 'sticky' === style.position ) ) {
					return true;
				}
				node = node.parentElement;
			}
			return false;
		}

		/**
		 * 要素の現在位置を求める。見つからなければクリック時の割合から推定する。
		 */
		function resolvePosition( doc, frameEl, row ) {
			if ( row.selector ) {
				var found = null;
				var index = row.index || 0;
				// move 集計には selector が無いので、ここはクリック系のみ。
				try {
					var all = doc.querySelectorAll( row.selector );
					// 記録した順番の要素が今のページに無いときは、先頭の別の要素に描かず、
					// 下の「記録時の割合」で描く（別の要素の上に描くと、押された場所を誤って見せる）。
					if ( all.length > index ) {
						found = all[ index ];
					}
				} catch ( e ) {
					found = null;
				}
				if ( found ) {
					var rect = found.getBoundingClientRect();
					// プレビューで表示されていない要素（閉じたメニュー・PC 幅で隠れる
					// スマホ用メニューなど）は大きさが 0 になり、位置が (0,0) になる。
					// そのときは要素の位置を使わず、記録時の割合で描く。
					if ( rect.width > 0 || rect.height > 0 ) {
						var win = doc.defaultView || window;
						return {
							x: rect.left + ( win.pageXOffset || 0 ) + rect.width / 2,
							y: rect.top + ( win.pageYOffset || 0 ) + rect.height / 2,
							floating: isFloating( found, win )
						};
					}
				}
			}

			// 割合ベースの推定。割合の基準は計測時の文書サイズ（realW/realH）なので、
			// 描画上限で切り詰めた docH ではなく実際の高さを掛ける。
			if ( row.xp || row.yp ) {
				return {
					x: ( row.xp / 100 ) * state.realW,
					y: ( row.yp / 100 ) * state.realH
				};
			}
			if ( row.x || row.y ) {
				return { x: row.x, y: row.y };
			}
			return null;
		}

		/* ------------------------------ 実描画 ------------------------------ */

		function draw( canvas ) {
			// 文書全体の大きさでオフスクリーンに描き、見える canvas には
			// プレビューのスクロール位置の部分だけを写す（paint）。
			var W = Math.max( 1, Math.round( state.docW ) );
			var H = Math.max( 1, Math.round( state.docH ) );
			var ctx = canvas.getContext( '2d' );
			ctx.clearRect( 0, 0, canvas.width, canvas.height );

			var base = document.createElement( 'canvas' );
			base.width = W;
			base.height = H;
			state.baseCanvas = base;

			if ( state.type === 'scroll' ) {
				drawScroll( base );
			} else if ( ! state.points.length ) {
				// データなし
			} else if ( state.render === 'dots' ) {
				drawDots( base );
			} else {
				drawHeat( base );
			}

			paint();
			updateEmptyNotice();
		}

		/**
		 * ベース画像に印（選択中の要素・ホバー中の要素）を重ねて表示する。
		 * ベースを作り直さないので、マウス移動のたびに呼んでも軽い。
		 */
		function paint() {
			var canvas = $( 'kashiwazaki-seo-heatmap-canvas' );
			if ( ! canvas ) {
				return;
			}
			var ctx = canvas.getContext( '2d' );
			ctx.clearRect( 0, 0, canvas.width, canvas.height );
			var scroll = frameScroll();
			if ( state.baseCanvas ) {
				// 文書全体の絵から、いまプレビューに見えている範囲だけを写す。
				var sw = Math.min( canvas.width, Math.max( 0, state.baseCanvas.width - scroll.x ) );
				var sh = Math.min( canvas.height, Math.max( 0, state.baseCanvas.height - scroll.y ) );
				if ( sw > 0 && sh > 0 ) {
					ctx.drawImage( state.baseCanvas, scroll.x, scroll.y, sw, sh, 0, 0, sw, sh );
				}
			}
			ctx.save();
			ctx.translate( -scroll.x, -scroll.y );
			if ( state.highlightKey ) {
				drawHighlight( ctx, state.highlightKey, '#2271b1' );
			}
			if ( state.hoverKey && state.hoverKey !== state.highlightKey ) {
				drawHighlight( ctx, state.hoverKey, '#d63638' );
			}
			ctx.restore();
		}

		function maxCount() {
			var max = 0;
			var i;
			for ( i = 0; i < state.points.length; i++ ) {
				if ( state.points[ i ].count > max ) {
					max = state.points[ i ].count;
				}
			}
			return max || 1;
		}

		function drawHeat( target ) {
			var W = target.width;
			var H = target.height;
			var max = maxCount();
			var scale = 1;
			var area = W * H;
			// 巨大なページでは縮小して色付けする（メモリと速度のため）。
			if ( area > 12000000 ) {
				scale = Math.sqrt( 12000000 / area );
			}
			var offW = Math.max( 1, Math.round( W * scale ) );
			var offH = Math.max( 1, Math.round( H * scale ) );

			var off = document.createElement( 'canvas' );
			off.width = offW;
			off.height = offH;
			// この作業用 canvas は、描いたあとに getImageData で読み返すためだけに使う。
			// MDN「willReadFrequently: A boolean value that indicates whether or not a lot
			// of read-back operations are planned. This will force the use of a software
			// (instead of hardware accelerated) 2D canvas and can save memory when calling
			// getImageData() frequently.」にしたがい、読み返す側だけソフトウェア描画にする
			// （表示用の canvas は読み返さないので、そのまま速いほうを使う）。
			var octx = off.getContext( '2d', { willReadFrequently: true } );
			octx.clearRect( 0, 0, offW, offH );

			var i;
			for ( i = 0; i < state.points.length; i++ ) {
				var p = state.points[ i ];
				var weight = p.count / max;
				var radius = state.radius * ( 0.6 + 0.9 * weight ) * scale;
				if ( radius < 2 ) {
					radius = 2;
				}
				var alpha = 0.18 + 0.42 * weight;
				var gradient = octx.createRadialGradient(
					p.x * scale, p.y * scale, 0,
					p.x * scale, p.y * scale, radius
				);
				gradient.addColorStop( 0, 'rgba(0,0,0,' + alpha.toFixed( 3 ) + ')' );
				gradient.addColorStop( 1, 'rgba(0,0,0,0)' );
				octx.fillStyle = gradient;
				octx.beginPath();
				octx.arc( p.x * scale, p.y * scale, radius, 0, Math.PI * 2 );
				octx.fill();
			}

			var ramp = buildRamp( state.scheme );
			var opacity = state.opacity / 100;
			var image = octx.getImageData( 0, 0, offW, offH );
			var data = image.data;
			for ( i = 0; i < data.length; i += 4 ) {
				var a = data[ i + 3 ] / 255;
				if ( a <= 0.02 ) {
					data[ i + 3 ] = 0;
					continue;
				}
				var t = Math.pow( a, 0.85 );
				var color = ramp[ clamp( Math.round( t * 255 ), 0, 255 ) ];
				data[ i ] = color[ 0 ];
				data[ i + 1 ] = color[ 1 ];
				data[ i + 2 ] = color[ 2 ];
				data[ i + 3 ] = Math.round( 255 * clamp( a, 0, 1 ) * opacity );
			}
			octx.putImageData( image, 0, 0 );

			var ctx = target.getContext( '2d' );
			ctx.clearRect( 0, 0, W, H );
			if ( scale === 1 ) {
				ctx.drawImage( off, 0, 0 );
			} else {
				ctx.imageSmoothingEnabled = true;
				ctx.drawImage( off, 0, 0, offW, offH, 0, 0, W, H );
			}
		}

		function drawDots( target ) {
			var ctx = target.getContext( '2d' );
			var ramp = buildRamp( state.scheme );
			var max = maxCount();
			var i;
			ctx.clearRect( 0, 0, target.width, target.height );
			for ( i = 0; i < state.points.length; i++ ) {
				var p = state.points[ i ];
				var weight = p.count / max;
				var radius = Math.max( 2.5, state.radius * 0.55 * ( 0.5 + weight ) );
				var color = ramp[ clamp( Math.round( weight * 255 ), 0, 255 ) ];
				ctx.globalAlpha = clamp( ( 0.35 + 0.5 * weight ) * ( state.opacity / 100 ) * 1.5, 0.05, 1 );
				ctx.fillStyle = 'rgb(' + color[ 0 ] + ',' + color[ 1 ] + ',' + color[ 2 ] + ')';
				ctx.beginPath();
				ctx.arc( p.x, p.y, radius, 0, Math.PI * 2 );
				ctx.fill();
			}
			ctx.globalAlpha = 1;
		}

		function drawScroll( target ) {
			var ctx = target.getContext( '2d' );
			var scroll = ( state.data && state.data.scroll ) || {};
			var depths = [ 10, 20, 30, 40, 50, 60, 70, 80, 90, 100 ];
			var max = 1;
			var i;
			for ( i = 0; i < depths.length; i++ ) {
				max = Math.max( max, scroll[ depths[ i ] ] || scroll[ String( depths[ i ] ) ] || 0 );
			}

			var W = target.width;
			var H = target.height;
			ctx.clearRect( 0, 0, W, H );
			var views = state.data && state.data.totals ? ( state.data.totals.pageviews || 0 ) : 0;

			for ( i = 0; i < depths.length; i++ ) {
				var depth = depths[ i ];
				var count = scroll[ depth ] || scroll[ String( depth ) ] || 0;
				var top = ( H * ( depth - 10 ) ) / 100;
				var bottom = ( H * depth ) / 100;
				var ratio = count / max;
				var alpha = 0.06 + 0.5 * ratio;
				// 到達した閲覧の割合（閲覧数が分母）。
				var reach = reachRatio( count, views );
				ctx.fillStyle = 'rgba(' +
					Math.round( 34 + 190 * ( 1 - reach ) ) + ',' +
					Math.round( 113 + 30 * reach ) + ',' +
					Math.round( 177 * ( 1 - reach ) + 30 * reach ) + ',' +
					alpha.toFixed( 3 ) + ')';
				ctx.fillRect( 0, top, W, Math.max( 2, bottom - top - 2 ) );
			}

			// 到達率の目盛り。
			ctx.save();
			ctx.font = '600 12px sans-serif';
			for ( i = 0; i < depths.length; i++ ) {
				var y = ( H * ( depths[ i ] - 5 ) ) / 100;
				ctx.fillStyle = 'rgba(0,0,0,0.55)';
				ctx.fillText( depths[ i ] + '%', 8, y );
			}
			ctx.restore();
		}

		function drawHighlight( ctx, key, color ) {
			var point = state.pointByKey[ key ];
			if ( ! point ) {
				return;
			}
			ctx.save();
			ctx.strokeStyle = color || '#2271b1';
			ctx.lineWidth = 3;
			ctx.setLineDash( [ 6, 4 ] );
			var radius = Math.max( 22, state.radius );
			ctx.beginPath();
			ctx.arc( point.x, point.y, radius, 0, Math.PI * 2 );
			ctx.stroke();
			ctx.restore();
		}

		/* ------------------------------ ホバー ------------------------------ */

		function onCanvasMove( event ) {
			if ( state.type === 'scroll' ) {
				return;
			}
			var canvas = $( 'kashiwazaki-seo-heatmap-canvas' );
			var rect = canvas.getBoundingClientRect();
			// 点の位置は文書の座標なので、プレビューのスクロール位置を足して比べる。
			var scroll = frameScroll();
			var x = event.clientX - rect.left + scroll.x;
			var y = event.clientY - rect.top + scroll.y;
			var nearest = null;
			var nearestDistance = Infinity;
			var limit = Math.max( 18, state.radius );
			var i;
			for ( i = 0; i < state.points.length; i++ ) {
				var p = state.points[ i ];
				var distance = Math.sqrt( ( p.x - x ) * ( p.x - x ) + ( p.y - y ) * ( p.y - y ) );
				if ( distance < limit && distance < nearestDistance ) {
					nearest = p;
					nearestDistance = distance;
				}
			}

			if ( ! nearest ) {
				tooltip.style.display = 'none';
				if ( state.hoverKey ) {
					state.hoverKey = '';
					paint();
				}
				return;
			}

			// ホバー中の要素に印を付ける（変わったときだけ描き直す）。
			if ( state.hoverKey !== nearest.key ) {
				state.hoverKey = nearest.key;
				paint();
			}

			var text = nearest.text ? nearest.text.slice( 0, 80 ) : nearest.tag;
			tooltip.innerHTML = '';
			var strong = document.createElement( 'strong' );
			strong.textContent = text || '（テキストなし）';
			tooltip.appendChild( strong );
			tooltip.appendChild( document.createElement( 'br' ) );
			tooltip.appendChild( document.createTextNode( fmt( nearest.count ) + ' ' + CFG.i18n.times ) );
			if ( nearest.selector ) {
				tooltip.appendChild( document.createElement( 'br' ) );
				var code = document.createElement( 'span' );
				code.style.opacity = '0.75';
				code.textContent = nearest.selector;
				tooltip.appendChild( code );
			}
			// 画面の外にはみ出さないように位置を調整する。
			tooltip.style.display = 'block';
			var tipW = tooltip.offsetWidth || 260;
			var tipH = tooltip.offsetHeight || 60;
			var left = event.clientX + 14;
			if ( left + tipW > window.innerWidth - 8 ) {
				left = Math.max( 8, event.clientX - tipW - 14 );
			}
			var top = event.clientY + 14;
			if ( top + tipH > window.innerHeight - 8 ) {
				top = Math.max( 8, event.clientY - tipH - 14 );
			}
			tooltip.style.left = left + 'px';
			tooltip.style.top = top + 'px';
		}

		function onCanvasLeave() {
			tooltip.style.display = 'none';
			if ( state.hoverKey ) {
				state.hoverKey = '';
				paint();
			}
		}

		$( 'kashiwazaki-seo-heatmap-canvas' ).addEventListener( 'mousemove', onCanvasMove );
		$( 'kashiwazaki-seo-heatmap-canvas' ).addEventListener( 'mouseleave', onCanvasLeave );

		// ヒートの上でホイールを回したら、下のプレビューをスクロールする
		// （canvas が上に重なっているので、そのままではプレビューに届かない）。
		$( 'kashiwazaki-seo-heatmap-canvas' ).addEventListener( 'wheel', function ( event ) {
			var win = null;
			try {
				win = frame.contentWindow;
			} catch ( e ) {
				win = null;
			}
			if ( ! win ) {
				return;
			}
			event.preventDefault();
			win.scrollBy( event.deltaX, event.deltaY );
		}, { passive: false } );

		/* ------------------------------ サイド ------------------------------ */

		function updateSummary( totals ) {
			if ( ! totals ) {
				return;
			}
			$( 'kashiwazaki-seo-heatmap-stat-pageviews' ).textContent = fmt( totals.pageviews );
			$( 'kashiwazaki-seo-heatmap-stat-visitors' ).textContent = fmt( totals.visitors );
			// 訪問者数は生データから数えるので、保持期間より前の分は入らない。そのときは見出しに日数を出す。
			var visitorsLabel = $( 'kashiwazaki-seo-heatmap-stat-visitors' ).nextElementSibling;
			if ( visitorsLabel ) {
				visitorsLabel.textContent = totals.visitorsLimitedDays ?
					CFG.i18n.visitorsLimited.replace( '%d', totals.visitorsLimitedDays ) :
					CFG.i18n.visitorsLabel;
			}
			$( 'kashiwazaki-seo-heatmap-stat-avgtime' ).textContent = totals.avgTime === undefined ? '—' : fmt( totals.avgTime );
			$( 'kashiwazaki-seo-heatmap-stat-avscroll' ).textContent = computeAverageScroll( state.data ? state.data.scroll : null, totals.pageviews );
			if ( state.data ) {
				var clicks = 0;
				var i;
				for ( i = 0; i < ( state.data.clicks || [] ).length; i++ ) {
					clicks += state.data.clicks[ i ].count;
				}
				$( 'kashiwazaki-seo-heatmap-stat-clicks' ).textContent = fmt( clicks );
			}
		}

		/**
		 * 平均スクロール（%）＝閲覧ごとの到達した深さの平均。
		 *
		 * 10% 刻みの各深さに到達した閲覧の割合（到達数 ÷ 閲覧数）を足して 10 倍する
		 * （各刻みが 10% 分の幅を表すため）。全員が最後まで読めば 100、全員が 50% で
		 * 止まれば 50 になる。
		 */
		function computeAverageScroll( scroll, views ) {
			if ( ! scroll || ! views ) {
				return '—';
			}
			var depths = [ 10, 20, 30, 40, 50, 60, 70, 80, 90, 100 ];
			var sum = 0;
			var i;
			for ( i = 0; i < depths.length; i++ ) {
				var count = scroll[ depths[ i ] ] || scroll[ String( depths[ i ] ) ] || 0;
				sum += reachRatio( count, views ) * 10;
			}
			return Math.round( sum );
		}

		function renderScrollChart( scroll ) {
			var box = $( 'kashiwazaki-seo-heatmap-scroll-chart' );
			if ( ! box ) {
				return;
			}
			box.innerHTML = '';
			var depths = [ 10, 20, 30, 40, 50, 60, 70, 80, 90, 100 ];
			// 分母は閲覧数（10% に届かなかった閲覧も含める）。
			var views = state.data && state.data.totals ? ( state.data.totals.pageviews || 0 ) : 0;
			var i;

			for ( i = 0; i < depths.length; i++ ) {
				var count = scroll ? ( scroll[ depths[ i ] ] || scroll[ String( depths[ i ] ) ] || 0 ) : 0;
				var reach = reachRatio( count, views );
				var row = document.createElement( 'div' );
				row.className = 'kashiwazaki-seo-heatmap-scroll-row';

				var depth = document.createElement( 'span' );
				depth.className = 'kashiwazaki-seo-heatmap-scroll-depth';
				depth.textContent = depths[ i ] + '%';

				var bar = document.createElement( 'span' );
				bar.className = 'kashiwazaki-seo-heatmap-scroll-bar';
				var fill = document.createElement( 'span' );
				fill.style.width = Math.round( reach * 100 ) + '%';
				bar.appendChild( fill );

				var label = document.createElement( 'span' );
				label.className = 'kashiwazaki-seo-heatmap-scroll-count';
				label.textContent = views > 0 ? Math.round( reach * 100 ) + '%' : '—';

				row.appendChild( depth );
				row.appendChild( bar );
				row.appendChild( label );
				box.appendChild( row );
			}
		}

		function renderElementTable( data ) {
			var tbody = $( 'kashiwazaki-seo-heatmap-element-tbody' );
			if ( ! tbody ) {
				return;
			}
			tbody.innerHTML = '';

			var rows = data.clicks || [];
			if ( state.type === 'rage' ) {
				rows = data.rage || [];
			} else if ( state.type === 'dead' ) {
				rows = data.dead || [];
			} else if ( state.type === 'outbound' ) {
				rows = data.outbound || [];
			} else if ( state.type === 'move' ) {
				rows = [];
			} else if ( state.type === 'scroll' ) {
				rows = [];
			}

			if ( ! rows.length ) {
				var empty = document.createElement( 'tr' );
				var cell = document.createElement( 'td' );
				cell.colSpan = 2;
				cell.textContent = elementEmptyMessage();
				empty.appendChild( cell );
				tbody.appendChild( empty );
				return;
			}

			rows.slice( 0, 200 ).forEach( function ( row ) {
				var tr = document.createElement( 'tr' );
				var tdText = document.createElement( 'td' );
				var title = document.createElement( 'div' );
				title.className = 'kashiwazaki-seo-heatmap-element-text';
				title.textContent = row.text ? row.text.slice( 0, 70 ) : ( row.tag || '(不明)' );
				var meta = document.createElement( 'div' );
				meta.className = 'kashiwazaki-seo-heatmap-element-meta';
				meta.textContent = row.tag + ( row.selector ? ' / ' + row.selector.slice( 0, 60 ) : '' );
				tdText.appendChild( title );
				tdText.appendChild( meta );

				var tdCount = document.createElement( 'td' );
				tdCount.textContent = fmt( row.count );

				tr.appendChild( tdText );
				tr.appendChild( tdCount );

				if ( row.key ) {
					tr.style.cursor = 'pointer';
					tr.addEventListener( 'click', function () {
						highlightElement( row.key, tr );
					} );
				}
				tbody.appendChild( tr );
			} );
		}

		/**
		 * 要素の一覧が無い種類のときに出す文言を返す。
		 *
		 * スクロール到達率とマウスムーブは要素単位では記録していない。
		 * そのときに「データはまだありません」と出すと、計測そのものが
		 * 止まっているように見えるため、種類に応じた文言にする。
		 */
		function elementEmptyMessage() {
			if ( state.type === 'scroll' || state.type === 'move' ) {
				var typeSelect = $( 'kashiwazaki-seo-heatmap-heat-type' );
				var label = '';
				if ( typeSelect && typeSelect.selectedIndex >= 0 ) {
					label = typeSelect.options[ typeSelect.selectedIndex ].textContent.trim();
				}
				return ( CFG.i18n.noElementForType || '「%s」は要素ごとの記録がないため、一覧はありません。' ).replace( '%s', label );
			}
			return CFG.i18n.noData;
		}

		function highlightElement( key, tr ) {
			state.highlightKey = state.highlightKey === key ? '' : key;
			Array.prototype.forEach.call(
				tbodyRows(),
				function ( row ) {
					row.classList.remove( 'kashiwazaki-seo-heatmap-row-active' );
				}
			);
			if ( state.highlightKey && tr ) {
				tr.classList.add( 'kashiwazaki-seo-heatmap-row-active' );
			}

			// ベース画像は変わらないので、印だけ描き直す。
			paint();

			// 選んだ要素がプレビューの真ん中に来るように、プレビューの中をスクロールする。
			var point = state.pointByKey[ state.highlightKey ];
			var win = null;
			try {
				win = frame.contentWindow;
			} catch ( e ) {
				win = null;
			}
			if ( point && win ) {
				win.scrollTo( {
					top: Math.max( 0, point.y - frameViewportHeight() / 2 ),
					behavior: 'smooth'
				} );
			}
		}

		function tbodyRows() {
			var tbody = $( 'kashiwazaki-seo-heatmap-element-tbody' );
			return tbody ? tbody.querySelectorAll( 'tr' ) : [];
		}

		/* ------------------------------ 起動 ------------------------------ */

		if ( state.pageKey || state.postId ) {
			reloadAll();
		} else {
			// 何も選ばれていない状態では、ヒートマップは表示しない。
			resetDisplay();
		}
	}

	function bind( id, eventName, handler ) {
		var el = document.getElementById( id );
		if ( el ) {
			el.addEventListener( eventName, handler );
		}
	}

	function showNotice( placeholder, message ) {
		if ( ! placeholder ) {
			return;
		}
		placeholder.innerHTML = '';
		var paragraph = document.createElement( 'p' );
		paragraph.textContent = message;
		placeholder.appendChild( paragraph );
	}

	function waitForImages( doc, timeout ) {
		return new Promise( function ( resolve ) {
			var done = false;
			function finish() {
				if ( ! done ) {
					done = true;
					resolve();
				}
			}

			var images = doc.images || [];
			var total = images.length;
			var settled = 0;
			var timer = 0;
			var i;

			if ( ! total ) {
				window.setTimeout( finish, 150 );
				return;
			}

			function settle() {
				settled++;
				if ( settled >= total ) {
					window.clearTimeout( timer );
					window.setTimeout( finish, 150 );
				}
			}

			for ( i = 0; i < total; i++ ) {
				( function ( image ) {
					if ( image.complete ) {
						settle();
						return;
					}
					var handled = false;
					function once() {
						if ( handled ) {
							return;
						}
						handled = true;
						settle();
					}
					image.addEventListener( 'load', once );
					image.addEventListener( 'error', once );
					// 完了の確認と登録のあいだに読み込みが終わった場合を取りこぼさない。
					if ( image.complete ) {
						once();
					}
				}( images[ i ] ) );
			}

			if ( settled >= total ) {
				return;
			}

			timer = window.setTimeout( finish, timeout || 4000 );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* ダッシュボード（日別の推移）                                        */
	/* ------------------------------------------------------------------ */

	function initDashboard() {
		var canvas = $( 'kashiwazaki-seo-heatmap-series-canvas' );
		if ( ! canvas ) {
			return;
		}
		var series = {};
		try {
			series = JSON.parse( canvas.getAttribute( 'data-series' ) || '{}' );
		} catch ( e ) {
			series = {};
		}
		drawSeries( canvas, series );
		window.addEventListener( 'resize', function () {
			drawSeries( canvas, series );
		} );
	}

	function drawSeries( canvas, series ) {
		var dates = Object.keys( series || {} );
		var box = canvas.parentNode;
		var cssHeight = 260;
		var ratio = window.devicePixelRatio || 1;

		// 内部の座標幅は、実際に表示される幅（親の内側の幅）に合わせる。
		// 親の clientWidth は padding を含むため、canvas 自身の表示幅を先に決めて測る。
		canvas.style.width = '100%';
		canvas.style.height = cssHeight + 'px';
		var cssWidth = canvas.clientWidth || ( box ? box.clientWidth - 24 : 0 ) || 800;

		canvas.width = cssWidth * ratio;
		canvas.height = cssHeight * ratio;

		var ctx = canvas.getContext( '2d' );
		ctx.setTransform( ratio, 0, 0, ratio, 0, 0 );
		ctx.clearRect( 0, 0, cssWidth, cssHeight );

		var padding = { left: 52, right: 16, top: 16, bottom: 28 };
		var plotW = cssWidth - padding.left - padding.right;
		var plotH = cssHeight - padding.top - padding.bottom;

		if ( ! dates.length ) {
			ctx.fillStyle = '#646970';
			ctx.font = '13px sans-serif';
			ctx.fillText( 'まだデータがありません。', padding.left, padding.top + 24 );
			return;
		}

		var maxValue = 1;
		var i;
		for ( i = 0; i < dates.length; i++ ) {
			maxValue = Math.max(
				maxValue,
				series[ dates[ i ] ].pageviews || 0,
				series[ dates[ i ] ].clicks || 0
			);
		}

		// 目盛り
		ctx.strokeStyle = '#dcdcde';
		ctx.fillStyle = '#646970';
		ctx.font = '11px sans-serif';
		ctx.lineWidth = 1;
		var steps = 4;
		for ( i = 0; i <= steps; i++ ) {
			var value = Math.round( ( maxValue / steps ) * i );
			var y = padding.top + plotH - ( plotH / steps ) * i;
			ctx.beginPath();
			ctx.moveTo( padding.left, y );
			ctx.lineTo( padding.left + plotW, y );
			ctx.stroke();
			ctx.fillText( String( value ), 6, y + 4 );
		}

		function xAt( index ) {
			if ( dates.length === 1 ) {
				return padding.left + plotW / 2;
			}
			return padding.left + ( plotW * index ) / ( dates.length - 1 );
		}
		function yAt( value ) {
			return padding.top + plotH - ( plotH * value ) / maxValue;
		}

		function line( key, color ) {
			ctx.strokeStyle = color;
			ctx.lineWidth = 2;
			ctx.beginPath();
			var started = false;
			for ( var j = 0; j < dates.length; j++ ) {
				var value2 = series[ dates[ j ] ][ key ] || 0;
				var px = xAt( j );
				var py = yAt( value2 );
				if ( ! started ) {
					ctx.moveTo( px, py );
					started = true;
				} else {
					ctx.lineTo( px, py );
				}
			}
			ctx.stroke();

			ctx.fillStyle = color;
			for ( var k = 0; k < dates.length; k++ ) {
				if ( dates.length > 60 && k % 2 === 1 ) {
					continue;
				}
				ctx.beginPath();
				ctx.arc( xAt( k ), yAt( series[ dates[ k ] ][ key ] || 0 ), 2.2, 0, Math.PI * 2 );
				ctx.fill();
			}
		}

		line( 'pageviews', '#2271b1' );
		line( 'clicks', '#d63638' );

		// X 軸の日付（間引いて表示）
		ctx.fillStyle = '#646970';
		ctx.font = '11px sans-serif';
		var step = Math.max( 1, Math.ceil( dates.length / 8 ) );
		for ( i = 0; i < dates.length; i += step ) {
			var label = dates[ i ].slice( 5 );
			ctx.fillText( label, xAt( i ) - 14, cssHeight - 8 );
		}
	}

	/* ------------------------------------------------------------------ */
	/* 設定画面                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * 「計測する投稿タイプ」の一括チェック / 一括解除。
	 * 対象の fieldset が無い画面では何もしない。
	 */
	function initSettingsPage() {
		var buttons = document.querySelectorAll( '.kashiwazaki-seo-heatmap-post-type-all, .kashiwazaki-seo-heatmap-post-type-none' );
		if ( ! buttons.length ) {
			return;
		}

		Array.prototype.forEach.call( buttons, function ( button ) {
			button.addEventListener( 'click', function () {
				var fieldset = document.getElementById( button.getAttribute( 'data-target' ) );
				if ( ! fieldset ) {
					return;
				}

				var checkAll = button.classList.contains( 'kashiwazaki-seo-heatmap-post-type-all' );
				Array.prototype.forEach.call( fieldset.querySelectorAll( 'input[type="checkbox"]' ), function ( box ) {
					if ( box.checked === checkAll ) {
						return;
					}
					box.checked = checkAll;
					box.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				} );
			} );
		} );
	}

	/**
	 * 設定画面で、入力を変えて保存していないままタブを押したら、移動してよいかを確かめる。
	 * キャンセルなら移動しない。保存（送信）や、左メニュー・ブラウザの戻るでは確かめない（今までと同じ）。
	 * 設定のフォームとタブが無い画面では何もしない。
	 * 公式: MDN Window.confirm「A boolean indicating whether OK (true) or Cancel (false) was selected」。
	 */
	function initSettingsTabsGuard() {
		var form = document.querySelector( '.kashiwazaki-seo-heatmap-wrap form[action="options.php"]' );
		var tabs = document.querySelectorAll( '.kashiwazaki-seo-heatmap-tabs a.nav-tab' );
		if ( ! form || ! tabs.length ) {
			return;
		}

		var dirty = false;
		var markDirty = function () {
			dirty = true;
		};
		form.addEventListener( 'input', markDirty );
		form.addEventListener( 'change', markDirty );
		form.addEventListener( 'submit', function () {
			dirty = false;
		} );

		Array.prototype.forEach.call( tabs, function ( tab ) {
			tab.addEventListener( 'click', function ( event ) {
				if ( dirty && ! window.confirm( CFG.i18n.unsavedLeave || '保存していない変更があります。このまま移動しますか？' ) ) {
					event.preventDefault();
				}
			} );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* 起動                                                                */
	/* ------------------------------------------------------------------ */

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			initHeatmapPage();
			initDashboard();
			initSettingsPage();
			initSettingsTabsGuard();
		} );
	} else {
		initHeatmapPage();
		initDashboard();
		initSettingsPage();
		initSettingsTabsGuard();
	}
} )();
