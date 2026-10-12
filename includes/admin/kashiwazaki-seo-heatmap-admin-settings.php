<?php
/**
 * 設定画面。
 *
 * @package Kashiwazaki_SEO_Heatmap
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 設定画面の HTML を出力する。
 *
 * @return void
 */
function kashiwazaki_seo_heatmap_render_settings_page() {
	$settings   = kashiwazaki_seo_heatmap_get_settings();
	$slug       = KASHIWAZAKI_SEO_HEATMAP_MENU_SLUG;
	$option     = KASHIWAZAKI_SEO_HEATMAP_SETTINGS_OPTION;
	$post_types = kashiwazaki_seo_heatmap_get_trackable_post_types();
	$roles      = wp_roles()->get_names();
	$schemes    = kashiwazaki_seo_heatmap_color_schemes();
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$purged     = isset( $_GET['purged'] ) && '1' === $_GET['purged'];
	?>
	<div class="wrap kashiwazaki-seo-heatmap-wrap">
		<h1><?php echo esc_html__( 'Kashiwazaki SEO Heatmap — 設定', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></h1>

		<?php
		// 保存したときの「設定を保存しました。」を出す。
		// 自前の画面は options-head.php を通らないため、ここで呼ばないと何も出ない。
		settings_errors();
		?>

		<?php if ( $purged ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php echo esc_html__( '計測データをすべて削除しました。', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></p></div>
		<?php endif; ?>

		<form method="post" action="options.php">
			<?php settings_fields( 'kashiwazaki_seo_heatmap_settings_group' ); ?>

			<h2><?php echo esc_html__( '計測する対象', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php echo esc_html__( '計測する投稿タイプ', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></th>
					<td>
						<div class="kashiwazaki-seo-heatmap-post-type-actions">
							<button type="button" class="button kashiwazaki-seo-heatmap-post-type-all" data-target="kashiwazaki-seo-heatmap-enabled-post-types"><?php echo esc_html__( '一括チェック', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></button>
							<button type="button" class="button kashiwazaki-seo-heatmap-post-type-none" data-target="kashiwazaki-seo-heatmap-enabled-post-types"><?php echo esc_html__( '一括解除', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></button>
						</div>
						<fieldset id="kashiwazaki-seo-heatmap-enabled-post-types">
							<legend class="screen-reader-text"><?php echo esc_html__( '計測する投稿タイプ', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></legend>
							<?php foreach ( $post_types as $type_name => $label ) : ?>
								<label class="kashiwazaki-seo-heatmap-check">
									<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[enabled_post_types][]" value="<?php echo esc_attr( $type_name ); ?>" <?php checked( in_array( $type_name, (array) $settings['enabled_post_types'], true ) ); ?> />
									<?php echo esc_html( $label ); ?>（<?php echo esc_html( $type_name ); ?>）
								</label><br />
							<?php endforeach; ?>
						</fieldset>
						<p class="description"><?php echo esc_html__( 'チェックを入れた投稿タイプだけを計測します。ここで選んだものの「どこがクリックされているか」がヒートマップで見られます。', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( '計測する項目', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><?php echo esc_html__( '計測する項目', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></legend>
							<label class="kashiwazaki-seo-heatmap-check"><input type="checkbox" name="<?php echo esc_attr( $option ); ?>[track_clicks]" value="1" <?php checked( ! empty( $settings['track_clicks'] ) ); ?> /> <?php echo esc_html__( 'クリック', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></label><br />
							<label class="kashiwazaki-seo-heatmap-check"><input type="checkbox" name="<?php echo esc_attr( $option ); ?>[track_scroll]" value="1" <?php checked( ! empty( $settings['track_scroll'] ) ); ?> /> <?php echo esc_html__( 'スクロール到達率', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></label><br />
							<label class="kashiwazaki-seo-heatmap-check"><input type="checkbox" name="<?php echo esc_attr( $option ); ?>[track_mouse_move]" value="1" <?php checked( ! empty( $settings['track_mouse_move'] ) ); ?> /> <?php echo esc_html__( 'マウスムーブ（動きの分布。データ量が増えます）', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></label><br />
							<label class="kashiwazaki-seo-heatmap-check"><input type="checkbox" name="<?php echo esc_attr( $option ); ?>[track_rage_clicks]" value="1" <?php checked( ! empty( $settings['track_rage_clicks'] ) ); ?> /> <?php echo esc_html__( 'レイジクリック（同じ場所を短時間に連打）', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></label><br />
							<label class="kashiwazaki-seo-heatmap-check"><input type="checkbox" name="<?php echo esc_attr( $option ); ?>[track_dead_clicks]" value="1" <?php checked( ! empty( $settings['track_dead_clicks'] ) ); ?> /> <?php echo esc_html__( 'デッドクリック（押しても何も起きないクリック）', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></label><br />
							<label class="kashiwazaki-seo-heatmap-check"><input type="checkbox" name="<?php echo esc_attr( $option ); ?>[track_outbound]" value="1" <?php checked( ! empty( $settings['track_outbound'] ) ); ?> /> <?php echo esc_html__( '外部リンクのクリック', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></label>
						</fieldset>
					</td>
				</tr>
			</table>

			<h2><?php echo esc_html__( '除外する条件', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php echo esc_html__( 'ログイン中は計測しない', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></th>
					<td>
						<label class="kashiwazaki-seo-heatmap-check"><input type="checkbox" name="<?php echo esc_attr( $option ); ?>[exclude_logged_in]" value="1" <?php checked( ! empty( $settings['exclude_logged_in'] ) ); ?> /> <?php echo esc_html__( 'ログイン中のユーザーを計測から除外する', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></label>
						<p class="description"><?php echo esc_html__( '自分のアクセスが混ざるのを防ぎます。', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( '除外する権限グループ', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></th>
					<td>
						<fieldset>
							<?php foreach ( $roles as $role_key => $role_name ) : ?>
								<label class="kashiwazaki-seo-heatmap-check"><input type="checkbox" name="<?php echo esc_attr( $option ); ?>[exclude_roles][]" value="<?php echo esc_attr( $role_key ); ?>" <?php checked( in_array( $role_key, (array) $settings['exclude_roles'], true ) ); ?> /> <?php echo esc_html( translate_user_role( $role_name ) ); ?></label><br />
							<?php endforeach; ?>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="kashiwazaki-seo-heatmap-exclude-ips"><?php echo esc_html__( '除外する IP アドレス', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></label></th>
					<td>
						<textarea id="kashiwazaki-seo-heatmap-exclude-ips" name="<?php echo esc_attr( $option ); ?>[exclude_ips]" rows="4" class="large-text code"><?php echo esc_textarea( $settings['exclude_ips'] ); ?></textarea>
						<p class="description"><?php echo esc_html__( '1 行に 1 つ。IP アドレスは完全一致で判定し、末尾に「*」を付けると前方一致になります（例: 203.0.113.*）。IP は保存しません（除外の判定にだけ使います）。', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="kashiwazaki-seo-heatmap-exclude-urls"><?php echo esc_html__( '除外する URL パターン', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></label></th>
					<td>
						<textarea id="kashiwazaki-seo-heatmap-exclude-urls" name="<?php echo esc_attr( $option ); ?>[exclude_url_patterns]" rows="4" class="large-text code"><?php echo esc_textarea( $settings['exclude_url_patterns'] ); ?></textarea>
						<p class="description"><?php echo esc_html__( '1 行に 1 つ。部分一致で判定します（例: /cart/、/privacy/）。', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php echo esc_html__( 'プライバシーとデータの保持', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php echo esc_html__( 'Do Not Track を尊重する', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></th>
					<td>
						<label class="kashiwazaki-seo-heatmap-check"><input type="checkbox" name="<?php echo esc_attr( $option ); ?>[honor_do_not_track]" value="1" <?php checked( ! empty( $settings['honor_do_not_track'] ) ); ?> /> <?php echo esc_html__( 'ブラウザの「追跡しない」設定を尊重する', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="kashiwazaki-seo-heatmap-cookie-days"><?php echo esc_html__( '訪問者の識別期間（日）', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></label></th>
					<td>
						<input type="number" id="kashiwazaki-seo-heatmap-cookie-days" name="<?php echo esc_attr( $option ); ?>[cookie_lifetime_days]" value="<?php echo esc_attr( (int) $settings['cookie_lifetime_days'] ); ?>" min="1" max="3650" class="small-text" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="kashiwazaki-seo-heatmap-sample-rate"><?php echo esc_html__( 'サンプリング（%）', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></label></th>
					<td>
						<input type="number" id="kashiwazaki-seo-heatmap-sample-rate" name="<?php echo esc_attr( $option ); ?>[sample_rate]" value="<?php echo esc_attr( (int) $settings['sample_rate'] ); ?>" min="1" max="100" class="small-text" />
						<p class="description"><?php echo esc_html__( '100 で全件計測。アクセスの多いサイトでは下げるとデータ量を抑えられます。', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="kashiwazaki-seo-heatmap-retention"><?php echo esc_html__( '生データの保持期間（日）', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></label></th>
					<td>
						<input type="number" id="kashiwazaki-seo-heatmap-retention" name="<?php echo esc_attr( $option ); ?>[retention_days]" value="<?php echo esc_attr( (int) $settings['retention_days'] ); ?>" min="1" max="3650" class="small-text" />
						<label class="kashiwazaki-seo-heatmap-check" style="margin-left:1em;">
							<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[enable_auto_cleanup]" value="1" <?php checked( ! empty( $settings['enable_auto_cleanup'] ) ); ?> />
							<?php echo esc_html__( '古い生データを毎日自動で削除する', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?>
						</label>
						<p class="description"><?php echo esc_html__( '集計済みのデータはこの期間を過ぎても残ります。', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( '削除時のデータ', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></th>
					<td>
						<label class="kashiwazaki-seo-heatmap-check"><input type="checkbox" name="<?php echo esc_attr( $option ); ?>[delete_data_on_uninstall]" value="1" <?php checked( ! empty( $settings['delete_data_on_uninstall'] ) ); ?> /> <?php echo esc_html__( 'プラグインを削除したときに計測データと設定も消す', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></label>
						<p class="description"><?php echo esc_html__( 'チェックを外すと、削除しても計測データはデータベースに残ります。', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php echo esc_html__( '判定のしきい値', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php echo esc_html__( 'レイジクリック', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></th>
					<td>
						<label>
							<?php echo esc_html__( '同じ場所を', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?>
							<input type="number" name="<?php echo esc_attr( $option ); ?>[rage_click_count]" value="<?php echo esc_attr( (int) $settings['rage_click_count'] ); ?>" min="2" max="20" class="small-text" />
							<?php echo esc_html__( '回、', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?>
							<input type="number" name="<?php echo esc_attr( $option ); ?>[rage_click_window_ms]" value="<?php echo esc_attr( (int) $settings['rage_click_window_ms'] ); ?>" min="100" max="10000" class="small-text" />
							<?php echo esc_html__( 'ミリ秒以内に、', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?>
							<input type="number" name="<?php echo esc_attr( $option ); ?>[rage_click_radius_px]" value="<?php echo esc_attr( (int) $settings['rage_click_radius_px'] ); ?>" min="5" max="200" class="small-text" />
							<?php echo esc_html__( 'ピクセル以内で連打したら記録', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'デッドクリック', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></th>
					<td>
						<label>
							<?php echo esc_html__( 'クリック後', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?>
							<input type="number" name="<?php echo esc_attr( $option ); ?>[dead_click_window_ms]" value="<?php echo esc_attr( (int) $settings['dead_click_window_ms'] ); ?>" min="200" max="10000" class="small-text" />
							<?php echo esc_html__( 'ミリ秒たっても画面が変わらなければ記録', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'マウスムーブの間隔', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></th>
					<td>
						<label>
							<input type="number" name="<?php echo esc_attr( $option ); ?>[mouse_move_interval_ms]" value="<?php echo esc_attr( (int) $settings['mouse_move_interval_ms'] ); ?>" min="50" max="2000" class="small-text" />
							<?php echo esc_html__( 'ミリ秒ごとに記録（大きいほど軽い）／1 回の表示で最大', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?>
							<input type="number" name="<?php echo esc_attr( $option ); ?>[mouse_move_max_events]" value="<?php echo esc_attr( (int) $settings['mouse_move_max_events'] ); ?>" min="10" max="2000" class="small-text" />
							<?php echo esc_html__( '件', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="kashiwazaki-seo-heatmap-flush"><?php echo esc_html__( '計測データの送信間隔', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></label></th>
					<td>
						<input type="number" id="kashiwazaki-seo-heatmap-flush" name="<?php echo esc_attr( $option ); ?>[flush_interval_ms]" value="<?php echo esc_attr( (int) $settings['flush_interval_ms'] ); ?>" min="1000" max="60000" class="small-text" />
						<?php echo esc_html__( 'ミリ秒ごとにまとめて送信', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?>
					</td>
				</tr>
			</table>

			<h2><?php echo esc_html__( 'ヒートマップの初期表示', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="kashiwazaki-seo-heatmap-default-radius"><?php echo esc_html__( '半径（ピクセル）', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></label></th>
					<td>
						<input type="number" id="kashiwazaki-seo-heatmap-default-radius" name="<?php echo esc_attr( $option ); ?>[default_heat_radius]" value="<?php echo esc_attr( (int) $settings['default_heat_radius'] ); ?>" min="5" max="120" class="small-text" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="kashiwazaki-seo-heatmap-default-opacity"><?php echo esc_html__( '濃さ（%）', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></label></th>
					<td>
						<input type="number" id="kashiwazaki-seo-heatmap-default-opacity" name="<?php echo esc_attr( $option ); ?>[default_heat_opacity]" value="<?php echo esc_attr( (int) $settings['default_heat_opacity'] ); ?>" min="5" max="100" class="small-text" />
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="kashiwazaki-seo-heatmap-default-scheme"><?php echo esc_html__( '配色', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></label></th>
					<td>
						<select id="kashiwazaki-seo-heatmap-default-scheme" name="<?php echo esc_attr( $option ); ?>[heat_color_scheme]">
							<?php foreach ( $schemes as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $settings['heat_color_scheme'], $key ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
			</table>

			<?php submit_button( __( '設定を保存', 'wp-plugin-kashiwazaki-seo-heatmap' ) ); ?>
		</form>

		<hr />
		<h2><?php echo esc_html__( '計測データの削除', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></h2>
		<p class="description"><?php echo esc_html__( '保存されている生データ・集計データをすべて削除します。元に戻せません。', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></p>
		<p>
			<a class="button button-secondary kashiwazaki-seo-heatmap-danger" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . $slug . '-settings&kashiwazaki_seo_heatmap_purge=1' ), 'kashiwazaki_seo_heatmap_purge' ) ); ?>" onclick="return window.confirm('<?php echo esc_js( __( '計測データをすべて削除します。よろしいですか？ この操作は元に戻せません。', 'wp-plugin-kashiwazaki-seo-heatmap' ) ); ?>');">
				<?php echo esc_html__( '計測データをすべて削除', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?>
			</a>
		</p>

		<hr />
		<h2><?php echo esc_html__( '使い方', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></h2>
		<ol class="kashiwazaki-seo-heatmap-help">
			<li><?php echo esc_html__( '上の「計測する投稿タイプ」で、計測したい投稿タイプ（投稿・固定ページ・カスタム投稿）にチェックを入れて保存します。', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></li>
			<li><?php echo esc_html__( 'その投稿タイプのページを普通に閲覧すると、クリックやスクロールが自動で記録されます（ログイン中の管理者は既定で除外されます）。', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></li>
			<li><?php echo esc_html__( '「ヒートマップ」画面で、ページ・期間・端末・表示するデータを選ぶと、クリックされた場所が色で表示されます。', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></li>
			<li><?php echo esc_html__( '右側の「よくクリックされている要素」から、実際に押されているリンクやボタンを確認できます。CSV でも書き出せます。', 'wp-plugin-kashiwazaki-seo-heatmap' ); ?></li>
		</ol>
	</div>
	<?php
}
