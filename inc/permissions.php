<?php
/**
 * 댓글 권한 게이트. CPT inquiry 에 한해 적용.
 *  - 관리자(moderate_comments) → 허용
 *  - 작성자 본인 세션 → 허용
 *  - 그 외 → 거부
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'preprocess_comment', 'inquiry_board_gate_comment', 10, 1 );

function inquiry_board_gate_comment( array $commentdata ): array {
	$post_id = (int) ( $commentdata['comment_post_ID'] ?? 0 );
	$post    = $post_id ? get_post( $post_id ) : null;
	if ( ! $post || $post->post_type !== 'inquiry' ) {
		return $commentdata;
	}
	if ( current_user_can( 'moderate_comments' ) ) {
		return $commentdata;
	}
	if ( inquiry_board_is_owner( $post_id ) ) {
		// 본인 댓글: 작성자명을 postmeta 의 _inquiry_author_name 로 강제 통일.
		$name = (string) get_post_meta( $post_id, '_inquiry_author_name', true );
		if ( $name ) {
			$commentdata['comment_author'] = $name;
		}
		$email = (string) get_post_meta( $post_id, '_inquiry_author_email', true );
		if ( $email ) {
			$commentdata['comment_author_email'] = $email;
		}
		return $commentdata;
	}
	wp_die(
		esc_html__( '댓글 작성 권한이 없습니다. 작성자 본인 또는 관리자만 댓글을 달 수 있습니다.', 'wp-qna-board' ),
		esc_html__( '권한 없음', 'wp-qna-board' ),
		[ 'response' => 403, 'back_link' => true ]
	);
}

add_filter( 'pre_comment_approved', 'inquiry_board_auto_approve', 10, 2 );

function inquiry_board_auto_approve( $approved, array $commentdata ) {
	$post_id = (int) ( $commentdata['comment_post_ID'] ?? 0 );
	$post    = $post_id ? get_post( $post_id ) : null;
	if ( ! $post || $post->post_type !== 'inquiry' ) {
		return $approved;
	}
	if ( current_user_can( 'moderate_comments' ) || inquiry_board_is_owner( $post_id ) ) {
		return 1;
	}
	return $approved;
}

/**
 * inquiry CPT 의 비로그인·비본인에게는 댓글 폼 자체를 비표시.
 */
add_filter( 'comments_open', 'inquiry_board_filter_comments_open', 10, 2 );

function inquiry_board_filter_comments_open( $open, $post_id ) {
	$post = $post_id ? get_post( $post_id ) : null;
	if ( ! $post || $post->post_type !== 'inquiry' ) {
		return $open;
	}
	if ( current_user_can( 'moderate_comments' ) ) {
		return $open;
	}
	if ( inquiry_board_is_owner( (int) $post_id ) ) {
		return $open;
	}
	return false;
}

/**
 * inquiry CPT 에서 단일 페이지 본문 위/아래 표시되는 비번 입력 폼 커스터마이즈.
 * 본 플러그인 템플릿의 password-form.php 를 사용해 작성자에게 "비밀번호 입력 후 본인 인증"
 * 안내까지 함께 제공.
 */
add_filter( 'the_password_form', 'inquiry_board_password_form', 10, 2 );

function inquiry_board_password_form( string $output, $post = null ) {
	$post_obj = $post ? get_post( $post ) : get_post();
	if ( ! $post_obj || $post_obj->post_type !== 'inquiry' ) {
		return $output;
	}
	ob_start();
	$template = locate_template( [ 'password-form.php' ] );
	if ( ! $template ) {
		$template = INQUIRY_BOARD_TEMPLATES . 'password-form.php';
	}
	$inquiry_post_id = (int) $post_obj->ID;
	include $template;
	$html = (string) ob_get_clean();
	// `the_content` 필터 체인의 wpautop 가 폼 안의 줄바꿈을 <br /> · <p> 로 변형해
	// 마크업이 깨지므로 newline·CR 을 모두 제거한다.
	return str_replace( [ "\r\n", "\n", "\r" ], '', $html );
}
