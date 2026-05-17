<?php
/**
 * [inquiry_form] 숏코드 + admin-post.php?action=inquiry_submit 핸들러.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const INQUIRY_BOARD_NONCE_ACTION = 'inquiry_board_submit';
const INQUIRY_BOARD_NONCE_FIELD  = 'inquiry_board_nonce';

add_shortcode( 'inquiry_form', 'inquiry_board_shortcode_form' );

function inquiry_board_shortcode_form( $atts = [] ): string {
	$atts = shortcode_atts( [
		'redirect' => '',
		'title'    => __( '문의하기', 'wp-qna-board' ),
	], $atts, 'inquiry_form' );

	ob_start();
	$template = locate_template( [ 'inquiry-form.php' ] );
	if ( ! $template ) {
		$template = INQUIRY_BOARD_TEMPLATES . 'inquiry-form.php';
	}
	include $template;
	return (string) ob_get_clean();
}

add_action( 'admin_post_nopriv_inquiry_submit', 'inquiry_board_handle_submit' );
add_action( 'admin_post_inquiry_submit',        'inquiry_board_handle_submit' );

function inquiry_board_handle_submit(): void {
	check_admin_referer( INQUIRY_BOARD_NONCE_ACTION, INQUIRY_BOARD_NONCE_FIELD );

	if ( ! empty( $_POST['inquiry_hp'] ) ) {
		inquiry_board_form_die( __( '잘못된 요청입니다.', 'wp-qna-board' ) );
	}

	$ip       = inquiry_board_get_client_ip();
	$throttle = 'inq_form_' . md5( $ip );
	if ( get_transient( $throttle ) ) {
		inquiry_board_form_die( __( '잠시 후 다시 시도해 주세요.', 'wp-qna-board' ) );
	}
	set_transient( $throttle, 1, 60 );

	$opts = get_option( 'inquiry_board_settings', [] );
	if ( ! inquiry_board_verify_recaptcha( $_POST['g-recaptcha-response'] ?? '', $opts ) ) {
		inquiry_board_form_die( __( 'reCAPTCHA 검증에 실패했습니다.', 'wp-qna-board' ) );
	}

	$title    = sanitize_text_field( wp_unslash( $_POST['inquiry_title']    ?? '' ) );
	$body     = wp_unslash( $_POST['inquiry_content'] ?? '' );
	$author   = sanitize_text_field( wp_unslash( $_POST['inquiry_author']   ?? '' ) );
	$email    = sanitize_email(   wp_unslash( $_POST['inquiry_email']    ?? '' ) );
	$password = (string) ( $_POST['inquiry_password'] ?? '' );
	$cat_slug = sanitize_key(     wp_unslash( $_POST['inquiry_category'] ?? '' ) );

	$errors = [];
	if ( $title === '' || mb_strlen( $title ) > 200 ) {
		$errors[] = __( '제목을 입력해 주세요. (최대 200자)', 'wp-qna-board' );
	}
	if ( trim( wp_strip_all_tags( $body ) ) === '' ) {
		$errors[] = __( '내용을 입력해 주세요.', 'wp-qna-board' );
	}
	if ( $author === '' ) {
		$errors[] = __( '작성자명을 입력해 주세요.', 'wp-qna-board' );
	}
	if ( ! is_email( $email ) ) {
		$errors[] = __( '올바른 이메일을 입력해 주세요.', 'wp-qna-board' );
	}
	$min_pwd = (int) ( $opts['password_min_length'] ?? 4 );
	if ( mb_strlen( $password ) < $min_pwd ) {
		$errors[] = sprintf( __( '비밀번호는 최소 %d자입니다.', 'wp-qna-board' ), $min_pwd );
	}
	$cat_term = $cat_slug ? get_term_by( 'slug', $cat_slug, 'inquiry_category' ) : null;
	if ( ! $cat_term ) {
		$errors[] = __( '카테고리를 선택해 주세요.', 'wp-qna-board' );
	}
	if ( $errors ) {
		inquiry_board_form_die( implode( '<br>', $errors ) );
	}

	// 본문 텍스트 전용 정제: <p>/<br>/<a> 만 허용.
	$body_clean = inquiry_board_sanitize_body( $body );

	$post_id = wp_insert_post( [
		'post_type'     => 'inquiry',
		'post_status'   => 'publish',
		'post_author'   => 0,
		'post_title'    => $title,
		'post_content'  => $body_clean,
		'post_password' => $password,
		'comment_status'=> 'open',
		'ping_status'   => 'closed',
	], true );

	if ( is_wp_error( $post_id ) ) {
		inquiry_board_form_die( $post_id->get_error_message() );
	}

	wp_set_object_terms( $post_id, [ (int) $cat_term->term_id ], 'inquiry_category', false );

	update_post_meta( $post_id, '_inquiry_author_name',    $author );
	update_post_meta( $post_id, '_inquiry_author_email',   $email );
	update_post_meta( $post_id, '_inquiry_author_ip',      $ip );
	update_post_meta( $post_id, '_inquiry_author_ua_hash', isset( $_SERVER['HTTP_USER_AGENT'] ) ? hash( 'sha256', (string) $_SERVER['HTTP_USER_AGENT'] ) : '' );

	inquiry_board_handle_uploads( $post_id, $opts );
	inquiry_board_issue_session( $post_id );
	inquiry_board_notify_admin( $post_id, $opts );

	$redirect = ! empty( $_POST['inquiry_redirect'] )
		? esc_url_raw( wp_unslash( $_POST['inquiry_redirect'] ) )
		: get_permalink( $post_id );
	wp_safe_redirect( $redirect );
	exit;
}

function inquiry_board_sanitize_body( string $raw ): string {
	$allowed = [
		'p'  => [],
		'br' => [],
		'a'  => [
			'href'   => true,
			'title'  => true,
			'rel'    => true,
			'target' => true,
		],
	];
	$clean = wp_kses( $raw, $allowed );
	if ( strpos( $clean, '<p>' ) === false ) {
		$clean = wpautop( $clean );
	}
	return $clean;
}

function inquiry_board_handle_uploads( int $post_id, array $opts ): void {
	if ( empty( $_FILES['inquiry_attachments'] ) ) {
		return;
	}
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';

	$allowed_ext = array_filter( array_map( 'trim', explode( ',', (string) ( $opts['allowed_ext'] ?? 'jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,hwp,zip' ) ) ) );
	$max_bytes   = max( 1, (int) ( $opts['max_upload_mb'] ?? 10 ) ) * 1024 * 1024;

	$files     = $_FILES['inquiry_attachments'];
	$count     = is_array( $files['name'] ) ? count( $files['name'] ) : 0;
	$saved_ids = [];

	for ( $i = 0; $i < $count; $i++ ) {
		if ( empty( $files['name'][ $i ] ) || ( $files['error'][ $i ] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK ) {
			continue;
		}
		if ( (int) $files['size'][ $i ] > $max_bytes ) {
			continue;
		}
		$ext = strtolower( pathinfo( $files['name'][ $i ], PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, $allowed_ext, true ) ) {
			continue;
		}
		$single = [
			'name'     => $files['name'][ $i ],
			'type'     => $files['type'][ $i ],
			'tmp_name' => $files['tmp_name'][ $i ],
			'error'    => $files['error'][ $i ],
			'size'     => $files['size'][ $i ],
		];
		$_FILES['_inquiry_single'] = $single;
		$att_id = media_handle_upload( '_inquiry_single', $post_id );
		unset( $_FILES['_inquiry_single'] );
		if ( ! is_wp_error( $att_id ) ) {
			$saved_ids[] = (int) $att_id;
		}
	}

	if ( $saved_ids ) {
		update_post_meta( $post_id, '_inquiry_attachments', $saved_ids );
	}
}

function inquiry_board_verify_recaptcha( string $token, array $opts ): bool {
	$secret = (string) ( $opts['recaptcha_secret'] ?? '' );
	if ( ! $secret ) {
		return true; // 설정 미입력 시 검증 우회 (개발 환경 가정). 운영에서는 settings 에서 강제.
	}
	if ( ! $token ) {
		return false;
	}
	$res = wp_remote_post( 'https://www.google.com/recaptcha/api/siteverify', [
		'timeout' => 5,
		'body'    => [
			'secret'   => $secret,
			'response' => $token,
			'remoteip' => inquiry_board_get_client_ip(),
		],
	] );
	if ( is_wp_error( $res ) ) {
		return false;
	}
	$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
	if ( empty( $body['success'] ) ) {
		return false;
	}
	$score = (float) ( $body['score'] ?? 0 );
	$min   = (float) ( $opts['recaptcha_min_score'] ?? 0.3 );
	return $score >= $min;
}

function inquiry_board_notify_admin( int $post_id, array $opts ): void {
	$to = (string) ( $opts['notify_email'] ?? '' );
	if ( ! $to ) {
		$to = (string) get_option( 'admin_email' );
	}
	if ( ! is_email( $to ) ) {
		return;
	}
	$title  = get_the_title( $post_id );
	$link   = get_edit_post_link( $post_id, '' );
	$author = (string) get_post_meta( $post_id, '_inquiry_author_name', true );
	$subj   = sprintf( '[%s] 새 문의: %s', wp_specialchars_decode( (string) get_bloginfo( 'name' ) ), $title );
	$body   = sprintf( "작성자: %s\n관리 화면: %s", $author, $link );
	wp_mail( $to, $subj, $body );
}

function inquiry_board_form_die( string $message ): void {
	wp_die(
		esc_html( $message ),
		__( '문의 작성 실패', 'wp-qna-board' ),
		[ 'back_link' => true, 'response' => 400 ]
	);
}
