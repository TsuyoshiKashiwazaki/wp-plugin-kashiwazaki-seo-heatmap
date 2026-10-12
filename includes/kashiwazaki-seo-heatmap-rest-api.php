<?php
/**
 * 計測データを受け取る REST API。
 *
 * @package Kashiwazaki_SEO_Heatmap
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST ルートを登録する。
 *
 * @return void
 */
function kashiwazaki_seo_heatmap_register_rest_routes() {
	register_rest_route(
		KASHIWAZAKI_SEO_HEATMAP_REST_NAMESPACE,
		'/collect',
		array(
			'methods'             => 'POST',
			'callback'            => 'kashiwazaki_seo_heatmap_rest_collect',
			// ログインしていない訪問者からも受け取る必要があるため公開する。
			// なりすまし・DoS はレート制限とデータ検証で防ぐ（認証情報は扱わない）。
			'permission_callback' => '__return_true',
		)
	);
}

/**
 * 計測データを受け取って保存する。
 *
 * @param WP_REST_Request $request リクエスト。
 * @return WP_REST_Response|WP_Error 応答。
 */
function kashiwazaki_seo_heatmap_rest_collect( WP_REST_Request $request ) {
	$settings = kashiwazaki_seo_heatmap_get_settings();

	// Do Not Track を尊重する。
	if ( ! empty( $settings['honor_do_not_track'] ) && kashiwazaki_seo_heatmap_request_has_dnt() ) {
		return new WP_REST_Response( array( 'ok' => true, 'skipped' => 'dnt' ), 200 );
	}

	// レート制限（1 IP あたり毎分 60 リクエストまで）。
	if ( ! kashiwazaki_seo_heatmap_check_rate_limit() ) {
		return new WP_Error( 'kashiwazaki_seo_heatmap_rate_limited', __( 'リクエストが多すぎます。', 'wp-plugin-kashiwazaki-seo-heatmap' ), array( 'status' => 429 ) );
	}

	$payload = $request->get_json_params();
	if ( ! is_array( $payload ) ) {
		return new WP_Error( 'kashiwazaki_seo_heatmap_bad_request', __( 'データ形式が不正です。', 'wp-plugin-kashiwazaki-seo-heatmap' ), array( 'status' => 400 ) );
	}

	$meta = isset( $payload['p'] ) && is_array( $payload['p'] ) ? $payload['p'] : array();

	// 送信された URL は自サイトのものだけ受け付ける（外部サイトへのデータ流入を防ぐ）。
	$page_url = isset( $meta['u'] ) ? esc_url_raw( kashiwazaki_seo_heatmap_to_string( $meta['u'] ) ) : '';
	if ( '' === $page_url || ! kashiwazaki_seo_heatmap_is_local_url( $page_url ) ) {
		return new WP_Error( 'kashiwazaki_seo_heatmap_bad_url', __( '送信元 URL が不正です。', 'wp-plugin-kashiwazaki-seo-heatmap' ), array( 'status' => 400 ) );
	}

	$page_key   = kashiwazaki_seo_heatmap_page_key( kashiwazaki_seo_heatmap_normalize_url_path( $page_url ) );
	$visitor_id = isset( $payload['v'] ) ? substr( preg_replace( '/[^a-f0-9]/i', '', kashiwazaki_seo_heatmap_to_string( $payload['v'] ) ), 0, 32 ) : '';
	$session_id = isset( $payload['s'] ) ? substr( preg_replace( '/[^a-f0-9]/i', '', kashiwazaki_seo_heatmap_to_string( $payload['s'] ) ), 0, 32 ) : '';

	// 訪問者 ID が無い場合は匿名の固定値を使う（個人は特定できない）。
	if ( '' === $visitor_id ) {
		$visitor_id = 'anonymous';
	}

	// 投稿 ID は URL から解決する（クライアントの申告を信用しない）。
	$post_id = url_to_postid( $page_url );
	if ( ! $post_id ) {
		// パーマリンクの作りによっては URL から解決できないことがある。
		// その場合も申告値をそのままは使わず、URL と一致する投稿のときだけ受け入れる
		// （無関係な投稿のヒートマップに数値を混ぜられないようにするため）。
		$claimed = isset( $meta['id'] ) ? absint( kashiwazaki_seo_heatmap_to_string( $meta['id'] ) ) : 0;
		if ( $claimed > 0 ) {
			$claimed_post = get_post( $claimed );
			if (
				$claimed_post
				&& kashiwazaki_seo_heatmap_normalize_url_path( get_permalink( $claimed_post ) ) === kashiwazaki_seo_heatmap_normalize_url_path( $page_url )
			) {
				$post_id = $claimed;
			}
		}
	}

	$context = array(
		'post_id'    => $post_id,
		'page_url'   => kashiwazaki_seo_heatmap_normalize_url_path( $page_url ),
		'page_key'   => $page_key,
		'device'     => kashiwazaki_seo_heatmap_sanitize_device( isset( $meta['d'] ) ? $meta['d'] : '' ),
		'visitor_id' => $visitor_id,
		'session_id' => $session_id,
		'created_at' => current_time( 'mysql' ),
	);

	$result = kashiwazaki_seo_heatmap_save_batch( $payload, $context );

	return new WP_REST_Response(
		array(
			'ok'     => true,
			'saved'  => $result,
		),
		200
	);
}

/**
 * リクエストに Do Not Track ヘッダが付いているか判定する。
 *
 * @return bool DNT なら true。
 */
function kashiwazaki_seo_heatmap_request_has_dnt() {
	if ( empty( $_SERVER['HTTP_DNT'] ) ) {
		return false;
	}
	// ブラウザによって 1 / yes のどちらかを送る（古い実装では両方）。
	$value = strtolower( trim( (string) wp_unslash( $_SERVER['HTTP_DNT'] ) ) );
	return '1' === $value || 'yes' === $value;
}

/**
 * URL がこのサイトのものか判定する。
 *
 * @param string $url 判定する URL。
 * @return bool 自サイトなら true。
 */
function kashiwazaki_seo_heatmap_is_local_url( $url ) {
	$host      = wp_parse_url( $url, PHP_URL_HOST );
	$site_host = wp_parse_url( home_url(), PHP_URL_HOST );

	if ( ! is_string( $host ) || '' === $host ) {
		return false;
	}

	// www の有無は無視して比較する。
	$host      = preg_replace( '/^www\./i', '', strtolower( $host ) );
	$site_host = preg_replace( '/^www\./i', '', strtolower( (string) $site_host ) );

	return $host === $site_host;
}

/**
 * IP 単位の簡易レート制限（1 分あたり 60 回）。
 *
 * 数え方は「分ごとの固定窓」にする。キーに分の番号を混ぜることで、
 * 送信のたびに期限を延ばしてしまう（いつまでも窓が閉じない）状態を避ける。
 * まじめに使っている人が、訪問を続けただけで弾かれるのを防ぐため。
 *
 * @return bool 許可するなら true。
 */
function kashiwazaki_seo_heatmap_check_rate_limit() {
	$ip = kashiwazaki_seo_heatmap_get_client_ip();
	if ( '' === $ip ) {
		return true;
	}

	$window = (int) floor( time() / MINUTE_IN_SECONDS );
	$key    = 'kashiwazaki_seo_heatmap_rl_' . md5( $ip ) . '_' . $window;
	$count  = get_transient( $key );
	$count  = ( false === $count ) ? 0 : (int) $count;

	if ( $count >= KASHIWAZAKI_SEO_HEATMAP_RATE_LIMIT ) {
		return false;
	}

	// キーに分が入っているので、期限を毎回入れ直しても窓は延びない。
	set_transient( $key, $count + 1, 2 * MINUTE_IN_SECONDS );
	return true;
}
