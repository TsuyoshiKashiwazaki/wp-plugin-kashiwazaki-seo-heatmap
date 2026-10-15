<?php
/**
 * 共通ヘルパー関数。
 *
 * @package Kashiwazaki_SEO_Heatmap
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 設定の既定値。
 *
 * @return array 既定値の連想配列。
 */
function kashiwazaki_seo_heatmap_default_settings() {
	$defaults = array(
		// 計測対象にする投稿タイプ（チェックしたものだけを計測する）。
		'enabled_post_types'      => array( 'post', 'page' ),
		// 計測の種類。
		'track_clicks'            => 1,
		'track_scroll'            => 1,
		'track_mouse_move'        => 0,
		'track_rage_clicks'       => 1,
		'track_dead_clicks'       => 1,
		'track_outbound'          => 1,
		// 除外条件。
		'exclude_logged_in'       => 1,
		'exclude_roles'           => array( 'administrator', 'editor' ),
		'exclude_ips'             => '',
		'exclude_url_patterns'    => '',
		// プライバシー。
		'honor_do_not_track'      => 1,
		'cookie_lifetime_days'    => 365,
		// レイジクリック判定（同じ場所を短時間に連打）。
		'rage_click_count'        => 3,
		'rage_click_window_ms'    => 1000,
		'rage_click_radius_px'    => 30,
		// デッドクリック判定（押しても何も起きないクリック）。
		'dead_click_window_ms'    => 1000,
		// マウスムーブのサンプリング間隔（ミリ秒）。
		'mouse_move_interval_ms'  => 250,
		'mouse_move_max_events'   => 300,
		// 送信。
		'flush_interval_ms'       => 5000,
		'sample_rate'             => 100,
		// データ保持。
		'retention_days'          => KASHIWAZAKI_SEO_HEATMAP_DEFAULT_RETENTION_DAYS,
		'enable_auto_cleanup'     => 1,
		// プラグインを削除（アンインストール）したときに計測データも消すか。
		'delete_data_on_uninstall' => 1,
		// ヒートマップ表示。
		'default_heat_radius'     => 28,
		'default_heat_opacity'    => 60,
		'heat_color_scheme'       => 'thermal',
	);

	/**
	 * 設定の既定値をフィルタで変更できるようにする。
	 *
	 * @param array $defaults 既定値。
	 */
	return apply_filters( 'kashiwazaki_seo_heatmap_default_settings', $defaults );
}

/**
 * 保存済み設定を既定値とマージして返す。
 *
 * @return array 設定。
 */
function kashiwazaki_seo_heatmap_get_settings() {
	$saved = get_option( KASHIWAZAKI_SEO_HEATMAP_SETTINGS_OPTION, array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}

	$settings = wp_parse_args( $saved, kashiwazaki_seo_heatmap_default_settings() );

	/**
	 * 実行時に読み込む設定をフィルタで変更できるようにする。
	 *
	 * @param array $settings 設定。
	 */
	return apply_filters( 'kashiwazaki_seo_heatmap_settings', $settings );
}

/**
 * 設定を保存する。
 *
 * @param array $settings 保存する設定。
 * @return array 保存後の設定。
 */
function kashiwazaki_seo_heatmap_update_settings( $settings ) {
	$settings = wp_parse_args( (array) $settings, kashiwazaki_seo_heatmap_default_settings() );
	update_option( KASHIWAZAKI_SEO_HEATMAP_SETTINGS_OPTION, $settings );
	return $settings;
}

/**
 * 計測対象にできる投稿タイプ（公開されているもの）を返す。
 *
 * @return array slug => ラベル。
 */
function kashiwazaki_seo_heatmap_get_trackable_post_types() {
	$types = get_post_types(
		array(
			'public'  => true,
			'show_ui' => true,
		),
		'objects'
	);

	$result = array();
	foreach ( $types as $type ) {
		// attachment はアップロード管理なので除外する。
		if ( 'attachment' === $type->name ) {
			continue;
		}
		$result[ $type->name ] = $type->labels->name;
	}

	return $result;
}

/**
 * すべての投稿タイプ（公開でないものも含む）を返す。管理画面の絞り込みに使う。
 *
 * @return array slug => ラベル。
 */
function kashiwazaki_seo_heatmap_get_all_post_types() {
	$types = get_post_types( array( 'show_ui' => true ), 'objects' );

	$result = array();
	foreach ( $types as $type ) {
		if ( 'attachment' === $type->name ) {
			continue;
		}
		$result[ $type->name ] = $type->labels->name;
	}

	return $result;
}

/**
 * 送られてきた値を安全に文字列へ直す。
 *
 * 配列やオブジェクトが来たときに (string) を当てると
 * 「Array to string conversion」の警告が出るため、いったんここで受ける。
 *
 * @param mixed $value 元の値。
 * @return string 文字列（文字列にできない値なら空文字）。
 */
function kashiwazaki_seo_heatmap_to_string( $value ) {
	if ( is_string( $value ) ) {
		return $value;
	}
	if ( is_int( $value ) || is_float( $value ) ) {
		return (string) $value;
	}
	return '';
}

/**
 * 送られてきた値を有限の数値（float）に直す。
 *
 * PHP では「1e400」のように桁あふれする数値文字列を (float) で変換すると INF に
 * なる。INF や NAN をそのまま wpdb に渡すと、prepare() の %f は sprintf( '%F' ) に
 * 化けて「INF」という文字列として SQL に埋め込まれ、MySQL が
 * 「Unknown column 'INF' in 'VALUES'」で INSERT ごと拒否する。すると同じ送信に
 * 含まれていた計測データまでまとめて捨てられてしまう。
 *
 * 公式: is_finite() —「Returns whether the given num is a finite float.
 * A finite float is neither NAN ( is_nan() ), nor infinite ( is_infinite() ).」
 * https://www.php.net/manual/en/function.is-finite.php
 *
 * @param mixed $value 元の値。
 * @return float 有限の数値（無限大・非数のときは 0.0）。
 */
function kashiwazaki_seo_heatmap_to_float( $value ) {
	if ( ! is_scalar( $value ) ) {
		return 0.0;
	}

	$number = (float) $value;
	if ( ! is_finite( $number ) ) {
		return 0.0;
	}

	return $number;
}

/**
 * 文字数で文字列を切り詰める（マルチバイト文字の途中では切らない）。
 *
 * PHP の substr() はバイト単位で切るため、日本語のように 1 文字が複数バイトの
 * 文字列では文字の途中で切れて壊れた UTF-8 ができる。それを MySQL に渡すと
 * 「Incorrect string value」で INSERT そのものが失敗し、同じ送信に含まれていた
 * 他の計測データまでまとめて捨てられてしまう（クリックが丸ごと記録されなくなる）。
 *
 * 公式: mb_substr() —「文字数に基づきマルチバイト対応の substr() 処理を行います。」
 * https://www.php.net/manual/ja/function.mb-substr.php
 *
 * mb_substr() は WordPress が必ず用意する（mbstring が無い環境でも
 * wp-includes/compat.php が同名の関数を定義する）ので、ここでは常に使える。
 *
 * @param string $text   元の文字列。
 * @param int    $length 残す文字数。
 * @return string 切り詰めた文字列。
 */
function kashiwazaki_seo_heatmap_truncate( $text, $length ) {
	$text   = kashiwazaki_seo_heatmap_to_string( $text );
	$length = max( 0, (int) $length );

	return mb_substr( $text, 0, $length, 'UTF-8' );
}

/**
 * クライアントが送ってきた device 文字列を検証する。
 *
 * @param string $device 端末文字列。
 * @return string 検証済みの端末文字列。
 */
function kashiwazaki_seo_heatmap_sanitize_device( $device ) {
	$device = sanitize_key( kashiwazaki_seo_heatmap_to_string( $device ) );
	if ( in_array( $device, array( 'desktop', 'tablet', 'mobile' ), true ) ) {
		return $device;
	}
	return 'desktop';
}

/**
 * 送信されたイベント種別を検証する。
 *
 * @param string $type 種別。
 * @return string 検証済みの種別（不正なら空文字）。
 */
function kashiwazaki_seo_heatmap_sanitize_event_type( $type ) {
	$allowed = array( 'click', 'scroll', 'move', 'rage', 'dead', 'outbound', 'pageview' );
	$type    = sanitize_key( kashiwazaki_seo_heatmap_to_string( $type ) );
	return in_array( $type, $allowed, true ) ? $type : '';
}

/**
 * 要素を一意に表すキー（ハッシュ）を作る。
 *
 * 同じセレクタでも、ページ内の出現位置（branch）と祖先の見出しテキストで
 * 別要素として区別する。レイアウト変更にある程度耐えられるようにするため。
 *
 * @param string $selector      CSS セレクタ。
 * @param int    $element_index 文書内の出現位置。
 * @param string $branch_key    祖先の見出しテキスト等の手がかり。
 * @return string 32 文字のハッシュ。
 */
function kashiwazaki_seo_heatmap_element_key( $selector, $element_index = 0, $branch_key = '' ) {
	$selector   = kashiwazaki_seo_heatmap_to_string( $selector );
	$branch_key = kashiwazaki_seo_heatmap_to_string( $branch_key );

	$raw = strtolower( trim( $selector ) ) . '|' . (int) $element_index . '|' . strtolower( trim( $branch_key ) );
	return md5( $raw );
}

/**
 * ページを表すキーを作る（投稿 ID が取れない URL 用）。
 *
 * @param string $url ページ URL。
 * @return string 32 文字のハッシュ。
 */
function kashiwazaki_seo_heatmap_page_key( $url ) {
	return md5( strtolower( trim( kashiwazaki_seo_heatmap_to_string( $url ) ) ) );
}

/**
 * URL から比較用のパス（クエリ・フラグメント除去）を取り出す。
 *
 * @param string $url URL。
 * @return string パス。
 */
function kashiwazaki_seo_heatmap_normalize_url_path( $url ) {
	$url = kashiwazaki_seo_heatmap_to_string( $url );
	$path = wp_parse_url( $url, PHP_URL_PATH );
	if ( ! is_string( $path ) || '' === $path ) {
		$path = '/';
	}
	// 先頭の連続した「/」は 1 つにまとめる。「//example.com/x」のままだと、
	// ブラウザはプロトコル相対の URL（別のサイト）として読むため。
	return (string) preg_replace( '#^/+#', '/', $path );
}

/**
 * 投稿を特定するクエリの名前（p・page_id などと、公開の投稿タイプのクエリの名前）。
 *
 * ページを見分ける URL を作るとき（normalize_page_url）と、計測スクリプトが長い URL を
 * 短くするときに残す名前（tracker.php で渡す）の両方に使う。
 *
 * @return string[] クエリの名前。
 */
function kashiwazaki_seo_heatmap_page_query_keys() {
	$keys = array( 'p', 'page_id', 'attachment_id', 'post_type', 'name', 'pagename' );
	foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
		if ( ! empty( $type->query_var ) && is_string( $type->query_var ) ) {
			$keys[] = $type->query_var;
		}
	}
	return array_values( array_unique( $keys ) );
}

/**
 * ページを見分けるための URL（パス＋投稿を特定するクエリ）を作る。
 *
 * パーマリンク設定が「基本」のサイト（/?p=123、/?page_id=45）や、書き換えの規則を
 * 持たない投稿タイプでは、パスだけだとどの投稿も「/」になり、別のページの計測が
 * 1 つに混ざる。そこで、投稿を特定するクエリ（p・page_id など）だけはパスに残す。
 * きれいなパーマリンク（クエリなし）のページは、これまでと同じパスだけになる。
 *
 * @param string $url URL。
 * @return string パス（必要なら「?キー=値」付き）。
 */
function kashiwazaki_seo_heatmap_normalize_page_url( $url ) {
	$url  = kashiwazaki_seo_heatmap_to_string( $url );
	$path = kashiwazaki_seo_heatmap_normalize_url_path( $url );

	$query = wp_parse_url( $url, PHP_URL_QUERY );
	if ( ! is_string( $query ) || '' === $query ) {
		return $path;
	}

	$args = array();
	wp_parse_str( $query, $args );

	$kept = array();
	foreach ( kashiwazaki_seo_heatmap_page_query_keys() as $key ) {
		if ( isset( $args[ $key ] ) && is_scalar( $args[ $key ] ) && '' !== (string) $args[ $key ] ) {
			$kept[ $key ] = (string) $args[ $key ];
		}
	}
	if ( empty( $kept ) ) {
		return $path;
	}

	ksort( $kept );
	return $path . '?' . http_build_query( $kept, '', '&', PHP_QUERY_RFC3986 );
}

/**
 * 管理画面で使う「解析対象の期間」を検証する。
 *
 * @param string $days 日数（数値文字列）。
 * @return int 1〜3650 に丸めた日数。
 */
function kashiwazaki_seo_heatmap_sanitize_days( $days ) {
	$days = absint( $days );
	if ( $days < 1 ) {
		$days = 30;
	}
	if ( $days > 3650 ) {
		$days = 3650;
	}
	return $days;
}

/**
 * N 日前の「その日の 00:00:00」を表す日付文字列を返す（サイトのタイムゾーン基準）。
 *
 * @param int $days 何日前か。0 なら今日。
 * @return string Y-m-d。
 */
function kashiwazaki_seo_heatmap_date_days_ago( $days ) {
	// サイトのタイムゾーン（30 分単位の時差・夏時間を含む）の今日から、暦の日数で戻る。
	// 公式: wp_timezone()「Retrieves the timezone of the site as a DateTimeZone object.」、
	// PHP の日付の相対表記（DateTimeImmutable::modify の『-N days』）。
	$today = new DateTimeImmutable( 'now', wp_timezone() );
	return $today->modify( '-' . absint( $days ) . ' days' )->format( 'Y-m-d' );
}

/**
 * UNIX 時刻を、集計の日付（サイトのタイムゾーン基準の Y-m-d）にする。
 *
 * 生データの日時（wp_date）・管理画面の期間（kashiwazaki_seo_heatmap_date_days_ago）と
 * 同じタイムゾーンで日付を決める（30 分単位の時差・夏時間でも食い違わない）。
 * 公式: wp_date()「this function accepts a true Unix timestamp, not summed with timezone offset」。
 *
 * @param int $timestamp UNIX 時刻。
 * @return string Y-m-d。
 */
function kashiwazaki_seo_heatmap_local_date( $timestamp ) {
	return wp_date( 'Y-m-d', (int) $timestamp );
}

/**
 * 現在ログインしているユーザーが計測から除外されるべきか判定する。
 *
 * @return bool 除外するなら true。
 */
function kashiwazaki_seo_heatmap_is_excluded_user() {
	if ( ! is_user_logged_in() ) {
		return false;
	}
	return kashiwazaki_seo_heatmap_is_excluded_user_id( get_current_user_id() );
}

/**
 * 指定したユーザーが計測から除外されるべきか判定する。
 *
 * @param int $user_id ユーザー ID（0 ならログインしていない）。
 * @return bool 除外するなら true。
 */
function kashiwazaki_seo_heatmap_is_excluded_user_id( $user_id ) {
	$user_id = (int) $user_id;
	if ( $user_id <= 0 ) {
		return false;
	}

	$settings = kashiwazaki_seo_heatmap_get_settings();
	if ( ! empty( $settings['exclude_logged_in'] ) ) {
		return true;
	}

	$exclude_roles = isset( $settings['exclude_roles'] ) ? (array) $settings['exclude_roles'] : array();
	if ( empty( $exclude_roles ) ) {
		return false;
	}

	$user = get_userdata( $user_id );
	if ( ! $user || ! $user->exists() ) {
		return false;
	}

	foreach ( $exclude_roles as $role ) {
		if ( in_array( $role, (array) $user->roles, true ) ) {
			return true;
		}
	}

	return false;
}

/**
 * 計測の受け口（REST）に届いた送信が、ログイン中のユーザーのものか調べる。
 *
 * 計測スクリプトは REST の nonce を送らないので、REST の中では
 * 「ログインしていない」扱いになる（WordPress REST API ハンドブック:
 * 「If no nonce is provided the API will set the current user to 0」）。
 * そこで、ログインの Cookie を wp_validate_auth_cookie() で直接確かめる
 * （scheme に 'logged_in' を指定し、正しければユーザー ID が返る）。
 * 除外の判定にだけ使い、権限の判定には使わない。
 *
 * @return int ユーザー ID（ログインしていなければ 0）。
 */
function kashiwazaki_seo_heatmap_get_cookie_user_id() {
	$user_id = wp_validate_auth_cookie( '', 'logged_in' );
	return $user_id ? (int) $user_id : 0;
}

/**
 * ログイン中で計測から外す人に付ける Cookie の名前（サイトごとに別の名前）。
 *
 * 同じホストの別の WordPress（サブディレクトリのマルチサイト・別のインストール）と
 * 取り違えないように、サイトの URL の指紋を名前に入れる。
 *
 * @return string Cookie の名前。
 */
function kashiwazaki_seo_heatmap_exclude_cookie_name() {
	return KASHIWAZAKI_SEO_HEATMAP_EXCLUDE_COOKIE_PREFIX . substr( md5( home_url( '/' ) ), 0, 8 );
}

/**
 * 計測から外す人の印の Cookie を付ける・消す。
 *
 * ログインの Cookie は HttpOnly で計測スクリプトから読めないので、外す人にだけ、
 * スクリプトから読める別の Cookie を付ける（httponly を付けない）。値はログインの期限
 * （UNIX 秒）で、計測スクリプトは期限を過ぎた印を使わない。expires を付けない（セッションの
 * Cookie）。ブラウザのセッションの復元では残ることがあるが（MDN Set-Cookie「Session cookies
 * will also be restored, as if the browser was never closed.」）、期限を過ぎた値は使わないので、
 * ログインの期限より長く外し続けることはない。計測スクリプトは、この印があるときは操作をまとまりにしない。
 * 公式: PHP setcookie「httponly: When true the cookie will be made accessible only through
 * the HTTP protocol」（付けなければスクリプトから読める）。
 *
 * @param int $until 期限の UNIX 秒（0 なら消す）。
 * @return void
 */
function kashiwazaki_seo_heatmap_set_exclude_cookie( $until ) {
	if ( headers_sent() ) {
		return;
	}
	$until = (int) $until;
	setcookie(
		kashiwazaki_seo_heatmap_exclude_cookie_name(),
		$until > 0 ? (string) $until : '',
		array(
			'expires'  => $until > 0 ? 0 : time() - YEAR_IN_SECONDS,
			'path'     => COOKIEPATH ? COOKIEPATH : '/',
			'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
			'secure'   => is_ssl(),
			'httponly' => false,
			'samesite' => 'Lax',
		)
	);
}

/**
 * いまのリクエストのログインの状態に、印の Cookie を合わせる（init で呼ぶ）。
 *
 * 外す人なのに印が無ければ付け、外す人でないのに印があれば消す（設定を変えたとき・
 * ログインの期限が切れたとき・除外する IP から外れたとき）。ログインの Cookie も印も無い
 * リクエストでは何もしない。除外する IP からのリクエストでは、印を消さない（付けるのは計測の
 * REST の応答だけ。mark_excluded_ip を参照）。
 *
 * @return void
 */
function kashiwazaki_seo_heatmap_sync_exclude_cookie() {
	if ( wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
		return;
	}
	$name       = kashiwazaki_seo_heatmap_exclude_cookie_name();
	$has_mark   = isset( $_COOKIE[ $name ] );
	$has_login  = defined( 'LOGGED_IN_COOKIE' ) && ! empty( $_COOKIE[ LOGGED_IN_COOKIE ] );
	if ( ! $has_mark && ! $has_login ) {
		return;
	}

	$until   = 0;
	$user_id = kashiwazaki_seo_heatmap_get_cookie_user_id();
	if ( $user_id > 0 && kashiwazaki_seo_heatmap_is_excluded_user_id( $user_id ) ) {
		$parts = wp_parse_auth_cookie( '', 'logged_in' );
		$until = ( is_array( $parts ) && ! empty( $parts['expiration'] ) ) ? (int) $parts['expiration'] : time() + 2 * DAY_IN_SECONDS;
	}

	$current = $has_mark ? (int) sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) ) : 0;
	if ( $until > 0 && $current !== $until ) {
		kashiwazaki_seo_heatmap_set_exclude_cookie( $until );
	} elseif ( $until <= 0 && $has_mark && ! kashiwazaki_seo_heatmap_is_excluded_ip() ) {
		kashiwazaki_seo_heatmap_set_exclude_cookie( 0 );
	}
}

/**
 * 除外する IP から計測が届いたときに、外す人の印の Cookie を付ける（計測の REST の応答だけで呼ぶ）。
 *
 * 計測スクリプトは印がある間の操作をまとまりにせず、送り箱に残っているまとまりにも外す人の印（x=1）を
 * 付けるので、除外する IP の間に作ったまとまりが、IP が変わった後に届いても数えない。ページの応答では
 * 付けない（ページキャッシュがその応答を保存すると、ほかの訪問者にも印が配られるため。REST の POST の
 * 応答はページキャッシュに入らない）。期限は 1 時間ごとの区切りの 2 時間先にする（届くたびに付け直さない
 * ため）。ログインの印のほうが長ければ、そのままにする。除外する IP から外れたら、次のリクエストの
 * sync_exclude_cookie が消す（ログインの印が無いとき）。
 *
 * @return void
 */
function kashiwazaki_seo_heatmap_mark_excluded_ip() {
	$name    = kashiwazaki_seo_heatmap_exclude_cookie_name();
	$current = isset( $_COOKIE[ $name ] ) ? (int) sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) ) : 0;
	$until   = ( (int) floor( time() / HOUR_IN_SECONDS ) + 2 ) * HOUR_IN_SECONDS;
	if ( $current < $until ) {
		kashiwazaki_seo_heatmap_set_exclude_cookie( $until );
	}
}

/**
 * ログインしたときに印を付ける（ログインの応答の時点で、外す人なら付ける）。
 *
 * 公式: set_logged_in_cookie「Fires immediately before the logged-in authentication cookie is set.」
 * 引数の $expiration は「The time when the logged-in authentication cookie expires」。
 *
 * @param string $logged_in_cookie ログインの Cookie の値。
 * @param int    $expire           猶予の期限。
 * @param int    $expiration       ログインの期限（UNIX 秒）。
 * @param int    $user_id          ユーザー ID。
 * @return void
 */
function kashiwazaki_seo_heatmap_mark_on_login( $logged_in_cookie, $expire, $expiration, $user_id ) {
	if ( kashiwazaki_seo_heatmap_is_excluded_user_id( (int) $user_id ) ) {
		kashiwazaki_seo_heatmap_set_exclude_cookie( (int) $expiration );
	} elseif ( isset( $_COOKIE[ kashiwazaki_seo_heatmap_exclude_cookie_name() ] ) ) {
		kashiwazaki_seo_heatmap_set_exclude_cookie( 0 );
	}
}

/**
 * ログアウトしたときに印を消す。
 *
 * 公式: clear_auth_cookie「Fires just before the authentication cookies are cleared.」
 *
 * @return void
 */
function kashiwazaki_seo_heatmap_unmark_on_logout() {
	kashiwazaki_seo_heatmap_set_exclude_cookie( 0 );
}

/**
 * パスが「除外する URL パターン」に当たるか判定する。
 *
 * @param string $path ページのパス。
 * @return bool 除外するなら true。
 */
function kashiwazaki_seo_heatmap_is_excluded_path( $path ) {
	$settings = kashiwazaki_seo_heatmap_get_settings();
	$patterns = isset( $settings['exclude_url_patterns'] ) ? (string) $settings['exclude_url_patterns'] : '';
	if ( '' === trim( $patterns ) ) {
		return false;
	}

	$path = kashiwazaki_seo_heatmap_to_string( $path );
	foreach ( preg_split( '/[\r\n]+/', $patterns ) as $pattern ) {
		$pattern = trim( $pattern );
		if ( '' === $pattern ) {
			continue;
		}
		if ( false !== strpos( $pattern, '*' ) ) {
			$regex = '#^' . str_replace( '\*', '.*', preg_quote( $pattern, '#' ) ) . '#';
			if ( preg_match( $regex, $path ) ) {
				return true;
			}
		} elseif ( false !== strpos( $path, $pattern ) ) {
			return true;
		}
	}

	return false;
}

/**
 * 投稿が「計測する投稿タイプ」に入っているか判定する。
 *
 * @param int $post_id 投稿 ID。
 * @return bool 計測するなら true。
 */
function kashiwazaki_seo_heatmap_is_trackable_post( $post_id ) {
	$type = get_post_type( (int) $post_id );
	if ( ! $type ) {
		return false;
	}
	$settings   = kashiwazaki_seo_heatmap_get_settings();
	$post_types = isset( $settings['enabled_post_types'] ) ? array_map( 'sanitize_key', (array) $settings['enabled_post_types'] ) : array();
	return in_array( $type, $post_types, true );
}

/**
 * 現在のリクエストが計測対象の投稿タイプか判定する。
 *
 * @return bool 計測するなら true。
 */
function kashiwazaki_seo_heatmap_is_trackable_request() {
	$settings    = kashiwazaki_seo_heatmap_get_settings();
	$post_types  = isset( $settings['enabled_post_types'] ) ? (array) $settings['enabled_post_types'] : array();
	$post_types  = array_map( 'sanitize_key', $post_types );
	$post_types  = array_filter( $post_types );
	$post_types  = array_values( array_unique( $post_types ) );
	$current_type = kashiwazaki_seo_heatmap_get_current_post_type();

	if ( empty( $post_types ) || ! $current_type ) {
		return false;
	}

	return in_array( $current_type, $post_types, true );
}

/**
 * 現在表示中の投稿タイプを返す（アーカイブ等では空文字）。
 *
 * @return string 投稿タイプの slug。
 */
function kashiwazaki_seo_heatmap_get_current_post_type() {
	if ( is_singular() ) {
		$type = get_post_type();
		return $type ? $type : '';
	}
	return '';
}

/**
 * 現在表示中の投稿 ID を返す（取得できなければ 0）。
 *
 * @return int 投稿 ID。
 */
function kashiwazaki_seo_heatmap_get_current_post_id() {
	if ( is_singular() ) {
		return (int) get_queried_object_id();
	}
	return 0;
}

/**
 * 送信元 IP を除外リストと突き合わせる。
 *
 * @return bool 除外するなら true。
 */
function kashiwazaki_seo_heatmap_is_excluded_ip() {
	$settings = kashiwazaki_seo_heatmap_get_settings();
	$list     = isset( $settings['exclude_ips'] ) ? (string) $settings['exclude_ips'] : '';
	if ( '' === trim( $list ) ) {
		return false;
	}

	// CDN・逆プロキシの後ろでは REMOTE_ADDR が中継の IP になるので、転送ヘッダの
	// IP とも照らし合わせる。除外は「計測しない」だけなので、偽のヘッダで除外されても
	// 困るのは送った本人だけで、他の人のデータには影響しない。
	$ips = kashiwazaki_seo_heatmap_get_candidate_ips();
	if ( empty( $ips ) ) {
		return false;
	}

	$patterns = preg_split( '/[\r\n,]+/', $list );
	foreach ( (array) $patterns as $pattern ) {
		$pattern = trim( $pattern );
		if ( '' === $pattern ) {
			continue;
		}
		foreach ( $ips as $ip ) {
			// 「*」を含む場合はワイルドカードとして前方一致などに使う（例: 203.0.113.*）。
			// 含まない場合は IP アドレスの完全一致だけを見る。
			if ( false !== strpos( $pattern, '*' ) ) {
				$regex = '#^' . str_replace( '\*', '.*', preg_quote( $pattern, '#' ) ) . '$#';
				if ( preg_match( $regex, $ip ) ) {
					return true;
				}
			} elseif ( $pattern === $ip ) {
				return true;
			}
		}
	}

	return false;
}

/**
 * 転送ヘッダから訪問者の IP を取り出す（信用はしない。除外とレート制限の区別にだけ使う）。
 *
 * Cloudflare などの CDN や逆プロキシの後ろでは、REMOTE_ADDR は中継の IP になり、
 * 訪問者の IP は CF-Connecting-IP・True-Client-IP・X-Real-IP や
 * X-Forwarded-For の先頭（「訪問者, 中継…」の順）に入る。
 *
 * @return string 訪問者の IP（見つからなければ空文字）。
 */
function kashiwazaki_seo_heatmap_get_forwarded_ip() {
	$keys = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_TRUE_CLIENT_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR' );
	foreach ( $keys as $key ) {
		if ( empty( $_SERVER[ $key ] ) ) {
			continue;
		}
		$value = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
		foreach ( explode( ',', $value ) as $part ) {
			$part = trim( $part );
			if ( filter_var( $part, FILTER_VALIDATE_IP ) ) {
				return $part;
			}
		}
	}
	return '';
}

/**
 * 除外の判定に使う IP の一覧（REMOTE_ADDR と転送ヘッダの IP すべて）。
 *
 * @return string[] IP の配列。
 */
function kashiwazaki_seo_heatmap_get_candidate_ips() {
	$ips  = array();
	$keys = array( 'REMOTE_ADDR', 'HTTP_CF_CONNECTING_IP', 'HTTP_TRUE_CLIENT_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR' );
	foreach ( $keys as $key ) {
		if ( empty( $_SERVER[ $key ] ) ) {
			continue;
		}
		$value = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
		foreach ( explode( ',', $value ) as $part ) {
			$part = trim( $part );
			if ( filter_var( $part, FILTER_VALIDATE_IP ) ) {
				$ips[] = $part;
			}
		}
	}
	return array_values( array_unique( $ips ) );
}

/**
 * リクエスト元 IP を取得する（保存はしない。除外判定とレート制限のみに使う）。
 *
 * 転送ヘッダ（CF-Connecting-IP / X-Forwarded-For / X-Real-IP）は送ってきた側が
 * 自由に書ける値なので、既定では信用せず REMOTE_ADDR を使う。信用してしまうと
 * リクエストごとにヘッダを変えるだけでレート制限をすり抜けられる。
 * Cloudflare などの手前に置く構成のときだけ、次のフィルタで有効にする。
 *
 *     add_filter( 'kashiwazaki_seo_heatmap_trust_forwarded_ip', '__return_true' );
 *
 * @return string IP アドレス（不明なら空文字）。
 */
function kashiwazaki_seo_heatmap_get_client_ip() {
	$keys = array( 'REMOTE_ADDR' );

	/**
	 * 転送ヘッダを信用するかどうか。
	 *
	 * @param bool $trust 既定は false。
	 */
	if ( apply_filters( 'kashiwazaki_seo_heatmap_trust_forwarded_ip', false ) ) {
		$keys = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' );
	}

	foreach ( $keys as $key ) {
		if ( empty( $_SERVER[ $key ] ) ) {
			continue;
		}
		$value = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
		foreach ( explode( ',', $value ) as $part ) {
			$part = trim( $part );
			if ( filter_var( $part, FILTER_VALIDATE_IP ) ) {
				return $part;
			}
		}
	}

	return '';
}

/**
 * 現在のリクエストのページに計測スクリプトを出すか。
 *
 * ここで判定するのは、ページ（URL）ごとに決まる条件だけにする。訪問者ごとに変わる
 * 条件（除外する IP・ログイン中・権限）で HTML を変えると、ページキャッシュが
 * 最初に開いた人の HTML を全員に配るので、除外した人が作ったキャッシュで
 * ほかの訪問者の計測まで止まる（転送ヘッダを偽ってキャッシュを作らせることもできる）。
 * 訪問者ごとの除外は、受信側（REST）で確かめて保存しない。
 *
 * @return bool 計測するなら true。
 */
function kashiwazaki_seo_heatmap_should_track() {
	// 管理画面のプレビュー（iframe）からのアクセスは計測しない。
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 判定のみで書き込みをしない。
	if ( isset( $_GET['kashiwazaki_seo_heatmap_preview'] ) ) {
		return false;
	}

	// URL パターンによる除外。
	if ( kashiwazaki_seo_heatmap_is_excluded_path( kashiwazaki_seo_heatmap_normalize_url_path( kashiwazaki_seo_heatmap_current_url() ) ) ) {
		return false;
	}

	return true;
}

/**
 * 現在のリクエストの URL（クエリ付き）を返す。
 *
 * @return string URL。
 */
function kashiwazaki_seo_heatmap_current_url() {
	$scheme = is_ssl() ? 'https://' : 'http://';
	$host   = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
	$uri    = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
	return $scheme . $host . $uri;
}

/**
 * データを保存するテーブル名を返す（$wpdb->prefix 付き）。
 *
 * @param string $which events|stats|pages。
 * @return string テーブル名。
 */
function kashiwazaki_seo_heatmap_table( $which ) {
	global $wpdb;

	switch ( $which ) {
		case 'events':
			return $wpdb->prefix . KASHIWAZAKI_SEO_HEATMAP_EVENTS_TABLE;
		case 'stats':
			return $wpdb->prefix . KASHIWAZAKI_SEO_HEATMAP_AGG_TABLE;
		case 'pages':
			return $wpdb->prefix . KASHIWAZAKI_SEO_HEATMAP_PAGES_TABLE;
		case 'batches':
			return $wpdb->prefix . KASHIWAZAKI_SEO_HEATMAP_BATCHES_TABLE;
	}
	return $wpdb->prefix . KASHIWAZAKI_SEO_HEATMAP_EVENTS_TABLE;
}

/**
 * スクロール到達率を数えるときのマイルストーン（%）。
 *
 * 計測側（storage）と集計側（db）で同じものを使う。
 *
 * @return int[] マイルストーンの配列。
 */
function kashiwazaki_seo_heatmap_scroll_milestones() {
	return array( 10, 20, 30, 40, 50, 60, 70, 80, 90, 100 );
}

/**
 * ヒートマップのカラースキーム一覧。
 *
 * @return array key => ラベル。
 */
function kashiwazaki_seo_heatmap_color_schemes() {
	return array(
		'thermal'  => __( 'サーマル（青→緑→黄→赤）', 'wp-plugin-kashiwazaki-seo-heatmap' ),
		'hot'      => __( 'ホット（黒→赤→黄）', 'wp-plugin-kashiwazaki-seo-heatmap' ),
		'cool'     => __( 'クール（青系）', 'wp-plugin-kashiwazaki-seo-heatmap' ),
		'viridis'  => __( 'Viridis（紫→青→緑→黄）', 'wp-plugin-kashiwazaki-seo-heatmap' ),
		'mono'     => __( 'モノクロ', 'wp-plugin-kashiwazaki-seo-heatmap' ),
	);
}

/**
 * JS・CSS を読み込むときの版（プラグインの版 ＋ ファイルの更新時刻）。
 *
 * プラグインの版を上げずにファイルを直したときも URL が変わるので、CDN やブラウザが古いファイルを
 * 返し続けない（版だけだと、同じ URL のまま古いファイルが長くキャッシュに残る）。
 * 公式: wp_enqueue_script の $ver「String specifying script version number, if it has one, which is added
 * to the URL as a query string for cache busting purposes.」。
 *
 * @param string $relative_path プラグインのディレクトリからのファイルのパス。
 * @return string 版の文字列。
 */
function kashiwazaki_seo_heatmap_asset_version( $relative_path ) {
	$file  = KASHIWAZAKI_SEO_HEATMAP_PLUGIN_DIR . $relative_path;
	$mtime = is_readable( $file ) ? filemtime( $file ) : false;
	return $mtime ? KASHIWAZAKI_SEO_HEATMAP_VERSION . '.' . $mtime : KASHIWAZAKI_SEO_HEATMAP_VERSION;
}
