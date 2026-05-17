<?php
/**
 * [inquiry_form] 출력 템플릿. 테마에서 inquiry-form.php 로 오버라이드 가능.
 *
 * 사용 가능 변수:
 *   $atts (array): 숏코드 속성
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$opts = wp_parse_args( get_option( 'inquiry_board_settings', [] ), inquiry_board_settings_defaults() );
$site_key = (string) ( $opts['recaptcha_site_key'] ?? '' );
$categories = get_terms( [ 'taxonomy' => 'inquiry_category', 'hide_empty' => false ] );
?>

<form class="inquiry-form" method="post" enctype="multipart/form-data"
      action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="inquiry_submit">
	<?php wp_nonce_field( INQUIRY_BOARD_NONCE_ACTION, INQUIRY_BOARD_NONCE_FIELD ); ?>
	<input type="text" name="inquiry_hp" value="" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px;">

	<p>
		<label for="inq_title"><?php esc_html_e( '제목', 'wp-qna-board' ); ?> <span aria-hidden="true">*</span></label>
		<input type="text" id="inq_title" name="inquiry_title" required maxlength="200" class="widefat">
	</p>

	<p>
		<label for="inq_category"><?php esc_html_e( '카테고리', 'wp-qna-board' ); ?> <span aria-hidden="true">*</span></label>
		<select id="inq_category" name="inquiry_category" required>
			<option value=""><?php esc_html_e( '선택하세요', 'wp-qna-board' ); ?></option>
			<?php foreach ( $categories as $t ) : ?>
				<option value="<?php echo esc_attr( $t->slug ); ?>"><?php echo esc_html( $t->name ); ?></option>
			<?php endforeach; ?>
		</select>
	</p>

	<p>
		<label for="inq_author"><?php esc_html_e( '이름', 'wp-qna-board' ); ?> <span aria-hidden="true">*</span></label>
		<input type="text" id="inq_author" name="inquiry_author" required maxlength="50">
	</p>

	<p>
		<label for="inq_email"><?php esc_html_e( '이메일', 'wp-qna-board' ); ?> <span aria-hidden="true">*</span></label>
		<input type="email" id="inq_email" name="inquiry_email" required>
	</p>

	<p>
		<label for="inq_password"><?php esc_html_e( '글 비밀번호', 'wp-qna-board' ); ?> <span aria-hidden="true">*</span></label>
		<input type="password" id="inq_password" name="inquiry_password" required minlength="<?php echo (int) $opts['password_min_length']; ?>">
		<br><small><?php esc_html_e( '본인 글 열람·수정·댓글에 사용됩니다. 작성 직후 24시간 동안은 같은 브라우저에서 비번 입력 없이 이용할 수 있습니다.', 'wp-qna-board' ); ?></small>
	</p>

	<p>
		<label for="inq_content"><?php esc_html_e( '내용', 'wp-qna-board' ); ?> <span aria-hidden="true">*</span></label>
		<?php
		wp_editor( '', 'inq_content', [
			'textarea_name' => 'inquiry_content',
			'media_buttons' => false,
			'tinymce'       => false,
			'quicktags'     => false,
			'editor_height' => 240,
			'textarea_rows' => 10,
		] );
		?>
	</p>

	<p>
		<label for="inq_files"><?php esc_html_e( '첨부 (선택)', 'wp-qna-board' ); ?></label>
		<input type="file" id="inq_files" name="inquiry_attachments[]" multiple>
		<br><small><?php echo esc_html( sprintf( __( '확장자: %1$s · 최대 %2$d MB', 'wp-qna-board' ), $opts['allowed_ext'], (int) $opts['max_upload_mb'] ) ); ?></small>
	</p>

	<?php if ( $site_key ) : ?>
		<input type="hidden" id="inq_recaptcha" name="g-recaptcha-response" value="">
		<script src="https://www.google.com/recaptcha/api.js?render=<?php echo esc_attr( $site_key ); ?>"></script>
		<script>
		document.addEventListener('submit', function (e) {
			if (!e.target.classList || !e.target.classList.contains('inquiry-form')) return;
			e.preventDefault();
			grecaptcha.ready(function () {
				grecaptcha.execute('<?php echo esc_js( $site_key ); ?>', { action: 'inquiry_submit' }).then(function (token) {
					document.getElementById('inq_recaptcha').value = token;
					e.target.submit();
				});
			});
		}, true);
		</script>
	<?php endif; ?>

	<p>
		<button type="submit" class="button button-primary"><?php esc_html_e( '등록', 'wp-qna-board' ); ?></button>
	</p>
</form>
