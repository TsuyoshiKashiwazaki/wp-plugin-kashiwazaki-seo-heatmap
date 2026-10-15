<?php
/**
 * 保持期間を超えたデータの削除。
 *
 * @package Kashiwazaki_SEO_Heatmap
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 保持期間を過ぎた生データを削除する（クーロンから呼ばれる）。
 *
 * 集計テーブル（stats / pages）はサイズが小さいため残す。
 *
 * @return int 削除した行数。
 */
function kashiwazaki_seo_heatmap_run_cleanup() {
	global $wpdb;

	// 送信のまとまりの番号は、計測スクリプトがブラウザに残して送り直す期間（7 日）を
	// 過ぎたら要らないので、自動削除の設定によらず 8 日より古いものを消す。
	$batches = kashiwazaki_seo_heatmap_table( 'batches' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- テーブル名はプレフィックス付きの固定値。
			"DELETE FROM {$batches} WHERE created_at < %s",
			gmdate( 'Y-m-d H:i:s', time() - 8 * DAY_IN_SECONDS )
		)
	);

	// 表の変換が済んでいないのに予約が無ければ、入れ直す（予約の実行が失敗して消えた場合）。
	if ( ! kashiwazaki_seo_heatmap_tables_transactional() && ! wp_next_scheduled( 'kashiwazaki_seo_heatmap_convert_event' ) ) {
		wp_schedule_single_event( time(), 'kashiwazaki_seo_heatmap_convert_event' );
	}

	$settings = kashiwazaki_seo_heatmap_get_settings();
	if ( empty( $settings['enable_auto_cleanup'] ) ) {
		return 0;
	}

	$days    = max( 1, (int) $settings['retention_days'] );
	$table   = kashiwazaki_seo_heatmap_table( 'events' );
	$cutoff  = kashiwazaki_seo_heatmap_date_days_ago( $days ) . ' 00:00:00';

	$sql = $wpdb->prepare(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		"DELETE FROM {$table} WHERE created_at < %s",
		$cutoff
	);

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
	$deleted = $wpdb->query( $sql );

	return (int) $deleted;
}

/**
 * 管理画面の「データをすべて削除」で呼ばれる一括削除。
 *
 * @return void
 */
function kashiwazaki_seo_heatmap_handle_purge_request() {
	if ( ! is_admin() || ! current_user_can( KASHIWAZAKI_SEO_HEATMAP_CAPABILITY ) ) {
		return;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce は下で検証する。
	$purge = isset( $_GET['kashiwazaki_seo_heatmap_purge'] )
		? kashiwazaki_seo_heatmap_to_string( wp_unslash( $_GET['kashiwazaki_seo_heatmap_purge'] ) )
		: '';
	// 値をそのまま真偽に使わず、こちらが付けた印（1）のときだけ進む。
	if ( '1' !== $purge ) {
		return;
	}
	$nonce = isset( $_GET['_wpnonce'] ) ? kashiwazaki_seo_heatmap_to_string( wp_unslash( $_GET['_wpnonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'kashiwazaki_seo_heatmap_purge' ) ) {
		wp_die( esc_html__( 'セキュリティチェックに失敗しました。', 'wp-plugin-kashiwazaki-seo-heatmap' ), 403 );
	}

	kashiwazaki_seo_heatmap_delete_all_data();

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'  => KASHIWAZAKI_SEO_HEATMAP_MENU_SLUG . '-settings',
				'purged' => '1',
			),
			admin_url( 'admin.php' )
		)
	);
	exit;
}
add_action( 'admin_init', 'kashiwazaki_seo_heatmap_handle_purge_request' );
