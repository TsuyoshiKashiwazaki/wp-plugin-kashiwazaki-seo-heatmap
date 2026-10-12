<?php
/**
 * ダッシュボード画面。
 *
 * @package Kashiwazaki_SEO_Heatmap
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ダッシュボード画面の HTML を出力する。
 *
 * @return void
 */
function kashiwazaki_seo_heatmap_render_dashboard_page() {
	$slug     = KASHIWAZAKI_SEO_HEATMAP_MENU_SLUG;
	$days     = 30;
	$from     = kashiwazaki_seo_heatmap_date_days_ago( $days - 1 );
	$to       = kashiwazaki_seo_heatmap_date_days_ago( 0 );
	$settings = kashiwazaki_seo_heatmap_get_settings();

	$args = array(
		'date_from' => $from,
		'date_to'   => $to,
		'device'    => '',
	);

	$totals      = kashiwazaki_seo_heatmap_get_page_totals( $args );
	$top_pages   = kashiwazaki_seo_heatmap_get_top_pages( array_merge( $args, array( 'limit' => 30 ) ) );
	$clicks      = kashiwazaki_seo_heatmap_get_total_clicks( array_merge( $args, array( 'event_type' => 'click' ) ) );
	$rage        = kashiwazaki_seo_heatmap_get_total_clicks( array_merge( $args, array( 'event_type' => 'rage' ) ) );
	$dead        = kashiwazaki_seo_heatmap_get_total_clicks( array_merge( $args, array( 'event_type' => 'dead' ) ) );
	$outbound    = kashiwazaki_seo_heatmap_get_total_clicks( array_merge( $args, array( 'event_type' => 'outbound' ) ) );
	$series      = kashiwazaki_seo_heatmap_get_daily_series( $args );

	$pageviews = isset( $totals['pageviews'] ) ? (int) $totals['pageviews'] : 0;
	$visitors  = isset( $totals['visitors'] ) ? (int) $totals['visitors'] : 0;
	$total_time = isset( $totals['total_time'] ) ? (float) $totals['total_time'] : 0.0;
	$avg_time  = $pageviews > 0 ? round( $total_time / $pageviews, 1 ) : 0;

	$enabled_types = (array) $settings['enabled_post_types'];
	?>
	<div class="wrap kashiwazaki-seo-heatmap-wrap">
		<h1><?php echo esc_html__( 'Kashiwazaki SEO Heatmap — ダッシュボード', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></h1>
		<p class="kashiwazaki-seo-heatmap-lead">
			<?php
			printf(
				/* translators: 1: start date, 2: end date, 3: day count */
				esc_html__( '%1$s 〜 %2$s（%3$d 日間）の集計です。', 'wp-plugin-kashiwazaki-seo-heatmap' ),
				esc_html( $from ),
				esc_html( $to ),
				(int) $days
			);
			?>
		</p>

		<?php if ( empty( $enabled_types ) ) : ?>
			<div class="notice notice-warning inline">
				<p>
					<?php echo esc_html__( '計測対象の投稿タイプがまだ選ばれていません。「設定」で投稿・固定ページ・カスタム投稿にチェックを入れると計測が始まります。', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $slug . '-settings' ) ); ?>"><?php echo esc_html__( '設定を開く', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></a>
				</p>
			</div>
		<?php endif; ?>

		<div class="kashiwazaki-seo-heatmap-summary">
			<div class="kashiwazaki-seo-heatmap-card"><span class="kashiwazaki-seo-heatmap-card-value"><?php echo esc_html( number_format_i18n( $pageviews ) ); ?></span><span class="kashiwazaki-seo-heatmap-card-label"><?php echo esc_html__( '閲覧数', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></span></div>
			<div class="kashiwazaki-seo-heatmap-card"><span class="kashiwazaki-seo-heatmap-card-value"><?php echo esc_html( number_format_i18n( $visitors ) ); ?></span><span class="kashiwazaki-seo-heatmap-card-label"><?php echo esc_html__( '訪問者数', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></span></div>
			<div class="kashiwazaki-seo-heatmap-card"><span class="kashiwazaki-seo-heatmap-card-value"><?php echo esc_html( number_format_i18n( $clicks ) ); ?></span><span class="kashiwazaki-seo-heatmap-card-label"><?php echo esc_html__( 'クリック数', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></span></div>
			<div class="kashiwazaki-seo-heatmap-card"><span class="kashiwazaki-seo-heatmap-card-value"><?php echo esc_html( number_format_i18n( $avg_time, 1 ) ); ?></span><span class="kashiwazaki-seo-heatmap-card-label"><?php echo esc_html__( '平均滞在時間（秒）', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></span></div>
			<div class="kashiwazaki-seo-heatmap-card kashiwazaki-seo-heatmap-card-alert"><span class="kashiwazaki-seo-heatmap-card-value"><?php echo esc_html( number_format_i18n( $rage ) ); ?></span><span class="kashiwazaki-seo-heatmap-card-label"><?php echo esc_html__( 'レイジクリック', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></span></div>
			<div class="kashiwazaki-seo-heatmap-card kashiwazaki-seo-heatmap-card-alert"><span class="kashiwazaki-seo-heatmap-card-value"><?php echo esc_html( number_format_i18n( $dead ) ); ?></span><span class="kashiwazaki-seo-heatmap-card-label"><?php echo esc_html__( 'デッドクリック', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></span></div>
			<div class="kashiwazaki-seo-heatmap-card"><span class="kashiwazaki-seo-heatmap-card-value"><?php echo esc_html( number_format_i18n( $outbound ) ); ?></span><span class="kashiwazaki-seo-heatmap-card-label"><?php echo esc_html__( '外部リンクのクリック', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></span></div>
		</div>

		<h2><?php echo esc_html__( '日別の推移', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></h2>
		<div class="kashiwazaki-seo-heatmap-chart-box">
			<canvas id="kashiwazaki-seo-heatmap-series-canvas" data-series="<?php echo esc_attr( wp_json_encode( $series ) ); ?>"></canvas>
		</div>
		<p class="kashiwazaki-seo-heatmap-legend">
			<span class="kashiwazaki-seo-heatmap-legend-item kashiwazaki-seo-heatmap-legend-pageviews"><?php echo esc_html__( '閲覧数', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></span>
			<span class="kashiwazaki-seo-heatmap-legend-item kashiwazaki-seo-heatmap-legend-clicks"><?php echo esc_html__( 'クリック数', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></span>
		</p>

		<h2><?php echo esc_html__( 'よく見られているページ', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></h2>
		<div class="kashiwazaki-seo-heatmap-table-wrap">
			<table class="widefat striped kashiwazaki-seo-heatmap-table">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'ページ', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></th>
						<th><?php echo esc_html__( '閲覧数', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></th>
						<th><?php echo esc_html__( '訪問者数', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></th>
						<th><?php echo esc_html__( '平均滞在時間（秒）', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></th>
						<th><?php echo esc_html__( 'ヒートマップ', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $top_pages ) ) : ?>
						<tr><td colspan="5"><?php echo esc_html__( 'まだデータがありません。', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></td></tr>
					<?php else : ?>
						<?php
						foreach ( $top_pages as $row ) :
							$views = (int) $row['pageviews'];
							$time  = (int) $row['total_time'];
							?>
							<tr>
								<td>
									<strong><?php echo esc_html( $row['page_url'] ); ?></strong>
								</td>
								<td><?php echo esc_html( number_format_i18n( $views ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (int) $row['visitors'] ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $views > 0 ? round( $time / $views, 1 ) : 0, 1 ) ); ?></td>
								<td>
									<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $slug . '&page_key=' . rawurlencode( $row['page_key'] ) . '&post_id=' . (int) $row['post_id'] ) ); ?>">
										<?php echo esc_html__( 'ヒートマップを見る', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
	</div>
	<?php
}
