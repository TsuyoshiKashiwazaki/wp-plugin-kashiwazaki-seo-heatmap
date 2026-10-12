<?php
/**
 * 有効化・無効化の処理。
 *
 * @package Kashiwazaki_SEO_Heatmap
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * プラグイン有効化時の処理。
 *
 * @return void
 */
function kashiwazaki_seo_heatmap_activate() {
	kashiwazaki_seo_heatmap_create_tables();
	update_option( KASHIWAZAKI_SEO_HEATMAP_DB_VERSION_OPTION, KASHIWAZAKI_SEO_HEATMAP_DB_VERSION );

	// 既定設定が無ければ入れる（既存の設定は壊さない）。
	if ( false === get_option( KASHIWAZAKI_SEO_HEATMAP_SETTINGS_OPTION, false ) ) {
		kashiwazaki_seo_heatmap_update_settings( kashiwazaki_seo_heatmap_default_settings() );
	}

	// 保持期間を超えたデータを毎日消すクーロンを登録する。
	if ( ! wp_next_scheduled( 'kashiwazaki_seo_heatmap_cleanup_event' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'kashiwazaki_seo_heatmap_cleanup_event' );
	}
}

/**
 * プラグイン無効化時の処理。データは消さない（アンインストール時のみ消す）。
 *
 * @return void
 */
function kashiwazaki_seo_heatmap_deactivate() {
	wp_clear_scheduled_hook( 'kashiwazaki_seo_heatmap_cleanup_event' );
}
