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
	$created_at = isset( $context['created_at'] ) ? $context['created_at'] : current_time( 'mysql' );
	$stat_date  = kashiwazaki_seo_heatmap_date_days_ago( 0 );

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
		$is_new_visitor = kashiwazaki_seo_heatmap_is_new_visitor_today( $page_key, $visitor_id );

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
		'events' => 0,
		'stats'  => 0,
		'pages'  => 0,
	);

	if ( ! empty( $raw_rows ) ) {
		$result['events'] = kashiwazaki_seo_heatmap_insert_events( $raw_rows );
	}
	if ( ! empty( $stats_rows ) ) {
		$result['stats'] = kashiwazaki_seo_heatmap_bump_stats( $stats_rows );
	}
	if ( ! empty( $pageview_rows ) ) {
		$result['pages'] = kashiwazaki_seo_heatmap_bump_pages( $pageview_rows );
	}

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
 * この訪問者が今日このページを初めて見たか判定する（訪問者数の重複を防ぐ）。
 *
 * @param string $page_key   ページキー。
 * @param string $visitor_id 訪問者 ID。
 * @return bool 初回なら true。
 */
function kashiwazaki_seo_heatmap_is_new_visitor_today( $page_key, $visitor_id ) {
	global $wpdb;

	if ( '' === $visitor_id ) {
		return false;
	}

	$table     = kashiwazaki_seo_heatmap_table( 'events' );
	$start_utc = kashiwazaki_seo_heatmap_date_days_ago( 0 ) . ' 00:00:00';

	$sql = $wpdb->prepare(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		"SELECT id FROM {$table} WHERE page_key = %s AND visitor_id = %s AND event_type = 'pageview' AND created_at >= %s LIMIT 1",
		$page_key,
		$visitor_id,
		$start_utc
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
	$found = $wpdb->get_var( $sql );

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
