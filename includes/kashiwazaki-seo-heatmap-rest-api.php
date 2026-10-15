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

	// Do Not Track を尊重する設定で、このリクエストに DNT が付いているか。付いていれば保存はしないが、早く返さずに
	// 下の除外の分岐に通す（番号を記録して、DNT の無い同じ番号の写しが後で届いても重複として数えず、置き換え（rp）も
	// 仮の値を引き、制限・変換中の判定も迂回しない）。
	$dnt = ! empty( $settings['honor_do_not_track'] ) && kashiwazaki_seo_heatmap_request_has_dnt();

	$payload = $request->get_json_params();
	if ( ! is_array( $payload ) ) {
		return new WP_Error( 'kashiwazaki_seo_heatmap_bad_request', __( 'データ形式が不正です。', 'wp-plugin-kashiwazaki-seo-heatmap' ), array( 'status' => 400 ) );
	}

	$visitor_id = isset( $payload['v'] ) ? substr( preg_replace( '/[^a-f0-9]/i', '', kashiwazaki_seo_heatmap_to_string( $payload['v'] ) ), 0, 32 ) : '';

	// 除外する IP からなら、外す人の印の Cookie を付ける（この REST の応答で。functions.php の mark_excluded_ip）。
	if ( kashiwazaki_seo_heatmap_is_excluded_ip() ) {
		kashiwazaki_seo_heatmap_mark_excluded_ip();
	}

	// レート制限（訪問者ごとに毎分 60 回、接続元ごとに毎分 3000 回まで）。
	// 超えた送信は 429 にする。計測スクリプトはまとまりを手元に残して送り直すので、捨てない。
	// 外す人かは、受け取らないと決めた後に確かめる（ログインの Cookie の確かめはデータベースを読むので、
	// 数を超えた送信のたびには行わない）。
	if ( ! kashiwazaki_seo_heatmap_check_rate_limit( $visitor_id ) ) {
		return kashiwazaki_seo_heatmap_rest_refusal( 'kashiwazaki_seo_heatmap_rate_limited', __( 'リクエストが多すぎます。', 'wp-plugin-kashiwazaki-seo-heatmap' ), 429, $dnt || kashiwazaki_seo_heatmap_request_excluded_now() );
	}

	// 表を InnoDB に変換している間は受け取らない（503）。変換の ALTER は同時の書き込みを止めるので、
	// 受け取ると変換が終わるまで待ち続けて、サイトの処理の枠を埋めるため。計測スクリプトは
	// まとまりを残して、間を空けて送り直す。公式: MySQL ALTER TABLE「An ALTER TABLE operation that
	// uses the COPY algorithm prevents concurrent DML operations.」。
	if ( kashiwazaki_seo_heatmap_is_converting() ) {
		return kashiwazaki_seo_heatmap_rest_refusal( 'kashiwazaki_seo_heatmap_busy', __( 'しばらくしてから送ってください。', 'wp-plugin-kashiwazaki-seo-heatmap' ), 503, $dnt || kashiwazaki_seo_heatmap_request_excluded_now() );
	}

	// 送信のまとまりの番号（計測スクリプトが付ける 32 桁の 16 進）。送り直しを二重に数えないために使う。
	$batch_id = isset( $payload['b'] ) ? strtolower( kashiwazaki_seo_heatmap_to_string( $payload['b'] ) ) : '';
	if ( ! preg_match( '/^[a-f0-9]{32}$/', $batch_id ) ) {
		$batch_id = '';
	}

	$meta = isset( $payload['p'] ) && is_array( $payload['p'] ) ? $payload['p'] : array();

	// 送信された URL は自サイトのものだけ受け付ける（外部サイトへのデータ流入を防ぐ）。
	$page_url = isset( $meta['u'] ) ? esc_url_raw( kashiwazaki_seo_heatmap_to_string( $meta['u'] ) ) : '';
	if ( '' === $page_url || ! kashiwazaki_seo_heatmap_is_local_url( $page_url ) ) {
		return new WP_Error( 'kashiwazaki_seo_heatmap_bad_url', __( '送信元 URL が不正です。', 'wp-plugin-kashiwazaki-seo-heatmap' ), array( 'status' => 400 ) );
	}

	// ページを見分ける URL（パス＋投稿を特定するクエリ）。基本のパーマリンク
	// （/?p=123）でも、ページごとに別のキーになる。
	$page_path  = kashiwazaki_seo_heatmap_normalize_page_url( $page_url );
	$page_key   = kashiwazaki_seo_heatmap_page_key( $page_path );
	$session_id = isset( $payload['s'] ) ? substr( preg_replace( '/[^a-f0-9]/i', '', kashiwazaki_seo_heatmap_to_string( $payload['s'] ) ), 0, 32 ) : '';

	// 訪問者 ID が無い場合は匿名の固定値を使う（個人は特定できない）。
	if ( '' === $visitor_id ) {
		$visitor_id = 'anonymous';
	}

	// 投稿 ID は URL から解決する（クライアントの申告を信用しない）。
	$post_id = url_to_postid( $page_url );
	$claimed = isset( $meta['id'] ) ? absint( kashiwazaki_seo_heatmap_to_string( $meta['id'] ) ) : 0;
	if ( ! $post_id ) {
		// パーマリンクの作りによっては URL から解決できないことがある。
		// その場合も申告値をそのままは使わず、URL と一致する投稿のときだけ受け入れる
		// （無関係な投稿のヒートマップに数値を混ぜられないようにするため）。
		if ( $claimed > 0 ) {
			$claimed_post = get_post( $claimed );
			if (
				$claimed_post
				&& kashiwazaki_seo_heatmap_normalize_page_url( get_permalink( $claimed_post ) ) === $page_path
			) {
				$post_id = $claimed;
			}
		}
	}

	// 操作のあった日時。計測スクリプトは、まとまりを作ってから送るまでの経過ミリ秒（a）を
	// 添える（送れずに残っていた分を、後のページから送り直すため）。受け取った時刻から引いて、
	// 操作のあった日の数字として数える。作ってから 7 日を過ぎたものは受け取らない
	// （番号は 8 日で消すので、それより後の送り直しを二重に数えないため）。
	$age = isset( $payload['a'] ) ? (int) floor( max( 0, kashiwazaki_seo_heatmap_to_float( $payload['a'] ) ) / 1000 ) : 0;
	if ( $age > 7 * DAY_IN_SECONDS ) {
		return new WP_REST_Response( array( 'ok' => true, 'skipped' => 'expired' ), 200 );
	}
	$event_time = time() - $age;

	$context = array(
		'post_id'    => $post_id,
		'page_url'   => $page_path,
		'page_key'   => $page_key,
		'device'     => kashiwazaki_seo_heatmap_sanitize_device( isset( $meta['d'] ) ? $meta['d'] : '' ),
		'visitor_id' => $visitor_id,
		'session_id' => $session_id,
		// 生データの日時はサイトのタイムゾーンの時刻（current_time( 'mysql' ) と同じ基準）、
		// 集計の日付は管理画面の期間と同じ基準で、どちらも操作のあった時刻から作る。
		// 公式: wp_date()「this function accepts a true Unix timestamp, not summed with timezone offset」。
		'created_at' => wp_date( 'Y-m-d H:i:s', $event_time ),
		'stat_date'  => kashiwazaki_seo_heatmap_local_date( $event_time ),
	);

	// ログイン中で計測から外す人か。届いた時点のログインの Cookie が外す人なら外し、計測スクリプトが
	// まとまりを作った時点で外す人だった（x=1）ときも外す（ログアウトの後に届いた分のため）。
	// 印の Cookie が無いこと（x=0）だけでは匿名と決めない。印はページキャッシュや開き直しで
	// 欠けることがあるため。ログインする前に作ったまとまりがログインの後に届いた場合も外す
	// （同じブラウザの人が計測から外す人になったので、その人の操作として扱う）。
	$flag = isset( $payload['x'] ) && is_scalar( $payload['x'] ) ? kashiwazaki_seo_heatmap_to_string( $payload['x'] ) : '';
	// いまのリクエストで外す人か（IP・届いた時点のログインの Cookie）。このときは、受け取らない応答（429・503・
	// 500）も含めて、外したことを応答のヘッダーで知らせる（kashiwazaki_seo_heatmap_rest_refusal）。印（x=1）だけで
	// 外したときは知らせない（ログアウトの後に古い写しが届いたとき、匿名の送り箱まで外させないため）。
	$excluded_now = kashiwazaki_seo_heatmap_request_excluded_now();
	// 外したことを応答のヘッダーで知らせる条件（外す人、または DNT）。DNT の人も、同じブラウザの送り箱のまとまりに
	// 外す人の印（x=1）を付けて送り直させる（送り直しの間に DNT が外れても、DNT の間に外した分を数えない）。
	$marks_excluded = $excluded_now || $dnt;

	// 除外の条件は、ページの HTML を作るときだけでなく、ここでも確かめる。
	// ページキャッシュがあると、除外した人・止めた投稿タイプのページにも
	// 計測スクリプトがそのまま配られるため。除外に当たったら保存せずに成功を返す
	// （計測スクリプトに再送させない）。
	$check_post   = $post_id ? $post_id : $claimed;
	$excluded_who = $excluded_now || ( '1' === $flag );
	$excluded_other = kashiwazaki_seo_heatmap_is_excluded_path( kashiwazaki_seo_heatmap_normalize_url_path( $page_url ) )
		|| ( $check_post > 0 && get_post( $check_post ) && ! kashiwazaki_seo_heatmap_is_trackable_post( $check_post ) );
	if ( $excluded_who || $dnt || $excluded_other ) {
		// 捨てたまとまりの番号も記録する。計測スクリプトは応答を確かめられなかった
		// まとまり（離脱時の sendBeacon）をブラウザに残して後で送り直すので、記録しておけば、
		// 送り直しを受け取ったときに除外の判定をやり直さずに済む。
		// 記録に失敗したら成功を返さない（送り直しで記録をやり直す。印が残ったままだと、後の送り直しを
		// 「まだ届いていない」まとまりとして保存してしまう）。いまのリクエストで外す人（IP・ログイン中）として
		// 外したときは、そのことを応答のヘッダーで知らせる。計測スクリプトはそのまとまりに外す人の印（x=1）を
		// 付けて送り直すので、送り直しの間にログアウトしても外す人のまま扱われる。
		// 公式: WP_HTTP_Response::header「Sets a single HTTP header.」。
		// 置き換えのまとまり（rp）なら、外しても、置き換えられる仮のまとまり（匿名のときに数えた分）を引く
		// （引かないと、外したまとまりの代わりに仮の値が残る）。番号の記録と引き算は保存と同じ取引で行う
		// （操作を除いた本文を save_batch_once に渡す。ほかの値は書かない。送り直しは重複として二重に引かない）。
		// データベースの更新が終わっていない・取引の効かない表がある間は、保存の分岐と同じく受け取らない（503）。
		$excluded_rp = '' !== $batch_id && isset( $meta['rp'] ) && '' !== $meta['rp'] && array() !== $meta['rp'];
		if ( $excluded_rp && ! kashiwazaki_seo_heatmap_schema_ready() ) {
			return kashiwazaki_seo_heatmap_rest_refusal( 'kashiwazaki_seo_heatmap_busy', __( 'しばらくしてから送ってください。', 'wp-plugin-kashiwazaki-seo-heatmap' ), 503, $marks_excluded );
		}
		$replaces = $excluded_rp ? kashiwazaki_seo_heatmap_replace_ids( $meta, $batch_id ) : array();
		if ( $replaces && ! kashiwazaki_seo_heatmap_tables_transactional() ) {
			return kashiwazaki_seo_heatmap_rest_refusal( 'kashiwazaki_seo_heatmap_busy', __( 'しばらくしてから送ってください。', 'wp-plugin-kashiwazaki-seo-heatmap' ), 503, $marks_excluded );
		}
		if ( $replaces ) {
			$retracted = kashiwazaki_seo_heatmap_save_batch_once( $batch_id, array( 'p' => array( 'rp' => $meta['rp'] ) ), $context );
			$recorded  = 'failed' !== $retracted['status'];
		} else {
			$recorded = '' === $batch_id || false !== kashiwazaki_seo_heatmap_mark_batch( $batch_id );
		}
		if ( ! $recorded ) {
			return kashiwazaki_seo_heatmap_rest_refusal( 'kashiwazaki_seo_heatmap_save_failed', __( '計測データを保存できませんでした。', 'wp-plugin-kashiwazaki-seo-heatmap' ), 500, $marks_excluded );
		}
		// 除外したのがこのページ（パス・投稿タイプ）だけなら、添えられた「まだ届いていない」番号
		// （ほかのページのまとまり）は記録する（計測スクリプトは 200 を受けると Cookie から外すため）。
		// 外す人（IP・ログイン中）なら、同じブラウザのほかのまとまりも外す人の分なので記録しない。
		// 記録に失敗したら成功を返さない（送り直しで、番号の記録と報告の記録をやり直す）。
		if ( ! $excluded_who && false === kashiwazaki_seo_heatmap_record_unarrived( $meta, $batch_id ) ) {
			return kashiwazaki_seo_heatmap_rest_refusal( 'kashiwazaki_seo_heatmap_save_failed', __( '計測データを保存できませんでした。', 'wp-plugin-kashiwazaki-seo-heatmap' ), 500, $marks_excluded );
		}
		// データベースの更新が終わっていない間は、知らせを記録できない（202。計測スクリプトは次のまとまりでまた知らせる）。
		$deferred = ! $excluded_who && kashiwazaki_seo_heatmap_has_reports( $meta ) && ! kashiwazaki_seo_heatmap_schema_ready();
		// DNT だけで外したときは skipped=dnt、ほかの理由（外す人・除外のパス・投稿タイプ）があれば excluded。
		$skipped  = new WP_REST_Response( array( 'ok' => true, 'skipped' => ( $dnt && ! $excluded_who && ! $excluded_other ) ? 'dnt' : 'excluded' ), $deferred ? 202 : 200 );
		// いまのリクエストで外す人（IP・ログイン中）として外したことは、記録できたときも知らせる。計測スクリプトは、
		// 送り箱に残っているほかのまとまりにも外す人の印（x=1）を付ける（同じブラウザの人が外す人なので。印の
		// Cookie を拒むブラウザでも、ほかのまとまりの応答が届かなかったときに外す人のまま扱われる）。
		if ( $marks_excluded ) {
			$skipped->header( 'X-Kashiwazaki-SEO-Heatmap-Excluded', '1' );
		}
		return $skipped;
	}

	// 仮の到達率（pr）・置き換え（rp）のまとまりは、番号の表の列（prov）で、後から引く・引かれる分を
	// 覚える。データベースの更新が終わっていない間に受け取ると、後から直せない形で数えてしまうので、
	// 更新が終わるまで受け取らない（503。計測スクリプトはまとまりを残して送り直す）。
	if ( kashiwazaki_seo_heatmap_uses_provisional( $meta ) && ! kashiwazaki_seo_heatmap_schema_ready() ) {
		return new WP_Error( 'kashiwazaki_seo_heatmap_busy', __( 'しばらくしてから送ってください。', 'wp-plugin-kashiwazaki-seo-heatmap' ), array( 'status' => 503 ) );
	}

	// 仮の到達率の置き換え（rp）は、引き算・置き換え済みの印・置き換えのまとまりの保存を 1 つの取引で
	// 行う。取引の効かない表（InnoDB への変換の前）がある間は、途中で失敗すると印だけが残って二重に
	// 数えるので、変換が済むまで受け取らない（503。計測スクリプトはまとまりを残して送り直す）。
	if ( kashiwazaki_seo_heatmap_replace_ids( $meta, $batch_id ) && ! kashiwazaki_seo_heatmap_tables_transactional() ) {
		return new WP_Error( 'kashiwazaki_seo_heatmap_busy', __( 'しばらくしてから送ってください。', 'wp-plugin-kashiwazaki-seo-heatmap' ), array( 'status' => 503 ) );
	}

	// 同じまとまりは 1 回だけ、生データ・集計・ページを全部か無しかで保存する。
	$result = kashiwazaki_seo_heatmap_save_batch_once( $batch_id, $payload, $context );

	// データベースの更新が終わっていない間は、添えられた知らせ（lr）を記録できない（下の 202 を参照）。
	$deferred = kashiwazaki_seo_heatmap_has_reports( $meta ) && ! kashiwazaki_seo_heatmap_schema_ready();

	if ( 'duplicate' === $result['status'] ) {
		// 送り直し（前の送信で保存済み）。成功を返して、計測スクリプトに手元の分を消させる。
		return new WP_REST_Response( array( 'ok' => true, 'skipped' => 'duplicate' ), $deferred ? 202 : 200 );
	}

	// 1 つでも書き込みに失敗したら取り消してあるので、成功を返さない。
	// 成功を返すと計測スクリプトはデータを捨てるので、データが黙って失われる。
	if ( 'saved' !== $result['status'] ) {
		return new WP_Error( 'kashiwazaki_seo_heatmap_save_failed', __( '計測データを保存できませんでした。', 'wp-plugin-kashiwazaki-seo-heatmap' ), array( 'status' => 500 ) );
	}

	// データベースの更新が終わっていない間は、添えられた知らせ（lr）を記録できていない。データは保存したので
	// 送り直しは要らないが、知らせは計測スクリプトに次のまとまりでまた添えさせる（202）。
	// 公式: MDN 202 Accepted「the request has been accepted for processing, but processing has not been completed」。
	return new WP_REST_Response(
		array(
			'ok'     => true,
			'saved'  => $result,
		),
		$deferred ? 202 : 200
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
 * いまのリクエストで計測から外す人か（除外する IP・届いた時点のログインの Cookie）。
 *
 * まとまりの本文の印（x=1）は見ない（ログアウトの後に届いた古い写しで、匿名の送り箱まで外させないため）。
 *
 * @return bool 外す人なら true。
 */
function kashiwazaki_seo_heatmap_request_excluded_now() {
	return kashiwazaki_seo_heatmap_is_excluded_ip() || kashiwazaki_seo_heatmap_is_excluded_user_id( kashiwazaki_seo_heatmap_get_cookie_user_id() );
}

/**
 * 受け取らない応答（429・500・503）を作る。いまのリクエストで外す人（除外する IP・届いた時点のログインの
 * Cookie）なら、外したことを知らせるヘッダーを付ける（計測スクリプトは、送ったときに送り箱にあったまとまりに
 * 外す人の印を付けて送り直すので、送り直しの間にログアウトしても外す人のまま扱われる）。
 * 本文は WP_Error を REST の応答にしたときと同じ形（code・message・data.status）にする。
 * 公式: WP_HTTP_Response::header「Sets a single HTTP header.」。
 *
 * @param string $code         エラーの符号。
 * @param string $message      エラーの文。
 * @param int    $status       HTTP の状態の番号。
 * @param bool   $excluded_now いまのリクエストで外す人なら true。
 * @return WP_REST_Response 応答。
 */
function kashiwazaki_seo_heatmap_rest_refusal( $code, $message, $status, $excluded_now ) {
	$response = new WP_REST_Response(
		array(
			'code'    => $code,
			'message' => $message,
			'data'    => array( 'status' => (int) $status ),
		),
		(int) $status
	);
	if ( $excluded_now ) {
		$response->header( 'X-Kashiwazaki-SEO-Heatmap-Excluded', '1' );
	}
	return $response;
}

/**
 * 簡易レート制限（訪問者の IP ごとに毎分 60 回、接続元ごとに大きめの上限）。
 *
 * CDN・逆プロキシの後ろでは REMOTE_ADDR が中継の IP になり、その IP を通る
 * 全員が 1 つの枠を取り合って、計測データが 429 で黙って捨てられる。そこで、
 * (1) 接続元（REMOTE_ADDR）・転送ヘッダの訪問者 IP・訪問者 ID の組ごとに毎分 60 回、
 * (2) 接続元（REMOTE_ADDR）ごとに、大勢の訪問者が通れる大きめの上限、
 * の 2 段で数える。NAT の後ろの訪問者も訪問者 ID で分かれるので、互いの枠を取り合わない。
 * 転送ヘッダと訪問者 ID は偽れるが、偽っても (2) の上限は越えられない。
 *
 * 数え方は「分ごとの固定窓」にする。キーに分の番号を混ぜることで、
 * 送信のたびに期限を延ばしてしまう（いつまでも窓が閉じない）状態を避ける。
 *
 * @param string $visitor_id 計測スクリプトの訪問者 ID（無ければ空）。
 * @return bool 許可するなら true。
 */
function kashiwazaki_seo_heatmap_check_rate_limit( $visitor_id = '' ) {
	$ip = kashiwazaki_seo_heatmap_get_client_ip();
	if ( '' === $ip ) {
		return true;
	}

	$forwarded = kashiwazaki_seo_heatmap_get_forwarded_ip();

	/**
	 * 接続元（REMOTE_ADDR）ごとの毎分の上限。CDN の中継の IP には大勢の訪問者が
	 * 乗るので、訪問者ごとの上限より大きくする。転送ヘッダは偽れるので、
	 * 直接つながる相手にとってはこれが実際の上限になる（毎分 3000 回＝毎秒 50 回）。
	 *
	 * @param int $limit 既定は 3000。
	 */
	$per_connection = (int) apply_filters( 'kashiwazaki_seo_heatmap_rate_limit_per_connection', 3000 );

	// 訪問者ごとの枠は、接続元・転送された IP・訪問者 ID（計測スクリプトの Cookie）の組で分ける。
	// 学校・会社・携帯の NAT のように 1 つの公開 IP の後ろに大勢がいても、互いの枠を取り合わない。
	// 訪問者 ID も転送ヘッダも偽れるが、偽って増やせるのは接続元ごとの上限までになる。
	$buckets = array(
		'c_' . md5( $ip )                                           => max( 1, $per_connection ),
		'v_' . md5( $ip . '|' . $forwarded . '|' . (string) $visitor_id ) => KASHIWAZAKI_SEO_HEATMAP_RATE_LIMIT,
	);

	$window = (int) floor( time() / MINUTE_IN_SECONDS );
	$counts = array();
	foreach ( $buckets as $name => $limit ) {
		$key            = 'kashiwazaki_seo_heatmap_rl_' . $name . '_' . $window;
		$count          = get_transient( $key );
		$counts[ $key ] = ( false === $count ) ? 0 : (int) $count;
		if ( $counts[ $key ] >= $limit ) {
			return false;
		}
	}

	// キーに分が入っているので、期限を毎回入れ直しても窓は延びない。
	foreach ( $counts as $key => $count ) {
		set_transient( $key, $count + 1, 2 * MINUTE_IN_SECONDS );
	}
	return true;
}
