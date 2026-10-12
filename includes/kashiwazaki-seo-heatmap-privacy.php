<?php
/**
 * プライバシーポリシーへの提案文。
 *
 * WordPress の「設定 → プライバシー」にある「プライバシーポリシーの下書きを提案」に、
 * このプラグインが集める情報の説明を差し込めるようにする。
 *
 * 公式ハンドブック（developer.wordpress.org/plugins/privacy/suggesting-text/）に従い、
 * admin_init で呼び、wp_kses_post( wpautop( ... ) ) を通して登録する。
 *
 * @package Kashiwazaki_SEO_Heatmap
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * プライバシーポリシーの提案文を登録する。
 *
 * @return void
 */
function kashiwazaki_seo_heatmap_add_privacy_policy_content() {
	if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
		return;
	}

	$intro = '<p class="privacy-policy-tutorial">'
		. esc_html__( 'この文章は Kashiwazaki SEO Heatmap が提案する内容です。必要に応じて書き換えてください。', 'wp-plugin-kashiwazaki-seo-heatmap' )
		. '</p>';

	$suggested = '<strong class="privacy-policy-tutorial">'
		. esc_html__( '提案する文面:', 'wp-plugin-kashiwazaki-seo-heatmap' )
		. '</strong> ';

	$suggested .= esc_html__( 'このサイトでは、ページのどこがクリックされ、どこまで読まれているかを知るために、閲覧中の操作を記録しています。記録するのは、クリックされた場所（ページ内の座標と、押された要素）、スクロールの到達率、マウスの動きの分布、操作のあった時刻、および端末の種類です。', 'wp-plugin-kashiwazaki-seo-heatmap' );

	$suggested .= ' ' . esc_html__( '個人を特定できる情報は記録しません。IP アドレスは保存せず、訪問者の区別にはブラウザの Cookie に保存したランダムな文字列だけを使います。この値から個人を特定することはできません。記録された内容はこのサイトのデータベースにのみ保存され、外部のサービスへ送信することはありません。', 'wp-plugin-kashiwazaki-seo-heatmap' );

	$suggested .= ' ' . esc_html__( 'ブラウザの「追跡しない（Do Not Track）」設定が有効な場合は記録しません。ログイン中の管理者は既定で記録の対象から外しています。', 'wp-plugin-kashiwazaki-seo-heatmap' );

	$settings  = kashiwazaki_seo_heatmap_get_settings();
	$retention = isset( $settings['retention_days'] ) ? (int) $settings['retention_days'] : KASHIWAZAKI_SEO_HEATMAP_DEFAULT_RETENTION_DAYS;

	$suggested .= ' ' . sprintf(
		/* translators: %d: 生データの保持日数。 */
		esc_html__( '記録した生データは %d 日を過ぎると自動で削除されます。', 'wp-plugin-kashiwazaki-seo-heatmap' ),
		$retention
	);

	$suggested .= ' ' . esc_html__( '集計した数値（クリック数の合計など）は、個人を特定できない形でそのまま残ります。', 'wp-plugin-kashiwazaki-seo-heatmap' );

	wp_add_privacy_policy_content(
		'Kashiwazaki SEO Heatmap',
		wp_kses_post( wpautop( $intro . $suggested, false ) )
	);
}
add_action( 'admin_init', 'kashiwazaki_seo_heatmap_add_privacy_policy_content' );
