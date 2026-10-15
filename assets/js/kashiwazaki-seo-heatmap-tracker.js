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
	// 送り箱: 番号を付けたまとまり [{ id, body }]。受け取られたと確かめるまで残し、
	// ブラウザの保存領域にも写す（ページを離れても、次のページが引き継いで送る）。
	var outbox = [];
	// 送信中のまとまりの番号（空なら送信していない）。
	var sendingId = '';
	// 送信の途中か（送信の途中から送信に入り直さないため）。
	var inFlush = false;
	// まだまとまりにしていない、表示中の滞在秒数。
	var secondsSinceFlush = 0;
	var lastActiveAt = Date.now();
	// タブが表示されているか。裏に回っている間の時間は滞在時間に入れない。
	var pageVisible = 'hidden' !== document.visibilityState;
	var pageUnloading = false;
	// ページビュー（pv）を送り終えたか。
	var flushedOnce = false;
	// 送信に失敗したら、次の定期送信まで間を空ける（429 などで叩き続けない）。
	var retryAfter = 0;
	var failures = 0;

	// 見えた一番下の位置（文書の上端からの px）。到達率（%）は送るときの文書の高さで計算する
	// （読み込みの途中の高さで % にすると、後から文書が伸びたときに大きく残るため）。
	var maxBottomPx = 0;
	var sentScrollDepth = 0;

	var moveCount = 0;
	var lastMoveAt = 0;

	var recentClicks = [];
	var lastRageAt = 0;

	// デッドクリックの判定に使う「最後に画面が反応した時刻」。
	var lastMutationAt = 0;
	var lastScrollAt = 0;
	var lastNavAt = 0;
	var deadObserver = null;
	var pendingDead = 0;
	// 判定の間に起きた属性・文字の変化（変化の前の値つき）。
	var mutationLog = [];

	var visitorId = getVisitorId();
	var sessionId = getSessionId();
	var isEntrance = ! sessionStorageGet( 'kashiwazaki_seo_heatmap_seen' );
	sessionStorageSet( 'kashiwazaki_seo_heatmap_seen', '1' );

	// 送信間隔（ミリ秒）。
	var FLUSH_MS = Math.max( 1000, CONFIG.flushInterval || 5000 );
	// 操作が無くても滞在時間だけを送る間隔（ミリ秒）。
	var HEARTBEAT_MS = 30000;
	// 到達率を送る前に、読み込みの完了を待つ上限（ミリ秒）。読み込みが終わらないページ
	// （応答の返らない画像など）でも、これを過ぎたらその時点の高さで送る。
	var LOAD_WAIT_MS = 10000;
	var startedAt = Date.now();
	// 1 回に送るイベントの上限（サーバ側の上限と合わせる）。
	var MAX_EVENTS = 200;
	// 1 回に送る本文の上限（バイト）。sendBeacon と keepalive の fetch は 64KiB までなので余裕をみる。
	// 公式: MDN sendBeacon「The total size of queued data is limited to 64 KiB」、
	// MDN RequestInit「The body size for keepalive requests is limited to 64 kibibytes」。
	// この 64KiB は、送信待ちの sendBeacon と keepalive の fetch の合計で使う枠
	// （Chromium で確かめた: 60000 バイトを送信待ちにすると、次の sendBeacon は false、
	// keepalive の fetch は拒否された）。定期の送信中にページを離れても、離脱時の
	// まとまりが 1 つは入るように、半分より小さくする。
	var MAX_BODY = 30000;
	// 送り直しの間に、満杯のまとまりを作って送り箱に足す目安（ほかのタブの送り箱を引き継ぐ上限にも使う）。
	// 目安なので、これを超えても手元のイベントやまとまりは捨てない。
	var OUTBOX_MAX = 50;
	// まとまりを残す期間（作った時刻から 7 日）。サーバーは番号を 8 日残し、7 日を過ぎたものは
	// 受け取らないので、期限を過ぎた送り直しが二重に数えられることはない。
	var OUTBOX_TTL = 7 * 24 * 60 * 60 * 1000;
	var OUTBOX_IDLE = 10000;
	// 送り箱の名前の接頭辞。送り先（このサイトの REST の URL）ごとに分ける。同じホストに
	// 別の WordPress（サブディレクトリのマルチサイト・別のインストール）があっても、
	// ほかのサイトの送り箱を引き継いで、こちらのサイトに送らないため。
	var OUTBOX_BASE = 'kashiwazaki_seo_heatmap_outbox_';
	var OUTBOX_PREFIX = OUTBOX_BASE + hashText( CONFIG.restUrl ) + '_';
	// この閲覧の番号と、送り箱の名前（閲覧ごとに別の名前。タブ同士で書き換え合わない）。
	var viewId = randomId();
	var outboxKey = OUTBOX_PREFIX + viewId;
	// 外す人の印（x=1）を付けたまとまりの番号ごとのキー（保存領域。同じオリジンのタブで読み合う。値は作った時刻）。
	// 「番号 X は外す人のまとまり」という決定を、番号ごとに 1 つのキーで持つ。ある番号が印付きかは、そのキーの
	// getItem 1 回で決まる（キーの列挙に頼らない）。書く値は誰が書いても同じなので、読んでから全体を書き直す競合も無い。
	// 同じまとまりのほかの写し（戻るで復元したページなど）も、送る前にこのキーを見て印を付ける。
	// 送り箱の接頭辞（OUTBOX_BASE）とは別の名前にする（送り箱として引き継がないため）。
	var EXCLUDED_PREFIX = 'kashiwazaki_seo_heatmap_excluded_' + hashText( CONFIG.restUrl ) + '_';
	// この閲覧が印を付けた番号（メモリの控え）と、番号ごとのキーをまだ書けていない番号。
	var ownExcluded = {};
	var pendingExcluded = {};
	// 本文を x=1 にしたが、送り箱の保存に失敗している。
	var excludedBodyUnsaved = false;
	// まとまりの番号は「閲覧の番号の先頭 24 桁 ＋ 連番 8 桁」（32 桁の 16 進）。届いていない
	// まとまりの記録（Cookie）に、連番だけを短く書けるようにする。
	var viewPrefix = viewId.slice( 0, 24 );
	var batchSeq = 0;
	// 読み込みの途中に送った「仮の到達率」のまとまりの番号（作った順。読み込みの後にまとめて置き換える）。
	// provFrom は仮の値の数え始めの深さ、provDepth は最後の仮の深さ。置き換えは鎖の全部を rp に入れる
	// （途中のまとまりが遅れて届いても、届かなくても、前の仮の値をサーバーが引けるように）。
	// サーバーが数えるのはマイルストーン（MILESTONES）を越えた数だけなので、2 つ目からの仮のまとまりは
	// 新しいマイルストーンを越えたときだけ作る（鎖はマイルストーンの数＋1 まで）。読み込みが終わらない
	// ページでも、深い到達を最後まで仮の値で送れる。PROV_MAX（サーバーが rp で見る数と同じ）は安全のための上限。
	var MILESTONES = scrollMilestones();
	var PROV_MAX = 20;
	var provChain = [];
	var provFrom = 0;
	var provDepth = 0;
	// 保存領域に保存できず、受け取りの応答もまだ無いまとまりの番号と件数を残す Cookie
	// （ほかのページが次のまとまりに添えてサーバーに知らせ、届いたかをサーバーが照らす）。
	// 名前は閲覧ごと（LOST_PREFIX ＋閲覧の番号の先頭 24 桁）で、書くのはその閲覧だけ（タブ同士で
	// 同じ Cookie を書き換えない）。値は「連番-件数」を「.」でつないだもの（encodeURIComponent で
	// そのまま残る文字だけ）。リクエストごとに送られるので、記録の Cookie の合計（名前と値）を
	// LOST_MAX_CHARS まで、1 つの閲覧の値を LOST_OWN_CHARS までにする。2 つのタブが同時に書くと、
	// 合計は 1 つの閲覧の分だけ超えうるので、その幅を見込んで小さめにしている。
	var LOST_PREFIX = 'kashiwazaki_seo_heatmap_lost_' + hashText( CONFIG.restUrl ) + '_';
	var ownLostName = LOST_PREFIX + viewPrefix;
	var LOST_MAX_CHARS = 2500;
	var LOST_OWN_CHARS = 1000;
	// 1 つのまとまりに添えて知らせる記録の上限（サーバー側の上限と合わせる）。
	var LOST_REPORT_MAX = 100;
	// 知らせて、記録された（200）番号（このページの中で知らせ直さない）。
	var reportedOk = {};
	// 保存領域に最後に書けたか（書けない間は、記録の無いまとまりを 1 つずつにする）。
	var storageOk = true;
	// 1 つの項目の長さの上限（1 件のイベントで本文の上限を超えないようにする）。
	var MAX_URL = 8000;
	var MAX_SELECTOR = 240;
	// ログイン中で計測から外す人に、サーバーが付ける Cookie（値はログインの期限の UNIX 秒）。
	var EXCLUDE_COOKIE = CONFIG.excludeCookie || '';
	// このページを読み込んだ時点で、計測から外す人だったか。ログアウトの直後に古いページの
	// 離脱の送信が走るときは、Cookie がもう消えているので、読み込んだ時点の状態も見る。
	var excludedAtLoad = isExcludedNow();

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

	/**
	 * 文字列の短い指紋（FNV-1a、16 進 8 桁）。送り箱の名前をサイトごとに分けるだけに使う。
	 */
	function hashText( text ) {
		var str = String( text || '' );
		var h = 0x811c9dc5;
		for ( var i = 0; i < str.length; i++ ) {
			h ^= str.charCodeAt( i );
			h = Math.imul( h, 0x01000193 ) >>> 0;
		}
		return ( '0000000' + h.toString( 16 ) ).slice( -8 );
	}

	/**
	 * いま、ログイン中で計測から外す人か（サーバーが付けた Cookie を読む）。
	 *
	 * ログインの Cookie は HttpOnly でスクリプトから読めないので（MDN Document.cookie
	 * 「HttpOnly ... can't be set or modified via JavaScript features such as Document.cookie」）、
	 * サーバーが除外する人にだけ、スクリプトから読める別の Cookie を付ける。
	 * 値はログインの期限（UNIX 秒）で、期限を過ぎた Cookie は除外に使わない。
	 */
	function isExcludedNow() {
		if ( ! EXCLUDE_COOKIE ) {
			return false;
		}
		var until = parseInt( getCookie( EXCLUDE_COOKIE ), 10 );
		return until > 0 && until * 1000 > Date.now();
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

	// セレクタに使う id・クラス名の長さの上限。とても長い名前（自動で作られた名前など）は使わない。
	function usableId( node ) {
		return !! node.id && node.id.length <= 64 && isSimpleIdent( node.id );
	}

	function usableClass( value ) {
		return value.length <= 40 && isSimpleIdent( value );
	}

	/**
	 * 要素を表す CSS セレクタを作る。id があればそれを使い、無ければ
	 * tag + class + nth-of-type のパスを最大 4 階層まで組み立てる。
	 *
	 * 長さは MAX_SELECTOR（保存する列の長さ）までに収める。長くなる階層は足さずに止めるので、
	 * 途中で切れた（セレクタとして壊れた）文字列にはならない。
	 */
	function buildSelector( el ) {
		if ( ! el || el.nodeType !== 1 ) {
			return '';
		}
		if ( usableId( el ) ) {
			return '#' + cssEscape( el.id );
		}

		var parts = [];
		var node = el;
		var depth = 0;

		while ( node && node.nodeType === 1 && node !== document.body && node !== document.documentElement && depth < 4 ) {
			if ( usableId( node ) ) {
				var idPart = '#' + cssEscape( node.id );
				if ( [ idPart ].concat( parts ).join( ' > ' ).length <= MAX_SELECTOR ) {
					parts.unshift( idPart );
				}
				break;
			}

			var part = node.tagName.toLowerCase();
			var classes = [];
			if ( node.className && typeof node.className === 'string' ) {
				classes = node.className.trim().split( /\s+/ ).filter( usableClass ).slice( 0, 2 );
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

			if ( [ part ].concat( parts ).join( ' > ' ).length > MAX_SELECTOR ) {
				break;
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
				return cutText( scope.id, 100 );
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
	 * デッドクリックの候補にしてよい要素か。
	 *
	 * 入力欄・チェックボックス・選択肢・ラベル・編集できる領域は、押すと
	 * 入力やチェックの状態（DOM の中身ではなくプロパティ）が変わるだけで、
	 * 画面の見た目の変化として捉えられないため、候補にしない。
	 * 行き先のあるリンク（# や javascript: 以外）も、別のタブで開く・
	 * ダウンロードするなどこのページが変わらない動きがあるため候補にしない。
	 */
	function isDeadCandidate( el ) {
		if ( ! looksClickable( el ) ) {
			return false;
		}
		if ( el.matches( 'input, textarea, select, option, label, [contenteditable]' ) || el.isContentEditable ) {
			return false;
		}
		if ( el.closest && el.closest( 'label' ) ) {
			return false;
		}
		// 行き先のあるリンク（中の深い装飾の要素を押した場合も含む）は、別のページ・別のタブ・
		// ダウンロードで反応するので候補にしない。# と javascript: のリンクだけは候補に残す。
		var link = 'A' === el.tagName ? el : ( el.closest ? el.closest( 'a[href]' ) : null );
		if ( link && link.getAttribute( 'href' ) ) {
			var href = link.getAttribute( 'href' ).trim().toLowerCase();
			if ( link.hasAttribute( 'download' ) || ( '' !== href && '#' !== href.charAt( 0 ) && 0 !== href.indexOf( 'javascript:' ) ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * 文書全体の変化を見張る（デッドクリックの判定の間だけ）。
	 *
	 * 押した要素自身の中身だけを比べると、メニューやアコーディオンのように
	 * 別の要素を開くボタンがすべて「何も起きない」になる。そこで、クリックの後に
	 * 文書のどこかで要素・属性・文字が変われば「反応あり」とする。
	 * 公式（MDN MutationObserver.observe）: subtree を true にすると
	 * 「extend monitoring to the entire subtree of nodes rooted at target」。
	 */
	function startDeadObserver() {
		if ( deadObserver || ! window.MutationObserver ) {
			return;
		}
		try {
			deadObserver = new MutationObserver( function ( list ) {
				var now = Date.now();
				for ( var i = 0; i < list.length; i++ ) {
					var m = list[ i ];
					// 要素の追加・削除は、そのまま「画面が変わった」とみなす。
					if ( 'childList' === m.type || mutationLog.length >= 500 ) {
						lastMutationAt = now;
						continue;
					}
					// 属性・文字の変化は、変化の前の値を覚えておき、判定の時に今の値と比べる。
					// クリックのたびにメニューの class を付け外しして元に戻すテーマがあり、
					// 「変化があった」だけで判定すると、何も起きないクリックを見逃すため。
					mutationLog.push( { at: now, node: m.target, kind: m.type, attr: m.attributeName, old: m.oldValue } );
				}
			} );
			// 公式（MDN MutationObserver.observe）: attributeOldValue は
			// 「record the previous value of any attribute that changes」。
			deadObserver.observe( document.documentElement, {
				subtree: true,
				childList: true,
				attributes: true,
				attributeOldValue: true,
				characterData: true,
				characterDataOldValue: true
			} );
		} catch ( e ) {
			deadObserver = null;
		}
	}

	function stopDeadObserver() {
		if ( deadObserver && pendingDead <= 0 ) {
			deadObserver.disconnect();
			deadObserver = null;
			mutationLog = [];
		}
	}

	/**
	 * at 以降に、画面の中身が実際に変わったか（変わって元に戻っただけのものは数えない）。
	 */
	function changedSince( at ) {
		if ( lastMutationAt >= at ) {
			return true;
		}
		var seen = [];
		for ( var i = 0; i < mutationLog.length; i++ ) {
			var r = mutationLog[ i ];
			if ( r.at < at ) {
				continue;
			}
			// 同じ要素・同じ属性は、クリックの後の最初の記録の「前の値」と今の値を比べる。
			var dup = false;
			for ( var j = 0; j < seen.length; j++ ) {
				if ( seen[ j ].node === r.node && seen[ j ].kind === r.kind && seen[ j ].attr === r.attr ) {
					dup = true;
					break;
				}
			}
			if ( dup ) {
				continue;
			}
			seen.push( r );
			var current = 'characterData' === r.kind ? r.node.data : ( r.node.getAttribute ? r.node.getAttribute( r.attr ) : null );
			if ( current !== r.old ) {
				return true;
			}
		}
		return false;
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
		// 計測から外す人の間（読み込んだ時点か、いま）の操作は手元に入れない。送り直しを待つ間は手元を
		// まとまりにしない（seal を呼ばない）ので、入れておくと、ログアウトの後のまとまりに入って数えられる。
		if ( excludedAtLoad || isExcludedNow() ) {
			return;
		}
		events.push( event );
		if ( events.length >= 150 && ! inFlush ) {
			flush( false );
		}
	}

	function baseEvent( el, selector, index, pos ) {
		return {
			t: 'click',
			sel: selector,
			i: index,
			tag: el && el.tagName ? cutText( el.tagName.toLowerCase(), 30 ) : '',
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
		if ( TRACK.dead && isDeadCandidate( el ) ) {
			scheduleDeadCheck( el, selector, index, pos, now );
		}
	}

	/**
	 * クリックから deadWindow ミリ秒たったところで、画面が反応したかを確かめる。
	 *
	 * 反応とみなすもの: 文書のどこかの変化（要素・属性・文字）、スクロール、
	 * URL の変化（# の移動を含む）、フォーカスが別の要素へ移ったこと、
	 * ページを離れる・タブを裏に回すこと。どれも無ければ「押しても何も起きない
	 * クリック」として記録する。
	 */
	function scheduleDeadCheck( el, selector, index, pos, at ) {
		if ( pendingDead >= 20 ) {
			return;
		}
		startDeadObserver();
		pendingDead++;
		var url = location.href;
		var focused = document.activeElement;
		var wait = Math.max( 200, CONFIG.deadWindow || 1000 );

		window.setTimeout( function () {
			pendingDead--;
			var focusMoved = document.activeElement !== focused && document.activeElement !== el && document.activeElement !== document.body;
			var reacted = changedSince( at ) ||
				lastScrollAt >= at ||
				lastNavAt >= at ||
				location.href !== url ||
				focusMoved ||
				pageUnloading ||
				! pageVisible;
			if ( ! reacted ) {
				var deadEvent = baseEvent( el, selector, index, pos );
				deadEvent.t = 'dead';
				pushEvent( deadEvent );
			}
			stopDeadObserver();
		}, wait );
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

	/* ------------------------------------------------------------------ */
	/* スクロール                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * 見えた一番下の位置を、いまの文書の高さに対する割合（%）にする。
	 */
	/**
	 * サーバーが到達率を数えるマイルストーン（%）。サーバーの一覧を設定で受け取る（無ければ 10% 刻み）。
	 */
	function scrollMilestones() {
		var list = [];
		var src = CONFIG.milestones;
		if ( src && src.length ) {
			for ( var i = 0; i < src.length; i++ ) {
				var m = Number( src[ i ] );
				if ( m > 0 && m <= 100 ) {
					list.push( m );
				}
			}
		}
		if ( ! list.length ) {
			list = [ 10, 20, 30, 40, 50, 60, 70, 80, 90, 100 ];
		}
		return list;
	}

	/**
	 * 深さが越えたマイルストーンの数。
	 */
	function milestoneLevel( depth ) {
		var n = 0;
		for ( var i = 0; i < MILESTONES.length; i++ ) {
			if ( depth >= MILESTONES[ i ] ) {
				n++;
			}
		}
		return n;
	}

	function computeScrollDepth() {
		var size = docSize();
		if ( size.h <= 0 ) {
			return 0;
		}
		var pct = ( maxBottomPx / size.h ) * 100;
		return Math.max( 0, Math.min( 100, Math.round( pct ) ) );
	}

	function handleScroll() {
		// デッドクリックの判定では、スクロールも「画面の反応」に数える。
		lastScrollAt = Date.now();
		measureScrollDepth();
	}

	/**
	 * いま見えている一番下の位置まで「到達した」として記録する。
	 *
	 * スクロールの時だけでなく、読み込んだ時にも測る。スクロールしないで
	 * 読んだ閲覧や、1 画面に収まる短いページでも、見えていた範囲が到達率に入る。
	 * ここでは位置（px）だけを覚え、割合（%）は送るときの文書の高さで計算する。
	 */
	function measureScrollDepth() {
		if ( ! TRACK.scroll ) {
			return;
		}
		var bottom = window.pageYOffset + window.innerHeight;
		if ( bottom > maxBottomPx ) {
			maxBottomPx = bottom;
		}
	}

	/**
	 * スクロールの到達率を送る。前回送った深さ（df）より深い分だけを
	 * サーバ側で到達率として数えてもらう（同じ深さを何度も数えない）。
	 *
	 * 読み込みが終わる前（document.readyState が complete でない間）は、ふだんは送らない。
	 * 寸法の無い画像や後から足す内容で文書が伸びるので、途中の高さで % にすると
	 * 実際より深く残るため（MDN Document.readyState「complete: The document and all
	 * sub-resources have finished loading」）。ただし、読み込みの途中に画面が隠れた・ページを
	 * 離れる・LOAD_WAIT_MS を過ぎた場合は、その時点の高さで「仮の値」として送る
	 * （sendProvisional。戻らない閲覧にも到達が残る）。読み込みが終わったら、読み込みの後の高さで
	 * 「置き換え」を送り、サーバーが仮の値で数えた分を引いて正しい分を足す（replaceProvisional）。
	 */
	function pushScrollEvent() {
		if ( ! TRACK.scroll ) {
			return;
		}
		var depth = computeScrollDepth();
		if ( 'complete' !== document.readyState ) {
			if ( pageVisible && ! pageUnloading && Date.now() - startedAt < LOAD_WAIT_MS ) {
				return;
			}
			if ( provChain.length ? milestoneLevel( depth ) > milestoneLevel( provDepth ) : depth > sentScrollDepth ) {
				sendProvisional( depth );
			}
			return;
		}
		if ( provChain.length ) {
			replaceProvisional( depth );
			return;
		}
		if ( depth <= sentScrollDepth ) {
			return;
		}
		// 積む前に「送った深さ」を進める。積む処理が送信を呼んでも、同じ到達を積み直さない。
		var from = sentScrollDepth;
		sentScrollDepth = depth;
		pushEvent( scrollEvent( depth, from ) );
	}

	/**
	 * 読み込みの途中の到達率を、到達率の 1 件だけの「仮のまとまり」（meta.pr=1）にして送り箱に入れる。
	 *
	 * 前の仮のまとまりがあれば、それまでの仮のまとまりの番号をすべて置き換える仮のまとまり（rp）にする
	 * （深さの数え始めは同じ）。sentScrollDepth は進めない（確定した深さだけを表す）。2 つ目からは、
	 * 新しいマイルストーンを越えたときだけ呼ばれる（pushScrollEvent）ので、鎖はマイルストーンの数＋1 まで。
	 * PROV_MAX は安全のための上限（マイルストーンの一覧が長すぎるときだけ効く）。
	 * 公式: MDN visibilitychange「Transitioning to hidden is the last event that's reliably observable by the page」。
	 */
	function sendProvisional( depth ) {
		if ( provChain.length >= PROV_MAX ) {
			return;
		}
		var from = provChain.length ? provFrom : sentScrollDepth;
		var id = sealSingle( [ scrollEvent( depth, from ) ], provChain.length ? { pr: 1, rp: provChain.slice() } : { pr: 1 } );
		if ( ! id ) {
			return;
		}
		provFrom = from;
		provChain.push( id );
		provDepth = depth;
	}

	/**
	 * 読み込みが終わったので、仮のまとまりを（鎖の全部を）、読み込みの後の高さの到達率で置き換える（meta.rp）。
	 *
	 * 読み込みの後の深さが数え始めより浅くても送る（仮の値を消すため。足すマイルストーンは無い）。
	 */
	function replaceProvisional( depth ) {
		var id = sealSingle( [ scrollEvent( depth, provFrom ) ], { rp: provChain.slice() } );
		if ( ! id ) {
			return;
		}
		sentScrollDepth = Math.max( depth, provFrom );
		provChain = [];
		provDepth = 0;
	}

	/**
	 * 到達率のイベント。前回送った深さ（df）より深い分を、サーバーが到達として数える。
	 */
	function scrollEvent( depth, from ) {
		return {
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
			d: depth,
			df: from
		};
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

	/**
	 * 本文に入れるページの URL。とても長い URL は、ページを見分けるのに要らない部分から短くする。
	 *
	 * URL は本文ごとに入るので、長いまま入れると 1 件のイベントでも本文の上限を超えてしまう。
	 * 先に # 以降を落とし、次に投稿を特定するクエリ（p・page_id などと公開の投稿タイプの名前。
	 * サーバーがページを見分けるのに使う名前と同じ一覧を CONFIG.pageKeys で受け取る）だけを、
	 * サーバーと同じ読み方で 1 つずつにして残す（queryKeys）。それでも長ければパスだけにする。
	 */
	function pageUrl() {
		var href = location.href;
		if ( href.length <= MAX_URL ) {
			return href;
		}
		href = href.split( '#' )[ 0 ];
		if ( href.length <= MAX_URL ) {
			return href;
		}
		var base = location.protocol + '//' + location.host + location.pathname;
		var kept = queryKeys( location.search.replace( /^\?/, '' ), CONFIG.pageKeys || [] );
		href = base + ( kept ? '?' + kept : '' );
		if ( href.length <= MAX_URL ) {
			return href;
		}
		return cutText( base, MAX_URL );
	}

	function decodePart( text ) {
		try {
			return decodeURIComponent( text.replace( /\+/g, ' ' ) );
		} catch ( e ) {
			// 壊れた %エスケープは、そのままで扱う。
			return text;
		}
	}

	/**
	 * クエリから、投稿を特定する名前（keys）の値だけを、サーバー（wp_parse_str）と同じ読み方で取り出す。
	 *
	 * 名前と値の %エスケープと + を戻し、名前の先頭の空白を落とし、名前の . と空白を _ にする。
	 * 名前に [ があり、その後に ] があれば配列なので使わない（サーバーも文字列の値だけを使う）。
	 * ] が無ければ [ も _ にする。同じ名前は後の値を使い、空の値は使わない。値は長さで落とさない
	 * （階層のスラッグは長くなる。サーバーも長さで落とさない）。
	 * 公式: PHP parse_str「Any spaces and dots in parameter names are converted to underscores」。
	 * [ の扱い・同じ名前・先頭の空白は、PHP で parse_str を実行して確かめた。
	 */
	function queryKeys( query, keys ) {
		var values = {};
		var order = [];
		var pairs = query.split( '&' );
		for ( var i = 0; i < pairs.length; i++ ) {
			if ( '' === pairs[ i ] ) {
				continue;
			}
			var eq = pairs[ i ].indexOf( '=' );
			var name = decodePart( eq >= 0 ? pairs[ i ].slice( 0, eq ) : pairs[ i ] ).replace( /^ +/, '' );
			var value = eq >= 0 ? decodePart( pairs[ i ].slice( eq + 1 ) ) : '';
			var open = name.indexOf( '[' );
			if ( open >= 0 && name.indexOf( ']', open ) > open ) {
				continue;
			}
			name = open >= 0 ? name.replace( /[ .\[]/g, '_' ) : name.replace( /[ .]/g, '_' );
			if ( keys.indexOf( name ) < 0 ) {
				continue;
			}
			if ( ! Object.prototype.hasOwnProperty.call( values, name ) ) {
				order.push( name );
			}
			values[ name ] = value;
		}
		var out = [];
		for ( var j = 0; j < order.length; j++ ) {
			var v = values[ order[ j ] ];
			if ( '' !== v ) {
				out.push( encodeURIComponent( order[ j ] ) + '=' + encodeURIComponent( v ) );
			}
		}
		return out.join( '&' );
	}

	/**
	 * 送る本文を作る。
	 *
	 * @param {string}  id       まとまりの番号。送り直しでも同じ番号・同じ中身を使い、
	 *                           サーバーはこの番号で同じまとまりを二重に数えない。
	 * @param {Array}   list     送るイベント。
	 * @param {number}  seconds  送る滞在秒数（表示されていた時間だけ）。
	 * @param {boolean} pv       ページビューとして数えるか。
	 * @param {Array}   lr       ほかの閲覧のまだ届いていないまとまりの [番号, 件数]（無ければ空）。
	 * @param {Object}  extra    meta に足す項目（仮の到達率 pr・置き換え rp）。
	 */
	function buildPayload( id, list, seconds, pv, lr, extra ) {
		var size = docSize();
		var vp = viewport();
		var meta = {
			id: CONFIG.postId,
			u: pageUrl(),
			w: size.w,
			h: size.h,
			vw: vp.vw,
			vh: vp.vh,
			d: detectDevice(),
			// まとまりの中の、表示中の滞在秒数。サーバ側で合計して平均滞在時間にする。
			t: Math.round( seconds * 10 ) / 10,
			e: pv && isEntrance ? 1 : 0,
			pv: pv ? 1 : 0
		};
		if ( lr && lr.length ) {
			meta.lr = lr;
		}
		if ( extra ) {
			for ( var k in extra ) {
				if ( Object.prototype.hasOwnProperty.call( extra, k ) ) {
					meta[ k ] = extra[ k ];
				}
			}
		}
		return {
			b: id,
			p: meta,
			v: visitorId,
			s: sessionId,
			// 作った時点で、ログイン中で計測から外す人ではなかった（外す人のまとまりは、そもそも作らない）。
			// サーバーは、届いた時点のログインの Cookie でも外す人かを確かめる。送り箱に残っている間に外す人に
			// なった・サーバーに外す人として外されたまとまりは、x=1 に書き換える（markOutboxExcluded・sendNext）。
			x: 0,
			ev: list
		};
	}

	function byteLength( text ) {
		if ( window.TextEncoder ) {
			return new TextEncoder().encode( text ).length;
		}
		return unescape( encodeURIComponent( text ) ).length;
	}

	/**
	 * 手元のイベントの先頭から、件数（MAX_EVENTS）と本文の大きさ（MAX_BODY）に
	 * 収まるだけを取り出して本文を作る。
	 *
	 * 1 件は必ず入れる。各項目の長さ（URL・セレクタ・文字）を切ってあるので 1 件で上限を
	 * 超えることは無く、もし超えても捨てずにそのまま送る（keepalive を使わずに送る。post を参照）。
	 */
	function takeChunk( id, seconds, pv, lr ) {
		var count = Math.min( MAX_EVENTS, events.length );
		var body = JSON.stringify( buildPayload( id, events.slice( 0, count ), seconds, pv, lr ) );
		while ( count > 1 && byteLength( body ) > MAX_BODY ) {
			count = Math.max( 1, Math.floor( count / 2 ) );
			body = JSON.stringify( buildPayload( id, events.slice( 0, count ), seconds, pv, lr ) );
		}
		return { count: count, body: body };
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

	/* ------------------------------------------------------------------ */
	/* まだ届いていないまとまりの記録                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * 届いていないまとまりの記録（閲覧ごとの Cookie）をすべて読む。
	 * 保存領域（localStorage）が使えない・一杯のときにも残せるように、Cookie に置く。
	 * 届いたかどうかはブラウザからは確かめられない（MDN sendBeacon「Returns true if the user agent
	 * successfully queued the data for transfer」）ので、決めるのはサーバー（番号の表で照らす）。
	 *
	 * @return {Object} 閲覧の番号の先頭 24 桁 → { list: [[連番, 件数], …], size: 名前と値の長さ }。
	 */
	function readLostCookies() {
		var map = {};
		var parts = document.cookie ? document.cookie.split( ';' ) : [];
		for ( var i = 0; i < parts.length; i++ ) {
			var part = parts[ i ].replace( /^\s+/, '' );
			var eq = part.indexOf( '=' );
			var name = eq >= 0 ? part.slice( 0, eq ) : part;
			if ( 0 !== name.indexOf( LOST_PREFIX ) ) {
				continue;
			}
			var prefix = name.slice( LOST_PREFIX.length );
			if ( ! /^[a-f0-9]{24}$/.test( prefix ) ) {
				continue;
			}
			var raw = eq >= 0 ? part.slice( eq + 1 ) : '';
			map[ prefix ] = { list: parseLost( raw ), size: name.length + 1 + raw.length };
		}
		return map;
	}

	function parseLost( value ) {
		var list = [];
		var items = String( value || '' ).split( '.' );
		for ( var j = 0; j < items.length; j++ ) {
			var f = items[ j ].split( '-' );
			var seq = parseInt( f[ 0 ], 36 );
			var n = parseInt( f[ 1 ], 10 );
			if ( 2 === f.length && seq > 0 && seq <= 0xffffffff && n > 0 ) {
				list.push( [ seq, n ] );
			}
		}
		return list;
	}

	function lostValue( list ) {
		return list.map( function ( e ) {
			return e[ 0 ].toString( 36 ) + '-' + e[ 1 ];
		} ).join( '.' );
	}

	function nextBatchId() {
		batchSeq++;
		return viewPrefix + ( '0000000' + batchSeq.toString( 16 ) ).slice( -8 );
	}

	function isOwnBatch( id ) {
		return id.slice( 0, 24 ) === viewPrefix;
	}

	/**
	 * この閲覧のまとまりのうち、保存領域に保存できず、受け取りの応答もまだ無いものの番号と件数を、
	 * この閲覧の Cookie に書く（無ければ消す）。送り箱には応答の無いまとまりだけがあるので、送り箱の
	 * persisted でない自分のまとまりを書く。sendBeacon で送ったかどうかでは変えない。
	 * 書くのはこの閲覧の Cookie だけ（公式: MDN Document.cookie「you can only set/update a single cookie
	 * at a time using this method」）で、ほかのタブは書かないので、読みと書きの間に競合しない。
	 * 記録の Cookie の合計と、この閲覧の値の長さの上限を確かめ、書いた後に読み直して確かめる。
	 * 終わったら、今の Cookie の中身どおりに、まとまりの recorded（記録がある）を付け直す。
	 * 期限は、記録する中で一番古いまとまりを作った時刻から OUTBOX_TTL（書き直しても延びない）。
	 */
	function writeOwnLost() {
		var list = [];
		var oldest = 0;
		for ( var i = 0; i < outbox.length; i++ ) {
			var b = outbox[ i ];
			if ( ! b.persisted && isOwnBatch( b.id ) ) {
				list.push( [ parseInt( b.id.slice( 24 ), 16 ), b.n || 1 ] );
				oldest = oldest ? Math.min( oldest, b.t ) : b.t;
			}
		}
		var value = lostValue( list );
		try {
			if ( getCookie( ownLostName ) !== value ) {
				if ( ! list.length ) {
					setCookie( ownLostName, '', -1 );
				} else {
					var others = 0;
					var all = readLostCookies();
					for ( var p in all ) {
						if ( Object.prototype.hasOwnProperty.call( all, p ) && p !== viewPrefix ) {
							others += all[ p ].size;
						}
					}
					if ( value.length <= LOST_OWN_CHARS && others + ownLostName.length + 1 + value.length <= LOST_MAX_CHARS ) {
						setCookie( ownLostName, value, Math.max( 60000, oldest + OUTBOX_TTL - Date.now() ) / 86400000 );
					}
				}
			}
		} catch ( e ) {
			// Cookie が使えない環境では記録できない（下で、記録が無いまとまりとして扱う）。
		}
		var recorded = {};
		var have = [];
		try {
			have = parseLost( getCookie( ownLostName ) );
		} catch ( e2 ) {
			have = [];
		}
		for ( var j = 0; j < have.length; j++ ) {
			recorded[ have[ j ][ 0 ] ] = true;
		}
		for ( var k = 0; k < outbox.length; k++ ) {
			if ( ! outbox[ k ].persisted && isOwnBatch( outbox[ k ].id ) ) {
				outbox[ k ].recorded = !! recorded[ parseInt( outbox[ k ].id.slice( 24 ), 16 ) ];
			}
		}
	}

	/**
	 * 保存領域にも Cookie にも残せていない（記録の無い）自分のまとまりが、送り箱にあるか。
	 * 保存領域が使えない間は、これがある間は新しいまとまりを作らない（記録の無いまとまりを 1 つずつにする）。
	 */
	function hasUnrecorded() {
		for ( var i = 0; i < outbox.length; i++ ) {
			if ( ! outbox[ i ].persisted && ! outbox[ i ].recorded && isOwnBatch( outbox[ i ].id ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * ほかの閲覧の記録を、知らせる [番号, 件数] にして返す（LOST_REPORT_MAX 件まで）。
	 * ほかの閲覧の Cookie は書かない（消すのはその閲覧か期限）。このページの中で既に知らせて受け取られた
	 * 番号（reportedOk）は知らせ直さない。複数のページが同じ記録を知らせても、サーバーが番号で
	 * 1 回だけ記録する。
	 */
	function unarrivedReports() {
		var out = [];
		try {
			var map = readLostCookies();
			for ( var p in map ) {
				if ( ! Object.prototype.hasOwnProperty.call( map, p ) || p === viewPrefix ) {
					continue;
				}
				for ( var i = 0; i < map[ p ].list.length && out.length < LOST_REPORT_MAX; i++ ) {
					var id = p + ( '0000000' + map[ p ].list[ i ][ 0 ].toString( 16 ) ).slice( -8 );
					if ( ! reportedOk[ id ] ) {
						out.push( [ id, map[ p ].list[ i ][ 1 ] ] );
					}
				}
			}
		} catch ( e ) {
			out = [];
		}
		return out;
	}

	/**
	 * 知らせた番号が記録された（200）ので、このページの中では知らせ直さない。
	 */
	function forgetReported( ids ) {
		if ( ! ids ) {
			return;
		}
		for ( var i = 0; i < ids.length; i++ ) {
			reportedOk[ ids[ i ] ] = true;
		}
	}

	/* ------------------------------------------------------------------ */
	/* まとまりと送り箱                                                    */
	/* ------------------------------------------------------------------ */

	/**
	 * 手元のイベント・滞在秒数・ページビューを、番号の付いたまとまりにして送り箱（outbox）に移す。
	 *
	 * まとまりは、サーバーが受け取ったと応答で確かめるまで送り箱とブラウザの保存領域
	 * （localStorage）に残し、同じ番号・同じ中身で送り直す。ページを離れて送れなかった分は、
	 * 次に開いたページが引き継いで送る。サーバーは番号で同じまとまりを 1 回だけ数える。
	 * まとまりには作った時刻（t）を持たせ、送るときに経過時間を添える（サーバーは操作のあった
	 * 日の数字として数える）。期限（7 日）も作った時刻から数える。保存できなかったまとまり
	 * （persisted が false）は手元だけに残り、離脱時に先に送る（sendFinal）。保存できなかった
	 * 自分のまとまりの番号と件数は Cookie に残す（writeOwnLost）。保存領域が使えず、Cookie にも
	 * 残せなかった（記録の無い）まとまりは 1 つずつにする: それが受け取られるまで新しいまとまりを
	 * 作らない（送るのは止めない）。離脱時は、記録できなくても手元の分をすべてまとまりにして送る。
	 * ほかの閲覧の記録は、このまとまりに添えて知らせる（unarrivedReports、meta.lr）。
	 *
	 * ログイン中で計測から外す人の操作は、まとまりにせずにここで捨てる（読み込んだ時点か、
	 * いまのどちらかで外す人なら外す）。
	 *
	 * @param {boolean} final 離脱時（滞在秒数が 30 秒に届かなくても作る）。
	 * @return {boolean} 手元の分を移したら true。
	 */
	function seal( final ) {
		var pv = ! flushedOnce;
		var seconds = secondsSinceFlush;
		if ( events.length <= 0 && ! pv && seconds < ( final ? 0.5 : HEARTBEAT_MS / 1000 ) ) {
			return false;
		}
		var excluded = excludedAtLoad || isExcludedNow();
		if ( ! excluded && ! final && ! storageOk && hasUnrecorded() ) {
			return false;
		}
		var lr = excluded ? [] : unarrivedReports();
		var id = nextBatchId();
		var chunk = takeChunk( id, seconds, pv, lr );
		events.splice( 0, chunk.count );
		secondsSinceFlush = Math.max( 0, secondsSinceFlush - seconds );
		if ( pv ) {
			flushedOnce = true;
			isEntrance = false;
		}
		moveCount = 0;
		if ( excluded ) {
			return true;
		}
		outbox.push( {
			id: id,
			t: Date.now(),
			body: chunk.body,
			n: chunk.count + ( pv ? 1 : 0 ),
			r: lr.map( function ( e ) {
				return e[ 0 ];
			} )
		} );
		saveOutbox( false );
		return true;
	}

	/**
	 * 到達率の仮の値・置き換えのように、決まった中身だけのまとまりを作って送り箱に入れる。
	 * 表示中は seal と同じく、記録の無いまとまりがある間は作らない（隠れた・離れるときは作って送る）。
	 *
	 * @param {Array}  list  イベント。
	 * @param {Object} extra meta に足す項目。
	 * @return {string} まとまりの番号（作らなかったら空）。
	 */
	function sealSingle( list, extra ) {
		if ( excludedAtLoad || isExcludedNow() ) {
			return '';
		}
		if ( pageVisible && ! pageUnloading && ! storageOk && hasUnrecorded() ) {
			return '';
		}
		var id = nextBatchId();
		var body = JSON.stringify( buildPayload( id, list, 0, false, [], extra ) );
		// rep: 仮の値を置き換えるまとまり（表の変換を待つ間はサーバーが 503 で受け取らないことがある）。
		outbox.push( { id: id, t: Date.now(), body: body, n: list.length, r: [], rep: !! ( extra && extra.rp ) } );
		saveOutbox( false );
		return id;
	}

	/**
	 * 送り箱をブラウザの保存領域に書く（空なら消す）。書けたら、送り箱のまとまりに保存済みの印を付ける。
	 * 書けても書けなくても、保存できていない自分のまとまりの記録（Cookie）を今の送り箱に合わせる。
	 *
	 * @param {boolean} closed ページを離れるとき true（次のページがすぐに引き継げる）。
	 * @return {boolean} 書けたら true。
	 */
	function saveOutbox( closed ) {
		var ok = false;
		try {
			if ( outbox.length ) {
				var list = [];
				for ( var i = 0; i < outbox.length; i++ ) {
					var b = outbox[ i ];
					list.push( { id: b.id, t: b.t, body: b.body, n: b.n, r: b.r || [], rep: b.rep ? 1 : 0 } );
				}
				window.localStorage.setItem( outboxKey, JSON.stringify( { at: Date.now(), closed: !! closed, r: CONFIG.restUrl, batches: list } ) );
			} else {
				window.localStorage.removeItem( outboxKey );
			}
			for ( var j = 0; j < outbox.length; j++ ) {
				outbox[ j ].persisted = true;
			}
			ok = true;
		} catch ( e ) {
			// 保存できない環境（プライベートモード・容量不足など）では手元だけで送る。
			// 公式: MDN Storage.setItem「QuotaExceededError ... Thrown if the storage run out of disk quota」、
			// MDN Window.localStorage「SecurityError ... The request violates a policy decision」。
			ok = false;
		}
		storageOk = ok;
		writeOwnLost();
		return ok;
	}

	/**
	 * 作ってから期限（7 日）を過ぎたまとまりを送り箱から外す（送信中のものは応答を待つ）。
	 *
	 * サーバーは 7 日を過ぎたまとまりを受け取らず、番号も 8 日で消すので、送っても数えられない。
	 */
	function pruneExpired() {
		var now = Date.now();
		var before = outbox.length;
		outbox = outbox.filter( function ( batch ) {
			return batch.id === sendingId || now - batch.t <= OUTBOX_TTL;
		} );
		if ( outbox.length !== before ) {
			saveOutbox( false );
		}
	}

	/**
	 * 保存領域の送り箱の中身が、すべて期限を過ぎているか（壊れていて読めないものも含む）。
	 */
	function allExpired( data, now ) {
		if ( ! data || ! data.batches || ! data.batches.length ) {
			return true;
		}
		for ( var i = 0; i < data.batches.length; i++ ) {
			var b = data.batches[ i ];
			if ( b && 'number' === typeof b.t && now - b.t <= OUTBOX_TTL ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * 前のページ（や閉じたタブ）が送りきれずに残した、このサイトの送り箱を引き継ぐ。
	 *
	 * 離れたページの印（closed）があるか、しばらく書き換えられていない送り箱だけを引き継ぐ
	 * （ほかのタブが今も送っている途中の送り箱は取らない。取っても、サーバーが番号で
	 * 二重に数えないので害は無い）。
	 *
	 * 引き継ぐのは、こちらの送り箱が目安（OUTBOX_MAX）に収まる分だけ。こちらに足して保存できたら、
	 * 取った分を元から外す（残りは元に書き戻し、こちらが減ってから引き継ぐ）。保存に失敗したら足した分を
	 * 戻し、元を残す。どちらの場合もまとまりは捨てない。期限を過ぎたまとまりは引き継がない。
	 * 別の送り先（別のサイト）の送り箱は引き継がず、期限を過ぎたものと壊れているものだけを消す。
	 */
	function adoptOutboxes() {
		var now = Date.now();
		var keys = storageKeysWithPrefix( OUTBOX_BASE ).filter( function ( k ) {
			return k !== outboxKey;
		} );
		var seen = {};
		for ( var j = 0; j < outbox.length; j++ ) {
			seen[ outbox[ j ].id ] = true;
		}
		adoptKeys( keys, seen, now );
		gcExcludedKeys();
	}

	/**
	 * 読んだ文字列が変わっていなければ、そのキーを消す（読んでから消すまでの間に、ほかのタブが書き換えた
	 * ものを消さない。localStorage には「比べてから消す」を 1 つにする操作が無いので、消す直前の読み直しで
	 * 窓を縮める。変わっていたら消さず、次の機会にもう一度読む）。
	 *
	 * @param {string} key キー名。
	 * @param {string} raw 最初に読んだ文字列。
	 * @return {boolean} 消した（または元から無かった）なら true。
	 */
	function removeIfUnchanged( key, raw ) {
		try {
			var cur = window.localStorage.getItem( key );
			if ( null === cur ) {
				return true;
			}
			if ( cur !== raw ) {
				return false;
			}
			window.localStorage.removeItem( key );
			return true;
		} catch ( e ) {
			return false;
		}
	}

	/**
	 * 取り込む・こちらにもある x=1 の写しの印を、元の送り箱を消す・書き戻す前に書く。
	 * こちらの該当まとまりを x=1 にして保存し、番号ごとのキーを書く。書けなければ元は消さない（呼び出し側が戻る）。
	 *
	 * @param {Object} ids 番号 → 作った時刻（x=1 の写しの番号）。
	 * @return {boolean} 書けた（または書くものが無い）なら true。
	 */
	function persistExcludedBeforeRemoval( ids ) {
		var any = false;
		var changed = false;
		for ( var id in ids ) {
			if ( Object.prototype.hasOwnProperty.call( ids, id ) ) {
				any = true;
			}
		}
		if ( ! any ) {
			return true;
		}
		for ( var i = 0; i < outbox.length; i++ ) {
			if ( Object.prototype.hasOwnProperty.call( ids, outbox[ i ].id ) && setExcluded( outbox[ i ] ) ) {
				changed = true;
			}
		}
		var ok = addExcluded( ids );
		if ( changed || excludedBodyUnsaved ) {
			excludedBodyUnsaved = ! saveOutbox( false );
			ok = ok && ! excludedBodyUnsaved;
		}
		return ok;
	}

	/**
	 * adoptOutboxes の本体（ほかの送り箱を 1 つずつ引き継ぐ）。保存できないときは途中で戻る。
	 *
	 * @param {string[]} keys ほかの送り箱の名前。
	 * @param {Object}   seen こちらにあるまとまりの番号 → true（引き継いだ分を足していく）。
	 * @param {number}   now  いまの時刻（ミリ秒）。
	 */
	function adoptKeys( keys, seen, now ) {
		for ( var n = 0; n < keys.length; n++ ) {
			var rawBefore = null;
			var data = null;
			try {
				rawBefore = window.localStorage.getItem( keys[ n ] );
				data = JSON.parse( rawBefore || 'null' );
			} catch ( e2 ) {
				data = null;
			}
			if ( 0 !== keys[ n ].indexOf( OUTBOX_PREFIX ) || ( data && data.r !== CONFIG.restUrl ) ) {
				// 別の送り先の送り箱（名前の指紋が違う・たまたま同じでも送り先が違う）。中身は送らない。
				// 期限切れだけのものを消す（読み直して同じときだけ）。
				if ( allExpired( data, now ) ) {
					removeIfUnchanged( keys[ n ], rawBefore );
				}
				continue;
			}
			var at = data && 'number' === typeof data.at ? data.at : 0;
			var list = data && data.batches && data.batches.length ? data.batches : [];
			if ( list.length && ! data.closed && now - at < OUTBOX_IDLE ) {
				continue;
			}
			var fresh = [];
			// この送り箱の中の x=1 の写しの番号（こちらにもある番号と、取り込む番号）。元を消す前に印を書く。
			var excludedHere = {};
			for ( var m = 0; m < list.length; m++ ) {
				var b = list[ m ];
				if (
					b && 'string' === typeof b.id && 'string' === typeof b.body && 'number' === typeof b.t &&
					/^[a-f0-9]{32}$/.test( b.id ) && ! seen[ b.id ] && now - b.t <= OUTBOX_TTL
				) {
					// 知らせた番号（r）も引き継ぐ（受け取りを確かめたときに Cookie から外すため）。
					var reported = [];
					if ( b.r && b.r.length ) {
						for ( var q = 0; q < b.r.length; q++ ) {
							if ( 'string' === typeof b.r[ q ] && /^[a-f0-9]{32}$/.test( b.r[ q ] ) ) {
								reported.push( b.r[ q ] );
							}
						}
					}
					fresh.push( { id: b.id, t: b.t, body: b.body, n: 'number' === typeof b.n ? b.n : 1, r: reported, rep: !! b.rep } );
					if ( isExcludedBody( b.body ) ) {
						excludedHere[ b.id ] = b.t;
					}
				} else if ( b && 'string' === typeof b.id && /^[a-f0-9]{32}$/.test( b.id ) && seen[ b.id ] && 'string' === typeof b.body && 'number' === typeof b.t && isExcludedBody( b.body ) ) {
					// こちらにもあるまとまりの写しに外す人の印が付いている。元の送り箱を消す前に、印をこちらに移す。
					excludedHere[ b.id ] = b.t;
				}
			}
			var room = OUTBOX_MAX - outbox.length;
			if ( fresh.length && room <= 0 ) {
				continue;
			}
			var take = fresh.slice( 0, room );
			var rest = fresh.slice( room );
			if ( take.length ) {
				var before = outbox.length;
				Array.prototype.push.apply( outbox, take );
				if ( ! saveOutbox( false ) ) {
					outbox.splice( before, take.length );
					// 保存できない間は、ほかの送り箱も引き継がない（元に残しておく）。
					return;
				}
				for ( var f = 0; f < take.length; f++ ) {
					seen[ take[ f ].id ] = true;
				}
			}
			// 元を消す・書き戻す前に、x=1 の写しの印を書く。書けなければ元は消さない（次の引き継ぎでやり直す）。
			if ( ! persistExcludedBeforeRemoval( excludedHere ) ) {
				continue;
			}
			try {
				if ( rest.length ) {
					// 取りきれなかった分を元に書き戻す（書き戻せなくても、元に全部が残るので失わない）。読み直して
					// 同じときだけ（変わっていたら、新しい本文を上書きしない）。
					var restRaw = JSON.stringify( { at: at, closed: !! ( data && data.closed ), r: CONFIG.restUrl, batches: rest } );
					if ( window.localStorage.getItem( keys[ n ] ) === rawBefore ) {
						window.localStorage.setItem( keys[ n ], restRaw );
					}
				} else {
					// 引き継いだか、引き継ぐものが無い（壊れている・空・期限切れ・引き継ぎ済み）送り箱を消す。
					removeIfUnchanged( keys[ n ], rawBefore );
				}
			} catch ( e3 ) {
				// 書き戻せない・消せなくても、サーバーが番号で二重に数えないので害は無い。
			}
		}
	}

	function removeStored( key ) {
		try {
			window.localStorage.removeItem( key );
		} catch ( e ) {
			// 消せなくても続ける。
		}
	}

	/**
	 * 応答で受け取ったと確かめられたら、送り箱から消してよいか。
	 *
	 * 2xx（保存済み・送り直しで既に保存済み・除外して捨てた・期限切れ）は消す。
	 * 中身が壊れていて何度送っても受け取られない 400・413 も消す（送り続けない）。
	 * それ以外（通信の失敗・15 秒の中断・429・500・503 など）は残して送り直す。
	 * 202 は「保存したが、添えた知らせ（lr）はまだ記録できない」（サーバーのデータベースの更新が
	 * 終わっていない間）。まとまりは消し、知らせは次のまとまりでまた添える。
	 * 公式: MDN 202 Accepted「the request has been accepted for processing, but processing has not been completed」。
	 */
	function isSettled( status ) {
		return ( status >= 200 && status < 300 ) || 400 === status || 413 === status;
	}

	function removeBatch( id, accepted ) {
		for ( var i = 0; i < outbox.length; i++ ) {
			if ( outbox[ i ].id === id ) {
				// 知らせも記録された（200）ので、このまとまりで知らせたほかの閲覧の記録は、このページの中では
				// 知らせ直さない。202（知らせはまだ記録されていない）・400・413 では残す。
				if ( accepted ) {
					forgetReported( outbox[ i ].r );
				}
				outbox.splice( i, 1 );
				break;
			}
		}
		saveOutbox( false );
	}

	/**
	 * 送る本文に、まとまりを作ってからの経過ミリ秒（a）を添える。
	 *
	 * サーバーは受け取った時刻から a を引いて、操作のあった日時として保存する
	 * （遅れて届いた分を、届いた日の数字として数えない）。送るたびに計り直す。
	 */
	function withAge( batch ) {
		var age = Math.max( 0, Math.round( Date.now() - batch.t ) );
		return '{"a":' + age + ',' + batch.body.slice( 1 );
	}

	/**
	 * まとまりの本文に「外す人の操作」の印（x=1）を付ける（送り直しでも外す人のまま扱わせる）。
	 *
	 * @param {Object} batch 送り箱のまとまり。
	 * @return {boolean} 印を付けた（変えた）ら true。
	 */
	function setExcluded( batch ) {
		try {
			var o = JSON.parse( batch.body );
			if ( 1 === o.x ) {
				return false;
			}
			o.x = 1;
			batch.body = JSON.stringify( o );
			return true;
		} catch ( e ) {
			return false;
		}
	}

	/**
	 * 番号の印のキー（番号ごとに 1 つ）。
	 *
	 * @param {string} id まとまりの番号。
	 * @return {string} キー名。
	 */
	function excludedKeyFor( id ) {
		return EXCLUDED_PREFIX + id;
	}

	/**
	 * この番号が外す人のまとまりとして印を付けられているか（メモリの控えか、番号ごとのキーの getItem 1 回）。
	 * キーを列挙しない。期限（作ってから OUTBOX_TTL）を過ぎたものは数えない。
	 *
	 * 公式: MDN Window.localStorage「allows you to access a Storage object for the Document's origin」
	 * （同じオリジンのタブで共有する）。
	 *
	 * @param {string} id まとまりの番号。
	 * @return {boolean} 印付きなら true。
	 */
	function isExcludedListed( id ) {
		var now = Date.now();
		if ( Object.prototype.hasOwnProperty.call( ownExcluded, id ) && now - ownExcluded[ id ] <= OUTBOX_TTL ) {
			return true;
		}
		try {
			var t = parseInt( window.localStorage.getItem( excludedKeyFor( id ) ), 10 );
			return t > 0 && now - t <= OUTBOX_TTL;
		} catch ( e ) {
			return false;
		}
	}

	/**
	 * 番号の印を、番号ごとのキーに書く（メモリの控えにも足す）。書けなかった番号は pendingExcluded に残し、
	 * あとで retryPendingExcluded が書き直す（MDN Storage.setItem「QuotaExceededError」: the storage run out of
	 * disk quota or the user declined to grant you more space）。
	 *
	 * @param {Object} ids 番号 → 作った時刻。
	 * @return {boolean} すべて書けたら true。
	 */
	function addExcluded( ids ) {
		var ok = true;
		for ( var id in ids ) {
			if ( ! Object.prototype.hasOwnProperty.call( ids, id ) || ! /^[a-f0-9]{32}$/.test( id ) ) {
				continue;
			}
			ownExcluded[ id ] = ids[ id ];
			try {
				window.localStorage.setItem( excludedKeyFor( id ), String( ids[ id ] ) );
				delete pendingExcluded[ id ];
			} catch ( e ) {
				pendingExcluded[ id ] = ids[ id ];
				ok = false;
			}
		}
		return ok;
	}

	/**
	 * 書けていない番号の印を、書き直す（保存できるようになったとき）。
	 */
	function retryPendingExcluded() {
		for ( var id in pendingExcluded ) {
			if ( Object.prototype.hasOwnProperty.call( pendingExcluded, id ) ) {
				try {
					window.localStorage.setItem( excludedKeyFor( id ), String( pendingExcluded[ id ] ) );
					delete pendingExcluded[ id ];
				} catch ( e ) {
					return;
				}
			}
		}
	}

	/**
	 * 保存領域のキーのうち、接頭辞で始まるものを返す。後ろから前へ 1 回だけ数える。
	 * ほかのタブの追加・削除で順序が入れ替わりうる（HTML Standard §12.2.1）ので、この結果は「見落としても
	 * 困らない使い方」にだけ使う（引き継ぎの元探し・番号ごとのキーに書けなかったときの代わりの確かめ・
	 * 期限切れの後始末）。印の判定そのものには使わない（isExcludedListed が番号ごとの getItem で決める）。
	 *
	 * @param {string} prefix 接頭辞。
	 * @return {string[]} キーの一覧（読めなければ空）。
	 */
	function storageKeysWithPrefix( prefix ) {
		var keys = [];
		try {
			for ( var i = window.localStorage.length - 1; i >= 0; i-- ) {
				var k = window.localStorage.key( i );
				if ( k && 0 === k.indexOf( prefix ) ) {
					keys.push( k );
				}
			}
		} catch ( e ) {
			return [];
		}
		return keys;
	}

	/**
	 * 期限（作ってから OUTBOX_TTL）を過ぎた番号ごとのキーを消す（後始末。見落としても消すのが遅れるだけ）。
	 */
	function gcExcludedKeys() {
		var keys = storageKeysWithPrefix( EXCLUDED_PREFIX );
		var now = Date.now();
		for ( var n = 0; n < keys.length; n++ ) {
			try {
				var raw = window.localStorage.getItem( keys[ n ] );
				var t = parseInt( raw, 10 );
				if ( ! ( t > 0 ) || now - t > OUTBOX_TTL ) {
					removeIfUnchanged( keys[ n ], raw );
				}
			} catch ( e ) {
				// 消せなくても続ける。
			}
		}
	}

	/**
	 * まとまりの本文に外す人の印（x=1）が付いているか。
	 *
	 * @param {string} body まとまりの本文（JSON の文字列）。
	 * @return {boolean} 付いていれば true。
	 */
	function isExcludedBody( body ) {
		try {
			var o = JSON.parse( body );
			return !! o && 1 === o.x;
		} catch ( e ) {
			return false;
		}
	}

	/**
	 * ほかの閲覧の送り箱（保存領域）に、同じ番号で外す人の印（x=1）の付いた写しがあるまとまりの番号。
	 * 番号ごとのキーに書けなかったタブ（容量不足など）の決定を、送り箱の写しから見分ける代わりの手段。
	 * 本文を読むのは、番号が同じ写しだけ。
	 *
	 * @param {Object} candidates 調べる番号 → true。
	 * @return {Object} x=1 の写しがある番号 → true。
	 */
	function otherCopiesExcluded( candidates ) {
		var found = {};
		var keys = storageKeysWithPrefix( OUTBOX_PREFIX );
		for ( var n = 0; n < keys.length; n++ ) {
			if ( keys[ n ] === outboxKey ) {
				continue;
			}
			var data = null;
			try {
				data = JSON.parse( window.localStorage.getItem( keys[ n ] ) || 'null' );
			} catch ( e2 ) {
				data = null;
			}
			var list = data && data.batches && data.batches.length ? data.batches : [];
			for ( var m = 0; m < list.length; m++ ) {
				var b = list[ m ];
				if ( b && 'string' === typeof b.id && Object.prototype.hasOwnProperty.call( candidates, b.id ) && 'string' === typeof b.body && isExcludedBody( b.body ) ) {
					found[ b.id ] = true;
				}
			}
		}
		return found;
	}

	/**
	 * 送り箱のまとまりに外す人の印（x=1）を付けて保存する。対象は、いま計測から外す人なら送り箱のすべての
	 * まとまり（ログインの前に作ったもの・ほかのタブから引き継いだものも）、そうでなければ、外す人の印が付いた
	 * 番号（番号ごとのキー・メモリの控え・ほかの送り箱の x=1 の写し）のまとまり。印を付けた番号は番号ごとのキーに書く。
	 *
	 * まとまりは捨てずに送る。サーバーは x=1 のまとまりを外し、番号を記録するので、同じまとまりのほかの写し
	 * が後で届いても重複として数えない。印は本文に残るので、送り直しの間にログアウトしても外す人のまま扱われる。
	 * 送信中・送り直し待ちでも付ける。「いま」の判定は isExcludedNow だけで行う。読み込んだ時点の判定
	 * （excludedAtLoad）はこのページ自身の操作のためのもので、ログアウトの後にほかのページから引き継いだ
	 * 匿名のまとまりには当てはめない。
	 *
	 * 読む順序: 先にほかの送り箱（番号ごとのキーに書けなかったときの代わり）を読み、その後で番号ごとのキーを読む。
	 * 引き継ぎは、番号ごとのキーを書いた後でなければ元の送り箱を消さないので、元が消えていたなら、その後に読む
	 * 番号ごとのキーに必ず印がある。
	 *
	 * @param {boolean} force   サーバーが外す人として外したと知らせたとき true（印の Cookie が無くても印を付ける）。
	 * @param {Object}  onlyIds force のときに印を付けるまとまりの番号 → true（その応答の送信のときに送り箱に
	 *                          あったまとまりだけ。応答が遅れる間に作った匿名のまとまりは外さない）。
	 */
	function markOutboxExcluded( force, onlyIds ) {
		if ( ! outbox.length ) {
			return;
		}
		retryPendingExcluded();
		var now = isExcludedNow();
		var copies = {};
		if ( ! now ) {
			var candidates = {};
			var any = false;
			for ( var c = 0; c < outbox.length; c++ ) {
				if ( ! Object.prototype.hasOwnProperty.call( ownExcluded, outbox[ c ].id ) ) {
					candidates[ outbox[ c ].id ] = true;
					any = true;
				}
			}
			if ( any ) {
				copies = otherCopiesExcluded( candidates );
			}
		}
		var changed = false;
		var adds = {};
		var added = false;
		for ( var i = 0; i < outbox.length; i++ ) {
			var b = outbox[ i ];
			var listed = isExcludedListed( b.id );
			var forced = !! force && ( ! onlyIds || Object.prototype.hasOwnProperty.call( onlyIds, b.id ) );
			if ( ! now && ! forced && ! listed && ! Object.prototype.hasOwnProperty.call( copies, b.id ) ) {
				continue;
			}
			if ( setExcluded( b ) ) {
				changed = true;
			}
			if ( ! listed ) {
				adds[ b.id ] = b.t;
				added = true;
			}
		}
		if ( added ) {
			addExcluded( adds );
		}
		if ( changed || excludedBodyUnsaved ) {
			excludedBodyUnsaved = ! saveOutbox( false );
		}
	}

	/**
	 * 送り箱の先頭のまとまりを 1 つ送る。応答を確かめ、受け取られたら消す。
	 */
	function sendNext() {
		markOutboxExcluded();
		if ( sendingId || Date.now() < retryAfter ) {
			return;
		}
		pruneExpired();
		var batch = outbox.length ? outbox[ 0 ] : null;
		if ( ! batch ) {
			return;
		}
		sendingId = batch.id;
		// 送るときに送り箱にあるまとまりの番号（「外した」応答で印を付ける範囲）。
		var sentIds = {};
		for ( var s = 0; s < outbox.length; s++ ) {
			sentIds[ outbox[ s ].id ] = true;
		}
		post( withAge( batch ), function ( status, excluded ) {
			sendingId = '';
			if ( excluded ) {
				// サーバーが外す人（IP・ログイン中）の操作として外した（記録できた 200 も、記録できなかった 500・503・
				// 429 も）。このまとまりと、送ったときに送り箱にあったほかのまとまりに外す人の印を付けて保存し、番号を
				// 一覧に足す（同じブラウザの人が外す人なので。印の Cookie を拒むブラウザでも、送り直し・ほかの写し・
				// 応答の届かなかったほかのまとまりを、ログアウトの後に送っても数えない）。応答を待つ間に作った
				// まとまりには付けない（その間にログアウトして作った匿名のまとまりを外さないため）。
				var add = {};
				add[ batch.id ] = batch.t;
				addExcluded( add );
				if ( setExcluded( batch ) && ! isSettled( status ) ) {
					excludedBodyUnsaved = ! saveOutbox( false );
				}
				markOutboxExcluded( true, sentIds );
			}
			if ( isSettled( status ) ) {
				removeBatch( batch.id, 200 === status );
				failures = 0;
				retryAfter = 0;
				// 溜まっている分を続けて送る（レート制限に当たれば 429 で間を空ける）。
				window.setTimeout( sendNext, 0 );
			} else {
				// 同じまとまりを、同じ番号で後で送り直す。失敗が続くほど間を空ける（最大 60 秒）。
				// 失敗したまとまりは送り箱の後ろに回す（受け取られないまとまり、例えば表の変換を待つ間の
				// 置き換えが、後ろのまとまりを止め続けないように。サーバーは届く順に依らずに数える）。
				if ( outbox.length > 1 && outbox[ 0 ].id === batch.id ) {
					outbox.push( outbox.shift() );
				}
				failures++;
				retryAfter = Date.now() + Math.min( 60000, FLUSH_MS * Math.pow( 2, failures - 1 ) );
			}
		} );
	}

	/**
	 * 計測をまとめて送る。
	 *
	 * @param {boolean} final ページを離れる・タブを裏に回すときの送信なら true。
	 */
	function flush( final ) {
		// 送る前にイベントを足す処理（スクロールの到達）が 150 件で送信を呼ぶので、
		// 送信の途中から送信に入り直さないようにする（呼び出しが止まらなくなるため）。
		if ( inFlush ) {
			return;
		}
		inFlush = true;
		try {
			// 外す人になっていたら、送り箱のまとまりに外す人の印を付ける（送り直し待ちの間も）。
			markOutboxExcluded();
			// 送る前に、いま見えている範囲の深さも測る（読み込みが終わる前に離れた閲覧も数える）。
			measureScrollDepth();
			pushScrollEvent();
			// 外す人の間は、送り直しを待っていても（下の分岐では seal を呼ばない）、手元に残っている操作（外す人に
			// なる前のもの）と滞在秒数を捨てる。seal は外す人の間はまとまりにせずに捨てる。残しておくと、
			// ログアウトの後のまとまりに入って数えられる。
			if ( excludedAtLoad || isExcludedNow() ) {
				for ( var drop = events.length + 2; drop > 0 && seal( true ); drop-- ) {
					// 手元が空になるまで捨てる。
				}
			}

			if ( final ) {
				sendFinal();
				return;
			}

			var waiting = 0;
			for ( var w = 0; w < outbox.length; w++ ) {
				if ( ! outbox[ w ].rep ) {
					waiting++;
				}
			}
			if ( ! waiting || ! flushedOnce ) {
				// 送り箱が空（か、置き換えのまとまりだけ）なら、手元の分をまとめる。置き換えは表の変換を
				// 待つ間 503 で残ることがあるので、それだけが残っていても手元の分は止めない（失敗した
				// まとまりは後ろに回るので、こちらが先に送られる）。ページビューは、送り箱に前のページの
				// 分が残っていても、この閲覧の日時で数えられるように先にまとめる。
				seal( false );
			} else {
				// 送り直しの間も、満杯になった分はまとまりにして保存する（手元で溜めすぎない）。
				// 送り箱が目安（OUTBOX_MAX）に達したら手元に置いておき、離脱時にまとめる。
				while ( events.length >= MAX_EVENTS && outbox.length < OUTBOX_MAX && seal( false ) ) {
					// 満杯のまとまりを作り続ける。
				}
			}
			sendNext();
		} finally {
			inFlush = false;
		}
	}

	/**
	 * ページを離れる・タブを裏に回すときの送信。
	 *
	 * 手元の分をすべてまとまりにして保存してから、sendBeacon で送る。sendBeacon は応答を
	 * 返さないので、送ったまとまりも送り箱に残す（後の送信でもう一度送り、サーバーは番号で
	 * 二重に数えない）。送信中（fetch の応答待ち）のまとまりも送る（その fetch が届くとは限らない）。
	 * 保存できていないまとまりから先に送る（保存できたものは、戻ったあとか次に開いたページで送れる）。
	 * 届いたかどうかはブラウザからは分からないので、ここでは数えない。保存できていない自分の
	 * まとまりは Cookie に番号と件数が残っていて（saveOutbox → writeOwnLost）、ほかのページが
	 * サーバーに知らせ、サーバーが届いたかを番号で照らす。
	 */
	function sendFinal() {
		// 外す人なら、送り箱のまとまりに外す人の印を付けてから送る（markOutboxExcluded）。手元の分は
		// seal が外す人の間はまとまりにせずに捨てる。
		markOutboxExcluded();
		// 手元が空になるまで、まとまりを作る（1 回で 1 件以上を移すので、件数の分で終わる）。
		for ( var guard = events.length + 2; guard > 0 && seal( true ); guard-- ) {
			// 続ける。
		}
		pruneExpired();
		var order = outbox.filter( function ( b ) {
			return ! b.persisted;
		} ).concat( outbox.filter( function ( b ) {
			return b.persisted;
		} ) );
		for ( var i = 0; i < order.length; i++ ) {
			var batch = order[ i ];
			if ( batch.beaconed ) {
				continue;
			}
			if ( ! beacon( withAge( batch ) ) ) {
				break;
			}
			batch.beaconed = true;
		}
		saveOutbox( pageUnloading );
	}

	/**
	 * 本文を POST し、HTTP の状態（通信の失敗・中断は 0）を done に渡す。
	 *
	 * fetch の keepalive を使うので、送信中にページを離れても送信は止まらない
	 * （MDN RequestInit「keepalive: When set to true, the browser will not abort the
	 * associated request if the page that initiated it is unloaded before the request is
	 * complete.」）。15 秒たっても応答が無ければ中断して失敗として扱い、同じまとまりを
	 * 同じ番号で送り直す（サーバーが保存を終えていても、番号で二重に数えない）。
	 * keepalive の本文は 64KiB までなので（MDN RequestInit「The body size for keepalive
	 * requests is limited to 64 kibibytes」）、それより大きい本文は keepalive を付けずに送る
	 * （各項目の長さを切ってあるので、ふつうは起きない）。
	 */
	function post( body, done ) {
		var settled = false;
		var timer = 0;
		// サーバーが外す人の操作として外したことを知らせる応答のヘッダー（rest-api.php）。
		// 公式: MDN Headers.get「returns a byte string of all the values of a header within a Headers object
		// with a given name」、MDN XMLHttpRequest.getResponseHeader「returns the string containing the text of
		// a particular header's value」（同じオリジンなので読める）。
		var EXCLUDED_HEADER = 'X-Kashiwazaki-SEO-Heatmap-Excluded';
		function settle( status, excluded ) {
			if ( settled ) {
				return;
			}
			settled = true;
			window.clearTimeout( timer );
			done( status, !! excluded );
		}

		if ( window.fetch ) {
			var controller = window.AbortController ? new AbortController() : null;
			timer = window.setTimeout( function () {
				if ( controller ) {
					controller.abort();
				}
				settle( 0 );
			}, 15000 );
			try {
				window.fetch( CONFIG.restUrl, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: body,
					credentials: 'same-origin',
					keepalive: byteLength( body ) <= 60000,
					signal: controller ? controller.signal : undefined
				} ).then( function ( res ) {
					settle( res ? res.status : 0, res && res.headers && '1' === res.headers.get( EXCLUDED_HEADER ) );
				}, function () {
					settle( 0 );
				} );
			} catch ( e ) {
				settle( 0 );
			}
			return;
		}

		try {
			var xhr = new XMLHttpRequest();
			xhr.open( 'POST', CONFIG.restUrl, true );
			xhr.setRequestHeader( 'Content-Type', 'application/json' );
			xhr.timeout = 15000;
			xhr.onreadystatechange = function () {
				if ( 4 === xhr.readyState ) {
					settle( xhr.status, '1' === xhr.getResponseHeader( EXCLUDED_HEADER ) );
				}
			};
			xhr.onerror = function () {
				settle( 0 );
			};
			xhr.ontimeout = function () {
				settle( 0 );
			};
			xhr.send( body );
		} catch ( e2 ) {
			settle( 0 );
		}
	}

	/**
	 * 離脱時の送信。sendBeacon が使えないときだけ keepalive の fetch を使う。
	 *
	 * sendBeacon が false を返したとき（送信待ちの合計が 64KiB を超える）は fetch に回さない。
	 * keepalive の fetch も同じ枠を使うので拒否される（Chromium で確かめた）。
	 * どちらでも、送ったまとまりは送り箱に残る（応答を確かめられないため）。
	 *
	 * @return {boolean} ブラウザが送信を受け付けたら true（届いたとは限らないので、同じ離脱の中で
	 *                   送り直さないためだけに使い、届いたかの判断には使わない）。
	 */
	function beacon( body ) {
		if ( navigator.sendBeacon ) {
			try {
				return navigator.sendBeacon( CONFIG.restUrl, new Blob( [ body ], { type: 'application/json' } ) );
			} catch ( e ) {
				// 下の fetch に回す。
			}
		}
		if ( window.fetch ) {
			try {
				window.fetch( CONFIG.restUrl, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: body,
					credentials: 'same-origin',
					keepalive: true
				} ).catch( function () {} );
				return true;
			} catch ( e2 ) {
				return false;
			}
		}
		return false;
	}

	/**
	 * 前回からの経過時間を、表示されている間の分だけ滞在時間に足す。
	 *
	 * 裏のタブでもタイマーは止まらず間引かれるだけなので（MDN Page Visibility API
	 * 「Timers such as setTimeout() are throttled in background/inactive tabs」）、
	 * 見ていない時間まで足すと平均滞在時間が膨らむ。
	 */
	function elapsedSeconds() {
		var now = Date.now();
		// 計測から外す人の間の滞在秒数は数えない（手元の操作と同じく、ログアウトの後のまとまりに入れない）。
		if ( pageVisible && ! ( excludedAtLoad || isExcludedNow() ) ) {
			secondsSinceFlush += ( now - lastActiveAt ) / 1000;
		}
		lastActiveAt = now;
	}

	/* ------------------------------------------------------------------ */
	/* 初期化                                                              */
	/* ------------------------------------------------------------------ */

	function init() {
		document.addEventListener( 'click', handleClick, true );
		window.addEventListener( 'scroll', handleScroll, { passive: true } );
		// URL の # の移動や履歴の移動も、デッドクリックの判定では「画面の反応」に数える。
		window.addEventListener( 'hashchange', function () {
			lastNavAt = Date.now();
		} );
		window.addEventListener( 'popstate', function () {
			lastNavAt = Date.now();
		} );

		if ( TRACK.move ) {
			window.addEventListener( 'mousemove', handleMouseMove, { passive: true } );
		}

		// 読み込んだ時点で見えている範囲も到達として記録する。読み込みが終わる前に
		// 離れる閲覧もあるので、すぐに測り、画像などで高さが変わる読み込みの完了でも測り直す。
		measureScrollDepth();
		if ( 'complete' !== document.readyState ) {
			window.addEventListener( 'load', measureScrollDepth );
			// 読み込みの途中に仮の到達率を送っていたら、読み込みの後の高さで置き換える
			// （隠れている間なら、そのまま sendBeacon で送る。隠れたまま破棄されても正しい値が残る）。
			// load の前に readyState は complete になる（MDN Document.readyState「complete ... The state
			// indicates that the load event is about to fire」）。
			window.addEventListener( 'load', function () {
				if ( provChain.length ) {
					flush( ! pageVisible );
				}
			} );
		}
		window.addEventListener( 'resize', measureScrollDepth, { passive: true } );

		// 前のページが送りきれずに残したまとまりを引き継ぐ。
		adoptOutboxes();

		// 定期的にまとめて送る。離れたページの送り箱も、ここで引き継ぐ。
		setInterval( function () {
			elapsedSeconds();
			adoptOutboxes();
			flush( false );
		}, FLUSH_MS );

		// 画面を離れるときは残りを確実に送る。
		// 読み込みの途中なら、到達率はその時点の高さの仮の値として送る（pushScrollEvent）。
		window.addEventListener( 'pagehide', function () {
			elapsedSeconds();
			pageUnloading = true;
			flush( true );
		} );

		// 「戻る」で復元されたときは計測を続ける。
		window.addEventListener( 'pageshow', function () {
			pageUnloading = false;
			pageVisible = 'hidden' !== document.visibilityState;
			lastActiveAt = Date.now();
			// 「戻る」で復元されたページの送り箱を、もう一度このページのものとして書く
			// （ほかのページが引き継いで消していても、送り直しはサーバーが二重に数えない）。
			saveOutbox( false );
		} );

		document.addEventListener( 'visibilitychange', function () {
			if ( 'hidden' === document.visibilityState ) {
				// 表示されていた時間までを数えてから、裏に回った扱いにする。
				elapsedSeconds();
				pageVisible = false;
				// 読み込みの途中なら、到達率はその時点の高さの仮の値として送る（pushScrollEvent）。
				flush( true );
			} else {
				lastActiveAt = Date.now();
				pageVisible = true;
				// 隠れている間に読み込みが終わっていて、仮の到達率が残っていれば、すぐに置き換える。
				if ( provChain.length ) {
					flush( false );
				}
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
