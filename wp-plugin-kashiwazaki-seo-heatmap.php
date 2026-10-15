<?php
/**
 * Plugin Name: Kashiwazaki SEO Heatmap
 * Plugin URI: https://www.tsuyoshikashiwazaki.jp
 * Description: 投稿・固定ページ・カスタム投稿の「どこがクリックされているか」を可視化するクリック/スクロール/マウスムーブのヒートマップ解析プラグイン。レイジクリック・デッドクリック検出、要素別ランキング、デバイス絞り込み、CSV書き出しに対応。
 * Version: 1.0.1
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
define( 'KASHIWAZAKI_SEO_HEATMAP_VERSION', '1.0.1' );

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
// 受け取った送信のまとまりの番号（送り直しを二重に数えないため）。
define( 'KASHIWAZAKI_SEO_HEATMAP_BATCHES_TABLE', 'kashiwazaki_seo_heatmap_batches' );

// スキーマのバージョン（dbDelta の再適用判定に使う）。1.0.7 で、番号の表の prov の長さも確かめてから
// 版を記録するようにした（1.0.6 を記録したサイトでも確かめ直す）。
define( 'KASHIWAZAKI_SEO_HEATMAP_DB_VERSION', '1.0.7' );
define( 'KASHIWAZAKI_SEO_HEATMAP_DB_VERSION_OPTION', 'kashiwazaki_seo_heatmap_db_version' );
// 表に InnoDB でないものがあり、変換を待っている印（1 のとき、保存は取引の効かない表の扱い）。
define( 'KASHIWAZAKI_SEO_HEATMAP_NEEDS_INNODB_OPTION', 'kashiwazaki_seo_heatmap_needs_innodb' );
// 表を変換している途中の印（変換を始めた UNIX 秒。0 なら変換していない）。
define( 'KASHIWAZAKI_SEO_HEATMAP_CONVERTING_OPTION', 'kashiwazaki_seo_heatmap_converting' );

// 設定オプション（1 つの配列にまとめて保存する）。
define( 'KASHIWAZAKI_SEO_HEATMAP_SETTINGS_OPTION', 'kashiwazaki_seo_heatmap_settings' );

// 訪問者を識別する Cookie（個人を特定しないランダム ID のみ保存する）。
define( 'KASHIWAZAKI_SEO_HEATMAP_COOKIE_NAME', 'kashiwazaki_seo_heatmap_visitor' );

// ログイン中で計測から外す人に付ける印の Cookie の名前の接頭辞（後ろにサイトの URL の指紋が付く）。
define( 'KASHIWAZAKI_SEO_HEATMAP_EXCLUDE_COOKIE_PREFIX', 'kashiwazaki_seo_heatmap_ex_' );

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
	// 作成に失敗したときは版を記録しない（次のリクエストでまた作り直しを試す）。
	if ( get_option( KASHIWAZAKI_SEO_HEATMAP_DB_VERSION_OPTION ) !== KASHIWAZAKI_SEO_HEATMAP_DB_VERSION ) {
		kashiwazaki_seo_heatmap_create_tables();
		if ( kashiwazaki_seo_heatmap_tables_exist() ) {
			// InnoDB でない表があれば、変換を予約する（この起動の中では変換しない。db.php を参照）。
			kashiwazaki_seo_heatmap_check_engines();
			update_option( KASHIWAZAKI_SEO_HEATMAP_DB_VERSION_OPTION, KASHIWAZAKI_SEO_HEATMAP_DB_VERSION );
		}
	}

	// ログイン中で計測から外す人の印の Cookie を、いまのログインの状態に合わせる。
	kashiwazaki_seo_heatmap_sync_exclude_cookie();

	// 設定と自動削除の予約がこのサイトに無ければ入れる。マルチサイトの
	// ネットワーク有効化では、有効化の処理が 1 つのサイトでしか動かないため。
	kashiwazaki_seo_heatmap_ensure_site_setup();

	// フロント側の計測スクリプト。
	kashiwazaki_seo_heatmap_register_tracker_hooks();
}
add_action( 'init', 'kashiwazaki_seo_heatmap_bootstrap', 5 );

// ログイン・ログアウトの応答で、計測から外す人の印の Cookie を付ける・消す。
add_action( 'set_logged_in_cookie', 'kashiwazaki_seo_heatmap_mark_on_login', 10, 4 );
add_action( 'clear_auth_cookie', 'kashiwazaki_seo_heatmap_unmark_on_logout' );

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

// 表を InnoDB に変換する予約の実行（db.php）。
add_action( 'kashiwazaki_seo_heatmap_convert_event', 'kashiwazaki_seo_heatmap_convert_tables' );

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
