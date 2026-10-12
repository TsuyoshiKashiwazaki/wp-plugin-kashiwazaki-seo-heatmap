<?php
/**
 * 設定の登録とサニタイズ。
 *
 * @package Kashiwazaki_SEO_Heatmap
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings API に設定を登録する。
 *
 * @return void
 */
function kashiwazaki_seo_heatmap_register_settings() {
	register_setting(
		'kashiwazaki_seo_heatmap_settings_group',
		KASHIWAZAKI_SEO_HEATMAP_SETTINGS_OPTION,
		array(
			'type'              => 'array',
			'sanitize_callback' => 'kashiwazaki_seo_heatmap_sanitize_settings',
			'default'           => kashiwazaki_seo_heatmap_default_settings(),
		)
	);
}
add_action( 'admin_init', 'kashiwazaki_seo_heatmap_register_settings' );

/**
 * 送信された設定を検証・サニタイズする。
 *
 * @param mixed $input 送信値。
 * @return array サニタイズ済みの設定。
 */
function kashiwazaki_seo_heatmap_sanitize_settings( $input ) {
	$defaults = kashiwazaki_seo_heatmap_default_settings();
	$input    = is_array( $input ) ? $input : array();
	$output   = $defaults;

	// 計測対象の投稿タイプ（許可されたものだけ残す）。
	$trackable = array_keys( kashiwazaki_seo_heatmap_get_trackable_post_types() );
	$types     = array();
	if ( isset( $input['enabled_post_types'] ) && is_array( $input['enabled_post_types'] ) ) {
		foreach ( $input['enabled_post_types'] as $type ) {
			$type = sanitize_key( $type );
			if ( in_array( $type, $trackable, true ) ) {
				$types[] = $type;
			}
		}
	}
	$output['enabled_post_types'] = array_values( array_unique( $types ) );

	// オン/オフのトグル。
	$toggles = array(
		'track_clicks',
		'track_scroll',
		'track_mouse_move',
		'track_rage_clicks',
		'track_dead_clicks',
		'track_outbound',
		'exclude_logged_in',
		'honor_do_not_track',
		'enable_auto_cleanup',
		'delete_data_on_uninstall',
	);
	foreach ( $toggles as $key ) {
		$output[ $key ] = ! empty( $input[ $key ] ) ? 1 : 0;
	}

	// 除外ロール（実在するロールだけ残す）。
	$roles       = array_keys( wp_roles()->get_names() );
	$role_values = array();
	if ( isset( $input['exclude_roles'] ) && is_array( $input['exclude_roles'] ) ) {
		foreach ( $input['exclude_roles'] as $role ) {
			$role = sanitize_key( $role );
			if ( in_array( $role, $roles, true ) ) {
				$role_values[] = $role;
			}
		}
	}
	$output['exclude_roles'] = array_values( array_unique( $role_values ) );

	// 複数行テキスト。
	$output['exclude_ips']          = sanitize_textarea_field( isset( $input['exclude_ips'] ) ? $input['exclude_ips'] : '' );
	$output['exclude_url_patterns'] = sanitize_textarea_field( isset( $input['exclude_url_patterns'] ) ? $input['exclude_url_patterns'] : '' );

	// 数値項目（範囲を丸める）。
	$output['cookie_lifetime_days']   = min( 3650, max( 1, absint( isset( $input['cookie_lifetime_days'] ) ? $input['cookie_lifetime_days'] : $defaults['cookie_lifetime_days'] ) ) );
	$output['rage_click_count']       = min( 20, max( 2, absint( isset( $input['rage_click_count'] ) ? $input['rage_click_count'] : $defaults['rage_click_count'] ) ) );
	$output['rage_click_window_ms']   = min( 10000, max( 100, absint( isset( $input['rage_click_window_ms'] ) ? $input['rage_click_window_ms'] : $defaults['rage_click_window_ms'] ) ) );
	$output['rage_click_radius_px']   = min( 200, max( 5, absint( isset( $input['rage_click_radius_px'] ) ? $input['rage_click_radius_px'] : $defaults['rage_click_radius_px'] ) ) );
	$output['dead_click_window_ms']   = min( 10000, max( 200, absint( isset( $input['dead_click_window_ms'] ) ? $input['dead_click_window_ms'] : $defaults['dead_click_window_ms'] ) ) );
	$output['mouse_move_interval_ms'] = min( 2000, max( 50, absint( isset( $input['mouse_move_interval_ms'] ) ? $input['mouse_move_interval_ms'] : $defaults['mouse_move_interval_ms'] ) ) );
	$output['mouse_move_max_events']  = min( 2000, max( 10, absint( isset( $input['mouse_move_max_events'] ) ? $input['mouse_move_max_events'] : $defaults['mouse_move_max_events'] ) ) );
	$output['flush_interval_ms']      = min( 60000, max( 1000, absint( isset( $input['flush_interval_ms'] ) ? $input['flush_interval_ms'] : $defaults['flush_interval_ms'] ) ) );
	$output['sample_rate']            = min( 100, max( 1, absint( isset( $input['sample_rate'] ) ? $input['sample_rate'] : $defaults['sample_rate'] ) ) );
	$output['retention_days']         = min( 3650, max( 1, absint( isset( $input['retention_days'] ) ? $input['retention_days'] : $defaults['retention_days'] ) ) );
	$output['default_heat_radius']    = min( 120, max( 5, absint( isset( $input['default_heat_radius'] ) ? $input['default_heat_radius'] : $defaults['default_heat_radius'] ) ) );
	$output['default_heat_opacity']   = min( 100, max( 5, absint( isset( $input['default_heat_opacity'] ) ? $input['default_heat_opacity'] : $defaults['default_heat_opacity'] ) ) );

	// カラースキーム（用意したものだけ）。
	$schemes                     = array_keys( kashiwazaki_seo_heatmap_color_schemes() );
	$scheme                      = isset( $input['heat_color_scheme'] ) ? sanitize_key( $input['heat_color_scheme'] ) : $defaults['heat_color_scheme'];
	$output['heat_color_scheme'] = in_array( $scheme, $schemes, true ) ? $scheme : $defaults['heat_color_scheme'];

	/**
	 * サニタイズ後の設定をフィルタで調整できるようにする。
	 *
	 * @param array $output サニタイズ済みの設定。
	 * @param array $input  送信された生の設定。
	 */
	return apply_filters( 'kashiwazaki_seo_heatmap_sanitized_settings', $output, $input );
}
