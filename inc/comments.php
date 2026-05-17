<?php
/**
 * 댓글 표시 보조: 관리자 답변 배지, 본인 댓글 폼 게이트.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 새 댓글이 등록될 때 관리자면 _is_admin_reply 메타 마킹.
 */
add_action( 'comment_post', 'inquiry_board_mark_admin_reply', 10, 3 );

function inquiry_board_mark_admin_reply( int $comment_ID, $approved, array $commentdata ): void {
	$post_id = (int) ( $commentdata['comment_post_ID'] ?? 0 );
	$post    = $post_id ? get_post( $post_id ) : null;
	if ( ! $post || $post->post_type !== 'inquiry' ) {
		return;
	}
	$user_id = (int) ( $commentdata['user_id'] ?? 0 );
	if ( $user_id > 0 && user_can( $user_id, 'moderate_comments' ) ) {
		add_comment_meta( $comment_ID, '_is_admin_reply', 1, true );
	}
}

/**
 * 댓글 작성자 표시명 옆에 관리자 답변 배지 prepend.
 */
add_filter( 'get_comment_author', 'inquiry_board_filter_author_badge', 10, 3 );

function inquiry_board_filter_author_badge( $author, $comment_ID, $comment ) {
	if ( ! $comment || empty( $comment->comment_post_ID ) ) {
		return $author;
	}
	$post = get_post( (int) $comment->comment_post_ID );
	if ( ! $post || $post->post_type !== 'inquiry' ) {
		return $author;
	}
	if ( get_comment_meta( $comment_ID, '_is_admin_reply', true ) ) {
		return $author . ' [' . esc_html__( '관리자 답변', 'wp-qna-board' ) . ']';
	}
	return $author;
}

/**
 * inquiry CPT 의 댓글 폼: 본인/관리자 외에는 폼 자체 출력 막음.
 * comments_open 필터가 이미 false 를 반환하므로 comment_form 호출이 빈 결과를 내지만
 * 추가 안전망으로 comment_form_defaults 도 hook.
 */
add_filter( 'comments_template', 'inquiry_board_comments_template' );

function inquiry_board_comments_template( string $path ): string {
	$post = get_post();
	if ( ! $post || $post->post_type !== 'inquiry' ) {
		return $path;
	}
	$own = INQUIRY_BOARD_TEMPLATES . 'comments-inquiry.php';
	if ( file_exists( $own ) ) {
		return $own;
	}
	return $path;
}
