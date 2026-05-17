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

/**
 * 단일 inquiry 페이지의 답글 폼이 POST 하는 핸들러.
 *  - admin-post.php?action=inquiry_reply
 *  - 본인 인증 세션(또는 관리자) 만 등록 가능. wp_new_comment 가
 *    preprocess_comment / pre_comment_approved 필터 체인을 거치므로
 *    권한 게이트와 자동 승인이 그대로 작동한다.
 *  - 관리자 답변이면 _is_admin_reply 메타가 comment_post 훅(inquiry_board_mark_admin_reply) 으로 자동 마킹.
 */
add_action( 'admin_post_inquiry_reply',        'inquiry_board_handle_reply' );
add_action( 'admin_post_nopriv_inquiry_reply', 'inquiry_board_handle_reply' );

function inquiry_board_handle_reply(): void {
	check_admin_referer( 'inquiry_board_reply', 'inquiry_board_reply_nonce' );

	$post_id = (int) ( $_POST['inquiry_post_id'] ?? 0 );
	$content = isset( $_POST['inquiry_reply_content'] )
		? (string) wp_unslash( $_POST['inquiry_reply_content'] )
		: '';

	if ( $post_id <= 0 ) {
		inquiry_board_form_die( __( '대상 글이 지정되지 않았습니다.', 'wp-qna-board' ) );
	}
	$post = get_post( $post_id );
	if ( ! $post || $post->post_type !== 'inquiry' ) {
		inquiry_board_form_die( __( '대상 글을 찾을 수 없습니다.', 'wp-qna-board' ) );
	}
	if ( ! inquiry_board_is_owner( $post_id ) ) {
		inquiry_board_form_die( __( '답글 작성 권한이 없습니다.', 'wp-qna-board' ) );
	}
	if ( trim( wp_strip_all_tags( $content ) ) === '' ) {
		inquiry_board_form_die( __( '답글 내용을 입력해 주세요.', 'wp-qna-board' ) );
	}

	if ( current_user_can( 'moderate_comments' ) ) {
		$u       = wp_get_current_user();
		$author  = $u->display_name ?: $u->user_login;
		$email   = (string) $u->user_email;
		$user_id = (int) $u->ID;
	} else {
		$author  = (string) get_post_meta( $post_id, '_inquiry_author_name', true );
		if ( $author === '' ) {
			$author = __( '익명', 'wp-qna-board' );
		}
		$email   = (string) get_post_meta( $post_id, '_inquiry_author_email', true );
		$user_id = 0;
	}

	$commentdata = [
		'comment_post_ID'      => $post_id,
		'comment_author'       => $author,
		'comment_author_email' => $email,
		'comment_author_url'   => '',
		'comment_author_IP'    => inquiry_board_get_client_ip(),
		'comment_content'      => $content,
		'comment_type'         => 'comment',
		'comment_parent'       => 0,
		'user_id'              => $user_id,
		'comment_agent'        => isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '',
	];

	$comment_id = wp_new_comment( $commentdata, true );
	if ( is_wp_error( $comment_id ) || ! $comment_id ) {
		$msg = is_wp_error( $comment_id ) ? $comment_id->get_error_message() : __( '답글 등록에 실패했습니다.', 'wp-qna-board' );
		inquiry_board_form_die( $msg );
	}

	wp_safe_redirect( get_permalink( $post_id ) . '#inquiry-comment-' . (int) $comment_id );
	exit;
}
