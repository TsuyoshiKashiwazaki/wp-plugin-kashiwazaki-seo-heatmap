<?php
/**
 * ヒートマップ表示画面。
 *
 * @package Kashiwazaki_SEO_Heatmap
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ヒートマップ画面の HTML を出力する。
 *
 * @return void
 */
function kashiwazaki_seo_heatmap_render_heatmap_page() {
	$settings  = kashiwazaki_seo_heatmap_get_settings();
	$slug      = KASHIWAZAKI_SEO_HEATMAP_MENU_SLUG;
	$post_types = kashiwazaki_seo_heatmap_get_all_post_types();
	$tracked   = kashiwazaki_seo_heatmap_get_tracked_pages( 180 );
	$schemes   = kashiwazaki_seo_heatmap_color_schemes();

	// 投稿の一覧（最新 300 件。多いサイトでは「投稿IDを直接指定」を使う）。
	$post_options = array();
	foreach ( array_keys( $post_types ) as $type ) {
		$posts = get_posts(
			array(
				'post_type'        => $type,
				'post_status'      => 'publish',
				'numberposts'      => 300,
				'orderby'          => 'date',
				'order'            => 'DESC',
				'suppress_filters' => false,
			)
		);
		foreach ( $posts as $post ) {
			$post_options[] = array(
				'id'    => (int) $post->ID,
				'type'  => $type,
				'label' => get_the_title( $post ) ? get_the_title( $post ) : __( '(無題)', 'wp-plugin-kashiwazaki-seo-heatmap' ),
				'url'   => get_permalink( $post ),
			);
		}
	}
	?>
	<div class="wrap kashiwazaki-seo-heatmap-wrap">
		<h1><?php echo esc_html__( 'Kashiwazaki SEO Heatmap', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></h1>
		<p class="kashiwazaki-seo-heatmap-lead">
			<?php echo esc_html__( '計測したいページを選ぶと、そのページのどこがクリックされているかをヒートマップで確認できます。', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?>
			<?php if ( empty( $tracked ) ) : ?>
				<strong><?php echo esc_html__( 'まだデータがありません。投稿を開いて少し操作すると、数秒後にデータが表示されます。', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></strong>
			<?php endif; ?>
		</p>

		<div class="kashiwazaki-seo-heatmap-toolbar">
			<div class="kashiwazaki-seo-heatmap-field">
				<label for="kashiwazaki-seo-heatmap-page-select"><?php echo esc_html__( '計測済みページ', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></label>
				<select id="kashiwazaki-seo-heatmap-page-select">
					<option value=""><?php echo esc_html__( '— 選択してください —', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></option>
					<?php foreach ( $tracked as $row ) : ?>
						<option
							value="<?php echo esc_attr( $row['page_key'] ); ?>"
							data-post="<?php echo esc_attr( (int) $row['post_id'] ); ?>"
							data-url="<?php echo esc_attr( $row['page_url'] ); ?>">
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: page path, 2: pageview count */
									__( '%1$s（%2$s 閲覧）', 'wp-plugin-kashiwazaki-seo-heatmap' ),
									$row['page_url'],
									number_format_i18n( (int) $row['pageviews'] )
								)
							);
							?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="kashiwazaki-seo-heatmap-field">
				<label for="kashiwazaki-seo-heatmap-post-filter"><?php echo esc_html__( '投稿から選ぶ（絞り込み）', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></label>
				<input type="search" id="kashiwazaki-seo-heatmap-post-filter" placeholder="<?php echo esc_attr__( 'タイトルで絞り込み', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?>" />
				<select id="kashiwazaki-seo-heatmap-post-select">
					<option value=""><?php echo esc_html__( '— 投稿を選択 —', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></option>
					<?php foreach ( $post_options as $option ) : ?>
						<option
							value="<?php echo esc_attr( $option['id'] ); ?>"
							data-url="<?php echo esc_attr( $option['url'] ); ?>"
							data-type="<?php echo esc_attr( $option['type'] ); ?>">
							<?php echo esc_html( '[' . $post_types[ $option['type'] ] . '] ' . $option['label'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="kashiwazaki-seo-heatmap-field kashiwazaki-seo-heatmap-field-narrow">
				<label for="kashiwazaki-seo-heatmap-post-id"><?php echo esc_html__( '投稿ID', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></label>
				<input type="number" id="kashiwazaki-seo-heatmap-post-id" min="0" step="1" placeholder="0" />
			</div>

			<div class="kashiwazaki-seo-heatmap-field kashiwazaki-seo-heatmap-field-narrow">
				<label for="kashiwazaki-seo-heatmap-days"><?php echo esc_html__( '期間', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></label>
				<select id="kashiwazaki-seo-heatmap-days">
					<option value="1"><?php echo esc_html__( '今日', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></option>
					<option value="7"><?php echo esc_html__( '過去 7 日', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></option>
					<option value="30" selected><?php echo esc_html__( '過去 30 日', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></option>
					<option value="90"><?php echo esc_html__( '過去 90 日', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></option>
					<option value="180"><?php echo esc_html__( '過去 180 日', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></option>
					<option value="365"><?php echo esc_html__( '過去 1 年', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></option>
				</select>
			</div>

			<div class="kashiwazaki-seo-heatmap-field kashiwazaki-seo-heatmap-field-narrow">
				<label for="kashiwazaki-seo-heatmap-device"><?php echo esc_html__( '端末', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></label>
				<select id="kashiwazaki-seo-heatmap-device">
					<option value=""><?php echo esc_html__( 'すべて', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></option>
					<option value="desktop"><?php echo esc_html__( 'PC', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></option>
					<option value="tablet"><?php echo esc_html__( 'タブレット', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></option>
					<option value="mobile"><?php echo esc_html__( 'スマートフォン', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></option>
				</select>
			</div>

			<div class="kashiwazaki-seo-heatmap-field kashiwazaki-seo-heatmap-field-narrow">
				<label for="kashiwazaki-seo-heatmap-heat-type"><?php echo esc_html__( '表示するデータ', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></label>
				<select id="kashiwazaki-seo-heatmap-heat-type">
					<option value="click"><?php echo esc_html__( 'クリック', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></option>
					<option value="rage"><?php echo esc_html__( 'レイジクリック', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></option>
					<option value="dead"><?php echo esc_html__( 'デッドクリック', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></option>
					<option value="outbound"><?php echo esc_html__( '外部リンク', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></option>
					<option value="move"><?php echo esc_html__( 'マウスムーブ', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></option>
					<option value="scroll"><?php echo esc_html__( 'スクロール到達率', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></option>
				</select>
			</div>

			<div class="kashiwazaki-seo-heatmap-field kashiwazaki-seo-heatmap-field-narrow">
				<label for="kashiwazaki-seo-heatmap-render"><?php echo esc_html__( '描画方法', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></label>
				<select id="kashiwazaki-seo-heatmap-render">
					<option value="heat"><?php echo esc_html__( 'ヒート（ぼかし）', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></option>
					<option value="dots"><?php echo esc_html__( 'ポイント（点）', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></option>
				</select>
			</div>

			<div class="kashiwazaki-seo-heatmap-field kashiwazaki-seo-heatmap-field-narrow">
				<label for="kashiwazaki-seo-heatmap-radius"><?php echo esc_html__( '半径', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></label>
				<input type="range" id="kashiwazaki-seo-heatmap-radius" min="5" max="120" step="1" value="<?php echo esc_attr( (int) $settings['default_heat_radius'] ); ?>" />
			</div>

			<div class="kashiwazaki-seo-heatmap-field kashiwazaki-seo-heatmap-field-narrow">
				<label for="kashiwazaki-seo-heatmap-opacity"><?php echo esc_html__( '濃さ', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></label>
				<input type="range" id="kashiwazaki-seo-heatmap-opacity" min="5" max="100" step="1" value="<?php echo esc_attr( (int) $settings['default_heat_opacity'] ); ?>" />
			</div>

			<div class="kashiwazaki-seo-heatmap-field kashiwazaki-seo-heatmap-field-narrow">
				<label for="kashiwazaki-seo-heatmap-scheme"><?php echo esc_html__( '配色', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></label>
				<select id="kashiwazaki-seo-heatmap-scheme">
					<?php foreach ( $schemes as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $settings['heat_color_scheme'], $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="kashiwazaki-seo-heatmap-field kashiwazaki-seo-heatmap-field-buttons">
				<button type="button" class="button button-primary" id="kashiwazaki-seo-heatmap-reload"><?php echo esc_html__( '表示を更新', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></button>
				<button type="button" class="button" id="kashiwazaki-seo-heatmap-csv"><?php echo esc_html__( 'CSV 書き出し', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></button>
			</div>
		</div>

		<div class="kashiwazaki-seo-heatmap-summary" id="kashiwazaki-seo-heatmap-summary">
			<div class="kashiwazaki-seo-heatmap-card"><span class="kashiwazaki-seo-heatmap-card-value" id="kashiwazaki-seo-heatmap-stat-pageviews">—</span><span class="kashiwazaki-seo-heatmap-card-label"><?php echo esc_html__( '閲覧数', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></span></div>
			<div class="kashiwazaki-seo-heatmap-card"><span class="kashiwazaki-seo-heatmap-card-value" id="kashiwazaki-seo-heatmap-stat-visitors">—</span><span class="kashiwazaki-seo-heatmap-card-label"><?php echo esc_html__( '訪問者数', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></span></div>
			<div class="kashiwazaki-seo-heatmap-card"><span class="kashiwazaki-seo-heatmap-card-value" id="kashiwazaki-seo-heatmap-stat-clicks">—</span><span class="kashiwazaki-seo-heatmap-card-label"><?php echo esc_html__( 'クリック数', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></span></div>
			<div class="kashiwazaki-seo-heatmap-card"><span class="kashiwazaki-seo-heatmap-card-value" id="kashiwazaki-seo-heatmap-stat-avgtime">—</span><span class="kashiwazaki-seo-heatmap-card-label"><?php echo esc_html__( '平均滞在時間（秒）', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></span></div>
			<div class="kashiwazaki-seo-heatmap-card"><span class="kashiwazaki-seo-heatmap-card-value" id="kashiwazaki-seo-heatmap-stat-avscroll">—</span><span class="kashiwazaki-seo-heatmap-card-label"><?php echo esc_html__( '平均スクロール（%）', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></span></div>
		</div>

		<div class="kashiwazaki-seo-heatmap-layout">
			<div class="kashiwazaki-seo-heatmap-canvas-area">
				<div class="kashiwazaki-seo-heatmap-frame-toolbar">
					<span class="kashiwazaki-seo-heatmap-frame-label"><?php echo esc_html__( 'プレビュー幅', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></span>
					<button type="button" class="button kashiwazaki-seo-heatmap-width-btn is-active" data-width="desktop"><?php echo esc_html__( 'PC', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></button>
					<button type="button" class="button kashiwazaki-seo-heatmap-width-btn" data-width="tablet"><?php echo esc_html__( 'タブレット', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></button>
					<button type="button" class="button kashiwazaki-seo-heatmap-width-btn" data-width="mobile"><?php echo esc_html__( 'スマートフォン', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></button>
					<label class="kashiwazaki-seo-heatmap-check">
						<input type="checkbox" id="kashiwazaki-seo-heatmap-interactive" />
						<?php echo esc_html__( 'ページをそのまま操作する（ヒートマップを隠す）', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?>
					</label>
					<span class="kashiwazaki-seo-heatmap-frame-url" id="kashiwazaki-seo-heatmap-frame-url"></span>
				</div>
				<p class="kashiwazaki-seo-heatmap-frame-notice" id="kashiwazaki-seo-heatmap-frame-notice" hidden></p>
				<div class="kashiwazaki-seo-heatmap-frame-wrap" id="kashiwazaki-seo-heatmap-frame-wrap">
					<div class="kashiwazaki-seo-heatmap-frame-inner" id="kashiwazaki-seo-heatmap-frame-inner">
						<iframe id="kashiwazaki-seo-heatmap-frame" title="<?php echo esc_attr__( '解析対象ページのプレビュー', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?>"></iframe>
						<canvas id="kashiwazaki-seo-heatmap-canvas"></canvas>
					</div>
					<div class="kashiwazaki-seo-heatmap-placeholder" id="kashiwazaki-seo-heatmap-placeholder">
						<p><?php echo esc_html__( '上の「計測済みページ」または「投稿から選ぶ」でページを選んでください。', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></p>
					</div>
				</div>
			</div>

			<aside class="kashiwazaki-seo-heatmap-side">
				<h2><?php echo esc_html__( 'スクロール到達率', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></h2>
				<div class="kashiwazaki-seo-heatmap-scroll-chart" id="kashiwazaki-seo-heatmap-scroll-chart"></div>

				<h2><?php echo esc_html__( 'よくクリックされている要素', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></h2>
				<div class="kashiwazaki-seo-heatmap-table-wrap">
					<table class="widefat striped kashiwazaki-seo-heatmap-table" id="kashiwazaki-seo-heatmap-element-table">
						<thead>
							<tr>
								<th><?php echo esc_html__( '要素', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></th>
								<th><?php echo esc_html__( '回数', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></th>
							</tr>
						</thead>
						<tbody id="kashiwazaki-seo-heatmap-element-tbody">
							<tr><td colspan="2"><?php echo esc_html__( 'データを読み込むと表示されます。', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></td></tr>
						</tbody>
					</table>
				</div>
			</aside>
		</div>
	</div>
	<?php
}
