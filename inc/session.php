<?php
/**
 * 비회원 본인 세션 모듈.
 *
 * 동작 원리:
 *  - 글 작성/비번 인증 성공 시점에 랜덤 토큰 발급.
 *  - 평문 토큰은 HttpOnly·Secure·SameSite=Strict 쿠키로만 클라이언트에 보관.
 *  - 서버에는 HMAC-SHA256 해시만 저장(postmeta) + 발급 시점 IP + 만료 시각.
 *  - 검증: 쿠키 해시 일치 AND IP 정확 일치 AND 만료 전. 셋 중 하나라도 불일치면 폴백.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const INQUIRY_BOARD_SESSION_TTL          = 86400; // 24h
const INQUIRY_BOARD_META_TOKEN_HASH      = '_inquiry_session_token_hash';
const INQUIRY_BOARD_META_SESSION_IP      = '_inquiry_session_ip';
const INQUIRY_BOARD_META_SESSION_EXPIRES = '_inquiry_session_expires';
const INQUIRY_BOARD_META_SESSION_UA      = '_inquiry_session_ua_hash';
const INQUIRY_BOARD_COOKIE_PREFIX        = 'inquiry_sess_';

/**
 * 현재 요청의 클라이언트 IP. 프록시(CF/Cloudways) 경유 시 X-Forwarded-For 우선.
 */
function inquiry_board_get_client_ip(): string {
	$candidates = [];
	if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
		$candidates[] = $_SERVER['HTTP_CF_CONNECTING_IP'];
	}
	if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
		$parts        = array_map( 'trim', explode( ',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
		$candidates[] = $parts[0] ?? '';
	}
	$candidates[] = $_SERVER['REMOTE_ADDR'] ?? '';
	foreach ( $candidates as $ip ) {
		$ip = is_string( $ip ) ? trim( $ip ) : '';
		if ( $ip && filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return $ip;
		}
	}
	return '';
}

function inquiry_board_hash_token( string $token ): string {
	return hash_hmac( 'sha256', $token, inquiry_board_get_secret() );
}

function inquiry_board_session_cookie_name( int $post_id ): string {
	return INQUIRY_BOARD_COOKIE_PREFIX . $post_id;
}

/**
 * 새 본인 세션 발급. 쿠키 set + 메타 저장.
 */
function inquiry_board_issue_session( int $post_id ): void {
	if ( $post_id <= 0 ) {
		return;
	}
	$token   = bin2hex( random_bytes( 32 ) );
	$hash    = inquiry_board_hash_token( $token );
	$ip      = inquiry_board_get_client_ip();
	$ua_hash = isset( $_SERVER['HTTP_USER_AGENT'] )
		? hash( 'sha256', (string) $_SERVER['HTTP_USER_AGENT'] )
		: '';
	$expires = time() + INQUIRY_BOARD_SESSION_TTL;

	update_post_meta( $post_id, INQUIRY_BOARD_META_TOKEN_HASH,      $hash );
	update_post_meta( $post_id, INQUIRY_BOARD_META_SESSION_IP,      $ip );
	update_post_meta( $post_id, INQUIRY_BOARD_META_SESSION_EXPIRES, $expires );
	update_post_meta( $post_id, INQUIRY_BOARD_META_SESSION_UA,      $ua_hash );

	if ( headers_sent() ) {
		return;
	}
	setcookie(
		inquiry_board_session_cookie_name( $post_id ),
		$token,
		[
			'expires'  => $expires,
			'path'     => COOKIEPATH ?: '/',
			'domain'   => COOKIE_DOMAIN ?: '',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Strict',
		]
	);
	// 같은 요청 안의 이후 검증을 위해 슈퍼글로벌도 채워준다.
	$_COOKIE[ inquiry_board_session_cookie_name( $post_id ) ] = $token;
}

/**
 * 현재 요청이 해당 글의 작성자 본인으로 인정되는지 검사.
 * - 관리자 권한이면 true.
 * - 쿠키 토큰 + IP + 만료 전 모두 통과 시 true.
 * - 둘 다 실패면 false.
 */
function inquiry_board_is_owner( int $post_id ): bool {
	if ( $post_id <= 0 ) {
		return false;
	}
	if ( current_user_can( 'moderate_comments' ) ) {
		return true;
	}
	$cookie_name = inquiry_board_session_cookie_name( $post_id );
	if ( empty( $_COOKIE[ $cookie_name ] ) ) {
		return false;
	}
	$token = (string) $_COOKIE[ $cookie_name ];
	$hash  = (string) get_post_meta( $post_id, INQUIRY_BOARD_META_TOKEN_HASH, true );
	if ( ! $hash || ! hash_equals( $hash, inquiry_board_hash_token( $token ) ) ) {
		return false;
	}
	$expires = (int) get_post_meta( $post_id, INQUIRY_BOARD_META_SESSION_EXPIRES, true );
	if ( $expires < time() ) {
		return false;
	}
	$ip = (string) get_post_meta( $post_id, INQUIRY_BOARD_META_SESSION_IP, true );
	if ( ! $ip || $ip !== inquiry_board_get_client_ip() ) {
		return false;
	}
	return true;
}

/**
 * 비번 입력값이 글의 post_password 와 일치하는지 검사. 일치 시 새 세션 발급.
 * Brute-force 방지를 위해 IP+post_id 기준 분당 5회 제한.
 */
function inquiry_board_try_password( int $post_id, string $password ): bool {
	if ( $post_id <= 0 || $password === '' ) {
		return false;
	}
	$post = get_post( $post_id );
	if ( ! $post || $post->post_type !== 'inquiry' ) {
		return false;
	}

	$ip          = inquiry_board_get_client_ip();
	$rate_key    = 'inq_pwd_' . md5( $ip . '|' . $post_id );
	$attempts    = (int) get_transient( $rate_key );
	if ( $attempts >= 5 ) {
		return false;
	}
	set_transient( $rate_key, $attempts + 1, MINUTE_IN_SECONDS );

	$stored = (string) $post->post_password;
	if ( $stored === '' ) {
		return false;
	}
	if ( ! hash_equals( $stored, $password ) ) {
		return false;
	}

	delete_transient( $rate_key );
	inquiry_board_issue_session( $post_id );
	return true;
}

/**
 * 본 글의 post_password 가 해제(쿠키 인증) 상태인지. WP 코어 wp-postpass cookie 검증.
 * is_owner 와 별도로, "다른 비회원이 비번을 알아 본문을 본 경우" 도 잡아낸다.
 */
function inquiry_board_postpass_unlocked( int $post_id ): bool {
	$post = get_post( $post_id );
	if ( ! $post || $post->post_password === '' ) {
		return true; // 공개글
	}
	return ! post_password_required( $post );
}

/**
 * 관리자 강제 세션 만료.
 */
function inquiry_board_revoke_session( int $post_id ): void {
	delete_post_meta( $post_id, INQUIRY_BOARD_META_TOKEN_HASH );
	delete_post_meta( $post_id, INQUIRY_BOARD_META_SESSION_IP );
	delete_post_meta( $post_id, INQUIRY_BOARD_META_SESSION_EXPIRES );
	delete_post_meta( $post_id, INQUIRY_BOARD_META_SESSION_UA );
}
