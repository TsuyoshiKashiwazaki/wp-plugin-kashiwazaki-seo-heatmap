<?php
/**
 * フロント側の計測スクリプトの読み込み。
 *
 * @package Kashiwazaki_SEO_Heatmap
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * フロント側のフックを登録する。
 *
 * @return void
 */
function kashiwazaki_seo_heatmap_register_tracker_hooks() {
	add_action( 'wp_enqueue_scripts', 'kashiwazaki_seo_heatmap_enqueue_tracker' );
}

/**
 * 計測対象のページでだけトラッカーを読み込む。
 *
 * @return void
 */
function kashiwazaki_seo_heatmap_enqueue_tracker() {
	if ( is_admin() || is_feed() || is_robots() || is_preview() ) {
		return;
	}
	if ( ! kashiwazaki_seo_heatmap_is_trackable_request() ) {
		return;
	}
	if ( ! kashiwazaki_seo_heatmap_should_track() ) {
		return;
	}

	$settings = kashiwazaki_seo_heatmap_get_settings();

	wp_enqueue_script(
		'kashiwazaki-seo-heatmap-tracker',
		KASHIWAZAKI_SEO_HEATMAP_PLUGIN_URL . 'assets/js/kashiwazaki-seo-heatmap-tracker.js',
		array(),
		kashiwazaki_seo_heatmap_asset_version( 'assets/js/kashiwazaki-seo-heatmap-tracker.js' ),
		true
	);

	$config = array(
		'restUrl'       => esc_url_raw( rest_url( KASHIWAZAKI_SEO_HEATMAP_REST_NAMESPACE . '/collect' ) ),
		'postId'        => kashiwazaki_seo_heatmap_get_current_post_id(),
		'cookieName'    => KASHIWAZAKI_SEO_HEATMAP_COOKIE_NAME,
		'cookieDays'    => (int) $settings['cookie_lifetime_days'],
		// ログイン中で計測から外す人の印の Cookie の名前（印があれば、操作をまとまりにしない）。
		'excludeCookie' => kashiwazaki_seo_heatmap_exclude_cookie_name(),
		// 投稿を特定するクエリの名前（とても長い URL を短くするときも、これらは残す）。
		'pageKeys'      => kashiwazaki_seo_heatmap_page_query_keys(),
		// 到達率を数えるマイルストーン（%）。読み込みの途中の仮の値は、新しいマイルストーンを越えたときだけ送る。
		'milestones'    => kashiwazaki_seo_heatmap_scroll_milestones(),
		'sampleRate'    => (int) $settings['sample_rate'],
		'flushInterval' => (int) $settings['flush_interval_ms'],
		'track'         => array(
			'clicks'   => ! empty( $settings['track_clicks'] ),
			'scroll'   => ! empty( $settings['track_scroll'] ),
			'move'     => ! empty( $settings['track_mouse_move'] ),
			'rage'     => ! empty( $settings['track_rage_clicks'] ),
			'dead'     => ! empty( $settings['track_dead_clicks'] ),
			'outbound' => ! empty( $settings['track_outbound'] ),
		),
		'rage'          => array(
			'count'  => (int) $settings['rage_click_count'],
			'window' => (int) $settings['rage_click_window_ms'],
			'radius' => (int) $settings['rage_click_radius_px'],
		),
		'deadWindow'    => (int) $settings['dead_click_window_ms'],
		'moveInterval'  => (int) $settings['mouse_move_interval_ms'],
		'moveMax'       => (int) $settings['mouse_move_max_events'],
		'honorDnt'      => ! empty( $settings['honor_do_not_track'] ),
		'debug'         => ( defined( 'WP_DEBUG' ) && WP_DEBUG ),
	);

	wp_localize_script( 'kashiwazaki-seo-heatmap-tracker', 'KashiwazakiSEOHeatmapConfig', $config );

	// 計測スクリプトは描画を妨げないように async で読み込む。
	add_filter(
		'script_loader_tag',
		function ( $tag, $handle ) {
			if ( 'kashiwazaki-seo-heatmap-tracker' !== $handle ) {
				return $tag;
			}
			return str_replace( ' src', ' async src', $tag );
		},
		10,
		2
	);
}
