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

/**
 * body class — [inquiry_form] 숏코드가 박힌 페이지에 `inquiry-shortcode-page`
 * 를 추가한다. CSS 가 단일 inquiry CPT 페이지(`body.single-inquiry`)와 동일하게
 * 테마(Uncode 등)의 hero·share·breadcrumb 영역을 숨길 수 있게 한다.
 */
add_filter( 'body_class', static function ( array $classes ): array {
	if ( is_singular() ) {
		$post = get_post();
		if ( $post && has_shortcode( (string) $post->post_content, 'inquiry_form' ) ) {
			$classes[] = 'inquiry-shortcode-page';
		}
	}
	return $classes;
} );

/**
 * [inquiry_form] 숏코드.
 *
 * 기본은 게시글 목록 + "글쓰기" 버튼을 표시하고, 글쓰기 버튼을 누르면
 * 같은 페이지에 ?ipv=write 파라미터가 붙어 작성 폼이 나타난다.
 *
 * 지원 속성:
 *  - posts_per_page  목록 페이지당 노출 글 수 (기본 20)
 *  - view            'auto' | 'list' | 'write' (기본 auto: URL 파라미터로 결정)
 *  - show_write_button  목록 화면 상단에 글쓰기 버튼 노출 여부 (기본 '1')
 *  - category        특정 카테고리 슬러그로 한정 (기본 빈 값 = 전체)
 *  - title           작성 폼 헤더용 텍스트 (기본 '문의하기')
 *  - redirect        작성 후 redirect URL (기존 호환)
 */
function inquiry_board_shortcode_form( $atts = [] ): string {
	$default_ppp = function_exists( 'inquiry_board_get_posts_per_page' )
		? inquiry_board_get_posts_per_page()
		: 20;

	$atts = shortcode_atts( [
		'redirect'          => '',
		'title'             => __( '문의하기', 'wp-qna-board' ),
		'posts_per_page'    => $default_ppp,
		'view'              => 'auto',
		'show_write_button' => '1',
		'category'          => '',
	], $atts, 'inquiry_form' );

	$atts['posts_per_page']    = max( 1, (int) $atts['posts_per_page'] );
	$atts['show_write_button'] = ! in_array( strtolower( (string) $atts['show_write_button'] ), [ '0', 'false', 'no', '' ], true );

	$view = strtolower( (string) $atts['view'] );
	if ( $view === 'auto' || ! in_array( $view, [ 'list', 'write' ], true ) ) {
		$view = ( isset( $_GET['ipv'] ) && $_GET['ipv'] === 'write' ) ? 'write' : 'list';
	}

	ob_start();
	if ( $view === 'write' ) {
		$template = locate_template( [ 'inquiry-form.php' ] );
		if ( ! $template ) {
			$template = INQUIRY_BOARD_TEMPLATES . 'inquiry-form.php';
		}
	} else {
		$template = locate_template( [ 'inquiry-list.php' ] );
		if ( ! $template ) {
			$template = INQUIRY_BOARD_TEMPLATES . 'inquiry-list.php';
		}
	}
	include $template;
	return (string) ob_get_clean();
}

/**
 * 쇼트코드가 박힌 현재 페이지의 베이스 URL.
 * paged/ipv 같은 보조 쿼리는 제거한다. page 단일 URL 기준.
 */
function inquiry_board_current_page_url(): string {
	$pid = get_queried_object_id();
	if ( $pid ) {
		$url = get_permalink( $pid );
		if ( $url ) {
			return $url;
		}
	}
	// fallback: 현재 요청 URL 에서 쿼리스트링만 정리.
	$req = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/';
	$url = home_url( strtok( $req, '?' ) );
	return $url;
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

	// 사람이 폼을 채우는 데는 최소 수 초가 걸린다. 서명된 렌더 시각과의 간격이
	// 너무 짧으면(즉시 POST) 스크립트 제출로 보고 거부한다. JS 없는 봇을 걸러낸다.
	if ( ! inquiry_board_form_timestamp_ok( (string) ( $_POST['inquiry_ts'] ?? '' ) ) ) {
		inquiry_board_form_die( __( '잘못된 요청입니다. 페이지를 새로고침한 뒤 다시 시도해 주세요.', 'wp-qna-board' ) );
	}

	$opts = get_option( 'inquiry_board_settings', [] );
	// 봇 방어 우선순위: Cloudflare Turnstile → reCAPTCHA. 사이트에 설정된 것을 쓴다.
	$turnstile = inquiry_board_verify_turnstile();
	if ( false === $turnstile ) {
		inquiry_board_form_die( __( '자동 등록 방지 확인에 실패했습니다. 페이지를 새로고침한 뒤 다시 시도해 주세요.', 'wp-qna-board' ) );
	}
	if ( null === $turnstile && ! inquiry_board_verify_recaptcha( $_POST['g-recaptcha-response'] ?? '', $opts ) ) {
		inquiry_board_form_die( __( 'reCAPTCHA 검증에 실패했습니다.', 'wp-qna-board' ) );
	}

	$title    = sanitize_text_field( wp_unslash( $_POST['inquiry_title']    ?? '' ) );
	$body     = wp_unslash( $_POST['inquiry_content'] ?? '' );
	$author   = sanitize_text_field( wp_unslash( $_POST['inquiry_author']   ?? '' ) );
	$email    = sanitize_email(   wp_unslash( $_POST['inquiry_email']    ?? '' ) );
	$phone    = inquiry_board_normalize_phone( sanitize_text_field( wp_unslash( $_POST['inquiry_phone'] ?? '' ) ) );
	$password = (string) ( $_POST['inquiry_password'] ?? '' );
	$cat_slug = sanitize_key(     wp_unslash( $_POST['inquiry_category'] ?? '' ) );
	$privacy_ok   = ! empty( $_POST['inquiry_privacy_consent'] );
	$marketing_ok = ! empty( $_POST['inquiry_marketing_consent'] );

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
	if ( $phone === '' ) {
		$errors[] = __( '연락처를 숫자 9~15자리로 입력해 주세요. (예: 010-1234-5678)', 'wp-qna-board' );
	}
	if ( ! $privacy_ok ) {
		$errors[] = __( '개인정보 수집·이용에 동의해 주셔야 문의를 접수할 수 있습니다.', 'wp-qna-board' );
	}
	$is_private = inquiry_board_is_private_submission( $opts, $_POST['inquiry_visibility'] ?? '' );

	if ( $is_private ) {
		$min_pwd = (int) ( $opts['password_min_length'] ?? 4 );
		if ( mb_strlen( $password ) < $min_pwd ) {
			$errors[] = sprintf( __( '비공개 글은 비밀번호가 필요합니다. (최소 %d자)', 'wp-qna-board' ), $min_pwd );
		}
	} else {
		// 공개글은 비밀번호를 저장하지 않는다 — 폼에 남아 있던 값이 있어도 버린다.
		$password = '';
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
		'post_author'   => inquiry_board_anon_author_id(),
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
	update_post_meta( $post_id, '_inquiry_author_phone',   $phone );
	// 동의 증빙 — 값 '1'/'0' 은 wowbiz-sync 가 그대로 동의/비동의로 읽는다(허용값에 '1' 포함).
	// 동의 시각은 글의 post_date 가 곧 제출 시각이므로 따로 두지 않는다.
	update_post_meta( $post_id, '_inquiry_privacy_consent',   '1' );
	update_post_meta( $post_id, '_inquiry_marketing_consent', $marketing_ok ? '1' : '0' );
	update_post_meta( $post_id, '_inquiry_author_ip',      $ip );
	update_post_meta( $post_id, '_inquiry_author_ua_hash', isset( $_SERVER['HTTP_USER_AGENT'] ) ? hash( 'sha256', (string) $_SERVER['HTTP_USER_AGENT'] ) : '' );

	inquiry_board_handle_uploads( $post_id, $opts );
	inquiry_board_issue_session( $post_id );
	// 알림 메일은 transition_post_status 훅이 담당한다 (아래 inquiry_board_notify_on_publish).

	$redirect = ! empty( $_POST['inquiry_redirect'] )
		? esc_url_raw( wp_unslash( $_POST['inquiry_redirect'] ) )
		: get_permalink( $post_id );
	wp_safe_redirect( $redirect );
	exit;
}

/**
 * 연락처 정규화. 유효하지 않으면 빈 문자열을 돌려준다(호출부에서 검증 실패 처리).
 *
 * 국내 휴대폰(01x)만 `010-1234-5678` 형태로 통일하고, 지역번호·국제표기(+44 등)는
 * 입력값을 그대로 보존한다 — 유학 문의 특성상 해외 번호가 들어온다.
 * app.wowbiz.net 연동은 이 메타(`_inquiry_author_phone`)를 읽어간다.
 */
function inquiry_board_normalize_phone( string $raw ): string {
	$digits = preg_replace( '/\D+/', '', $raw );
	if ( strlen( $digits ) < 9 || strlen( $digits ) > 15 ) {
		return '';
	}
	if ( preg_match( '/^(01\d)(\d{3,4})(\d{4})$/', $digits, $m ) ) {
		return $m[1] . '-' . $m[2] . '-' . $m[3];
	}
	return trim( $raw );
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

/**
 * Cloudflare Turnstile site key. simple-cloudflare-turnstile 플러그인의 옵션을 재사용한다.
 * 미설치·미설정이면 빈 문자열(→ 위젯 미노출, 서버 검증도 skip).
 */
function inquiry_board_turnstile_sitekey(): string {
	return trim( (string) get_option( 'cfturnstile_key', '' ) );
}

/**
 * Turnstile 토큰 서버 검증.
 *  - null  : 이 사이트는 Turnstile 미설정(secret 없음) → 검증 대상 아님(reCAPTCHA 로 폴백)
 *  - false : 설정돼 있으나 토큰 없음·검증 실패 → 제출 거부
 *  - true  : 통과
 */
function inquiry_board_verify_turnstile(): ?bool {
	$secret = trim( (string) get_option( 'cfturnstile_secret', '' ) );
	if ( $secret === '' ) {
		return null;
	}
	$token = isset( $_POST['cf-turnstile-response'] ) ? sanitize_text_field( wp_unslash( $_POST['cf-turnstile-response'] ) ) : '';
	if ( $token === '' ) {
		return false;
	}
	$res = wp_remote_post( 'https://challenges.cloudflare.com/turnstile/v0/siteverify', [
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
	return ! empty( $body['success'] );
}

/**
 * 폼 렌더 시각을 HMAC 서명해 hidden 필드로 내보낸다(위조 방지). time-trap 의 짝.
 */
function inquiry_board_form_timestamp_field(): string {
	$ts  = (string) time();
	$sig = hash_hmac( 'sha256', $ts, inquiry_board_get_secret() );
	return '<input type="hidden" name="inquiry_ts" value="' . esc_attr( $ts . '.' . $sig ) . '">';
}

/**
 * 렌더~제출 간격 검사. 서명 위조 불가 + 3초 미만(즉시 제출)·1시간 초과(오래된 폼) 거부.
 */
function inquiry_board_form_timestamp_ok( string $raw ): bool {
	$parts = explode( '.', $raw, 2 );
	if ( count( $parts ) !== 2 ) {
		return false;
	}
	list( $ts, $sig ) = $parts;
	if ( ! ctype_digit( $ts ) || ! hash_equals( hash_hmac( 'sha256', $ts, inquiry_board_get_secret() ), $sig ) ) {
		return false;
	}
	$elapsed = time() - (int) $ts;
	return $elapsed >= 3 && $elapsed <= HOUR_IN_SECONDS;
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

/**
 * 이번 제출을 비공개(비밀번호 보호)로 저장할지 판정.
 *
 * `password_required` 옵션이 켜져 있으면 폼 값과 무관하게 항상 비공개다(기존 사이트 호환).
 * 꺼져 있으면 공개가 기본이고, 폼에서 명시적으로 private 을 골랐을 때만 비공개.
 */
function inquiry_board_is_private_submission( array $opts, $raw_visibility ): bool {
	if ( ! empty( $opts['password_required'] ) ) {
		return true;
	}
	return 'private' === sanitize_key( (string) wp_unslash( $raw_visibility ) );
}

/**
 * 글에 딸린 첨부 ID 목록. 폼 업로드는 _inquiry_attachments 메타에 기록되지만,
 * 그 메타가 없던 시기의 글을 위해 attachment 의 post_parent 로 폴백한다.
 *
 * 알림 메일(inc/notify.php)과 관리 화면 답글 UI(inc/admin-reply.php) 양쪽에서 쓰므로
 * admin 여부와 무관하게 항상 로드되는 이 파일에 둔다.
 */
function inquiry_board_get_attachment_ids( int $post_id ): array {
	$ids = get_post_meta( $post_id, '_inquiry_attachments', true );
	$ids = is_array( $ids ) ? array_values( array_filter( array_map( 'intval', $ids ) ) ) : [];
	if ( $ids ) {
		return $ids;
	}
	return array_map( 'intval', (array) get_posts( [
		'post_type'      => 'attachment',
		'post_parent'    => $post_id,
		'post_status'    => 'inherit',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'orderby'        => 'ID',
		'order'          => 'ASC',
	] ) );
}

function inquiry_board_form_die( string $message ): void {
	wp_die(
		esc_html( $message ),
		__( '문의 작성 실패', 'wp-qna-board' ),
		[ 'back_link' => true, 'response' => 400 ]
	);
}

/**
 * 익명 inquiry 글의 post_author 로 박을 site administrator user id.
 *
 * 일부 테마(single.php 안의 author 처리)는 post_author 가 0 이면 get_userdata(0)
 * 가 false 를 반환해 PHP 8 호환성 fatal 을 일으킨다. 그래서 익명이라도
 * wp_posts.post_author 자체는 유효 administrator id 로 두고, 화면에 표시되는
 * 작성자명은 _inquiry_author_name 메타로 the_author 필터에서 치환한다.
 *
 * 결정 우선순위:
 *  1) get_option('inquiry_board_anon_author') 가 유효 사용자면 사용
 *  2) admin_email 에 해당하는 administrator
 *  3) ID 오름차순 첫 administrator
 */
function inquiry_board_anon_author_id(): int {
	static $cached = null;
	if ( $cached !== null ) {
		return (int) $cached;
	}
	$opt = (int) get_option( 'inquiry_board_anon_author', 0 );
	if ( $opt > 0 && get_userdata( $opt ) ) {
		return $cached = $opt;
	}
	$email = (string) get_option( 'admin_email' );
	if ( $email ) {
		$u = get_user_by( 'email', $email );
		if ( $u && user_can( $u, 'manage_options' ) ) {
			return $cached = (int) $u->ID;
		}
	}
	$users = get_users( [
		'role'    => 'administrator',
		'number'  => 1,
		'orderby' => 'ID',
		'order'   => 'ASC',
		'fields'  => 'ID',
	] );
	return $cached = ! empty( $users ) ? (int) $users[0] : 0;
}
