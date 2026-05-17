<?php
/**
 * 설정 페이지: Settings → Q&A 게시판.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_menu', 'inquiry_board_settings_menu' );
add_action( 'admin_init', 'inquiry_board_settings_register' );

function inquiry_board_settings_menu(): void {
	add_options_page(
		__( 'Q&A 게시판 설정', 'wp-qna-board' ),
		__( 'Q&A 게시판', 'wp-qna-board' ),
		'manage_options',
		'wp-qna-board',
		'inquiry_board_settings_page'
	);
}

function inquiry_board_settings_register(): void {
	register_setting( 'inquiry_board', 'inquiry_board_settings', [
		'type'              => 'array',
		'sanitize_callback' => 'inquiry_board_settings_sanitize',
		'default'           => inquiry_board_settings_defaults(),
	] );
}

function inquiry_board_settings_defaults(): array {
	return [
		'recaptcha_site_key'   => '',
		'recaptcha_secret'     => '',
		'recaptcha_min_score'  => 0.3,
		'allowed_ext'          => 'jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,hwp,zip',
		'max_upload_mb'        => 10,
		'session_ttl'          => 86400,
		'notify_email'         => '',
		'password_min_length'  => 4,
		'password_required'    => 1,
	];
}

function inquiry_board_settings_sanitize( $raw ): array {
	$d   = inquiry_board_settings_defaults();
	$out = $d;
	$raw = is_array( $raw ) ? $raw : [];

	$out['recaptcha_site_key']  = sanitize_text_field( (string) ( $raw['recaptcha_site_key']  ?? '' ) );
	$out['recaptcha_secret']    = sanitize_text_field( (string) ( $raw['recaptcha_secret']    ?? '' ) );
	$out['recaptcha_min_score'] = max( 0.0, min( 1.0, (float) ( $raw['recaptcha_min_score'] ?? 0.3 ) ) );

	$exts = preg_replace( '/[^a-z0-9,]+/', '', strtolower( (string) ( $raw['allowed_ext'] ?? $d['allowed_ext'] ) ) );
	$out['allowed_ext']         = $exts ?: $d['allowed_ext'];
	$out['max_upload_mb']       = max( 1, (int) ( $raw['max_upload_mb'] ?? 10 ) );
	$out['session_ttl']         = max( 600, (int) ( $raw['session_ttl'] ?? 86400 ) );
	$out['notify_email']        = sanitize_email( (string) ( $raw['notify_email'] ?? '' ) );
	$out['password_min_length'] = max( 1, (int) ( $raw['password_min_length'] ?? 4 ) );
	$out['password_required']   = ! empty( $raw['password_required'] ) ? 1 : 0;
	return $out;
}

function inquiry_board_settings_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$opts = wp_parse_args( get_option( 'inquiry_board_settings', [] ), inquiry_board_settings_defaults() );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Q&A 게시판 설정', 'wp-qna-board' ); ?></h1>
		<form method="post" action="options.php">
			<?php settings_fields( 'inquiry_board' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="ibs_site_key"><?php esc_html_e( 'reCAPTCHA Site Key', 'wp-qna-board' ); ?></label></th>
					<td><input type="text" id="ibs_site_key" name="inquiry_board_settings[recaptcha_site_key]" class="regular-text" value="<?php echo esc_attr( $opts['recaptcha_site_key'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="ibs_secret"><?php esc_html_e( 'reCAPTCHA Secret', 'wp-qna-board' ); ?></label></th>
					<td><input type="text" id="ibs_secret" name="inquiry_board_settings[recaptcha_secret]" class="regular-text" value="<?php echo esc_attr( $opts['recaptcha_secret'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="ibs_score"><?php esc_html_e( 'reCAPTCHA 최소 점수 (0.0~1.0)', 'wp-qna-board' ); ?></label></th>
					<td><input type="number" step="0.1" min="0" max="1" id="ibs_score" name="inquiry_board_settings[recaptcha_min_score]" value="<?php echo esc_attr( (string) $opts['recaptcha_min_score'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="ibs_ext"><?php esc_html_e( '첨부 허용 확장자', 'wp-qna-board' ); ?></label></th>
					<td><input type="text" id="ibs_ext" name="inquiry_board_settings[allowed_ext]" class="regular-text" value="<?php echo esc_attr( $opts['allowed_ext'] ); ?>"><br><small><?php esc_html_e( '소문자, 쉼표 구분. 예: jpg,png,pdf', 'wp-qna-board' ); ?></small></td>
				</tr>
				<tr>
					<th scope="row"><label for="ibs_size"><?php esc_html_e( '첨부 용량 상한 (MB)', 'wp-qna-board' ); ?></label></th>
					<td><input type="number" min="1" id="ibs_size" name="inquiry_board_settings[max_upload_mb]" value="<?php echo esc_attr( (string) $opts['max_upload_mb'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="ibs_ttl"><?php esc_html_e( '본인 세션 lifetime (초)', 'wp-qna-board' ); ?></label></th>
					<td><input type="number" min="600" id="ibs_ttl" name="inquiry_board_settings[session_ttl]" value="<?php echo esc_attr( (string) $opts['session_ttl'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="ibs_notify"><?php esc_html_e( '관리자 알림 수신 이메일', 'wp-qna-board' ); ?></label></th>
					<td><input type="email" id="ibs_notify" name="inquiry_board_settings[notify_email]" class="regular-text" value="<?php echo esc_attr( $opts['notify_email'] ); ?>"><br><small><?php esc_html_e( '미입력 시 사이트 관리자 이메일로 발송', 'wp-qna-board' ); ?></small></td>
				</tr>
				<tr>
					<th scope="row"><label for="ibs_pwd_min"><?php esc_html_e( '비밀번호 최소 자릿수', 'wp-qna-board' ); ?></label></th>
					<td><input type="number" min="1" id="ibs_pwd_min" name="inquiry_board_settings[password_min_length]" value="<?php echo esc_attr( (string) $opts['password_min_length'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( '비밀번호 필수 여부', 'wp-qna-board' ); ?></th>
					<td><label><input type="checkbox" name="inquiry_board_settings[password_required]" value="1" <?php checked( ! empty( $opts['password_required'] ) ); ?>> <?php esc_html_e( '필수로 받기 (권장)', 'wp-qna-board' ); ?></label></td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}
