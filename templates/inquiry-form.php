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

$list_url = function_exists( 'inquiry_board_current_page_url' ) ? inquiry_board_current_page_url() : '';
$redirect = ! empty( $atts['redirect'] ) ? (string) $atts['redirect'] : $list_url;
$form_title = isset( $atts['title'] ) && $atts['title'] !== '' ? (string) $atts['title'] : __( '문의하기', 'wp-qna-board' );
?>

<div class="inquiry-form-wrap">

<div class="inquiry-form-header">
	<?php if ( $form_title ) : ?>
		<h2 class="inquiry-form-title"><?php echo esc_html( $form_title ); ?></h2>
	<?php endif; ?>
	<?php if ( $list_url ) : ?>
		<p class="inquiry-form-back">
			<a class="button inquiry-list-btn" href="<?php echo esc_url( $list_url ); ?>">
				<?php esc_html_e( '← 목록으로', 'wp-qna-board' ); ?>
			</a>
		</p>
	<?php endif; ?>
</div>

<form class="inquiry-form" method="post" enctype="multipart/form-data"
      action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="inquiry_submit">
	<?php wp_nonce_field( INQUIRY_BOARD_NONCE_ACTION, INQUIRY_BOARD_NONCE_FIELD ); ?>
	<?php if ( $redirect ) : ?>
		<input type="hidden" name="inquiry_redirect" value="<?php echo esc_attr( $redirect ); ?>">
	<?php endif; ?>
	<input type="text" name="inquiry_hp" value="" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px;">

	<div class="inquiry-form-grid inquiry-form-grid--2col">
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
	</div>

	<?php
	// password_required 가 켜져 있으면 모든 글을 비공개로 강제하고 공개 선택지를 감춘다(기존 동작).
	// 꺼져 있으면 공개가 기본이고, 비공개를 고를 때만 비밀번호를 받는다.
	$force_private = ! empty( $opts['password_required'] );
	?>

	<div class="inquiry-form-grid inquiry-form-grid--<?php echo $force_private ? '4col' : '3col'; ?>">
		<p>
			<label for="inq_author"><?php esc_html_e( '이름', 'wp-qna-board' ); ?> <span aria-hidden="true">*</span></label>
			<input type="text" id="inq_author" name="inquiry_author" required maxlength="50">
		</p>

		<p>
			<label for="inq_email"><?php esc_html_e( '이메일', 'wp-qna-board' ); ?> <span aria-hidden="true">*</span></label>
			<input type="email" id="inq_email" name="inquiry_email" required>
		</p>

		<p>
			<label for="inq_phone"><?php esc_html_e( '연락처', 'wp-qna-board' ); ?> <span aria-hidden="true">*</span></label>
			<input type="tel" id="inq_phone" name="inquiry_phone" required maxlength="25"
			       inputmode="tel" autocomplete="tel" placeholder="010-1234-5678">
		</p>

		<?php if ( $force_private ) : ?>
			<p>
				<label for="inq_password"><?php esc_html_e( '글 비밀번호', 'wp-qna-board' ); ?> <span aria-hidden="true">*</span></label>
				<input type="password" id="inq_password" name="inquiry_password" required minlength="<?php echo (int) $opts['password_min_length']; ?>" autocomplete="new-password">
			</p>
		<?php endif; ?>
	</div>

	<?php if ( $force_private ) : ?>
		<p class="inquiry-form-help">
			<small><?php esc_html_e( '비밀번호는 본인 글 열람·수정·댓글에 사용됩니다. 작성 직후 24시간 동안은 같은 브라우저에서 비번 입력 없이 이용할 수 있습니다.', 'wp-qna-board' ); ?></small>
		</p>
	<?php else : ?>
		<fieldset class="inquiry-visibility">
			<legend><?php esc_html_e( '공개 설정', 'wp-qna-board' ); ?></legend>
			<label class="inquiry-visibility-opt">
				<input type="radio" name="inquiry_visibility" value="public" checked>
				<span><strong><?php esc_html_e( '공개', 'wp-qna-board' ); ?></strong>
					<small><?php esc_html_e( '누구나 내용을 볼 수 있습니다.', 'wp-qna-board' ); ?></small></span>
			</label>
			<label class="inquiry-visibility-opt">
				<input type="radio" name="inquiry_visibility" value="private">
				<span><strong><?php esc_html_e( '비공개', 'wp-qna-board' ); ?></strong>
					<small><?php esc_html_e( '비밀번호를 아는 사람과 관리자만 볼 수 있습니다.', 'wp-qna-board' ); ?></small></span>
			</label>
		</fieldset>

		<p class="inquiry-password-field" hidden>
			<label for="inq_password"><?php esc_html_e( '글 비밀번호', 'wp-qna-board' ); ?> <span aria-hidden="true">*</span></label>
			<input type="password" id="inq_password" name="inquiry_password" minlength="<?php echo (int) $opts['password_min_length']; ?>" autocomplete="new-password">
			<small><?php printf(
				/* translators: %d: 최소 자릿수 */
				esc_html__( '최소 %d자. 본인 글 열람·수정·댓글에 사용됩니다.', 'wp-qna-board' ),
				(int) $opts['password_min_length']
			); ?></small>
		</p>

		<p class="inquiry-form-help">
			<small><?php esc_html_e( '작성 직후 24시간 동안은 같은 브라우저에서 비밀번호 없이 수정·삭제할 수 있습니다. 공개글은 24시간이 지나면 본인 수정이 불가하니, 나중에 직접 고칠 일이 있다면 비공개로 작성해 주세요.', 'wp-qna-board' ); ?></small>
		</p>
	<?php endif; ?>

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

	<?php
	/**
	 * 파일 첨부 UI — 클립 아이콘 + (선택수/최대) 카운트 + 확장자·용량 안내.
	 * 실제 file input 은 시각적으로 숨기고(label 의 클릭으로 트리거),
	 * inquiry-file-list 에 선택된 파일명을 chip 형태로 표시한다.
	 * (max=5 는 현재 하드코딩, 후속 패치에서 settings 옵션으로 분리 예정.)
	 */
	$inq_max_files = 5;
	$inq_accept    = '.' . str_replace( ',', ',.', (string) $opts['allowed_ext'] );
	$inq_ext_label = strtoupper( str_replace( ',', ', ', (string) $opts['allowed_ext'] ) );
	?>
	<p class="inquiry-form-files">
		<label class="inquiry-file-trigger" for="inq_files">
			<span class="inquiry-file-icon" aria-hidden="true">
				<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" focusable="false"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 1 1-2.83-2.83l8.49-8.48" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
			</span>
			<span class="inquiry-file-label-text">
				<?php esc_html_e( '파일 첨부', 'wp-qna-board' ); ?>
				<span class="inquiry-file-count" data-max="<?php echo (int) $inq_max_files; ?>">(0/<?php echo (int) $inq_max_files; ?>)</span>
			</span>
		</label>
		<input type="file" id="inq_files" name="inquiry_attachments[]" multiple
		       class="inquiry-file-input"
		       data-max="<?php echo (int) $inq_max_files; ?>"
		       accept="<?php echo esc_attr( $inq_accept ); ?>">
		<span class="inquiry-file-help">
			<?php echo esc_html( sprintf( __( '%1$s / 최대 %2$d MB', 'wp-qna-board' ), $inq_ext_label, (int) $opts['max_upload_mb'] ) ); ?>
		</span>
		<ul class="inquiry-file-list" aria-live="polite"></ul>
	</p>

	<?php
	/**
	 * 동의 — 개인정보 수집·이용(필수)과 마케팅 정보 수신(선택)을 **분리해서** 받는다.
	 * 하나로 묶으면 문의하려면 어차피 체크해야 하므로 전원이 마케팅 수신 동의로 집계된다.
	 * 둘 다 기본 미체크(사전 선택된 동의는 유효한 동의로 보지 않는다).
	 */
	$privacy_url = get_privacy_policy_url();
	?>
	<fieldset class="inquiry-consent">
		<legend><?php esc_html_e( '동의', 'wp-qna-board' ); ?></legend>

		<label class="inquiry-consent-opt">
			<input type="checkbox" name="inquiry_privacy_consent" value="1" required>
			<span>
				<strong><?php esc_html_e( '[필수] 개인정보 수집·이용 동의', 'wp-qna-board' ); ?></strong>
				<small>
					<?php esc_html_e( '문의 접수와 답변 안내를 위해 이름·이메일·연락처를 수집하며, 문의 처리 완료 후 관련 법령에 따라 보관·파기합니다.', 'wp-qna-board' ); ?>
					<?php if ( $privacy_url ) : ?>
						<a href="<?php echo esc_url( $privacy_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( '개인정보취급방침 보기', 'wp-qna-board' ); ?></a>
					<?php endif; ?>
				</small>
			</span>
		</label>

		<label class="inquiry-consent-opt">
			<input type="checkbox" name="inquiry_marketing_consent" value="1">
			<span>
				<strong><?php esc_html_e( '[선택] 마케팅 정보 수신 동의', 'wp-qna-board' ); ?></strong>
				<small><?php esc_html_e( '유학 설명회·모집 일정 등 광고성 정보를 이메일·문자로 받아봅니다. 동의하지 않아도 문의 접수와 답변에는 제한이 없으며, 언제든 수신을 거부할 수 있습니다.', 'wp-qna-board' ); ?></small>
			</span>
		</label>
	</fieldset>

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

	<p class="inquiry-form-actions">
		<button type="submit" class="button button-primary inquiry-form-submit"><?php esc_html_e( '등록 하기', 'wp-qna-board' ); ?></button>
	</p>
</form>

</div><!-- /.inquiry-form-wrap -->
