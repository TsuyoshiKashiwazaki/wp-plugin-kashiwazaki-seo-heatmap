<?php
/**
 * データベースのスキーマ作成と読み書き。
 *
 * 生の生データ（events）と、集計済みの統計（stats / pages）を分けて持つ。
 * 管理画面は常に集計テーブルを読むため、生データが増えても表示は遅くならない。
 *
 * @package Kashiwazaki_SEO_Heatmap
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * テーブルを作成・更新する（dbDelta）。
 *
 * @return array dbDelta の結果。
 */
function kashiwazaki_seo_heatmap_create_tables() {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$charset_collate = $wpdb->get_charset_collate();
	$events          = kashiwazaki_seo_heatmap_table( 'events' );
	$stats           = kashiwazaki_seo_heatmap_table( 'stats' );
	$pages           = kashiwazaki_seo_heatmap_table( 'pages' );

	$sql = array();

	// 生イベント（保持期間を過ぎたらクーロンで削除する）。
	$sql[] = "CREATE TABLE {$events} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		post_id bigint(20) unsigned NOT NULL DEFAULT 0,
		page_key char(32) NOT NULL DEFAULT '',
		page_url varchar(255) NOT NULL DEFAULT '',
		event_type varchar(12) NOT NULL DEFAULT '',
		visitor_id char(32) NOT NULL DEFAULT '',
		session_id char(32) NOT NULL DEFAULT '',
		element_key char(32) NOT NULL DEFAULT '',
		selector varchar(255) NOT NULL DEFAULT '',
		element_index int(11) NOT NULL DEFAULT 0,
		element_tag varchar(32) NOT NULL DEFAULT '',
		element_text varchar(150) NOT NULL DEFAULT '',
		pos_x double NOT NULL DEFAULT 0,
		pos_y double NOT NULL DEFAULT 0,
		pos_x_pct double NOT NULL DEFAULT 0,
		pos_y_pct double NOT NULL DEFAULT 0,
		doc_w int(11) NOT NULL DEFAULT 0,
		doc_h int(11) NOT NULL DEFAULT 0,
		viewport_w int(11) NOT NULL DEFAULT 0,
		viewport_h int(11) NOT NULL DEFAULT 0,
		scroll_depth tinyint(4) NOT NULL DEFAULT 0,
		device varchar(10) NOT NULL DEFAULT '',
		created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		KEY post_date (post_id, created_at),
		KEY type_date (event_type, created_at),
		KEY created (created_at),
		KEY visitor (visitor_id),
		KEY session (session_id),
		KEY page_key (page_key)
	) {$charset_collate};";

	// 集計（ページ × 日付 × 端末 × 種別 × 要素）。
	$sql[] = "CREATE TABLE {$stats} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		post_id bigint(20) unsigned NOT NULL DEFAULT 0,
		page_key char(32) NOT NULL DEFAULT '',
		stat_date date NOT NULL DEFAULT '1970-01-01',
		device varchar(10) NOT NULL DEFAULT '',
		event_type varchar(12) NOT NULL DEFAULT '',
		element_key char(32) NOT NULL DEFAULT '',
		selector varchar(255) NOT NULL DEFAULT '',
		element_index int(11) NOT NULL DEFAULT 0,
		element_tag varchar(32) NOT NULL DEFAULT '',
		element_text varchar(150) NOT NULL DEFAULT '',
		count bigint(20) unsigned NOT NULL DEFAULT 0,
		sum_x double NOT NULL DEFAULT 0,
		sum_y double NOT NULL DEFAULT 0,
		sum_x_pct double NOT NULL DEFAULT 0,
		sum_y_pct double NOT NULL DEFAULT 0,
		PRIMARY KEY  (id),
		UNIQUE KEY uniq_stat (page_key, stat_date, device, event_type, element_key),
		KEY post_date (post_id, stat_date, event_type),
		KEY element (element_key),
		KEY type_date (event_type, stat_date)
	) {$charset_collate};";

	// ページ単位の統計（閲覧数・訪問者数・スクロール・滞在時間）。
	$sql[] = "CREATE TABLE {$pages} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		post_id bigint(20) unsigned NOT NULL DEFAULT 0,
		page_key char(32) NOT NULL DEFAULT '',
		page_url varchar(255) NOT NULL DEFAULT '',
		stat_date date NOT NULL DEFAULT '1970-01-01',
		device varchar(10) NOT NULL DEFAULT '',
		pageviews bigint(20) unsigned NOT NULL DEFAULT 0,
		visitors bigint(20) unsigned NOT NULL DEFAULT 0,
		entrances bigint(20) unsigned NOT NULL DEFAULT 0,
		total_time double NOT NULL DEFAULT 0,
		PRIMARY KEY  (id),
		UNIQUE KEY uniq_page (page_key, stat_date, device),
		KEY post_date (post_id, stat_date),
		KEY stat_date (stat_date)
	) {$charset_collate};";

	return dbDelta( $sql );
}

/**
 * 生イベントを一括挿入する。
 *
 * @param array $rows 挿入する行（連想配列の配列）。
 * @return int 挿入した件数。
 */
function kashiwazaki_seo_heatmap_insert_events( $rows ) {
	global $wpdb;

	if ( empty( $rows ) ) {
		return 0;
	}

	$table   = kashiwazaki_seo_heatmap_table( 'events' );
	$columns = array(
		'post_id',
		'page_key',
		'page_url',
		'event_type',
		'visitor_id',
		'session_id',
		'element_key',
		'selector',
		'element_index',
		'element_tag',
		'element_text',
		'pos_x',
		'pos_y',
		'pos_x_pct',
		'pos_y_pct',
		'doc_w',
		'doc_h',
		'viewport_w',
		'viewport_h',
		'scroll_depth',
		'device',
		'created_at',
	);

	$inserted = 0;
	// 1 行ずつではなく、まとめてプレースホルダを組み立てて一括挿入する。
	foreach ( array_chunk( $rows, 100 ) as $chunk ) {
		$placeholders = array();
		$values       = array();

		foreach ( $chunk as $row ) {
			$row_placeholders = array();
			foreach ( $columns as $column ) {
				$value              = isset( $row[ $column ] ) ? $row[ $column ] : '';
				$row_placeholders[] = ( '%d' === kashiwazaki_seo_heatmap_column_placeholder( $column ) ) ? '%d' : ( '%f' === kashiwazaki_seo_heatmap_column_placeholder( $column ) ? '%f' : '%s' );
				$values[]           = $value;
			}
			$placeholders[] = '(' . implode( ',', $row_placeholders ) . ')';
		}

		$sql = 'INSERT INTO ' . $table . ' (' . implode( ',', $columns ) . ') VALUES ' . implode( ',', $placeholders );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- プレースホルダは下で prepare に渡している。
		$prepared = $wpdb->prepare( $sql, $values );
		if ( $prepared ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
			$result = $wpdb->query( $prepared );
			if ( false !== $result ) {
				$inserted += (int) $result;
			}
		}
	}

	return $inserted;
}

/**
 * 列に対応する prepare のプレースホルダを返す。
 *
 * @param string $column 列名。
 * @return string %d | %f | %s。
 */
function kashiwazaki_seo_heatmap_column_placeholder( $column ) {
	$int_columns = array( 'post_id', 'element_index', 'doc_w', 'doc_h', 'viewport_w', 'viewport_h', 'scroll_depth' );
	$float_columns = array( 'pos_x', 'pos_y', 'pos_x_pct', 'pos_y_pct' );

	if ( in_array( $column, $int_columns, true ) ) {
		return '%d';
	}
	if ( in_array( $column, $float_columns, true ) ) {
		return '%f';
	}
	return '%s';
}

/**
 * 集計テーブルを加算更新する（同一キーは count を加算）。
 *
 * @param array $rows 集計行。page_key, stat_date, device, event_type, element_key, selector,
 *                    element_index, element_tag, element_text, count, sum_x, sum_y, sum_x_pct, sum_y_pct, post_id。
 * @return int 処理した行数。
 */
function kashiwazaki_seo_heatmap_bump_stats( $rows ) {
	global $wpdb;

	if ( empty( $rows ) ) {
		return 0;
	}

	$table = kashiwazaki_seo_heatmap_table( 'stats' );

	// 同じキーを行内でまとめる。
	$merged = array();
	foreach ( $rows as $row ) {
		$key = $row['page_key'] . '|' . $row['stat_date'] . '|' . $row['device'] . '|' . $row['event_type'] . '|' . $row['element_key'];
		if ( ! isset( $merged[ $key ] ) ) {
			$merged[ $key ] = $row;
		} else {
			$merged[ $key ]['count']     = (int) $merged[ $key ]['count'] + (int) $row['count'];
			$merged[ $key ]['sum_x']     = (float) $merged[ $key ]['sum_x'] + (float) $row['sum_x'];
			$merged[ $key ]['sum_y']     = (float) $merged[ $key ]['sum_y'] + (float) $row['sum_y'];
			$merged[ $key ]['sum_x_pct'] = (float) $merged[ $key ]['sum_x_pct'] + (float) $row['sum_x_pct'];
			$merged[ $key ]['sum_y_pct'] = (float) $merged[ $key ]['sum_y_pct'] + (float) $row['sum_y_pct'];
		}
	}

	$processed = 0;
	foreach ( $merged as $row ) {
		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名はプレフィックス付きの固定値。
			"INSERT INTO {$table}
				(post_id, page_key, stat_date, device, event_type, element_key, selector, element_index, element_tag, element_text, count, sum_x, sum_y, sum_x_pct, sum_y_pct)
			VALUES (%d, %s, %s, %s, %s, %s, %s, %d, %s, %s, %d, %f, %f, %f, %f)
			ON DUPLICATE KEY UPDATE
				count = count + VALUES(count),
				sum_x = sum_x + VALUES(sum_x),
				sum_y = sum_y + VALUES(sum_y),
				sum_x_pct = sum_x_pct + VALUES(sum_x_pct),
				sum_y_pct = sum_y_pct + VALUES(sum_y_pct),
				post_id = VALUES(post_id),
				selector = VALUES(selector),
				element_index = VALUES(element_index),
				element_tag = VALUES(element_tag),
				element_text = VALUES(element_text)",
			(int) $row['post_id'],
			$row['page_key'],
			$row['stat_date'],
			$row['device'],
			$row['event_type'],
			$row['element_key'],
			$row['selector'],
			(int) $row['element_index'],
			$row['element_tag'],
			$row['element_text'],
			(int) $row['count'],
			(float) $row['sum_x'],
			(float) $row['sum_y'],
			(float) $row['sum_x_pct'],
			(float) $row['sum_y_pct']
		);

		if ( $sql ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
			$result = $wpdb->query( $sql );
			if ( false !== $result ) {
				$processed++;
			}
		}
	}

	return $processed;
}

/**
 * ページ統計テーブルを加算更新する。
 *
 * @param array $rows page_key, stat_date, device, post_id, page_url, pageviews, visitors,
 *                    entrances, total_time を持つ行の配列。
 * @return int 処理した行数。
 */
function kashiwazaki_seo_heatmap_bump_pages( $rows ) {
	global $wpdb;

	if ( empty( $rows ) ) {
		return 0;
	}

	$table = kashiwazaki_seo_heatmap_table( 'pages' );

	$merged = array();
	foreach ( $rows as $row ) {
		$key = $row['page_key'] . '|' . $row['stat_date'] . '|' . $row['device'];
		if ( ! isset( $merged[ $key ] ) ) {
			$merged[ $key ] = $row;
		} else {
			$merged[ $key ]['pageviews']    = (int) $merged[ $key ]['pageviews'] + (int) $row['pageviews'];
			$merged[ $key ]['visitors']     = (int) $merged[ $key ]['visitors'] + (int) $row['visitors'];
			$merged[ $key ]['entrances']    = (int) $merged[ $key ]['entrances'] + (int) $row['entrances'];
			$merged[ $key ]['total_time']   = (float) $merged[ $key ]['total_time'] + (float) $row['total_time'];
		}
	}

	$processed = 0;
	foreach ( $merged as $row ) {
		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名はプレフィックス付きの固定値。
			"INSERT INTO {$table}
				(post_id, page_key, page_url, stat_date, device, pageviews, visitors, entrances, total_time)
			VALUES (%d, %s, %s, %s, %s, %d, %d, %d, %f)
			ON DUPLICATE KEY UPDATE
				pageviews = pageviews + VALUES(pageviews),
				visitors = visitors + VALUES(visitors),
				entrances = entrances + VALUES(entrances),
				total_time = total_time + VALUES(total_time),
				post_id = VALUES(post_id),
				page_url = VALUES(page_url)",
			(int) $row['post_id'],
			$row['page_key'],
			(string) $row['page_url'],
			$row['stat_date'],
			$row['device'],
			(int) $row['pageviews'],
			(int) $row['visitors'],
			(int) $row['entrances'],
			(float) $row['total_time']
		);

		if ( $sql ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
			$result = $wpdb->query( $sql );
			if ( false !== $result ) {
				$processed++;
			}
		}
	}

	return $processed;
}

/**
 * 集計済みの要素別クリック統計を取得する。
 *
 * @param array $args 絞り込み条件。
 * @return array 行の配列。
 */
function kashiwazaki_seo_heatmap_get_element_stats( $args = array() ) {
	global $wpdb;

	$args = wp_parse_args(
		$args,
		array(
			'post_id'    => 0,
			'page_key'   => '',
			'date_from'  => kashiwazaki_seo_heatmap_date_days_ago( 30 ),
			'date_to'    => kashiwazaki_seo_heatmap_date_days_ago( 0 ),
			'device'     => '',
			'event_type' => 'click',
			'limit'      => 0,
		)
	);

	$table = kashiwazaki_seo_heatmap_table( 'stats' );

	$where    = array();
	$params   = array();

	if ( ! empty( $args['page_key'] ) ) {
		$where[]  = 'page_key = %s';
		$params[] = $args['page_key'];
	} elseif ( (int) $args['post_id'] > 0 ) {
		$where[]  = 'post_id = %d';
		$params[] = (int) $args['post_id'];
	} else {
		return array();
	}

	$where[]  = 'stat_date >= %s';
	$params[] = $args['date_from'];
	$where[]  = 'stat_date <= %s';
	$params[] = $args['date_to'];

	if ( '' !== $args['device'] ) {
		$where[]  = 'device = %s';
		$params[] = $args['device'];
	}

	$where[]  = 'event_type = %s';
	$params[] = $args['event_type'];

	$sql = "SELECT element_key, MAX(selector) AS selector, MAX(element_index) AS element_index,
			MAX(element_tag) AS element_tag, MAX(element_text) AS element_text,
			SUM(count) AS total, SUM(sum_x) AS sum_x, SUM(sum_y) AS sum_y,
			SUM(sum_x_pct) AS sum_x_pct, SUM(sum_y_pct) AS sum_y_pct
		FROM {$table}
		WHERE " . implode( ' AND ', $where ) . '
		GROUP BY element_key
		ORDER BY total DESC';

	if ( (int) $args['limit'] > 0 ) {
		$sql .= ' LIMIT ' . (int) $args['limit'];
	}

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql はこの直後に prepare へ渡す。
	$prepared = $wpdb->prepare( $sql, $params );
	if ( ! $prepared ) {
		return array();
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
	return $wpdb->get_results( $prepared, ARRAY_A );
}

/**
 * スクロール到達率の分布（10%刻み）を取得する。
 *
 * @param array $args 絞り込み条件（post_id / page_key / date_from / date_to / device）。
 * @return array depth => count。
 */
function kashiwazaki_seo_heatmap_get_scroll_distribution( $args = array() ) {
	global $wpdb;

	$args = wp_parse_args(
		$args,
		array(
			'post_id'   => 0,
			'page_key'  => '',
			'date_from' => kashiwazaki_seo_heatmap_date_days_ago( 30 ),
			'date_to'   => kashiwazaki_seo_heatmap_date_days_ago( 0 ),
			'device'    => '',
		)
	);

	$table = kashiwazaki_seo_heatmap_table( 'stats' );

	$where  = array();
	$params = array();

	if ( ! empty( $args['page_key'] ) ) {
		$where[]  = 'page_key = %s';
		$params[] = $args['page_key'];
	} elseif ( (int) $args['post_id'] > 0 ) {
		$where[]  = 'post_id = %d';
		$params[] = (int) $args['post_id'];
	} else {
		return array();
	}

	$where[]  = 'stat_date >= %s';
	$params[] = $args['date_from'];
	$where[]  = 'stat_date <= %s';
	$params[] = $args['date_to'];
	$where[]  = "event_type = 'scroll'";

	if ( '' !== $args['device'] ) {
		$where[]  = 'device = %s';
		$params[] = $args['device'];
	}

	$sql = "SELECT element_key, SUM(count) AS total FROM {$table}
		WHERE " . implode( ' AND ', $where ) . '
		GROUP BY element_key';

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$prepared = $wpdb->prepare( $sql, $params );
	if ( ! $prepared ) {
		return array();
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
	$rows = $wpdb->get_results( $prepared, ARRAY_A );
	if ( ! is_array( $rows ) ) {
		$rows = array();
	}

	$result = array();
	foreach ( kashiwazaki_seo_heatmap_scroll_milestones() as $milestone ) {
		$result[ $milestone ] = 0;
	}
	foreach ( $rows as $row ) {
		// element_key は "scroll:NN" 形式。
		if ( preg_match( '/^scroll:(\d{1,3})$/', (string) $row['element_key'], $m ) ) {
			$depth = (int) $m[1];
			if ( isset( $result[ $depth ] ) ) {
				$result[ $depth ] += (int) $row['total'];
			}
		}
	}

	return $result;
}

/**
 * ページ全体の統計（閲覧数・訪問者数・平均滞在時間など）を取得する。
 *
 * @param array $args 絞り込み条件。
 * @return array 合計値の連想配列。
 */
function kashiwazaki_seo_heatmap_get_page_totals( $args = array() ) {
	global $wpdb;

	$args = wp_parse_args(
		$args,
		array(
			'post_id'   => 0,
			'page_key'  => '',
			'date_from' => kashiwazaki_seo_heatmap_date_days_ago( 30 ),
			'date_to'   => kashiwazaki_seo_heatmap_date_days_ago( 0 ),
			'device'    => '',
		)
	);

	$table = kashiwazaki_seo_heatmap_table( 'pages' );

	$where  = array();
	$params = array();

	// ページを絞らない場合はサイト全体の合計を返す（ダッシュボードで使う）。
	if ( ! empty( $args['page_key'] ) ) {
		$where[]  = 'page_key = %s';
		$params[] = $args['page_key'];
	} elseif ( (int) $args['post_id'] > 0 ) {
		$where[]  = 'post_id = %d';
		$params[] = (int) $args['post_id'];
	}

	$where[]  = 'stat_date >= %s';
	$params[] = $args['date_from'];
	$where[]  = 'stat_date <= %s';
	$params[] = $args['date_to'];

	if ( '' !== $args['device'] ) {
		$where[]  = 'device = %s';
		$params[] = $args['device'];
	}

	$sql = "SELECT SUM(pageviews) AS pageviews, SUM(visitors) AS visitors, SUM(entrances) AS entrances,
			SUM(total_time) AS total_time
		FROM {$table}
		WHERE " . implode( ' AND ', $where );

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$prepared = $wpdb->prepare( $sql, $params );
	if ( ! $prepared ) {
		return array();
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
	$row = $wpdb->get_row( $prepared, ARRAY_A );

	return is_array( $row ) ? $row : array();
}

/**
 * サイト全体でよく見られているページを取得する。
 *
 * @param array $args 絞り込み条件。
 * @return array 行の配列。
 */
function kashiwazaki_seo_heatmap_get_top_pages( $args = array() ) {
	global $wpdb;

	$args = wp_parse_args(
		$args,
		array(
			'date_from' => kashiwazaki_seo_heatmap_date_days_ago( 30 ),
			'date_to'   => kashiwazaki_seo_heatmap_date_days_ago( 0 ),
			'device'    => '',
			'limit'     => 20,
			'post_type' => '',
		)
	);

	$table  = kashiwazaki_seo_heatmap_table( 'pages' );
	$where  = array( 'stat_date >= %s', 'stat_date <= %s' );
	$params = array( $args['date_from'], $args['date_to'] );

	if ( '' !== $args['device'] ) {
		$where[]  = 'device = %s';
		$params[] = $args['device'];
	}

	// 投稿タイプで絞り込む場合は、その投稿タイプの投稿 ID に限定する。
	$post_types = array_filter( array_map( 'sanitize_key', (array) $args['post_type'] ) );
	if ( ! empty( $post_types ) ) {
		$placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
		$where[]      = "post_id IN ( SELECT ID FROM {$wpdb->posts} WHERE post_type IN ( {$placeholders} ) )";
		foreach ( $post_types as $type ) {
			$params[] = $type;
		}
	}

	$sql = "SELECT page_key, MAX(page_url) AS page_url, MAX(post_id) AS post_id,
			SUM(pageviews) AS pageviews, SUM(visitors) AS visitors, SUM(entrances) AS entrances,
			SUM(total_time) AS total_time
		FROM {$table}
		WHERE " . implode( ' AND ', $where ) . '
		GROUP BY page_key
		ORDER BY pageviews DESC
		LIMIT ' . (int) $args['limit'];

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$prepared = $wpdb->prepare( $sql, $params );
	if ( ! $prepared ) {
		return array();
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
	return $wpdb->get_results( $prepared, ARRAY_A );
}

/**
 * 日別の推移（閲覧数・クリック数）を取得する。
 *
 * @param array $args 絞り込み条件。
 * @return array 日付 => array( pageviews, visitors, clicks )。
 */
function kashiwazaki_seo_heatmap_get_daily_series( $args = array() ) {
	global $wpdb;

	$args = wp_parse_args(
		$args,
		array(
			'post_id'   => 0,
			'page_key'  => '',
			'date_from' => kashiwazaki_seo_heatmap_date_days_ago( 30 ),
			'date_to'   => kashiwazaki_seo_heatmap_date_days_ago( 0 ),
			'device'    => '',
		)
	);

	$pages_table = kashiwazaki_seo_heatmap_table( 'pages' );
	$stats_table = kashiwazaki_seo_heatmap_table( 'stats' );

	$p_where  = array( 'stat_date >= %s', 'stat_date <= %s' );
	$p_params = array( $args['date_from'], $args['date_to'] );

	if ( ! empty( $args['page_key'] ) ) {
		$p_where[]  = 'page_key = %s';
		$p_params[] = $args['page_key'];
	} elseif ( (int) $args['post_id'] > 0 ) {
		$p_where[]  = 'post_id = %d';
		$p_params[] = (int) $args['post_id'];
	}

	if ( '' !== $args['device'] ) {
		$p_where[]  = 'device = %s';
		$p_params[] = $args['device'];
	}

	$sql = "SELECT stat_date, SUM(pageviews) AS pageviews, SUM(visitors) AS visitors
		FROM {$pages_table} WHERE " . implode( ' AND ', $p_where ) . ' GROUP BY stat_date';

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$prepared = $wpdb->prepare( $sql, $p_params );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
	$page_rows = $prepared ? $wpdb->get_results( $prepared, ARRAY_A ) : array();

	$s_where  = array( "event_type = 'click'", 'stat_date >= %s', 'stat_date <= %s' );
	$s_params = array( $args['date_from'], $args['date_to'] );

	if ( ! empty( $args['page_key'] ) ) {
		$s_where[]  = 'page_key = %s';
		$s_params[] = $args['page_key'];
	} elseif ( (int) $args['post_id'] > 0 ) {
		$s_where[]  = 'post_id = %d';
		$s_params[] = (int) $args['post_id'];
	}

	if ( '' !== $args['device'] ) {
		$s_where[]  = 'device = %s';
		$s_params[] = $args['device'];
	}

	$sql2 = "SELECT stat_date, SUM(count) AS clicks FROM {$stats_table}
		WHERE " . implode( ' AND ', $s_where ) . ' GROUP BY stat_date';

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$prepared2 = $wpdb->prepare( $sql2, $s_params );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
	$click_rows = $prepared2 ? $wpdb->get_results( $prepared2, ARRAY_A ) : array();

	$result = array();
	foreach ( $page_rows as $row ) {
		$result[ $row['stat_date'] ] = array(
			'pageviews' => (int) $row['pageviews'],
			'visitors'  => (int) $row['visitors'],
			'clicks'    => 0,
		);
	}
	foreach ( $click_rows as $row ) {
		if ( ! isset( $result[ $row['stat_date'] ] ) ) {
			$result[ $row['stat_date'] ] = array(
				'pageviews' => 0,
				'visitors'  => 0,
				'clicks'    => 0,
			);
		}
		$result[ $row['stat_date'] ]['clicks'] = (int) $row['clicks'];
	}

	ksort( $result );
	return $result;
}

/**
 * 管理画面のドロップダウン用に、計測実績のあるページ一覧を返す。
 *
 * @param int $days 何日前までを対象にするか。
 * @return array 行の配列（page_key, page_url, post_id）。
 */
function kashiwazaki_seo_heatmap_get_tracked_pages( $days = 90 ) {
	global $wpdb;

	$table  = kashiwazaki_seo_heatmap_table( 'pages' );
	$from   = kashiwazaki_seo_heatmap_date_days_ago( $days );
	$sql    = $wpdb->prepare(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		"SELECT page_key, MAX(page_url) AS page_url, MAX(post_id) AS post_id, SUM(pageviews) AS pageviews
		FROM {$table} WHERE stat_date >= %s GROUP BY page_key ORDER BY pageviews DESC LIMIT 500",
		$from
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
	return $wpdb->get_results( $sql, ARRAY_A );
}

/**
 * 期間内の合計クリック数を取得する。
 *
 * @param array $args 絞り込み条件。
 * @return int 合計クリック数。
 */
function kashiwazaki_seo_heatmap_get_total_clicks( $args = array() ) {
	global $wpdb;

	$args = wp_parse_args(
		$args,
		array(
			'post_id'   => 0,
			'page_key'  => '',
			'date_from' => kashiwazaki_seo_heatmap_date_days_ago( 30 ),
			'date_to'   => kashiwazaki_seo_heatmap_date_days_ago( 0 ),
			'device'    => '',
			'event_type' => 'click',
		)
	);

	$table  = kashiwazaki_seo_heatmap_table( 'stats' );
	$where  = array( 'event_type = %s', 'stat_date >= %s', 'stat_date <= %s' );
	$params = array( $args['event_type'], $args['date_from'], $args['date_to'] );

	if ( ! empty( $args['page_key'] ) ) {
		$where[]  = 'page_key = %s';
		$params[] = $args['page_key'];
	} elseif ( (int) $args['post_id'] > 0 ) {
		$where[]  = 'post_id = %d';
		$params[] = (int) $args['post_id'];
	}

	if ( '' !== $args['device'] ) {
		$where[]  = 'device = %s';
		$params[] = $args['device'];
	}

	$sql = "SELECT SUM(count) FROM {$table} WHERE " . implode( ' AND ', $where );

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$prepared = $wpdb->prepare( $sql, $params );
	if ( ! $prepared ) {
		return 0;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
	return (int) $wpdb->get_var( $prepared );
}

/**
 * すべてのデータ（生データ・集計・設定）を消す。
 *
 * @return void
 */
function kashiwazaki_seo_heatmap_delete_all_data() {
	global $wpdb;

	foreach ( array( 'events', 'stats', 'pages' ) as $which ) {
		$table = kashiwazaki_seo_heatmap_table( $which );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE {$table}" );
	}
}
