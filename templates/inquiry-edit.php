<?php
/**
 * 본인 수정 폼. inquiry_board_render_edit_form( $post_id ) 에서 include.
 * $post 변수가 단일 페이지에서 전달된다고 가정.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$current   = isset( $post ) && $post instanceof WP_Post ? $post : get_post();
$post_id   = (int) $current->ID;
$cur_terms = wp_get_object_terms( $post_id, 'inquiry_category', [ 'fields' => 'slugs' ] );
$cur_cat   = $cur_terms[0] ?? '';
$categories = get_terms( [ 'taxonomy' => 'inquiry_category', 'hide_empty' => false ] );
?>
<form class="inquiry-edit-form" method="post"
      action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="inquiry_update">
	<input type="hidden" name="inquiry_post_id" value="<?php echo (int) $post_id; ?>">
	<?php wp_nonce_field( INQUIRY_BOARD_EDIT_NONCE_ACTION, INQUIRY_BOARD_EDIT_NONCE_FIELD ); ?>

	<p>
		<label><?php esc_html_e( '제목', 'wp-qna-board' ); ?>
			<input type="text" name="inquiry_title" required maxlength="200" class="widefat" value="<?php echo esc_attr( $current->post_title ); ?>">
		</label>
	</p>
	<p>
		<label><?php esc_html_e( '카테고리', 'wp-qna-board' ); ?>
			<select name="inquiry_category" required>
				<?php foreach ( $categories as $t ) : ?>
					<option value="<?php echo esc_attr( $t->slug ); ?>" <?php selected( $cur_cat, $t->slug ); ?>><?php echo esc_html( $t->name ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
	</p>
	<p>
		<label><?php esc_html_e( '내용', 'wp-qna-board' ); ?></label>
		<?php
		wp_editor( $current->post_content, 'inq_edit_content', [
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
		<label><?php esc_html_e( '비밀번호 변경 (빈 칸이면 유지)', 'wp-qna-board' ); ?>
			<input type="password" name="inquiry_new_password" autocomplete="new-password">
		</label>
	</p>
	<p>
		<button type="submit" class="button button-primary"><?php esc_html_e( '수정 저장', 'wp-qna-board' ); ?></button>
	</p>
</form>

<form class="inquiry-delete-form" method="post"
      action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
      onsubmit="return confirm('<?php echo esc_js( __( '글을 휴지통으로 이동합니다. 진행할까요?', 'wp-qna-board' ) ); ?>');">
	<input type="hidden" name="action" value="inquiry_delete">
	<input type="hidden" name="inquiry_post_id" value="<?php echo (int) $post_id; ?>">
	<?php wp_nonce_field( 'inquiry_board_delete', 'inquiry_board_delete_nonce' ); ?>
	<button type="submit" class="button"><?php esc_html_e( '삭제', 'wp-qna-board' ); ?></button>
</form>
