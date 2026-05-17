<?php
/**
 * Uncode 테마 한정 호환 처리.
 *
 *  - inquiry 글에 _uncode_active_sidebar='off' 메타를 자동 보장해 사이드바 미출력.
 *  - inquiry 글 작성 직후 wp_insert_post 훅에서 동일 메타 보장.
 *
 * Uncode (또는 자식 테마) 활성 시에만 이 파일이 로드되며, 그 외 테마에서는 영향 없음.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 글 1건에 inquiry 기본 테마 메타 보장.
 */
function inquiry_board_ensure_uncode_meta( int $post_id ): void {
	if ( $post_id <= 0 ) {
		return;
	}
	$post = get_post( $post_id );
	if ( ! $post || $post->post_type !== 'inquiry' ) {
		return;
	}
	if ( ! get_post_meta( $post_id, '_uncode_active_sidebar', true ) ) {
		update_post_meta( $post_id, '_uncode_active_sidebar', 'off' );
	}
}

// 새 글 등록·갱신 시 자동 적용 (form 핸들러나 wp-admin 모두 커버).
add_action( 'save_post_inquiry', 'inquiry_board_ensure_uncode_meta', 10, 1 );
