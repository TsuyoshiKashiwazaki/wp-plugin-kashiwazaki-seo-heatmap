<?php
/**
 * プラグイン削除時の処理。
 *
 * 「プラグインを削除したときに計測データと設定も消す」にチェックが入っている場合だけ
 * データベースを掃除する。チェックが外れていればデータは残す（後で再インストールした
 * ときに今までの計測結果を続けて見られるようにするため）。
 *
 * このファイルは WordPress が単独で読み込むため、プラグイン本体は読み込まれない。
 * そのため、定数や関数はここで自前に定義する。
 *
 * @package Kashiwazaki_SEO_Heatmap
 */

// プラグイン削除の流れ以外から直接叩かれた場合は何もしない。
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * 1 サイト分のデータと設定を消す。
 *
 * @return void
 */
function kashiwazaki_seo_heatmap_uninstall_site() {
	global $wpdb;

	$option   = 'kashiwazaki_seo_heatmap_settings';
	$settings = get_option( $option, false );

	// 設定が保存されていないサイト（マルチサイトで一度も開かれていない子サイトなど）は、
	// 画面の既定（「削除したときに計測データと設定も消す」がオン）として扱う。
	// 「削除時に消す」が外されているときだけ、データを残す。
	if ( is_array( $settings ) && empty( $settings['delete_data_on_uninstall'] ) ) {
		return;
	}

	$tables = array(
		$wpdb->prefix . 'kashiwazaki_seo_heatmap_events',
		$wpdb->prefix . 'kashiwazaki_seo_heatmap_stats',
		$wpdb->prefix . 'kashiwazaki_seo_heatmap_pages',
		$wpdb->prefix . 'kashiwazaki_seo_heatmap_batches',
	);

	foreach ( $tables as $table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}

	// 設定とスキーマのバージョン。
	delete_option( $option );
	delete_option( 'kashiwazaki_seo_heatmap_db_version' );
	delete_option( 'kashiwazaki_seo_heatmap_needs_innodb' );
	delete_option( 'kashiwazaki_seo_heatmap_converting' );

	// レート制限などの一時データ（接頭辞でまとめて消す）。
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_kashiwazaki_seo_heatmap_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_kashiwazaki_seo_heatmap_' ) . '%'
		)
	);

	// 定期実行の予約を確実に外す。
	wp_clear_scheduled_hook( 'kashiwazaki_seo_heatmap_cleanup_event' );
	wp_clear_scheduled_hook( 'kashiwazaki_seo_heatmap_convert_event' );
}

if ( is_multisite() ) {
	$kashiwazaki_seo_heatmap_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $kashiwazaki_seo_heatmap_sites as $kashiwazaki_seo_heatmap_site_id ) {
		switch_to_blog( (int) $kashiwazaki_seo_heatmap_site_id );
		kashiwazaki_seo_heatmap_uninstall_site();
		restore_current_blog();
	}
} else {
	kashiwazaki_seo_heatmap_uninstall_site();
}

// クーロンはどのサイトでも必ず外す（設定に関わらず残骸を残さない）。
wp_clear_scheduled_hook( 'kashiwazaki_seo_heatmap_cleanup_event' );
wp_clear_scheduled_hook( 'kashiwazaki_seo_heatmap_convert_event' );
