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
	return $path;
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
	$days   = absint( $days );
	$offset = (int) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS;
	$now    = time() + $offset;
	return gmdate( 'Y-m-d', $now - ( $days * DAY_IN_SECONDS ) );
}

/**
 * 現在ログインしているユーザーが計測から除外されるべきか判定する。
 *
 * @return bool 除外するなら true。
 */
function kashiwazaki_seo_heatmap_is_excluded_user() {
	$settings = kashiwazaki_seo_heatmap_get_settings();

	if ( ! is_user_logged_in() ) {
		return false;
	}

	if ( ! empty( $settings['exclude_logged_in'] ) ) {
		return true;
	}

	$exclude_roles = isset( $settings['exclude_roles'] ) ? (array) $settings['exclude_roles'] : array();
	if ( empty( $exclude_roles ) ) {
		return false;
	}

	$user = wp_get_current_user();
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

	$ip = kashiwazaki_seo_heatmap_get_client_ip();
	if ( '' === $ip ) {
		return false;
	}

	$patterns = preg_split( '/[\r\n,]+/', $list );
	foreach ( (array) $patterns as $pattern ) {
		$pattern = trim( $pattern );
		if ( '' === $pattern ) {
			continue;
		}
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

	return false;
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
 * 現在のリクエストで計測してよいか（全条件の総合判定）。
 *
 * @return bool 計測するなら true。
 */
function kashiwazaki_seo_heatmap_should_track() {
	$settings = kashiwazaki_seo_heatmap_get_settings();

	// 管理画面のプレビュー（iframe）からのアクセスは計測しない。
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 判定のみで書き込みをしない。
	if ( isset( $_GET['kashiwazaki_seo_heatmap_preview'] ) ) {
		return false;
	}

	if ( kashiwazaki_seo_heatmap_is_excluded_user() ) {
		return false;
	}
	if ( kashiwazaki_seo_heatmap_is_excluded_ip() ) {
		return false;
	}

	// URL パターンによる除外。
	$patterns = isset( $settings['exclude_url_patterns'] ) ? (string) $settings['exclude_url_patterns'] : '';
	if ( '' !== trim( $patterns ) ) {
		$path = kashiwazaki_seo_heatmap_normalize_url_path( kashiwazaki_seo_heatmap_current_url() );
		foreach ( preg_split( '/[\r\n]+/', $patterns ) as $pattern ) {
			$pattern = trim( $pattern );
			if ( '' === $pattern ) {
				continue;
			}
			if ( false !== strpos( $pattern, '*' ) ) {
				$regex = '#^' . str_replace( '\*', '.*', preg_quote( $pattern, '#' ) ) . '#';
				if ( preg_match( $regex, $path ) ) {
					return false;
				}
			} elseif ( false !== strpos( $path, $pattern ) ) {
				return false;
			}
		}
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
