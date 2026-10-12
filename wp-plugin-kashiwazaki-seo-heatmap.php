<?php
/**
 * Plugin Name: Kashiwazaki SEO Heatmap
 * Plugin URI: https://www.tsuyoshikashiwazaki.jp
 * Description: 投稿・固定ページ・カスタム投稿の「どこがクリックされているか」を可視化するクリック/スクロール/マウスムーブのヒートマップ解析プラグイン。レイジクリック・デッドクリック検出、要素別ランキング、デバイス絞り込み、CSV書き出しに対応。
 * Version: 1.0.0
 * Author: 柏崎剛 (Tsuyoshi Kashiwazaki)
 * Author URI: https://www.tsuyoshikashiwazaki.jp/profile/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: wp-plugin-kashiwazaki-seo-heatmap
 * Domain Path: /languages
 *
 * @package Kashiwazaki_SEO_Heatmap
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * ---------------------------------------------------------------------------
 * 定数（プラグイン全体で使う名前。表示名は省略せず Kashiwazaki SEO Heatmap）。
 * ---------------------------------------------------------------------------
 */

define( 'KASHIWAZAKI_SEO_HEATMAP_PLUGIN_FILE', __FILE__ );
define( 'KASHIWAZAKI_SEO_HEATMAP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'KASHIWAZAKI_SEO_HEATMAP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'KASHIWAZAKI_SEO_HEATMAP_VERSION', '1.0.0' );

// メニュー（トップレベル）のスラッグ。位置は設定(80)の直後、区切り(99)の前の 81。
define( 'KASHIWAZAKI_SEO_HEATMAP_MENU_SLUG', 'kashiwazaki-seo-heatmap' );
define( 'KASHIWAZAKI_SEO_HEATMAP_MENU_POSITION', 81 );
define( 'KASHIWAZAKI_SEO_HEATMAP_CAPABILITY', 'manage_options' );

// REST API の名前空間。
define( 'KASHIWAZAKI_SEO_HEATMAP_REST_NAMESPACE', 'kashiwazaki-seo-heatmap/v1' );

// DB テーブル（$wpdb->prefix を前置して使う）。
define( 'KASHIWAZAKI_SEO_HEATMAP_EVENTS_TABLE', 'kashiwazaki_seo_heatmap_events' );
define( 'KASHIWAZAKI_SEO_HEATMAP_AGG_TABLE', 'kashiwazaki_seo_heatmap_stats' );
define( 'KASHIWAZAKI_SEO_HEATMAP_PAGES_TABLE', 'kashiwazaki_seo_heatmap_pages' );

// スキーマのバージョン（dbDelta の再適用判定に使う）。
define( 'KASHIWAZAKI_SEO_HEATMAP_DB_VERSION', '1.0.1' );
define( 'KASHIWAZAKI_SEO_HEATMAP_DB_VERSION_OPTION', 'kashiwazaki_seo_heatmap_db_version' );

// 設定オプション（1 つの配列にまとめて保存する）。
define( 'KASHIWAZAKI_SEO_HEATMAP_SETTINGS_OPTION', 'kashiwazaki_seo_heatmap_settings' );

// 訪問者を識別する Cookie（個人を特定しないランダム ID のみ保存する）。
define( 'KASHIWAZAKI_SEO_HEATMAP_COOKIE_NAME', 'kashiwazaki_seo_heatmap_visitor' );

// 生データの既定保持日数。
define( 'KASHIWAZAKI_SEO_HEATMAP_DEFAULT_RETENTION_DAYS', 180 );

// 1 リクエストで受け付ける最大イベント数（DoS 対策）。
define( 'KASHIWAZAKI_SEO_HEATMAP_MAX_EVENTS_PER_REQUEST', 200 );

// 1 つの IP から 1 分間に受け付ける最大送信回数。
define( 'KASHIWAZAKI_SEO_HEATMAP_RATE_LIMIT', 60 );

/*
 * ---------------------------------------------------------------------------
 * 読み込み
 * ---------------------------------------------------------------------------
 */
require_once KASHIWAZAKI_SEO_HEATMAP_PLUGIN_DIR . 'includes/kashiwazaki-seo-heatmap-functions.php';
require_once KASHIWAZAKI_SEO_HEATMAP_PLUGIN_DIR . 'includes/kashiwazaki-seo-heatmap-db.php';
require_once KASHIWAZAKI_SEO_HEATMAP_PLUGIN_DIR . 'includes/kashiwazaki-seo-heatmap-settings.php';
require_once KASHIWAZAKI_SEO_HEATMAP_PLUGIN_DIR . 'includes/kashiwazaki-seo-heatmap-activation.php';
require_once KASHIWAZAKI_SEO_HEATMAP_PLUGIN_DIR . 'includes/kashiwazaki-seo-heatmap-storage.php';
require_once KASHIWAZAKI_SEO_HEATMAP_PLUGIN_DIR . 'includes/kashiwazaki-seo-heatmap-rest-api.php';
require_once KASHIWAZAKI_SEO_HEATMAP_PLUGIN_DIR . 'includes/kashiwazaki-seo-heatmap-tracker.php';
require_once KASHIWAZAKI_SEO_HEATMAP_PLUGIN_DIR . 'includes/kashiwazaki-seo-heatmap-retention.php';
require_once KASHIWAZAKI_SEO_HEATMAP_PLUGIN_DIR . 'includes/kashiwazaki-seo-heatmap-privacy.php';

if ( is_admin() ) {
	require_once KASHIWAZAKI_SEO_HEATMAP_PLUGIN_DIR . 'includes/admin/class-kashiwazaki-seo-heatmap-admin.php';
	// AJAX（admin-ajax.php では admin_menu が発火しない）でも確実に登録されるよう、
	// ここで先にインスタンスを作る。
	Kashiwazaki_SEO_Heatmap_Admin::instance();
}

/*
 * ---------------------------------------------------------------------------
 * アクティベーション / デアクティベーション
 * ---------------------------------------------------------------------------
 */
register_activation_hook( KASHIWAZAKI_SEO_HEATMAP_PLUGIN_FILE, 'kashiwazaki_seo_heatmap_activate' );
register_deactivation_hook( KASHIWAZAKI_SEO_HEATMAP_PLUGIN_FILE, 'kashiwazaki_seo_heatmap_deactivate' );

/*
 * ---------------------------------------------------------------------------
 * 起動
 * ---------------------------------------------------------------------------
 */

/**
 * プラグインを起動する。スキーマの自己修復と、フロント側トラッカーの登録を行う。
 */
function kashiwazaki_seo_heatmap_bootstrap() {
	// 更新後に再有効化されなくてもテーブルが無ければ作る（自己修復）。
	if ( get_option( KASHIWAZAKI_SEO_HEATMAP_DB_VERSION_OPTION ) !== KASHIWAZAKI_SEO_HEATMAP_DB_VERSION ) {
		kashiwazaki_seo_heatmap_create_tables();
		update_option( KASHIWAZAKI_SEO_HEATMAP_DB_VERSION_OPTION, KASHIWAZAKI_SEO_HEATMAP_DB_VERSION );
	}

	// フロント側の計測スクリプト。
	kashiwazaki_seo_heatmap_register_tracker_hooks();
}
add_action( 'init', 'kashiwazaki_seo_heatmap_bootstrap', 5 );

/**
 * 管理画面のプレビュー（iframe）では管理バーを出さない。
 *
 * 管理バーが出ていると、その分だけ本文が下へずれる（WordPress 本体が
 * html に margin-top を付ける）。プレビューは「訪問者が見たままのページ」を
 * 写すためのものなので、ずれたまま写すと記録した座標と合わなくなる。
 *
 * @param bool $show 管理バーを出すかどうか。
 * @return bool プレビューのときは false。
 */
function kashiwazaki_seo_heatmap_hide_admin_bar_in_preview( $show ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 表示の判定のみで書き込みをしない。
	if ( isset( $_GET['kashiwazaki_seo_heatmap_preview'] ) ) {
		return false;
	}
	return $show;
}
add_filter( 'show_admin_bar', 'kashiwazaki_seo_heatmap_hide_admin_bar_in_preview' );

/**
 * REST API のルートを登録する。
 */
add_action( 'rest_api_init', 'kashiwazaki_seo_heatmap_register_rest_routes' );

/**
 * 保持期間を超えたデータを消すクーロン。
 */
add_action( 'kashiwazaki_seo_heatmap_cleanup_event', 'kashiwazaki_seo_heatmap_run_cleanup' );

/**
 * 管理画面のメニューを登録する。
 */
function kashiwazaki_seo_heatmap_admin_menu() {
	if ( class_exists( 'Kashiwazaki_SEO_Heatmap_Admin' ) ) {
		Kashiwazaki_SEO_Heatmap_Admin::instance()->register_menu();
	}
}
add_action( 'admin_menu', 'kashiwazaki_seo_heatmap_admin_menu' );

/**
 * プラグイン一覧に「設定」リンクを追加する。
 *
 * @param array $links 既存のアクションリンク。
 * @return array 設定リンクを先頭に足したリンク。
 */
function kashiwazaki_seo_heatmap_plugin_action_links( $links ) {
	$settings_url = add_query_arg(
		array( 'page' => KASHIWAZAKI_SEO_HEATMAP_MENU_SLUG . '-settings' ),
		admin_url( 'admin.php' )
	);
	$heatmap_url = add_query_arg(
		array( 'page' => KASHIWAZAKI_SEO_HEATMAP_MENU_SLUG ),
		admin_url( 'admin.php' )
	);

	$custom = array(
		'kashiwazaki_seo_heatmap_settings' => '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( '設定', 'wp-plugin-kashiwazaki-seo-heatmap' ) . '</a>',
		'kashiwazaki_seo_heatmap_view'     => '<a href="' . esc_url( $heatmap_url ) . '">' . esc_html__( 'ヒートマップ', 'wp-plugin-kashiwazaki-seo-heatmap' ) . '</a>',
	);

	return array_merge( $custom, $links );
}
add_filter( 'plugin_action_links_' . plugin_basename( KASHIWAZAKI_SEO_HEATMAP_PLUGIN_FILE ), 'kashiwazaki_seo_heatmap_plugin_action_links' );
