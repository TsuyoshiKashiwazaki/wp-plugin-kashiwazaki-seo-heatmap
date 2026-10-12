/**
 * Kashiwazaki SEO Heatmap - フロント側の計測スクリプト。
 *
 * クリック・スクロール・マウスムーブを計測し、まとめて REST API へ送る。
 * jQuery などには依存しない（テーマとの競合を避けるため）。
 *
 * @package Kashiwazaki_SEO_Heatmap
 */
( function () {
	'use strict';

	var CONFIG = window.KashiwazakiSEOHeatmapConfig;
	if ( ! CONFIG || ! CONFIG.restUrl ) {
		return;
	}

	var TRACK = CONFIG.track || {};
	var RAGE = CONFIG.rage || { count: 3, window: 1000, radius: 30 };

	// Do Not Track を尊重する。
	if ( CONFIG.honorDnt && doNotTrackEnabled() ) {
		return;
	}

	// サンプリング（100 なら全件）。
	if ( CONFIG.sampleRate < 100 && Math.random() * 100 >= CONFIG.sampleRate ) {
		return;
	}

	var events = [];
	var flushing = false;
	var secondsSinceFlush = 0;
	var lastActiveAt = Date.now();
	var pageUnloading = false;
	var flushedOnce = false;

	var maxScrollDepth = 0;
	var sentScrollDepth = 0;

	var moveCount = 0;
	var lastMoveAt = 0;

	var recentClicks = [];
	var lastRageAt = 0;
	var pendingDeadClicks = [];

	var visitorId = getVisitorId();
	var sessionId = getSessionId();
	var isEntrance = ! sessionStorageGet( 'kashiwazaki_seo_heatmap_seen' );
	sessionStorageSet( 'kashiwazaki_seo_heatmap_seen', '1' );

	// 送信間隔（ミリ秒）。
	var FLUSH_MS = Math.max( 1000, CONFIG.flushInterval || 5000 );
	// 操作が無くても滞在時間だけを送る間隔（ミリ秒）。
	var HEARTBEAT_MS = 30000;
	// 1 回に送るイベントの上限（サーバ側の上限と合わせる）。
	var MAX_EVENTS = 200;

	/* ------------------------------------------------------------------ */
	/* 判定                                                                */
	/* ------------------------------------------------------------------ */

	function doNotTrackEnabled() {
		var dnt = navigator.doNotTrack || window.doNotTrack || navigator.msDoNotTrack;
		return '1' === dnt || 'yes' === dnt;
	}

	/* ------------------------------------------------------------------ */
	/* ID の管理                                                           */
	/* ------------------------------------------------------------------ */

	function randomId() {
		var bytes = new Uint8Array( 16 );
		if ( window.crypto && window.crypto.getRandomValues ) {
			window.crypto.getRandomValues( bytes );
		} else {
			for ( var i = 0; i < bytes.length; i++ ) {
				bytes[ i ] = Math.floor( Math.random() * 256 );
			}
		}
		var out = '';
		for ( var j = 0; j < bytes.length; j++ ) {
			out += ( '0' + bytes[ j ].toString( 16 ) ).slice( -2 );
		}
		return out;
	}

	function getCookie( name ) {
		var parts = document.cookie ? document.cookie.split( '; ' ) : [];
		for ( var i = 0; i < parts.length; i++ ) {
			var eq = parts[ i ].indexOf( '=' );
			var key = eq >= 0 ? parts[ i ].slice( 0, eq ) : parts[ i ];
			if ( key !== name ) {
				continue;
			}
			var value = eq >= 0 ? parts[ i ].slice( eq + 1 ) : '';
			try {
				return decodeURIComponent( value );
			} catch ( e ) {
				// 壊れた %エスケープでも例外で止めない。
				return value;
			}
		}
		return '';
	}

	function setCookie( name, value, days ) {
		var expires = '';
		if ( days ) {
			var d = new Date();
			d.setTime( d.getTime() + days * 24 * 60 * 60 * 1000 );
			expires = '; expires=' + d.toUTCString();
		}
		var secure = ( 'https:' === location.protocol ) ? '; Secure' : '';
		document.cookie = name + '=' + encodeURIComponent( value ) + expires + '; path=/; SameSite=Lax' + secure;
	}

	function getVisitorId() {
		var existing = getCookie( CONFIG.cookieName );
		if ( existing && /^[a-f0-9]{32}$/i.test( existing ) ) {
			return existing.toLowerCase();
		}
		var id = randomId();
		setCookie( CONFIG.cookieName, id, CONFIG.cookieDays || 365 );
		return id;
	}

	function sessionStorageGet( key ) {
		try {
			return window.sessionStorage.getItem( key );
		} catch ( e ) {
			return null;
		}
	}

	function sessionStorageSet( key, value ) {
		try {
			window.sessionStorage.setItem( key, value );
		} catch ( e ) {
			// ストレージが使えない環境では何もしない。
		}
	}

	function getSessionId() {
		var existing = sessionStorageGet( 'kashiwazaki_seo_heatmap_session' );
		if ( existing && /^[a-f0-9]{32}$/i.test( existing ) ) {
			return existing.toLowerCase();
		}
		var id = randomId();
		sessionStorageSet( 'kashiwazaki_seo_heatmap_session', id );
		return id;
	}

	/* ------------------------------------------------------------------ */
	/* 要素の特定                                                          */
	/* ------------------------------------------------------------------ */

	function cssEscape( value ) {
		if ( window.CSS && window.CSS.escape ) {
			return window.CSS.escape( value );
		}
		return String( value ).replace( /[^a-zA-Z0-9_-]/g, function ( ch ) {
			return '\\' + ch;
		} );
	}

	function isSimpleIdent( value ) {
		return /^[A-Za-z][\w-]*$/.test( value );
	}

	/**
	 * 要素を表す CSS セレクタを作る。id があればそれを使い、無ければ
	 * tag + class + nth-of-type のパスを最大 4 階層まで組み立てる。
	 */
	function buildSelector( el ) {
		if ( ! el || el.nodeType !== 1 ) {
			return '';
		}
		if ( el.id && isSimpleIdent( el.id ) ) {
			return '#' + cssEscape( el.id );
		}

		var parts = [];
		var node = el;
		var depth = 0;

		while ( node && node.nodeType === 1 && node !== document.body && node !== document.documentElement && depth < 4 ) {
			if ( node.id && isSimpleIdent( node.id ) ) {
				parts.unshift( '#' + cssEscape( node.id ) );
				break;
			}

			var part = node.tagName.toLowerCase();
			var classes = [];
			if ( node.className && typeof node.className === 'string' ) {
				classes = node.className.trim().split( /\s+/ ).filter( isSimpleIdent ).slice( 0, 2 );
			}
			if ( classes.length ) {
				part += '.' + classes.map( cssEscape ).join( '.' );
			}

			var parent = node.parentElement;
			if ( parent ) {
				var sameTag = [];
				for ( var i = 0; i < parent.children.length; i++ ) {
					if ( parent.children[ i ].tagName === node.tagName ) {
						sameTag.push( parent.children[ i ] );
					}
				}
				if ( sameTag.length > 1 ) {
					part += ':nth-of-type(' + ( sameTag.indexOf( node ) + 1 ) + ')';
				}
			}

			parts.unshift( part );
			node = node.parentElement;
			depth++;
		}

		return parts.join( ' > ' );
	}

	function elementIndex( el, selector ) {
		if ( ! selector ) {
			return 0;
		}
		try {
			var all = document.querySelectorAll( selector );
			for ( var i = 0; i < all.length; i++ ) {
				if ( all[ i ] === el ) {
					return i;
				}
			}
		} catch ( e ) {
			return 0;
		}
		return 0;
	}

	/**
	 * 文字数で切り詰める（サロゲートペアの途中では切らない）。
	 *
	 * String.prototype.slice() は UTF-16 のコード単位で切るため、絵文字のような
	 * サロゲートペアの途中で切れると「対になっていないサロゲート」ができる。
	 * 公式: charCodeAt() —「charCodeAt() may return lone surrogates, which are
	 * not valid Unicode characters.」
	 * https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/String/charCodeAt
	 *
	 * それを JSON にして送ると、PHP 側の json_decode() が
	 * 「Single unpaired UTF-16 surrogate in unicode escape」で失敗して
	 * リクエスト全体が不正扱いになり、同じ送信に含まれていた計測データまで
	 * まとめて捨てられてしまう。
	 *
	 * @param {string} str 元の文字列。
	 * @param {number} max 残す長さ（UTF-16 コード単位）。
	 * @return {string} 切り詰めた文字列。
	 */
	function cutText( str, max ) {
		str = str || '';
		if ( str.length <= max ) {
			return str;
		}
		var cut = str.slice( 0, max );
		var last = cut.charCodeAt( cut.length - 1 );
		// 末尾が上位サロゲート（0xD800〜0xDBFF）なら、対になる下位サロゲートが
		// 切れている。末尾に下位サロゲート（0xDC00〜0xDFFF）は来ないので、
		// ここだけ見れば足りる。
		if ( last >= 0xd800 && last <= 0xdbff ) {
			cut = cut.slice( 0, -1 );
		}
		return cut;
	}

	/**
	 * 同じセレクタでも場所を区別できるように、祖先の見出しを手がかりにする。
	 */
	function branchKey( el ) {
		var scope = el;
		for ( var up = 0; up < 4 && scope; up++ ) {
			scope = scope.parentElement;
			if ( ! scope ) {
				break;
			}
			if ( scope.id ) {
				return scope.id;
			}
			var heading = scope.querySelector ? scope.querySelector( 'h1, h2, h3, h4' ) : null;
			if ( heading && heading.textContent ) {
				return cutText( heading.textContent.trim(), 60 );
			}
		}
		return '';
	}

	function elementText( el ) {
		// 中身がコードの要素は、文字を拾うと意味のない長文になるため拾わない。
		// html / body は「何も無いところを押した」ときの宛先で、ページ全体の文字を
		// 持ってしまうため、やはり拾わない。
		var tag = el.tagName ? el.tagName.toLowerCase() : '';
		if ( 'script' === tag || 'style' === tag || 'noscript' === tag || 'template' === tag || 'html' === tag || 'body' === tag ) {
			return '';
		}

		var text = '';
		if ( el.getAttribute ) {
			text = el.getAttribute( 'aria-label' ) || el.getAttribute( 'title' ) || '';
		}
		if ( ! text ) {
			// innerText は画面に実際に出ている文字だけを返すため、
			// script や style の中身が混ざらない（html を押した場合の対策）。
			try {
				text = el.innerText || '';
			} catch ( e ) {
				text = '';
			}
			if ( ! text && el.textContent ) {
				text = el.textContent;
			}
		}
		return cutText( ( text || '' ).replace( /\s+/g, ' ' ).trim(), 120 );
	}

	/**
	 * クリックされた位置から「実際に押された要素」を決める。
	 */
	function resolveClickTarget( target ) {
		if ( ! target || target.nodeType !== 1 ) {
			return null;
		}
		var interactive = 'a, button, [role="button"], input, select, textarea, label, summary';
		var node = target;
		for ( var i = 0; i < 3 && node && node.nodeType === 1; i++ ) {
			if ( node.matches && node.matches( interactive ) ) {
				return node;
			}
			node = node.parentElement;
		}
		return target;
	}

	/**
	 * 「押せそうに見える要素」かどうか。デッドクリックの対象を絞るために使う。
	 */
	function looksClickable( el ) {
		if ( ! el || ! el.matches ) {
			return false;
		}
		if ( el.matches( 'a, button, [role="button"], input, select, textarea, label, summary, details' ) ) {
			return true;
		}
		try {
			return 'pointer' === window.getComputedStyle( el ).cursor;
		} catch ( e ) {
			return false;
		}
	}

	/**
	 * 要素の中身の変化を比べるための手がかり（デッドクリック判定用）。
	 */
	function domSignature( el ) {
		if ( ! el ) {
			return '';
		}
		try {
			var html = el.innerHTML || '';
			var text = el.textContent || '';
			return html.length + ':' + text.length + ':' + text.slice( 0, 80 );
		} catch ( e ) {
			return '';
		}
	}

	/* ------------------------------------------------------------------ */
	/* 位置                                                                */
	/* ------------------------------------------------------------------ */

	function docSize() {
		var doc = document.documentElement;
		var body = document.body;
		return {
			w: Math.max( doc.scrollWidth, body ? body.scrollWidth : 0, doc.clientWidth ),
			h: Math.max( doc.scrollHeight, body ? body.scrollHeight : 0, doc.clientHeight )
		};
	}

	function positionOf( clientX, clientY ) {
		var size = docSize();
		var x = clientX + window.pageXOffset;
		var y = clientY + window.pageYOffset;
		return {
			x: Math.round( x ),
			y: Math.round( y ),
			xp: size.w > 0 ? + ( ( x / size.w ) * 100 ).toFixed( 3 ) : 0,
			yp: size.h > 0 ? + ( ( y / size.h ) * 100 ).toFixed( 3 ) : 0
		};
	}

	function viewport() {
		return {
			vw: window.innerWidth,
			vh: window.innerHeight
		};
	}

	/* ------------------------------------------------------------------ */
	/* イベントの蓄積                                                      */
	/* ------------------------------------------------------------------ */

	function pushEvent( event ) {
		events.push( event );
		if ( events.length >= 150 ) {
			flush( false );
		}
	}

	function baseEvent( el, selector, index, pos ) {
		return {
			t: 'click',
			sel: selector,
			i: index,
			tag: el && el.tagName ? el.tagName.toLowerCase() : '',
			txt: elementText( el ),
			br: branchKey( el ),
			x: pos.x,
			y: pos.y,
			xp: pos.xp,
			yp: pos.yp
		};
	}

	/* ------------------------------------------------------------------ */
	/* クリック                                                            */
	/* ------------------------------------------------------------------ */

	function handleClick( event ) {
		if ( event.button && event.button !== 0 ) {
			return;
		}

		var rawTarget = event.target;
		if ( ! rawTarget || rawTarget.nodeType !== 1 ) {
			return;
		}
		// 管理バー上のクリックは計測しない。
		if ( rawTarget.closest && rawTarget.closest( '#wpadminbar, .wp-admin-bar-root-default' ) ) {
			return;
		}

		var el = resolveClickTarget( rawTarget );
		if ( ! el ) {
			return;
		}

		var selector = buildSelector( el );
		var index = elementIndex( el, selector );

		var clientX = event.clientX;
		var clientY = event.clientY;
		// キーボード操作（Enter / Space）で起きた click は座標が 0 になる。
		if ( 0 === clientX && 0 === clientY && el.getBoundingClientRect ) {
			var rect = el.getBoundingClientRect();
			clientX = rect.left + rect.width / 2;
			clientY = rect.top + rect.height / 2;
		}
		var pos = positionOf( clientX, clientY );

		if ( TRACK.clicks ) {
			pushEvent( baseEvent( el, selector, index, pos ) );
		}

		// 外部リンクへのクリック。
		if ( TRACK.outbound && el.tagName === 'A' && el.href ) {
			var hrefHost = '';
			try {
				hrefHost = new URL( el.href, location.href ).hostname;
			} catch ( e ) {
				hrefHost = '';
			}
			if ( hrefHost && hrefHost !== location.hostname ) {
				var outEvent = baseEvent( el, selector, index, pos );
				outEvent.t = 'outbound';
				pushEvent( outEvent );
			}
		}

		var now = Date.now();
		checkRage( now, pos, el, selector, index );

		// 押せそうに見える要素だけをデッドクリックの候補にする。
		if ( TRACK.dead && looksClickable( el ) ) {
			pendingDeadClicks.push( {
				at: now,
				sig: domSignature( el ),
				url: location.href,
				el: el,
				selector: selector,
				index: index,
				pos: pos
			} );
			if ( pendingDeadClicks.length > 50 ) {
				pendingDeadClicks.shift();
			}
		}
	}

	function checkRage( now, pos, el, selector, index ) {
		if ( ! TRACK.rage ) {
			return;
		}
		recentClicks.push( { at: now, x: pos.x, y: pos.y } );
		recentClicks = recentClicks.filter( function ( c ) {
			return now - c.at <= RAGE.window;
		} );

		if ( recentClicks.length < RAGE.count ) {
			return;
		}

		// 直近のクリックがすべて同じ半径内にあるか確認する。
		var base = recentClicks[ recentClicks.length - 1 ];
		var clustered = recentClicks.filter( function ( c ) {
			return Math.abs( c.x - base.x ) <= RAGE.radius && Math.abs( c.y - base.y ) <= RAGE.radius;
		} );

		if ( clustered.length >= RAGE.count && now - lastRageAt > RAGE.window ) {
			lastRageAt = now;
			var rageEvent = baseEvent( el, selector, index, pos );
			rageEvent.t = 'rage';
			pushEvent( rageEvent );
		}
	}

	function evaluateDeadClicks() {
		if ( ! TRACK.dead || ! pendingDeadClicks.length ) {
			return;
		}
		var now = Date.now();
		var remaining = [];

		for ( var i = 0; i < pendingDeadClicks.length; i++ ) {
			var item = pendingDeadClicks[ i ];
			if ( now - item.at < CONFIG.deadWindow ) {
				remaining.push( item );
				continue;
			}
			// 画面遷移が無く、クリックした要素の中身も変化していなければ
			// 「押しても何も起きなかったクリック」とみなす。
			if ( item.url === location.href && item.sig === domSignature( item.el ) && ! pageUnloading ) {
				var deadEvent = baseEvent( item.el, item.selector, item.index, item.pos );
				deadEvent.t = 'dead';
				pushEvent( deadEvent );
			}
		}

		pendingDeadClicks = remaining;
	}

	/* ------------------------------------------------------------------ */
	/* スクロール                                                          */
	/* ------------------------------------------------------------------ */

	function computeScrollDepth() {
		var size = docSize();
		if ( size.h <= 0 ) {
			return 0;
		}
		var bottom = window.pageYOffset + window.innerHeight;
		var pct = ( bottom / size.h ) * 100;
		return Math.max( 0, Math.min( 100, Math.round( pct ) ) );
	}

	function handleScroll() {
		if ( ! TRACK.scroll ) {
			return;
		}
		var depth = computeScrollDepth();
		if ( depth <= maxScrollDepth ) {
			return;
		}
		maxScrollDepth = depth;
	}

	/**
	 * スクロールの到達率を送る。前回送った深さ（df）より深い分だけを
	 * サーバ側で到達率として数えてもらう（同じ深さを何度も数えない）。
	 */
	function pushScrollEvent() {
		if ( ! TRACK.scroll || maxScrollDepth <= sentScrollDepth ) {
			return;
		}
		pushEvent( {
			t: 'scroll',
			sel: '',
			i: 0,
			tag: '',
			txt: '',
			br: '',
			x: 0,
			y: 0,
			xp: 0,
			yp: 0,
			d: maxScrollDepth,
			df: sentScrollDepth
		} );
		sentScrollDepth = maxScrollDepth;
	}

	/* ------------------------------------------------------------------ */
	/* マウスムーブ                                                        */
	/* ------------------------------------------------------------------ */

	function handleMouseMove( event ) {
		if ( ! TRACK.move || moveCount >= CONFIG.moveMax ) {
			return;
		}
		var now = Date.now();
		if ( now - lastMoveAt < CONFIG.moveInterval ) {
			return;
		}
		lastMoveAt = now;
		moveCount++;

		var pos = positionOf( event.clientX, event.clientY );
		pushEvent( {
			t: 'move',
			sel: '',
			i: 0,
			tag: '',
			txt: '',
			br: '',
			x: pos.x,
			y: pos.y,
			xp: pos.xp,
			yp: pos.yp
		} );
	}

	/* ------------------------------------------------------------------ */
	/* 送信                                                                */
	/* ------------------------------------------------------------------ */

	function buildPayload() {
		var size = docSize();
		var vp = viewport();
		return {
			p: {
				id: CONFIG.postId,
				u: location.href,
				w: size.w,
				h: size.h,
				vw: vp.vw,
				vh: vp.vh,
				d: detectDevice(),
				// 前回の送信からの滞在秒数。サーバ側で合計して平均滞在時間にする。
				t: Math.round( secondsSinceFlush * 10 ) / 10,
				e: isEntrance ? 1 : 0,
				pv: isEntrance || ! flushedOnce ? 1 : 0
			},
			v: visitorId,
			s: sessionId,
			ev: events.slice( 0, MAX_EVENTS )
		};
	}

	function detectDevice() {
		var ua = ( navigator.userAgent || '' ).toLowerCase();
		if ( /ipad|tablet|playbook|silk|android(?!.*mobile)/.test( ua ) ) {
			return 'tablet';
		}
		if ( /mobile|iphone|ipod|android|blackberry|iemobile|opera mini|windows phone/.test( ua ) ) {
			return 'mobile';
		}
		return 'desktop';
	}

	function flush( useBeacon ) {
		if ( flushing ) {
			return;
		}
		// 再入を防ぐため、イベントを積む処理より先にフラグを立てる。
		flushing = true;

		pushScrollEvent();
		evaluateDeadClicks();

		var hasNews = events.length > 0 || isEntrance || ! flushedOnce;
		// 操作が無くても、一定時間ごとに滞在時間だけを送る。
		if ( ! hasNews && secondsSinceFlush * 1000 < HEARTBEAT_MS ) {
			flushing = false;
			return;
		}

		var body = JSON.stringify( buildPayload() );
		var sent = events.slice( 0, MAX_EVENTS );

		var done = false;
		function finish( ok ) {
			if ( done ) {
				return;
			}
			done = true;
			if ( ok ) {
				// 送れた分だけを取り除く（送信中に増えた分は残す）。
				events = events.slice( sent.length );
				flushedOnce = true;
				isEntrance = false;
				secondsSinceFlush = 0;
				moveCount = 0;
			}
			flushing = false;
		}

		if ( useBeacon && navigator.sendBeacon ) {
			var beaconOk = false;
			try {
				beaconOk = navigator.sendBeacon( CONFIG.restUrl, new Blob( [ body ], { type: 'application/json' } ) );
			} catch ( e ) {
				beaconOk = false;
			}
			if ( beaconOk ) {
				finish( true );
				return;
			}
		}

		try {
			var xhr = new XMLHttpRequest();
			xhr.open( 'POST', CONFIG.restUrl, true );
			xhr.setRequestHeader( 'Content-Type', 'application/json' );
			xhr.onreadystatechange = function () {
				if ( 4 !== xhr.readyState ) {
					return;
				}
				finish( xhr.status >= 200 && xhr.status < 300 );
			};
			xhr.onerror = function () {
				finish( false );
			};
			xhr.ontimeout = function () {
				finish( false );
			};
			xhr.send( body );
		} catch ( e2 ) {
			// 送信に失敗した分は次回に回す。
			finish( false );
			return;
		}

		// 応答が返らない場合に備えた保険（次の送信を止め続けないようにする）。
		window.setTimeout( function () {
			finish( true );
		}, 15000 );
	}

	function elapsedSeconds() {
		var now = Date.now();
		secondsSinceFlush += ( now - lastActiveAt ) / 1000;
		lastActiveAt = now;
	}

	/* ------------------------------------------------------------------ */
	/* 初期化                                                              */
	/* ------------------------------------------------------------------ */

	function init() {
		document.addEventListener( 'click', handleClick, true );
		window.addEventListener( 'scroll', handleScroll, { passive: true } );

		if ( TRACK.move ) {
			window.addEventListener( 'mousemove', handleMouseMove, { passive: true } );
		}

		// 定期的にまとめて送る。
		setInterval( function () {
			elapsedSeconds();
			flush( false );
		}, FLUSH_MS );

		// 画面を離れるときは残りを確実に送る。
		window.addEventListener( 'pagehide', function () {
			pageUnloading = true;
			elapsedSeconds();
			flush( true );
		} );

		// 「戻る」で復元されたときは計測を続ける。
		window.addEventListener( 'pageshow', function () {
			pageUnloading = false;
			lastActiveAt = Date.now();
		} );

		document.addEventListener( 'visibilitychange', function () {
			if ( 'hidden' === document.visibilityState ) {
				elapsedSeconds();
				flush( true );
			} else {
				lastActiveAt = Date.now();
			}
		} );

		// 最初の 1 回（ページビュー）を早めに送る。
		window.setTimeout( function () {
			elapsedSeconds();
			flush( false );
		}, 1200 );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
