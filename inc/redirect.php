<?php
/**
 * 레거시 KBoard URL → 새 inquiry post 301.
 *
 * 인식 패턴:
 *   - 쿼리: ?mod=document&uid=12345
 *   - 쿼리: ?uid=12345 (KBoard 게시판 페이지 안에서)
 *   - 쿼리: ?pageid=...&uid=12345
 * 매핑은 새 inquiry post 의 postmeta `_legacy_kboard_uid` 로 검색.
 *
 * 일반 설정의 `legacy_redirect_enabled` 옵션이 켜진 경우에만 동작한다.
 * 기본값은 off — 원본 게시판과 새 게시판을 병행 운영할 수 있도록 한다.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'template_redirect', 'inquiry_board_legacy_redirect' );

function inquiry_board_legacy_redirect(): void {
	if ( is_admin() || ! isset( $_GET['uid'] ) ) {
		return;
	}
	if ( ! function_exists( 'inquiry_board_get_settings' ) ) {
		return;
	}
	$opts = inquiry_board_get_settings();
	if ( empty( $opts['legacy_redirect_enabled'] ) ) {
		return;
	}
	$uid = (int) $_GET['uid'];
	if ( $uid <= 0 ) {
		return;
	}
	// KBoard 인식 시그널: mod=document 또는 KBoard 게시판 페이지(우리는 알 수 없으나 uid 만 있어도 시도)
	$mod = isset( $_GET['mod'] ) ? sanitize_key( (string) $_GET['mod'] ) : '';
	if ( $mod && ! in_array( $mod, [ 'document', 'mobile_document' ], true ) ) {
		return;
	}
	$found = inquiry_board_find_by_legacy_uid( $uid );
	if ( ! $found ) {
		return;
	}
	wp_safe_redirect( get_permalink( $found ), 301 );
	exit;
}

function inquiry_board_find_by_legacy_uid( int $uid ): int {
	$cache_key = 'inq_legacy_' . $uid;
	$cached    = wp_cache_get( $cache_key, 'wp-qna-board' );
	if ( $cached !== false ) {
		return (int) $cached;
	}
	$q = new WP_Query( [
		'post_type'      => 'inquiry',
		'post_status'    => [ 'publish', 'private' ],
		'meta_key'       => '_legacy_kboard_uid',
		'meta_value'     => $uid,
		'fields'         => 'ids',
		'posts_per_page' => 1,
		'no_found_rows'  => true,
	] );
	$id = $q->posts[0] ?? 0;
	wp_cache_set( $cache_key, (int) $id, 'wp-qna-board', HOUR_IN_SECONDS );
	return (int) $id;
}
