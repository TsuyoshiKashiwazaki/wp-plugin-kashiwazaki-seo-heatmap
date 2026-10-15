<?php
/**
 * 送信された計測データを検証して保存する。
 *
 * REST エンドポイントから呼ばれる。生データ（events）と集計（stats / pages）の
 * 両方を、ここで一括して書き込む。
 *
 * @package Kashiwazaki_SEO_Heatmap
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 1 バッチ分の計測データを保存する。
 *
 * @param array $payload 検証済みの送信データ。
 * @param array $context post_id, page_url, page_key, device, visitor_id, session_id, user_id, created_at。
 * @return array 保存結果（events / stats / pages の件数）。
 */
function kashiwazaki_seo_heatmap_save_batch( $payload, $context ) {
	global $wpdb;

	$settings = kashiwazaki_seo_heatmap_get_settings();

	$post_id    = (int) $context['post_id'];
	// page_url は varchar(255)。長すぎると INSERT ごと失敗して計測データを
	// 丸ごと落とすので、使う前に文字数で切っておく（表示用の列なので切れてよい）。
	$page_url   = kashiwazaki_seo_heatmap_truncate( (string) $context['page_url'], 255 );
	$page_key   = (string) $context['page_key'];
	$device     = (string) $context['device'];
	$visitor_id = (string) $context['visitor_id'];
	$session_id = (string) $context['session_id'];
	// 操作のあった日時と日付（遅れて届いた分も、操作のあった日の数字として数える）。
	$created_at = isset( $context['created_at'] ) ? $context['created_at'] : current_time( 'mysql' );
	$stat_date  = isset( $context['stat_date'] ) ? $context['stat_date'] : kashiwazaki_seo_heatmap_date_days_ago( 0 );

	$meta   = isset( $payload['p'] ) && is_array( $payload['p'] ) ? $payload['p'] : array();
	$events = isset( $payload['ev'] ) && is_array( $payload['ev'] ) ? $payload['ev'] : array();

	$doc_w = max( 0, (int) kashiwazaki_seo_heatmap_to_float( isset( $meta['w'] ) ? $meta['w'] : 0 ) );
	$doc_h = max( 0, (int) kashiwazaki_seo_heatmap_to_float( isset( $meta['h'] ) ? $meta['h'] : 0 ) );
	$vw    = max( 0, (int) kashiwazaki_seo_heatmap_to_float( isset( $meta['vw'] ) ? $meta['vw'] : 0 ) );
	$vh    = max( 0, (int) kashiwazaki_seo_heatmap_to_float( isset( $meta['vh'] ) ? $meta['vh'] : 0 ) );

	// イベント数を制限する。
	$events = array_slice( $events, 0, KASHIWAZAKI_SEO_HEATMAP_MAX_EVENTS_PER_REQUEST );

	$raw_rows   = array();
	$stats_rows = array();
	$milestone_hits = array(); // scroll の到達マイルストーン。
	$scroll_seen    = array(); // scroll のイベントごとの（起点の深さ, 深さ）。

	foreach ( $events as $event ) {
		if ( ! is_array( $event ) ) {
			continue;
		}

		$type = kashiwazaki_seo_heatmap_sanitize_event_type( isset( $event['t'] ) ? $event['t'] : '' );
		if ( '' === $type || 'pageview' === $type ) {
			continue;
		}

		$selector = kashiwazaki_seo_heatmap_sanitize_selector( isset( $event['sel'] ) ? $event['sel'] : '' );
		$index    = isset( $event['i'] ) ? max( 0, (int) $event['i'] ) : 0;
		$tag      = isset( $event['tag'] ) ? kashiwazaki_seo_heatmap_truncate( sanitize_key( $event['tag'] ), 30 ) : '';
		// 文言は日本語なので、バイト単位で切ると壊れた UTF-8 になり保存に失敗する。
		// 文字数で切る（element_text は varchar(150) なので 140 文字なら収まる）。
		$text     = isset( $event['txt'] ) ? kashiwazaki_seo_heatmap_truncate( sanitize_text_field( $event['txt'] ), 140 ) : '';
		$branch   = isset( $event['br'] ) ? kashiwazaki_seo_heatmap_truncate( sanitize_text_field( $event['br'] ), 140 ) : '';

		// 座標は有限の数値だけを受け付ける（INF / NAN は INSERT ごと失敗させる）。
		$x     = kashiwazaki_seo_heatmap_to_float( isset( $event['x'] ) ? $event['x'] : 0 );
		$y     = kashiwazaki_seo_heatmap_to_float( isset( $event['y'] ) ? $event['y'] : 0 );
		$xp    = kashiwazaki_seo_heatmap_to_float( isset( $event['xp'] ) ? $event['xp'] : 0 );
		$yp    = kashiwazaki_seo_heatmap_to_float( isset( $event['yp'] ) ? $event['yp'] : 0 );
		$depth = isset( $event['d'] ) ? min( 100, max( 0, (int) $event['d'] ) ) : 0;

		// 位置の割合が来ていなければ、その場で計算する。
		if ( 0.0 === $xp && $doc_w > 0 && $x > 0 ) {
			$xp = ( $x / $doc_w ) * 100;
		}
		if ( 0.0 === $yp && $doc_h > 0 && $y > 0 ) {
			$yp = ( $y / $doc_h ) * 100;
		}

		if ( 'move' === $type ) {
			// マウスムーブは件数が多いため集計のみ（生データには残さない）。
			$grid = kashiwazaki_seo_heatmap_move_grid_key( $x, $y, $doc_w, $doc_h );
			$stats_rows[] = kashiwazaki_seo_heatmap_build_stats_row(
				array(
					'post_id'     => $post_id,
					'page_key'    => $page_key,
					'stat_date'   => $stat_date,
					'device'      => $device,
					'event_type'  => 'move',
					'element_key' => $grid,
					'selector'    => '',
					'element_index' => 0,
					'element_tag' => '',
					'element_text' => '',
					'count'       => 1,
					'x'           => $x,
					'y'           => $y,
					'xp'          => $xp,
					'yp'          => $yp,
				)
			);
			continue;
		}

		if ( 'scroll' === $type ) {
			// 前回送信した深さ（df）。同じ深さを何度も数えないようにする。
			$depth_from = isset( $event['df'] ) ? min( 100, max( 0, (int) $event['df'] ) ) : 0;
			// 仮の到達率のまとまり（save_batch_once）が、数えたものを番号の表に残すために返す。
			$scroll_seen[] = array( $depth_from, $depth );

			// 到達したマイルストーン（10% 刻み）を数える。
			foreach ( kashiwazaki_seo_heatmap_scroll_milestones() as $milestone ) {
				if ( $depth >= $milestone && $depth_from < $milestone ) {
					if ( ! isset( $milestone_hits[ $milestone ] ) ) {
						$milestone_hits[ $milestone ] = 0;
					}
					$milestone_hits[ $milestone ]++;
				}
			}
			$raw_rows[] = kashiwazaki_seo_heatmap_build_raw_row(
				compact( 'post_id', 'page_key', 'page_url', 'type', 'visitor_id', 'session_id', 'x', 'y', 'xp', 'yp', 'doc_w', 'doc_h', 'vw', 'vh', 'device', 'created_at' ) + array(
					'depth'   => $depth,
					'element_key' => 'scroll:' . $depth,
					'selector'    => '',
					'index'       => 0,
					'tag'         => '',
					'text'        => '',
				)
			);
			continue;
		}

		// クリック系（click / rage / dead / outbound）。
		$element_key = kashiwazaki_seo_heatmap_element_key( $selector, $index, $branch );

		$raw_rows[] = kashiwazaki_seo_heatmap_build_raw_row(
			compact( 'post_id', 'page_key', 'page_url', 'type', 'visitor_id', 'session_id', 'x', 'y', 'xp', 'yp', 'doc_w', 'doc_h', 'vw', 'vh', 'device', 'created_at' ) + array(
				'depth'       => 0,
				'element_key' => $element_key,
				'selector'    => $selector,
				'index'       => $index,
				'tag'         => $tag,
				'text'        => $text,
			)
		);

		$stats_rows[] = kashiwazaki_seo_heatmap_build_stats_row(
			array(
				'post_id'      => $post_id,
				'page_key'     => $page_key,
				'stat_date'    => $stat_date,
				'device'       => $device,
				'event_type'   => $type,
				'element_key'  => $element_key,
				'selector'     => $selector,
				'element_index' => $index,
				'element_tag'  => $tag,
				'element_text' => $text,
				'count'        => 1,
				'x'            => $x,
				'y'            => $y,
				'xp'           => $xp,
				'yp'           => $yp,
			)
		);
	}

	// スクロールのマイルストーンを集計行に変換する。
	foreach ( $milestone_hits as $milestone => $count ) {
		$stats_rows[] = kashiwazaki_seo_heatmap_build_stats_row(
			array(
				'post_id'      => $post_id,
				'page_key'     => $page_key,
				'stat_date'    => $stat_date,
				'device'       => $device,
				'event_type'   => 'scroll',
				'element_key'  => 'scroll:' . $milestone,
				'selector'     => '',
				'element_index' => 0,
				'element_tag'  => '',
				'element_text' => '',
				'count'        => $count,
				'x'            => 0,
				'y'            => 0,
				'xp'           => 0,
				'yp'           => 0,
			)
		);
	}

	// ページビューの記録。
	// t は「前回の送信からの滞在秒数」。送信のたびに積み上げて平均滞在時間を出す。
	$time_on_page = min( 3600, max( 0, kashiwazaki_seo_heatmap_to_float( isset( $meta['t'] ) ? $meta['t'] : 0 ) ) );
	$is_pageview  = ! empty( $meta['pv'] );

	$pageview_rows = array();
	if ( $is_pageview ) {
		$is_new_visitor = kashiwazaki_seo_heatmap_is_new_visitor_today( $page_key, $visitor_id, $stat_date );
		if ( null === $is_new_visitor ) {
			// 読めなかった。書かずに失敗を返す（REST が取引ごと取り消し、計測スクリプトが送り直す）。
			return array( 'events' => 0, 'stats' => 0, 'pages' => 0, 'attempted' => 0, 'failed' => 1, 'scroll' => $scroll_seen );
		}

		$pageview_rows[] = array(
			'post_id'    => $post_id,
			'page_key'   => $page_key,
			'page_url'   => $page_url,
			'stat_date'  => $stat_date,
			'device'     => $device,
			'pageviews'  => 1,
			'visitors'   => $is_new_visitor ? 1 : 0,
			'entrances'  => ! empty( $meta['e'] ) ? 1 : 0,
			'total_time' => $time_on_page,
		);

		// ページビューも生データに 1 行残す。
		$raw_rows[] = kashiwazaki_seo_heatmap_build_raw_row(
			compact( 'post_id', 'page_key', 'page_url', 'visitor_id', 'session_id', 'doc_w', 'doc_h', 'vw', 'vh', 'device', 'created_at' ) + array(
				'type'        => 'pageview',
				'x'           => 0,
				'y'           => 0,
				'xp'          => 0,
				'yp'          => 0,
				'depth'       => 0,
				'element_key' => 'pageview',
				'selector'    => '',
				'index'       => 0,
				'tag'         => '',
				'text'        => '',
			)
		);
	} elseif ( $time_on_page > 0 ) {
		// ページビュー以外の送信では、滞在時間だけを足す。
		$pageview_rows[] = array(
			'post_id'    => $post_id,
			'page_key'   => $page_key,
			'page_url'   => $page_url,
			'stat_date'  => $stat_date,
			'device'     => $device,
			'pageviews'  => 0,
			'visitors'   => 0,
			'entrances'  => 0,
			'total_time' => $time_on_page,
		);
	}

	$result = array(
		'events'    => 0,
		'stats'     => 0,
		'pages'     => 0,
		// 書き込もうとした種類の数と、1 行でも失敗した種類の数。
		// 1 種類でも失敗したら、REST が取引ごと取り消して成功を返さない（計測スクリプトが送り直す）。
		'attempted' => 0,
		'failed'    => 0,
		// 到達率のイベントごとの（起点の深さ, 深さ）。
		'scroll'    => $scroll_seen,
	);

	$writes = array(
		'events' => array( 'kashiwazaki_seo_heatmap_insert_events', $raw_rows ),
		'stats'  => array( 'kashiwazaki_seo_heatmap_bump_stats', $stats_rows ),
		'pages'  => array( 'kashiwazaki_seo_heatmap_bump_pages', $pageview_rows ),
	);
	foreach ( $writes as $name => $write ) {
		if ( empty( $write[1] ) ) {
			continue;
		}
		$result['attempted']++;
		$written = call_user_func( $write[0], $write[1] );
		if ( false === $written ) {
			$result['failed']++;
			// 後の書き込みは取り消されるので、続けない。
			break;
		}
		$result[ $name ] = (int) $written;
	}

	return $result;
}

/**
 * まとまりの番号を「受け取り済み」として記録する（保存せずに捨てたまとまり用）。
 *
 * 後で同じまとまりが送り直されても、save_batch_once が重複として数えない。
 * ほかの閲覧から「まだ届いていない」と知らされていた番号なら、届いたので印を戻す
 * （除外して捨てたまとまりは、届いていないものとして数えない）。
 *
 * データベースの更新が終わっていない（prov が短い）表でも、lost_n の列があれば印を戻す（列を名指しせずに
 * 行を読む。save_batch_once の重複の分岐と同じ）。印が残ったままだと、後で送り直されたときに「届いて
 * いない」まとまりとして保存してしまう。
 *
 * @param string $batch_id まとまりの番号（32 桁の 16 進）。
 * @return bool 書き込みに失敗したら false（受信側は成功を返さず、送り直させる）。
 */
function kashiwazaki_seo_heatmap_mark_batch( $batch_id ) {
	global $wpdb;

	$batches = kashiwazaki_seo_heatmap_table( 'batches' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$marked = $wpdb->query(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名はプレフィックス付きの固定値。
			"INSERT IGNORE INTO {$batches} (batch_id, created_at) VALUES (%s, %s)",
			$batch_id,
			current_time( 'mysql', true )
		)
	);
	if ( false === $marked ) {
		return false;
	}
	if ( 0 !== (int) $marked ) {
		return true;
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$row = $wpdb->get_row(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名はプレフィックス付きの固定値。
			"SELECT * FROM {$batches} WHERE batch_id = %s",
			$batch_id
		),
		ARRAY_A
	);
	// 読めなかった（SQL のエラー）ときは、行が無い・列が無いのと分けて失敗にする。
	// 公式: wpdb「Returns null both on failure and when the matched cell value is an empty string.
	// To distinguish the two cases, check self::$last_error.」。
	if ( '' !== $wpdb->last_error ) {
		return false;
	}
	if ( ! $row || ! isset( $row['lost_n'] ) || (int) $row['lost_n'] <= 0 ) {
		return true;
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	return false !== $wpdb->query(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名はプレフィックス付きの固定値。
			"UPDATE {$batches} SET lost_n = 0 WHERE batch_id = %s AND lost_n > 0",
			$batch_id
		)
	);
}

/**
 * ほかの閲覧から知らされた「まだ届いていない」まとまりの番号と件数を、番号の表に記録する（meta.lr）。
 *
 * 計測スクリプトは、保存領域に保存できず、受け取りの応答もまだ無いまとまりの番号と件数を Cookie に残し、
 * 次に作るまとまりに添えて知らせる。届いたかどうかはサーバーだけが知っているので、ここで番号の表と照らす。
 * 既にある番号（届いた・置き換え済み・知らされ済み）は INSERT IGNORE で何もしない。まだ無い番号は
 * lost_n に件数を入れて作る（後で本物が届けば save_batch_once が 0 に戻す）。
 * 公式: MariaDB INSERT IGNORE「Warnings are issued for duplicate key errors when using IGNORE.」。
 *
 * @param array  $meta     まとまりの p。
 * @param string $batch_id 知らせてきたまとまり自身の番号（自分は記録しない）。
 * @return bool 書き込みに失敗したら false。
 */
function kashiwazaki_seo_heatmap_record_unarrived( $meta, $batch_id ) {
	global $wpdb;

	if ( empty( $meta['lr'] ) || ! is_array( $meta['lr'] ) || ! kashiwazaki_seo_heatmap_schema_ready() ) {
		return true;
	}
	$batches = kashiwazaki_seo_heatmap_table( 'batches' );
	foreach ( array_slice( $meta['lr'], 0, 100 ) as $item ) {
		if ( ! is_array( $item ) || ! isset( $item[0], $item[1] ) || ! is_scalar( $item[0] ) ) {
			continue;
		}
		$id = strtolower( kashiwazaki_seo_heatmap_to_string( $item[0] ) );
		$n  = (int) kashiwazaki_seo_heatmap_to_float( $item[1] );
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $id ) || $id === $batch_id || $n < 1 || $n > 10000 ) {
			continue;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$done = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名はプレフィックス付きの固定値。
				"INSERT IGNORE INTO {$batches} (batch_id, created_at, lost_n) VALUES (%s, %s, %d)",
				$id,
				current_time( 'mysql', true ),
				$n
			)
		);
		if ( false === $done ) {
			return false;
		}
	}
	return true;
}

/**
 * まとまりに、ほかの閲覧の「まだ届いていない」番号の知らせ（meta.lr）が添えてあるか。
 *
 * @param array $meta まとまりの p。
 * @return bool 添えてあれば true。
 */
function kashiwazaki_seo_heatmap_has_reports( $meta ) {
	return ! empty( $meta['lr'] ) && is_array( $meta['lr'] );
}

/**
 * まとまりが仮の到達率のまとまり（meta.pr）か、置き換えのまとまり（meta.rp）か。
 *
 * どちらも番号の表の列（prov）を使うので、データベースの更新が終わるまで受け取らない（rest-api.php）。
 *
 * @param array $meta まとまりの p。
 * @return bool どちらかなら true。
 */
function kashiwazaki_seo_heatmap_uses_provisional( $meta ) {
	return ! empty( $meta['pr'] ) || ( isset( $meta['rp'] ) && '' !== $meta['rp'] && array() !== $meta['rp'] );
}

/**
 * まとまりが置き換える仮のまとまりの番号の一覧（meta.rp）。
 *
 * 計測スクリプトは、この閲覧の仮のまとまりの番号を作った順にすべて入れる（鎖の途中が遅れて届いても、
 * 届かなくても、前の仮の値を引けるように）。1 つの番号の文字列も受け付ける。32 桁の 16 進だけ、
 * 20 個まで、自分の番号は除く。
 *
 * @param array  $meta     まとまりの p。
 * @param string $batch_id まとまり自身の番号。
 * @return string[] 番号の一覧。
 */
function kashiwazaki_seo_heatmap_replace_ids( $meta, $batch_id ) {
	if ( ! isset( $meta['rp'] ) || ! kashiwazaki_seo_heatmap_schema_ready() ) {
		return array();
	}
	$list = is_array( $meta['rp'] ) ? array_slice( $meta['rp'], 0, 20 ) : array( $meta['rp'] );
	$ids  = array();
	foreach ( $list as $id ) {
		if ( ! is_scalar( $id ) ) {
			continue;
		}
		$id = strtolower( kashiwazaki_seo_heatmap_to_string( $id ) );
		if ( preg_match( '/^[a-f0-9]{32}$/', $id ) && $id !== $batch_id && ! in_array( $id, $ids, true ) ) {
			$ids[] = $id;
		}
	}
	return $ids;
}

/**
 * 仮のまとまりが数えたもの（番号の表の prov）を読む。
 *
 * 形は 2 つある（どちらも区切りは「|」。ページのキーは md5 か投稿の番号から作るので「|」を含まない）。
 * - 今の形: ページのキー|集計の日付|端末|生データの日時|訪問者|起点-深さ,起点-深さ…（到達率のイベントごと）
 * - 前の内部の版（DB の版 1.0.5）の形: ページのキー|集計の日付|端末|起点|深さ|生データの日時|訪問者（1 件だけ）
 * 項目の形（日付・日時・数字・区間）で見分け、どちらにも合わなければ null を返す。
 *
 * @param string $prov 番号の表の prov。
 * @return array|null [ page_key, stat_date, device, created_at, visitor_id, [ [ from, to ], … ] ]。読めなければ null。
 */
function kashiwazaki_seo_heatmap_parse_prov( $prov ) {
	$parts    = explode( '|', (string) $prov );
	$date     = '/^\d{4}-\d{2}-\d{2}$/';
	$datetime = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/';
	$depth    = '/^\d{1,3}$/';

	if ( 7 === count( $parts ) && preg_match( $date, $parts[1] ) && preg_match( $depth, $parts[3] ) && preg_match( $depth, $parts[4] ) && preg_match( $datetime, $parts[5] ) ) {
		return array( $parts[0], $parts[1], $parts[2], $parts[5], $parts[6], array( array( (int) $parts[3], (int) $parts[4] ) ) );
	}
	if ( 6 === count( $parts ) && preg_match( $date, $parts[1] ) && preg_match( $datetime, $parts[3] ) ) {
		$ranges = array();
		foreach ( explode( ',', $parts[5] ) as $range ) {
			if ( ! preg_match( '/^(\d{1,3})-(\d{1,3})$/', $range, $m ) ) {
				return null;
			}
			$ranges[] = array( (int) $m[1], (int) $m[2] );
		}
		return array( $parts[0], $parts[1], $parts[2], $parts[3], $parts[4], $ranges );
	}
	return null;
}

/**
 * 仮の到達率のまとまりを、置き換えのまとまりで置き換える（meta.rp の 1 つの番号）。
 *
 * 計測スクリプトは、読み込みの途中に隠れた・離れたときの到達率を、その時点の高さで「仮のまとまり」
 * （meta.pr=1）として送り、読み込みが終わった後に、読み込みの後の高さで「置き換えのまとまり」を送る。
 * ここでは、仮のまとまりが数えたもの（番号の表の prov）を引く。届いていなければ、置き換え済みの行を
 * 先に作り、後で届いても数えない。置き換えられるのは、仮のまとまりとして記録した行（と、届いていない
 * と知らされた行）だけ。普通のまとまりの数は引かない。
 * 公式: MySQL Locking Reads「SELECT ... FOR UPDATE ... locks the rows and any associated index entries,
 * the same as if you issued an UPDATE statement for those rows」。
 *
 * @param string $replaces 仮のまとまりの番号（32 桁の 16 進）。
 * @return bool 書き込みに失敗したら、または仮のまとまりの prov を読めなければ false。
 */
function kashiwazaki_seo_heatmap_replace_provisional( $replaces ) {
	global $wpdb;

	$batches = kashiwazaki_seo_heatmap_table( 'batches' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$inserted = $wpdb->query(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名はプレフィックス付きの固定値。
			"INSERT IGNORE INTO {$batches} (batch_id, created_at, prov) VALUES (%s, %s, 'x')",
			$replaces,
			current_time( 'mysql', true )
		)
	);
	if ( false === $inserted ) {
		return false;
	}
	if ( 1 === (int) $inserted ) {
		// 仮のまとまりはまだ届いていない。置き換え済みの行を作ったので、後で届いても数えない。
		return true;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$row = $wpdb->get_row(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名はプレフィックス付きの固定値。
			"SELECT lost_n, prov FROM {$batches} WHERE batch_id = %s FOR UPDATE",
			$replaces
		),
		ARRAY_A
	);
	// 読めなかった（SQL のエラー）ときは、置き換えを失敗にする（引かずに置き換えを保存すると二重に数える）。
	if ( '' !== $wpdb->last_error ) {
		return false;
	}
	if ( ! $row || 'x' === $row['prov'] ) {
		return true;
	}
	if ( '' === $row['prov'] ) {
		if ( (int) $row['lost_n'] > 0 ) {
			// 届いていないと知らされていた仮のまとまり。置き換えが代わりに届いたので、置き換え済みにする。
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return false !== $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名はプレフィックス付きの固定値。
					"UPDATE {$batches} SET prov = 'x', lost_n = 0 WHERE batch_id = %s",
					$replaces
				)
			);
		}
		// 普通のまとまりは置き換えない。
		return true;
	}

	// 到達率のイベントごとに数えた区間をすべて引き、生データの行をイベントごとに 1 行ずつ消す。
	// 読めない prov は、引かずに置き換え済みにすると仮の値と置き換えの値の両方が残る（二重に数える）ので、
	// 置き換えを失敗にする（取引を取り消し、仮の値だけを残す）。
	$prov = kashiwazaki_seo_heatmap_parse_prov( $row['prov'] );
	if ( null === $prov ) {
		return false;
	}
	list( $page_key, $stat_date, $device, $created_at, $visitor_id, $ranges ) = $prov;
	$stats  = kashiwazaki_seo_heatmap_table( 'stats' );
	$events = kashiwazaki_seo_heatmap_table( 'events' );
	foreach ( $ranges as $range ) {
		list( $from, $to ) = $range;
		foreach ( kashiwazaki_seo_heatmap_scroll_milestones() as $milestone ) {
			if ( $from < $milestone && $milestone <= $to ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$done = $wpdb->query(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名はプレフィックス付きの固定値。
						"UPDATE {$stats} SET count = IF(count > 0, count - 1, 0) WHERE page_key = %s AND stat_date = %s AND device = %s AND event_type = 'scroll' AND element_key = %s",
						$page_key,
						$stat_date,
						$device,
						'scroll:' . $milestone
					)
				);
				if ( false === $done ) {
					return false;
				}
			}
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$done = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名はプレフィックス付きの固定値。
				"DELETE FROM {$events} WHERE page_key = %s AND visitor_id = %s AND event_type = 'scroll' AND scroll_depth = %d AND created_at = %s LIMIT 1",
				$page_key,
				$visitor_id,
				$to,
				$created_at
			)
		);
		if ( false === $done ) {
			return false;
		}
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	return false !== $wpdb->query(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名はプレフィックス付きの固定値。
			"UPDATE {$batches} SET prov = 'x', lost_n = 0 WHERE batch_id = %s",
			$replaces
		)
	);
}

/**
 * 1 回の送信（まとまり）を、同じまとまりは 1 回だけ、全部か無しかで保存する。
 *
 * 計測スクリプトは、応答を確かめるまで同じまとまりを同じ番号で送り直す
 * （応答の遅れ・429・離脱で受け取れなかった分も、次のページで送り直す）。
 * 番号の表に INSERT IGNORE で記録し、既にあれば数えずに成功を返すので、
 * 送り直しで二重に数えない。生データ・集計・ページの書き込みは 1 つの取引にまとめ、
 * 1 つでも失敗したら取り消して失敗を返す（一部だけ数えて成功を返さない）。
 * 公式: MariaDB START TRANSACTION「changes to transaction-safe tables (such as those for
 * InnoDB or NDBCLUSTER) are not made permanent immediately. You must use COMMIT to store your
 * changes to disk or ROLLBACK to ignore the changes.」、INSERT IGNORE「Warnings are issued for
 * duplicate key errors when using IGNORE.」、wpdb::query「Number of rows affected/selected for
 * all other queries. Boolean false on error.」。
 *
 * 同じ取引の中で、次も行う（どれかが失敗したら、まとまりごと取り消す）。
 * - ほかの閲覧から「まだ届いていない」と知らされていた番号が届いたら、その印（lost_n）を戻して保存する。
 * - 置き換えのまとまり（meta.rp）なら、仮の到達率のまとまりの数を先に引く（replace_provisional）。
 * - 添えられた「まだ届いていない」番号を記録する（meta.lr、record_unarrived。送り直しの重複でも記録する）。
 * - 仮の到達率のまとまり（meta.pr=1、到達率のイベントが 1 件だけ）なら、数えたものを番号の行（prov）に残す。
 *
 * @param string $batch_id まとまりの番号（32 桁の 16 進。無ければ重複を見ない）。
 * @param array  $payload  受信したデータ。
 * @param array  $context  ページと訪問者の情報。
 * @return array 'status' は saved | duplicate | failed。
 */
function kashiwazaki_seo_heatmap_save_batch_once( $batch_id, $payload, $context ) {
	global $wpdb;

	$batches = kashiwazaki_seo_heatmap_table( 'batches' );
	$meta    = isset( $payload['p'] ) && is_array( $payload['p'] ) ? $payload['p'] : array();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
		return array( 'status' => 'failed' );
	}

	// この番号が「まだ届いていない」と知らされていたときの件数（取引の効かない表で失敗したら戻す）。
	$was_unarrived = 0;
	if ( '' !== $batch_id ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$marked = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名はプレフィックス付きの固定値。
				"INSERT IGNORE INTO {$batches} (batch_id, created_at) VALUES (%s, %s)",
				$batch_id,
				current_time( 'mysql', true )
			)
		);
		if ( false === $marked ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return array( 'status' => 'failed' );
		}
		if ( 0 === (int) $marked ) {
			// 番号の行が既にある。同時に届いた同じ番号の送信とは、行のロックで順に決める。
			// データベースの更新が終わっていない（列が無い・prov が短い）サイトでも読めるように、列を名指しせずに
			// 読む。lost_n の列があれば「まだ届いていない」と知らされていた行かを見る（列が無いサイトでは
			// その行は作られていない）。
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$row = $wpdb->get_row(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名はプレフィックス付きの固定値。
					"SELECT * FROM {$batches} WHERE batch_id = %s FOR UPDATE",
					$batch_id
				),
				ARRAY_A
			);
			// 読めなかった（SQL のエラー）ときは、重複とみなさずに失敗にする（届いていない印の行を見落とさない）。
			if ( '' !== $wpdb->last_error ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				return array( 'status' => 'failed' );
			}
			$replaced = $row && isset( $row['prov'] ) && 'x' === $row['prov'];
			if ( $row && isset( $row['lost_n'] ) && (int) $row['lost_n'] > 0 ) {
				// 「まだ届いていない」と知らされていた番号が届いた。印を戻す。
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$cleared = $wpdb->query(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名はプレフィックス付きの固定値。
						"UPDATE {$batches} SET lost_n = 0 WHERE batch_id = %s",
						$batch_id
					)
				);
				if ( false === $cleared ) {
					$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					return array( 'status' => 'failed' );
				}
				if ( ! $replaced ) {
					$was_unarrived = (int) $row['lost_n'];
				}
			}
			if ( 0 === $was_unarrived ) {
				// 同じまとまりを既に保存している（送り直し）か、置き換え済み（数えない）。置き換え済みの
				// 仮のまとまりが遅れて届いたときは、自分の数は足さないが、それが置き換える前の仮のまとまり
				// （鎖の途中が遅れて届いた場合）は引く。
				if ( $replaced ) {
					foreach ( kashiwazaki_seo_heatmap_replace_ids( $meta, $batch_id ) as $replaces ) {
						if ( ! kashiwazaki_seo_heatmap_replace_provisional( $replaces ) ) {
							$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
							return array( 'status' => 'failed' );
						}
					}
				}
				// 送り直しに添えられた知らせ（meta.lr）も記録する。前の送信で記録できなかった（データベースの
				// 更新の途中で 202 を返した・応答が届かなかった）知らせを、送り直しで記録し直すため。
				// 同じ番号は INSERT IGNORE で 1 回だけ記録される。
				if ( ! kashiwazaki_seo_heatmap_record_unarrived( $meta, $batch_id ) ) {
					$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					return array( 'status' => 'failed' );
				}
				// 確定できなかったら成功を返さない（知らせ・置き換えが記録されないまま、ブラウザに消させないため）。
				// 公式: wpdb::query「Boolean false on error.」。
				if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					return array( 'status' => 'failed' );
				}
				return array( 'status' => 'duplicate' );
			}
		}
	}

	$ok = true;
	foreach ( kashiwazaki_seo_heatmap_replace_ids( $meta, $batch_id ) as $replaces ) {
		if ( $ok ) {
			$ok = kashiwazaki_seo_heatmap_replace_provisional( $replaces );
		}
	}
	if ( $ok ) {
		$ok = kashiwazaki_seo_heatmap_record_unarrived( $meta, $batch_id );
	}

	$result = $ok ? kashiwazaki_seo_heatmap_save_batch( $payload, $context ) : array( 'failed' => 1 );

	if ( empty( $result['failed'] ) && '' !== $batch_id && ! empty( $meta['pr'] ) && ! empty( $result['scroll'] ) && kashiwazaki_seo_heatmap_schema_ready() ) {
		// 仮の到達率のまとまり。置き換えのときに引けるように、数えたもの（到達率のイベントごとの
		// 起点-深さの区間をすべて）を残す。
		$ranges = array();
		foreach ( $result['scroll'] as $pair ) {
			$ranges[] = (int) $pair[0] . '-' . (int) $pair[1];
		}
		$prov = implode(
			'|',
			array(
				(string) $context['page_key'],
				(string) $context['stat_date'],
				(string) $context['device'],
				(string) $context['created_at'],
				(string) $context['visitor_id'],
				implode( ',', $ranges ),
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$done = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名はプレフィックス付きの固定値。
				"UPDATE {$batches} SET prov = %s WHERE batch_id = %s",
				$prov,
				$batch_id
			)
		);
		if ( false === $done ) {
			$result['failed'] = 1;
		}
	}

	if ( ! empty( $result['failed'] ) ) {
		// 番号の記録も含めて取り消す。表が InnoDB なら ROLLBACK で全部が戻るので、取り消しの後に
		// 番号を消し直すことはしない（同じ番号で同時に届いた別の送信が保存を終えていれば、その番号
		// まで消して、次の送り直しを二重に数えてしまうため）。
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( '' !== $batch_id && ! kashiwazaki_seo_heatmap_tables_transactional() ) {
			// InnoDB への変換が済んでいない表がある間だけ（db.php の check_engines）。取引の効かない
			// 番号の表では ROLLBACK で番号が戻らないので、消して送り直しを受け付ける（数え漏れを避ける。
			// 先に書けた取引の効かない表の分は、送り直しで重なりうる）。「まだ届いていない」と知らされて
			// いた番号なら、消さずに印を戻す。
			if ( $was_unarrived > 0 ) {
				$wpdb->update( $batches, array( 'lost_n' => $was_unarrived ), array( 'batch_id' => $batch_id ), array( '%d' ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			} else {
				$wpdb->delete( $batches, array( 'batch_id' => $batch_id ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			}
		}
		$result['status'] = 'failed';
		return $result;
	}

	if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// 確定できなかった取引を閉じてから失敗を返す（重複の分岐と同じ。開いたままだと、同じ接続の後の
		// 書き込みがこの取引の中で行われる）。公式: MySQL「ROLLBACK rolls back the current transaction,
		// canceling its changes.」。
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result['status'] = 'failed';
		return $result;
	}

	$result['status'] = 'saved';
	return $result;
}

/**
 * 生データ 1 行を組み立てる。
 *
 * @param array $data 各種フィールド。
 * @return array 挿入用の行。
 */
function kashiwazaki_seo_heatmap_build_raw_row( $data ) {
	return array(
		'post_id'      => isset( $data['post_id'] ) ? (int) $data['post_id'] : 0,
		'page_key'     => isset( $data['page_key'] ) ? $data['page_key'] : '',
		'page_url'     => isset( $data['page_url'] ) ? $data['page_url'] : '',
		'event_type'   => isset( $data['type'] ) ? $data['type'] : '',
		'visitor_id'   => isset( $data['visitor_id'] ) ? $data['visitor_id'] : '',
		'session_id'   => isset( $data['session_id'] ) ? $data['session_id'] : '',
		'element_key'  => isset( $data['element_key'] ) ? $data['element_key'] : '',
		'selector'     => isset( $data['selector'] ) ? $data['selector'] : '',
		'element_index' => isset( $data['index'] ) ? (int) $data['index'] : 0,
		'element_tag'  => isset( $data['tag'] ) ? $data['tag'] : '',
		'element_text' => isset( $data['text'] ) ? $data['text'] : '',
		'pos_x'        => isset( $data['x'] ) ? kashiwazaki_seo_heatmap_to_float( $data['x'] ) : 0,
		'pos_y'        => isset( $data['y'] ) ? kashiwazaki_seo_heatmap_to_float( $data['y'] ) : 0,
		'pos_x_pct'    => isset( $data['xp'] ) ? kashiwazaki_seo_heatmap_to_float( $data['xp'] ) : 0,
		'pos_y_pct'    => isset( $data['yp'] ) ? kashiwazaki_seo_heatmap_to_float( $data['yp'] ) : 0,
		'doc_w'        => isset( $data['doc_w'] ) ? (int) $data['doc_w'] : 0,
		'doc_h'        => isset( $data['doc_h'] ) ? (int) $data['doc_h'] : 0,
		'viewport_w'   => isset( $data['vw'] ) ? (int) $data['vw'] : 0,
		'viewport_h'   => isset( $data['vh'] ) ? (int) $data['vh'] : 0,
		'scroll_depth' => isset( $data['depth'] ) ? (int) $data['depth'] : 0,
		'device'       => isset( $data['device'] ) ? $data['device'] : '',
		'created_at'   => isset( $data['created_at'] ) ? $data['created_at'] : current_time( 'mysql' ),
	);
}

/**
 * 集計 1 行を組み立てる。
 *
 * @param array $data 各種フィールド。
 * @return array 挿入用の行。
 */
function kashiwazaki_seo_heatmap_build_stats_row( $data ) {
	$count = isset( $data['count'] ) ? (int) $data['count'] : 1;
	// ここも有限の数値だけにする（INF は INSERT ごと失敗させる）。
	$x     = isset( $data['x'] ) ? kashiwazaki_seo_heatmap_to_float( $data['x'] ) : 0;
	$y     = isset( $data['y'] ) ? kashiwazaki_seo_heatmap_to_float( $data['y'] ) : 0;
	$xp    = isset( $data['xp'] ) ? kashiwazaki_seo_heatmap_to_float( $data['xp'] ) : 0;
	$yp    = isset( $data['yp'] ) ? kashiwazaki_seo_heatmap_to_float( $data['yp'] ) : 0;

	return array(
		'post_id'      => isset( $data['post_id'] ) ? (int) $data['post_id'] : 0,
		'page_key'     => isset( $data['page_key'] ) ? $data['page_key'] : '',
		'stat_date'    => isset( $data['stat_date'] ) ? $data['stat_date'] : kashiwazaki_seo_heatmap_date_days_ago( 0 ),
		'device'       => isset( $data['device'] ) ? $data['device'] : '',
		'event_type'   => isset( $data['event_type'] ) ? $data['event_type'] : '',
		'element_key'  => isset( $data['element_key'] ) ? $data['element_key'] : '',
		'selector'     => isset( $data['selector'] ) ? $data['selector'] : '',
		'element_index' => isset( $data['element_index'] ) ? (int) $data['element_index'] : 0,
		'element_tag'  => isset( $data['element_tag'] ) ? $data['element_tag'] : '',
		'element_text' => isset( $data['element_text'] ) ? $data['element_text'] : '',
		'count'        => $count,
		'sum_x'        => $x * $count,
		'sum_y'        => $y * $count,
		'sum_x_pct'    => $xp * $count,
		'sum_y_pct'    => $yp * $count,
	);
}

/**
 * マウスムーブを格子 (10x10) のキーに丸める。
 *
 * @param float $x     文書内 X 座標。
 * @param float $y     文書内 Y 座標。
 * @param int   $doc_w 文書幅。
 * @param int   $doc_h 文書高。
 * @return string 格子キー。
 */
function kashiwazaki_seo_heatmap_move_grid_key( $x, $y, $doc_w, $doc_h ) {
	$doc_w = $doc_w > 0 ? $doc_w : 1;
	$doc_h = $doc_h > 0 ? $doc_h : 1;

	$col = (int) floor( ( $x / $doc_w ) * 10 );
	$row = (int) floor( ( $y / $doc_h ) * 10 );
	$col = min( 9, max( 0, $col ) );
	$row = min( 9, max( 0, $row ) );

	return 'grid:' . $col . ':' . $row;
}

/**
 * この訪問者がその日にこのページを初めて見たか判定する（訪問者数の重複を防ぐ）。
 *
 * @param string $page_key   ページキー。
 * @param string $visitor_id 訪問者 ID。
 * @param string $stat_date  見た日（Y-m-d。省略すると今日）。遅れて届いたページビューは、見た日で判定する。
 * @return bool|null 初回なら true。読めなかったら null。
 */
function kashiwazaki_seo_heatmap_is_new_visitor_today( $page_key, $visitor_id, $stat_date = '' ) {
	global $wpdb;

	if ( '' === $visitor_id ) {
		return false;
	}

	if ( ! is_string( $stat_date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $stat_date ) ) {
		$stat_date = kashiwazaki_seo_heatmap_date_days_ago( 0 );
	}
	$table      = kashiwazaki_seo_heatmap_table( 'events' );
	$day_start  = $stat_date . ' 00:00:00';
	$next_start = gmdate( 'Y-m-d', strtotime( $stat_date . ' 00:00:00 UTC' ) + DAY_IN_SECONDS ) . ' 00:00:00';

	$sql = $wpdb->prepare(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		"SELECT id FROM {$table} WHERE page_key = %s AND visitor_id = %s AND event_type = 'pageview' AND created_at >= %s AND created_at < %s LIMIT 1",
		$page_key,
		$visitor_id,
		$day_start,
		$next_start
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
	$found = $wpdb->get_var( $sql );

	// 読めなかった（SQL のエラー）ときは null（初回とみなして訪問者を数え過ぎない。呼び出し側が保存を失敗にする）。
	if ( '' !== $wpdb->last_error ) {
		return null;
	}
	return empty( $found );
}

/**
 * セレクタ文字列を検証する（CSS セレクタとして妥当な文字だけ残す）。
 *
 * @param string $selector セレクタ。
 * @return string 検証済みセレクタ。
 */
function kashiwazaki_seo_heatmap_sanitize_selector( $selector ) {
	$selector = (string) $selector;
	// 制御文字を除去する。
	$selector = preg_replace( '/[\x00-\x1F\x7F]/u', '', $selector );
	// 長さを制限する（selector は varchar(255) なので 240 文字なら収まる）。
	return kashiwazaki_seo_heatmap_truncate( $selector, 240 );
}
