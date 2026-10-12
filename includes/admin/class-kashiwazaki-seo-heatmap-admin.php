<?php
/**
 * 管理画面のメニューと共通処理。
 *
 * @package Kashiwazaki_SEO_Heatmap
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once KASHIWAZAKI_SEO_HEATMAP_PLUGIN_DIR . 'includes/admin/kashiwazaki-seo-heatmap-admin-heatmap.php';
require_once KASHIWAZAKI_SEO_HEATMAP_PLUGIN_DIR . 'includes/admin/kashiwazaki-seo-heatmap-admin-dashboard.php';
require_once KASHIWAZAKI_SEO_HEATMAP_PLUGIN_DIR . 'includes/admin/kashiwazaki-seo-heatmap-admin-settings.php';

/**
 * 管理画面のメニュー・アセット・AJAX をまとめるクラス。
 */
class Kashiwazaki_SEO_Heatmap_Admin {

	/**
	 * シングルトンのインスタンス。
	 *
	 * @var Kashiwazaki_SEO_Heatmap_Admin|null
	 */
	private static $instance = null;

	/**
	 * インスタンスを取得する。
	 *
	 * @return Kashiwazaki_SEO_Heatmap_Admin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * コンストラクタ。
	 */
	private function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_kashiwazaki_seo_heatmap_get_data', array( $this, 'ajax_get_heatmap_data' ) );
		add_action( 'wp_ajax_kashiwazaki_seo_heatmap_export_csv', array( $this, 'ajax_export_csv' ) );
	}

	/**
	 * 管理メニューを登録する。
	 *
	 * @return void
	 */
	public function register_menu() {
		$capability = KASHIWAZAKI_SEO_HEATMAP_CAPABILITY;
		$slug       = KASHIWAZAKI_SEO_HEATMAP_MENU_SLUG;

		add_menu_page(
			__( 'Kashiwazaki SEO Heatmap', 'wp-plugin-kashiwazaki-seo-heatmap' ),
			__( 'Kashiwazaki SEO Heatmap', 'wp-plugin-kashiwazaki-seo-heatmap' ),
			$capability,
			$slug,
			array( $this, 'render_heatmap_page' ),
			'dashicons-chart-area',
			KASHIWAZAKI_SEO_HEATMAP_MENU_POSITION
		);

		// トップレベルと同じスラッグで最初のサブメニューを差し替える。
		add_submenu_page(
			$slug,
			__( 'ヒートマップ', 'wp-plugin-kashiwazaki-seo-heatmap' ),
			__( 'ヒートマップ', 'wp-plugin-kashiwazaki-seo-heatmap' ),
			$capability,
			$slug,
			array( $this, 'render_heatmap_page' )
		);

		add_submenu_page(
			$slug,
			__( 'ダッシュボード', 'wp-plugin-kashiwazaki-seo-heatmap' ),
			__( 'ダッシュボード', 'wp-plugin-kashiwazaki-seo-heatmap' ),
			$capability,
			$slug . '-dashboard',
			array( $this, 'render_dashboard_page' )
		);

		add_submenu_page(
			$slug,
			__( '設定', 'wp-plugin-kashiwazaki-seo-heatmap' ),
			__( '設定', 'wp-plugin-kashiwazaki-seo-heatmap' ),
			$capability,
			$slug . '-settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * 管理画面のアセットを読み込む。
	 *
	 * @param string $hook_suffix 現在の管理画面のフック。
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( false === strpos( $hook_suffix, KASHIWAZAKI_SEO_HEATMAP_MENU_SLUG ) ) {
			return;
		}

		wp_enqueue_style(
			'kashiwazaki-seo-heatmap-admin',
			KASHIWAZAKI_SEO_HEATMAP_PLUGIN_URL . 'assets/css/kashiwazaki-seo-heatmap-admin.css',
			array(),
			KASHIWAZAKI_SEO_HEATMAP_VERSION
		);

		wp_enqueue_script(
			'kashiwazaki-seo-heatmap-admin',
			KASHIWAZAKI_SEO_HEATMAP_PLUGIN_URL . 'assets/js/kashiwazaki-seo-heatmap-admin.js',
			array(),
			KASHIWAZAKI_SEO_HEATMAP_VERSION,
			true
		);

		$settings = kashiwazaki_seo_heatmap_get_settings();

		wp_localize_script(
			'kashiwazaki-seo-heatmap-admin',
			'KashiwazakiSEOHeatmapAdmin',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'kashiwazaki_seo_heatmap_admin' ),
				'postTypes'   => kashiwazaki_seo_heatmap_get_all_post_types(),
				'trackedTypes' => array_keys( kashiwazaki_seo_heatmap_get_trackable_post_types() ),
				'settings'    => array(
					'heatRadius'  => (int) $settings['default_heat_radius'],
					'heatOpacity' => (int) $settings['default_heat_opacity'],
					'colorScheme' => $settings['heat_color_scheme'],
				),
				'i18n'        => array(
					'loading'   => __( '読み込み中…', 'wp-plugin-kashiwazaki-seo-heatmap' ),
					'noData'    => __( 'この期間のデータはまだありません。', 'wp-plugin-kashiwazaki-seo-heatmap' ),
					'error'     => __( 'データの取得に失敗しました。', 'wp-plugin-kashiwazaki-seo-heatmap' ),
					'click'     => __( 'クリック', 'wp-plugin-kashiwazaki-seo-heatmap' ),
					'rage'      => __( 'レイジクリック', 'wp-plugin-kashiwazaki-seo-heatmap' ),
					'dead'      => __( 'デッドクリック', 'wp-plugin-kashiwazaki-seo-heatmap' ),
					'outbound'  => __( '外部リンクのクリック', 'wp-plugin-kashiwazaki-seo-heatmap' ),
					'move'      => __( 'マウスムーブ', 'wp-plugin-kashiwazaki-seo-heatmap' ),
					'scroll'    => __( 'スクロール到達率', 'wp-plugin-kashiwazaki-seo-heatmap' ),
					'viewport'  => __( '表示範囲', 'wp-plugin-kashiwazaki-seo-heatmap' ),
					'times'     => __( '回', 'wp-plugin-kashiwazaki-seo-heatmap' ),
					'visitors'  => __( '訪問者', 'wp-plugin-kashiwazaki-seo-heatmap' ),
					/* translators: %s: 表示するデータの種類（クリック、レイジクリックなど）。 */
					'noDataForType' => __( 'この期間の「%s」は 1 件も記録されていません。', 'wp-plugin-kashiwazaki-seo-heatmap' ),
					/* translators: %s: 表示するデータの種類（スクロール到達率、マウスムーブ）。 */
					'noElementForType' => __( '「%s」は要素ごとの記録がないため、一覧はありません。', 'wp-plugin-kashiwazaki-seo-heatmap' ),
				),
			)
		);
	}

	/**
	 * ヒートマップ画面を表示する。
	 *
	 * @return void
	 */
	public function render_heatmap_page() {
		if ( ! current_user_can( KASHIWAZAKI_SEO_HEATMAP_CAPABILITY ) ) {
			wp_die( esc_html__( 'この画面を見る権限がありません。', 'wp-plugin-kashiwazaki-seo-heatmap' ), 403 );
		}
		kashiwazaki_seo_heatmap_render_heatmap_page();
	}

	/**
	 * ダッシュボード画面を表示する。
	 *
	 * @return void
	 */
	public function render_dashboard_page() {
		if ( ! current_user_can( KASHIWAZAKI_SEO_HEATMAP_CAPABILITY ) ) {
			wp_die( esc_html__( 'この画面を見る権限がありません。', 'wp-plugin-kashiwazaki-seo-heatmap' ), 403 );
		}
		kashiwazaki_seo_heatmap_render_dashboard_page();
	}

	/**
	 * 設定画面を表示する。
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( KASHIWAZAKI_SEO_HEATMAP_CAPABILITY ) ) {
			wp_die( esc_html__( 'この画面を見る権限がありません。', 'wp-plugin-kashiwazaki-seo-heatmap' ), 403 );
		}
		kashiwazaki_seo_heatmap_render_settings_page();
	}

	/**
	 * ヒートマップ描画用のデータを返す AJAX ハンドラ。
	 *
	 * @return void
	 */
	public function ajax_get_heatmap_data() {
		check_ajax_referer( 'kashiwazaki_seo_heatmap_admin', 'nonce' );

		if ( ! current_user_can( KASHIWAZAKI_SEO_HEATMAP_CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( '権限がありません。', 'wp-plugin-kashiwazaki-seo-heatmap' ) ), 403 );
		}

		$post_id  = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$page_key = isset( $_POST['page_key'] ) ? sanitize_text_field( wp_unslash( $_POST['page_key'] ) ) : '';
		$days     = isset( $_POST['days'] ) ? kashiwazaki_seo_heatmap_sanitize_days( wp_unslash( $_POST['days'] ) ) : 30;
		$device   = isset( $_POST['device'] ) ? sanitize_key( wp_unslash( $_POST['device'] ) ) : '';
		$url      = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';

		// page_key が無く URL がある場合は URL から作る。
		if ( '' === $page_key && '' !== $url ) {
			$page_key = kashiwazaki_seo_heatmap_page_key( kashiwazaki_seo_heatmap_normalize_url_path( $url ) );
		}
		if ( '' !== $device && ! in_array( $device, array( 'desktop', 'tablet', 'mobile' ), true ) ) {
			$device = '';
		}

		$args = array(
			'post_id'   => $post_id,
			'page_key'  => $page_key,
			'date_from' => kashiwazaki_seo_heatmap_date_days_ago( $days - 1 ),
			'date_to'   => kashiwazaki_seo_heatmap_date_days_ago( 0 ),
			'device'    => $device,
		);

		$clicks     = kashiwazaki_seo_heatmap_get_element_stats( array_merge( $args, array( 'event_type' => 'click' ) ) );
		$rage       = kashiwazaki_seo_heatmap_get_element_stats( array_merge( $args, array( 'event_type' => 'rage' ) ) );
		$dead       = kashiwazaki_seo_heatmap_get_element_stats( array_merge( $args, array( 'event_type' => 'dead' ) ) );
		$outbound   = kashiwazaki_seo_heatmap_get_element_stats( array_merge( $args, array( 'event_type' => 'outbound' ) ) );
		$moves      = kashiwazaki_seo_heatmap_get_element_stats( array_merge( $args, array( 'event_type' => 'move' ) ) );
		$scroll     = kashiwazaki_seo_heatmap_get_scroll_distribution( $args );
		$totals     = kashiwazaki_seo_heatmap_get_page_totals( $args );
		$series     = kashiwazaki_seo_heatmap_get_daily_series( $args );

		$post_url = '';
		if ( $post_id > 0 ) {
			$permalink = get_permalink( $post_id );
			if ( $permalink ) {
				$post_url = $permalink;
			}
		}

		wp_send_json_success(
			array(
				'clicks'   => $this->format_element_rows( $clicks ),
				'rage'     => $this->format_element_rows( $rage ),
				'dead'     => $this->format_element_rows( $dead ),
				'outbound' => $this->format_element_rows( $outbound ),
				'moves'    => $this->format_move_rows( $moves ),
				'scroll'   => $scroll,
				'totals'   => array(
					'pageviews'  => isset( $totals['pageviews'] ) ? (int) $totals['pageviews'] : 0,
					'visitors'   => isset( $totals['visitors'] ) ? (int) $totals['visitors'] : 0,
					'entrances'  => isset( $totals['entrances'] ) ? (int) $totals['entrances'] : 0,
					'avgTime'    => ( isset( $totals['pageviews'] ) && (int) $totals['pageviews'] > 0 ) ? round( (float) $totals['total_time'] / (int) $totals['pageviews'], 1 ) : 0,
				),
				'series'   => $series,
				'pageUrl'  => $post_url,
			)
		);
	}

	/**
	 * 要素別の集計行を JS 用に整える。
	 *
	 * @param array $rows DB から取得した行。
	 * @return array 整形後の行。
	 */
	private function format_element_rows( $rows ) {
		$out = array();
		foreach ( (array) $rows as $row ) {
			$count = isset( $row['total'] ) ? (int) $row['total'] : 0;
			if ( $count <= 0 ) {
				continue;
			}
			$out[] = array(
				'key'      => isset( $row['element_key'] ) ? $row['element_key'] : '',
				'selector' => isset( $row['selector'] ) ? $row['selector'] : '',
				'index'    => isset( $row['element_index'] ) ? (int) $row['element_index'] : 0,
				'tag'      => isset( $row['element_tag'] ) ? $row['element_tag'] : '',
				'text'     => isset( $row['element_text'] ) ? $row['element_text'] : '',
				'count'    => $count,
				'x'        => isset( $row['sum_x'] ) ? round( (float) $row['sum_x'] / $count, 1 ) : 0,
				'y'        => isset( $row['sum_y'] ) ? round( (float) $row['sum_y'] / $count, 1 ) : 0,
				'xp'       => isset( $row['sum_x_pct'] ) ? round( (float) $row['sum_x_pct'] / $count, 3 ) : 0,
				'yp'       => isset( $row['sum_y_pct'] ) ? round( (float) $row['sum_y_pct'] / $count, 3 ) : 0,
			);
		}
		return $out;
	}

	/**
	 * マウスムーブの格子集計を JS 用に整える。
	 *
	 * @param array $rows DB から取得した行。
	 * @return array 整形後の行。
	 */
	private function format_move_rows( $rows ) {
		$out = array();
		foreach ( (array) $rows as $row ) {
			$count = isset( $row['total'] ) ? (int) $row['total'] : 0;
			if ( $count <= 0 ) {
				continue;
			}
			$out[] = array(
				'count' => $count,
				'x'     => isset( $row['sum_x'] ) ? round( (float) $row['sum_x'] / $count, 1 ) : 0,
				'y'     => isset( $row['sum_y'] ) ? round( (float) $row['sum_y'] / $count, 1 ) : 0,
				'xp'    => isset( $row['sum_x_pct'] ) ? round( (float) $row['sum_x_pct'] / $count, 3 ) : 0,
				'yp'    => isset( $row['sum_y_pct'] ) ? round( (float) $row['sum_y_pct'] / $count, 3 ) : 0,
			);
		}
		return $out;
	}

	/**
	 * CSV の 1 マス分の文字を整える。
	 *
	 * 表計算ソフトは「=」などで始まるマスを数式として実行する。要素の文字は
	 * サイト側の書き方しだいで何でも入りうるため、先頭に引用符を足して
	 * 文字のまま出させ、数式として実行させない。
	 *
	 * @param mixed $value マスの値。
	 * @return string 整えた値。
	 */
	private function csv_cell( $value ) {
		$value = is_scalar( $value ) ? (string) $value : '';
		if ( '' === $value ) {
			return '';
		}
		if ( in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return "'" . $value;
		}
		return $value;
	}

	/**
	 * 集計データを CSV で書き出す。
	 *
	 * @return void
	 */
	public function ajax_export_csv() {
		check_ajax_referer( 'kashiwazaki_seo_heatmap_admin', 'nonce' );

		if ( ! current_user_can( KASHIWAZAKI_SEO_HEATMAP_CAPABILITY ) ) {
			wp_die( esc_html__( '権限がありません。', 'wp-plugin-kashiwazaki-seo-heatmap' ), 403 );
		}

		$post_id  = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
		$page_key = isset( $_GET['page_key'] ) ? sanitize_text_field( wp_unslash( $_GET['page_key'] ) ) : '';
		$days     = isset( $_GET['days'] ) ? kashiwazaki_seo_heatmap_sanitize_days( wp_unslash( $_GET['days'] ) ) : 30;
		$device   = isset( $_GET['device'] ) ? sanitize_key( wp_unslash( $_GET['device'] ) ) : '';
		$type     = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : 'click';

		$rows = kashiwazaki_seo_heatmap_get_element_stats(
			array(
				'post_id'    => $post_id,
				'page_key'   => $page_key,
				'date_from'  => kashiwazaki_seo_heatmap_date_days_ago( $days - 1 ),
				'date_to'    => kashiwazaki_seo_heatmap_date_days_ago( 0 ),
				'device'     => $device,
				'event_type' => $type,
			)
		);

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="kashiwazaki-seo-heatmap-' . $type . '-' . gmdate( 'Ymd-His' ) . '.csv"' );

		$output = fopen( 'php://output', 'w' );
		if ( ! $output ) {
			wp_die( esc_html__( 'CSV を書き出せませんでした。', 'wp-plugin-kashiwazaki-seo-heatmap' ), 500 );
		}
		// Excel で文字化けしないように BOM を付ける。
		fwrite( $output, "\xEF\xBB\xBF" );
		fputcsv( $output, array( 'セレクタ', '要素', 'テキスト', '出現位置', 'クリック数', '平均X', '平均Y', 'X(%)', 'Y(%)' ) );

		foreach ( $this->format_element_rows( $rows ) as $row ) {
			fputcsv(
				$output,
				array(
					$this->csv_cell( $row['selector'] ),
					$this->csv_cell( $row['tag'] ),
					$this->csv_cell( $row['text'] ),
					$row['index'],
					$row['count'],
					$row['x'],
					$row['y'],
					$row['xp'],
					$row['yp'],
				)
			);
		}

		fclose( $output );
		exit;
	}
}
