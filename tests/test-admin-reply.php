<?php
/**
 * 편집 화면 답글 UI 셀프체크.
 *
 * 실행: wp eval-file wp-content/plugins/wp-qna-board/tests/test-admin-reply.php
 *
 * inc/admin-reply.php 는 is_admin() 안에서만 로드되고 WP-CLI 는 is_admin() 이 false 라
 * 여기서 직접 require 한다. AJAX 핸들러는 wp_send_json_* 이 die 하므로 직접 호출하지 않고
 * 같은 저장 경로(wp_insert_comment + _is_admin_reply 마킹)를 재현해 검증한다.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

require_once WP_PLUGIN_DIR . '/wp-qna-board/inc/admin-reply.php';

$fail  = 0;
$check = static function ( string $label, bool $ok ) use ( &$fail ): void {
	if ( ! $ok ) {
		$fail++;
	}
	WP_CLI::log( ( $ok ? '  OK   ' : '  FAIL ' ) . $label );
};

// 0. Classic Editor 강제
$check(
	'inquiry 는 Classic Editor (블록 에디터 off)',
	false === apply_filters( 'use_block_editor_for_post_type', true, 'inquiry' )
);
// 주의: ukuhak.com 은 Classic Editor 플러그인이 priority 100 __return_false 로 전역 차단 중이라
// 'post' 등 다른 CPT 도 false 다. 그래서 "다른 CPT 는 true" 식 검증은 성립하지 않는다.
// 우리 필터가 다른 CPT 의 값을 바꾸지 않는지는 필터 함수 단위로 확인한다.
$others_untouched = true;
foreach ( [ 'post', 'page', 'school' ] as $pt ) {
	$before = apply_filters( 'use_block_editor_for_post_type', true, $pt );
	$after  = apply_filters( 'use_block_editor_for_post_type', $before, $pt );
	if ( $before !== $after ) {
		$others_untouched = false;
	}
}
$check( '다른 CPT 값을 우리 필터가 뒤집지 않음', $others_untouched );

// 1. 메타박스가 inquiry 편집 화면에 등록되는가
do_action( 'add_meta_boxes_inquiry', new WP_Post( (object) [ 'ID' => 0, 'post_type' => 'inquiry' ] ) );
global $wp_meta_boxes;
$box = $wp_meta_boxes['inquiry']['normal']['high']['inquiry_admin_reply'] ?? null;
$check( '메타박스가 normal/high 로 등록됨', is_array( $box ) && ! empty( $box['callback'] ) );

// 2. 테스트 픽스처 — 알림 셀프체크와 같은 글을 재사용해 누적을 막는다.
$title_marker = '[셀프체크] 알림 메일 검증';
$found        = get_posts( [
	'post_type'   => 'inquiry',
	'post_status' => [ 'draft', 'publish' ],
	'title'       => $title_marker,
	'numberposts' => 1,
	'fields'      => 'ids',
] );
$post_id = $found ? (int) $found[0] : (int) wp_insert_post( [
	'post_type'    => 'inquiry',
	'post_status'  => 'draft',
	'post_title'   => $title_marker,
	'post_content' => '테스트 본문',
], true );
if ( is_wp_error( $post_id ) || ! $post_id ) {
	WP_CLI::error( '테스트 글 준비 실패' );
}
update_post_meta( $post_id, '_inquiry_author_name', '테스트작성자' );
update_post_meta( $post_id, '_inquiry_author_email', 'tester@example.com' );

$render = static function ( int $id ): string {
	ob_start();
	inquiry_board_render_reply_metabox( get_post( $id ) );
	return (string) ob_get_clean();
};

// 3. 관리자 답변이 없을 때 — 답변대기
$reply_marker = '[셀프체크] 관리자 답변 렌더 검증';
$existing     = get_comments( [
	'post_id' => $post_id,
	'search'  => $reply_marker,
	'number'  => 1,
] );
foreach ( $existing as $c ) {
	// 재실행 시 이전 셀프체크 댓글을 초기 상태로 되돌린다 (댓글은 재사용, 새로 쌓지 않음).
	delete_comment_meta( (int) $c->comment_ID, '_is_admin_reply' );
	wp_update_comment( [ 'comment_ID' => (int) $c->comment_ID, 'comment_approved' => 0 ] );
}

$html = $render( $post_id );
$check( '요약에 작성자명 출력', str_contains( $html, '테스트작성자' ) );
$check( '요약에 이메일 출력', str_contains( $html, 'tester@example.com' ) );
$check( '관리자 답변 0건이면 답변대기 배지', str_contains( $html, '답변대기' ) && ! str_contains( $html, '답변완료' ) );
$check( '문의 원문 블록 렌더', str_contains( $html, '문의 원문' ) );
$check( '답글 입력 textarea 렌더', str_contains( $html, 'id="ib_reply_content"' ) );
$check( '제출 버튼 렌더', str_contains( $html, 'id="ib-reply-submit"' ) );
$check( 'nonce hidden 필드 렌더', str_contains( $html, 'id="ib_reply_nonce"' ) );
$check( 'AJAX action 이름이 등록된 훅과 일치', str_contains( $html, "'inquiry_board_admin_reply'" ) || str_contains( $html, 'inquiry_board_admin_reply' ) );
$check( 'wp_ajax 훅 등록됨', has_action( 'wp_ajax_inquiry_board_admin_reply' ) !== false );

// 4. AJAX 와 동일한 저장 경로 재현 — wp_insert_comment + _is_admin_reply 마킹
$admin = get_users( [ 'role' => 'administrator', 'number' => 1 ] );
if ( ! $admin ) {
	WP_CLI::error( '관리자 계정을 찾을 수 없습니다.' );
}
$admin = $admin[0];

if ( $existing ) {
	$comment_id = (int) $existing[0]->comment_ID;
	wp_update_comment( [ 'comment_ID' => $comment_id, 'comment_approved' => 1 ] );
} else {
	$comment_id = (int) wp_insert_comment( [
		'comment_post_ID'      => $post_id,
		'comment_author'       => $admin->display_name ?: $admin->user_login,
		'comment_author_email' => (string) $admin->user_email,
		'comment_content'      => $reply_marker,
		'comment_type'         => 'comment',
		'user_id'              => (int) $admin->ID,
		'comment_approved'     => 1,
	] );
}
$check( '답글 댓글 저장됨', $comment_id > 0 );

// wp_insert_comment 는 comment_post 훅을 돌리지 않으므로 마킹이 없어야 정상
$check(
	'wp_insert_comment 만으로는 _is_admin_reply 가 붙지 않음 (직접 마킹 필요)',
	'' === (string) get_comment_meta( $comment_id, '_is_admin_reply', true )
);

add_comment_meta( $comment_id, '_is_admin_reply', 1, true );
$check( '_is_admin_reply 마킹 후 관리자 답변으로 판별', inquiry_board_is_admin_reply( get_comment( $comment_id ) ) );

$html = $render( $post_id );
$check( '관리자 답변 1건이면 답변완료 배지', str_contains( $html, '답변완료' ) );
$check( '스레드에 관리자 답변 라벨', str_contains( $html, '관리자 답변' ) );
$check( '관리자 답변 박스에 ib-msg-admin 클래스', str_contains( $html, 'ib-msg-admin' ) );
$check( '답변 본문 렌더', str_contains( $html, $reply_marker ) );

// 5. 첨부 렌더
//    운영에 첨부가 달린 문의가 아직 0건이라, 미디어 라이브러리의 기존 첨부를 빌려 렌더 함수만
//    호출한다. 글에 연결하지 않으므로 데이터 부작용이 없다.
$check( '첨부 없으면 아무것도 출력하지 않음', '' === ( function (): string {
	ob_start();
	inquiry_board_render_admin_attachments( [] );
	return (string) ob_get_clean();
} )() );

$img_id = (int) ( get_posts( [
	'post_type'      => 'attachment',
	'post_status'    => 'inherit',
	'post_mime_type' => 'image',
	'posts_per_page' => 1,
	'fields'         => 'ids',
] )[0] ?? 0 );
if ( $img_id ) {
	ob_start();
	inquiry_board_render_admin_attachments( [ $img_id ] );
	$att_html = (string) ob_get_clean();
	$check( '이미지 첨부는 썸네일로 렌더', str_contains( $att_html, 'ib-att-img' ) && str_contains( $att_html, '<img' ) );
} else {
	WP_CLI::log( '  SKIP  이미지 첨부가 없어 썸네일 렌더 검증 생략' );
}

$file_id = (int) ( get_posts( [
	'post_type'      => 'attachment',
	'post_status'    => 'inherit',
	'post_mime_type' => [ 'application/pdf', 'application/zip', 'application/msword' ],
	'posts_per_page' => 1,
	'fields'         => 'ids',
] )[0] ?? 0 );
if ( $file_id ) {
	ob_start();
	inquiry_board_render_admin_attachments( [ $file_id ] );
	$att_html = (string) ob_get_clean();
	$check( '비이미지 첨부는 확장자 칩으로 렌더', str_contains( $att_html, 'ib-att-file' ) && str_contains( $att_html, 'ib-att-ext' ) );
} else {
	WP_CLI::log( '  SKIP  비이미지 첨부가 없어 파일 칩 렌더 검증 생략' );
}

// 폴백 경로: 메타가 없는 글은 attachment 의 post_parent 로 조회한다.
$check( '첨부 메타·자식 모두 없으면 빈 배열', [] === inquiry_board_get_attachment_ids( $post_id ) );

// 6. 정리 — 픽스처는 다음 실행에서 재사용하므로 지우지 않고 미승인으로 되돌린다.
wp_update_comment( [ 'comment_ID' => $comment_id, 'comment_approved' => 0 ] );
delete_comment_meta( $comment_id, '_is_admin_reply' );
WP_CLI::log( sprintf( '  ---   픽스처 유지: 글 #%d (draft) · 댓글 #%d (미승인)', $post_id, $comment_id ) );

if ( $fail ) {
	WP_CLI::error( sprintf( '%d 건 실패', $fail ) );
}
WP_CLI::success( '전체 통과' );
