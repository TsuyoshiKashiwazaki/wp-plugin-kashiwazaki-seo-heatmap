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
 * データベースの更新が終わっていないときの警告を出す（ダッシュボードとヒートマップの画面）。
 *
 * 更新が終わるまで、読み込みの途中の到達率（仮・置き換えのまとまり）は受け取らず（503）、
 * 訪問者のブラウザが 7 日まで送り直す。7 日を過ぎた分は記録されないので、そのことも書く。
 *
 * @return void
 */
function kashiwazaki_seo_heatmap_render_schema_notice() {
	if ( kashiwazaki_seo_heatmap_schema_ready() ) {
		return;
	}
	?>
	<div class="notice notice-warning inline">
		<p><?php echo esc_html__( 'データベースの更新が終わっていません。計測データの表を変更できない状態です（データベースのユーザーに表を変更する権限が無い、など）。この間、読み込みの途中に離れた閲覧・読み込みに 10 秒以上かかった閲覧の到達率は、訪問者のブラウザで送り直しを待ち、7 日を過ぎた分は記録されません（到達率の数字がその分だけ少なくなります）。届いていない操作の数も表示されません。サーバーの管理者に、データベースの権限を確かめてもらってください。権限が直れば、次のアクセスで自動で更新され、その後に訪問者のブラウザから送り直された分（作ってから 7 日以内）は記録されます。', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></p>
	</div>
	<?php
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
	// 訪問者のブラウザからまだ届いていない操作の数（ブラウザの保存領域が使えない・一杯の状態で、
	// 受け取りの応答を確かめられないままページを離れた閲覧の分。後で届けば減る）。上の数字には
	// 入らないので、別に知らせる。番号は 8 日で消すので、直近 8 日の分。
	$lost        = kashiwazaki_seo_heatmap_count_unarrived();
	$series      = kashiwazaki_seo_heatmap_get_daily_series( $args );

	$pageviews = isset( $totals['pageviews'] ) ? (int) $totals['pageviews'] : 0;
	// 訪問者数は期間・ページをまたいで重複なしで数える。
	$visitors  = kashiwazaki_seo_heatmap_count_unique_visitors( $args );
	$limited   = kashiwazaki_seo_heatmap_visitors_limited_days( $from );
	$page_visitors = kashiwazaki_seo_heatmap_count_unique_visitors_by_page( wp_list_pluck( (array) $top_pages, 'page_key' ), $args );
	$visitors_label = $limited
		/* translators: %d: 生データの保持日数。 */
		? sprintf( __( '訪問者数（直近 %d 日）', 'wp-plugin-kashiwazaki-seo-heatmap' ), $limited )
		: __( '訪問者数', 'wp-plugin-kashiwazaki-seo-heatmap' );
	$total_time = isset( $totals['total_time'] ) ? (float) $totals['total_time'] : 0.0;
	$avg_time  = $pageviews > 0 ? round( $total_time / $pageviews, 1 ) : 0;

	$enabled_types = (array) $settings['enabled_post_types'];
	?>
	<div class="wrap kashiwazaki-seo-heatmap-wrap">
		<h1><?php echo esc_html__( 'Kashiwazaki SEO Heatmap — ダッシュボード', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></h1>
		<?php kashiwazaki_seo_heatmap_render_admin_tabs( 'dashboard' ); ?>
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

		<?php kashiwazaki_seo_heatmap_render_schema_notice(); ?>

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
			<div class="kashiwazaki-seo-heatmap-card"><span class="kashiwazaki-seo-heatmap-card-value"><?php echo esc_html( number_format_i18n( $visitors ) ); ?></span><span class="kashiwazaki-seo-heatmap-card-label"><?php echo esc_html( $visitors_label ); ?></span></div>
			<div class="kashiwazaki-seo-heatmap-card"><span class="kashiwazaki-seo-heatmap-card-value"><?php echo esc_html( number_format_i18n( $clicks ) ); ?></span><span class="kashiwazaki-seo-heatmap-card-label"><?php echo esc_html__( 'クリック数', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></span></div>
			<div class="kashiwazaki-seo-heatmap-card"><span class="kashiwazaki-seo-heatmap-card-value"><?php echo esc_html( number_format_i18n( $avg_time, 1 ) ); ?></span><span class="kashiwazaki-seo-heatmap-card-label"><?php echo esc_html__( '平均滞在時間（秒）', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></span></div>
			<div class="kashiwazaki-seo-heatmap-card kashiwazaki-seo-heatmap-card-alert"><span class="kashiwazaki-seo-heatmap-card-value"><?php echo esc_html( number_format_i18n( $rage ) ); ?></span><span class="kashiwazaki-seo-heatmap-card-label"><?php echo esc_html__( 'レイジクリック', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></span></div>
			<div class="kashiwazaki-seo-heatmap-card kashiwazaki-seo-heatmap-card-alert"><span class="kashiwazaki-seo-heatmap-card-value"><?php echo esc_html( number_format_i18n( $dead ) ); ?></span><span class="kashiwazaki-seo-heatmap-card-label"><?php echo esc_html__( 'デッドクリック', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></span></div>
			<div class="kashiwazaki-seo-heatmap-card"><span class="kashiwazaki-seo-heatmap-card-value"><?php echo esc_html( number_format_i18n( $outbound ) ); ?></span><span class="kashiwazaki-seo-heatmap-card-label"><?php echo esc_html__( '外部リンクのクリック', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></span></div>
		</div>

		<?php if ( $lost > 0 ) : ?>
			<div class="notice notice-warning inline">
				<p>
					<?php
					printf(
						/* translators: %s: 件数。 */
						esc_html__( '直近 8 日に、訪問者のブラウザからまだ届いていない操作が %s 件あります（ブラウザの保存領域が使えない・一杯の状態で、受け取りを確かめられないままページを離れた閲覧の分）。上の数字には入っていません。後で届けば、この数は減ります。', 'wp-plugin-kashiwazaki-seo-heatmap' ),
						esc_html( number_format_i18n( $lost ) )
					);
					?>
				</p>
			</div>
		<?php endif; ?>

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
						<th><?php echo esc_html( $visitors_label ); ?></th>
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
								<td><?php echo esc_html( number_format_i18n( isset( $page_visitors[ $row['page_key'] ] ) ? (int) $page_visitors[ $row['page_key'] ] : 0 ) ); ?></td>
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
