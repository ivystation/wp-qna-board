<?php
/**
 * inquiry CPT 의 비번 입력 폼. the_password_form 필터에서 사용.
 * $inquiry_post_id 사용 가능.
 *
 * 주의: 이 출력은 the_content 필터 체인의 wpautop 를 통과한다.
 * <p> 태그를 쓰면 wpautop 가 paragraph 변형에 끼어들어 마크업이 깨지므로
 * 단락 표시는 모두 <div> 로 한다. (permissions.php 의 콜백에서
 * 추가로 newline·CR 도 제거한다.)
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$post_id = (int) ( $inquiry_post_id ?? get_the_ID() );
?>
<form class="inquiry-password-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<div class="ipf-hidden">
		<input type="hidden" name="action" value="inquiry_unlock">
		<input type="hidden" name="inquiry_post_id" value="<?php echo (int) $post_id; ?>">
		<?php wp_nonce_field( 'inquiry_board_unlock', 'inquiry_board_unlock_nonce' ); ?>
	</div>
	<div class="ipf-msg"><?php esc_html_e( '이 글은 비밀번호로 보호되어 있습니다. 작성자 본인이거나 비밀번호를 알고 있는 경우 입력해 주세요.', 'wp-qna-board' ); ?></div>
	<div class="ipf-field">
		<label><?php esc_html_e( '비밀번호', 'wp-qna-board' ); ?>
			<input type="password" name="inquiry_password" required>
		</label>
		<button type="submit" class="button"><?php esc_html_e( '확인', 'wp-qna-board' ); ?></button>
	</div>
	<div class="ipf-help"><small><?php esc_html_e( '확인 시 같은 브라우저·네트워크에서 24시간 동안은 비밀번호 재입력 없이 본문을 보실 수 있습니다.', 'wp-qna-board' ); ?></small></div>
</form>
