<?php
/**
 * 본인 글 수정 화면. /inquiry/<slug>/?inquiry_action=edit 으로 진입.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const INQUIRY_BOARD_EDIT_NONCE_ACTION = 'inquiry_board_edit';
const INQUIRY_BOARD_EDIT_NONCE_FIELD  = 'inquiry_board_edit_nonce';

/**
 * 본문 위에 수정/삭제 폼이 필요하면 single 템플릿에서 inquiry_board_render_edit_form( $post_id )
 * 직접 호출하거나, single-inquiry.php 가 자동으로 호출한다.
 */
function inquiry_board_render_edit_form( int $post_id ): string {
	$post = get_post( $post_id );
	if ( ! $post || $post->post_type !== 'inquiry' ) {
		return '';
	}
	if ( ! inquiry_board_is_owner( $post_id ) ) {
		return '';
	}
	ob_start();
	$template = locate_template( [ 'inquiry-edit.php' ] );
	if ( ! $template ) {
		$template = INQUIRY_BOARD_TEMPLATES . 'inquiry-edit.php';
	}
	include $template;
	return (string) ob_get_clean();
}

add_action( 'admin_post_nopriv_inquiry_update', 'inquiry_board_handle_update' );
add_action( 'admin_post_inquiry_update',        'inquiry_board_handle_update' );

function inquiry_board_handle_update(): void {
	check_admin_referer( INQUIRY_BOARD_EDIT_NONCE_ACTION, INQUIRY_BOARD_EDIT_NONCE_FIELD );

	$post_id = (int) ( $_POST['inquiry_post_id'] ?? 0 );
	if ( $post_id <= 0 || ! inquiry_board_is_owner( $post_id ) ) {
		inquiry_board_form_die( __( '수정 권한이 없습니다.', 'wp-qna-board' ) );
	}
	$post = get_post( $post_id );
	if ( ! $post || $post->post_type !== 'inquiry' ) {
		inquiry_board_form_die( __( '대상 글을 찾을 수 없습니다.', 'wp-qna-board' ) );
	}

	$title    = sanitize_text_field( wp_unslash( $_POST['inquiry_title']    ?? '' ) );
	$body     = wp_unslash( $_POST['inquiry_content'] ?? '' );
	$cat_slug = sanitize_key( wp_unslash( $_POST['inquiry_category'] ?? '' ) );
	$new_pwd  = (string) ( $_POST['inquiry_new_password'] ?? '' );

	if ( $title === '' || trim( wp_strip_all_tags( $body ) ) === '' ) {
		inquiry_board_form_die( __( '제목·내용을 입력해 주세요.', 'wp-qna-board' ) );
	}

	$update = [
		'ID'           => $post_id,
		'post_title'   => $title,
		'post_content' => inquiry_board_sanitize_body( $body ),
	];
	if ( $new_pwd !== '' ) {
		$update['post_password'] = $new_pwd;
	}

	$res = wp_update_post( $update, true );
	if ( is_wp_error( $res ) ) {
		inquiry_board_form_die( $res->get_error_message() );
	}

	if ( $cat_slug ) {
		$cat_term = get_term_by( 'slug', $cat_slug, 'inquiry_category' );
		if ( $cat_term ) {
			wp_set_object_terms( $post_id, [ (int) $cat_term->term_id ], 'inquiry_category', false );
		}
	}

	$history = (array) get_post_meta( $post_id, '_inquiry_edit_history', true );
	$history[] = [
		'at' => current_time( 'mysql' ),
		'ip' => inquiry_board_get_client_ip(),
		'ua' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? hash( 'sha256', (string) $_SERVER['HTTP_USER_AGENT'] ) : '',
	];
	update_post_meta( $post_id, '_inquiry_edit_history', $history );

	// 비번 변경 시 기존 세션 폐기 + 신규 세션 재발급(현재 사용자에게 24h 부여).
	if ( $new_pwd !== '' ) {
		inquiry_board_revoke_session( $post_id );
		inquiry_board_issue_session( $post_id );
	}

	wp_safe_redirect( get_permalink( $post_id ) );
	exit;
}

/**
 * 비번 입력 → 본인 인증 처리. 단일 페이지의 비번 입력 폼이 이 액션으로 POST.
 */
add_action( 'admin_post_nopriv_inquiry_unlock', 'inquiry_board_handle_unlock' );
add_action( 'admin_post_inquiry_unlock',        'inquiry_board_handle_unlock' );

function inquiry_board_handle_unlock(): void {
	check_admin_referer( 'inquiry_board_unlock', 'inquiry_board_unlock_nonce' );
	$post_id = (int) ( $_POST['inquiry_post_id'] ?? 0 );
	$pwd     = (string) ( $_POST['inquiry_password'] ?? '' );
	if ( $post_id <= 0 ) {
		inquiry_board_form_die( __( '대상 글이 지정되지 않았습니다.', 'wp-qna-board' ) );
	}

	$post = get_post( $post_id );
	if ( ! $post || $post->post_type !== 'inquiry' ) {
		inquiry_board_form_die( __( '대상 글을 찾을 수 없습니다.', 'wp-qna-board' ) );
	}

	// 비번이 설정된 글: HMAC 비교 + WP 표준 wp-postpass 쿠키도 함께 발급해 본문 노출.
	if ( $post->post_password !== '' ) {
		if ( ! inquiry_board_try_password( $post_id, $pwd ) ) {
			inquiry_board_form_die( __( '비밀번호가 일치하지 않습니다.', 'wp-qna-board' ) );
		}
		// WP 표준 비번 쿠키도 같이 발급(본문 노출 위해).
		require_once ABSPATH . WPINC . '/class-phpass.php';
		$hasher = new PasswordHash( 8, true );
		$expire = time() + INQUIRY_BOARD_SESSION_TTL;
		setcookie( 'wp-postpass_' . COOKIEHASH, $hasher->HashPassword( wp_unslash( $pwd ) ), $expire, COOKIEPATH );
	} else {
		// 비번 없는 글에는 본인 인증 불가 (작성 시점 쿠키만 유효).
		inquiry_board_form_die( __( '비밀번호가 설정되지 않은 글입니다.', 'wp-qna-board' ) );
	}

	wp_safe_redirect( get_permalink( $post_id ) );
	exit;
}

/**
 * 본인 삭제(완전 삭제 아닌 휴지통). 추가 보안: nonce + post_id + 본인 또는 관리자.
 */
add_action( 'admin_post_nopriv_inquiry_delete', 'inquiry_board_handle_delete' );
add_action( 'admin_post_inquiry_delete',        'inquiry_board_handle_delete' );

function inquiry_board_handle_delete(): void {
	check_admin_referer( 'inquiry_board_delete', 'inquiry_board_delete_nonce' );
	$post_id = (int) ( $_POST['inquiry_post_id'] ?? 0 );
	if ( $post_id <= 0 || ! inquiry_board_is_owner( $post_id ) ) {
		inquiry_board_form_die( __( '삭제 권한이 없습니다.', 'wp-qna-board' ) );
	}
	wp_trash_post( $post_id );
	wp_safe_redirect( get_post_type_archive_link( 'inquiry' ) ?: home_url( '/' ) );
	exit;
}
