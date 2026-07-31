<?php
/**
 * 알림 메일 셀프체크. 실제 메일은 pre_wp_mail 로 가로채므로 발송되지 않는다.
 *
 * 실행: wp eval-file wp-content/plugins/wp-qna-board/tests/test-notify.php
 *
 * 검증 항목
 *  1. 수신자 파싱 — 쉼표/세미콜론/공백 구분, 중복 제거, 무효 주소 탈락
 *  2. 미설정 시 admin_email 폴백
 *  3. draft → publish 전이에서 알림 1회 발송 (모든 등록 경로 공통 지점)
 *  4. 재발행으로 중복 발송되지 않음
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

$fail = 0;
$check = static function ( string $label, bool $ok ) use ( &$fail ): void {
	if ( ! $ok ) {
		$fail++;
	}
	WP_CLI::log( ( $ok ? '  OK   ' : '  FAIL ' ) . $label );
};

// 1. 파싱
$check(
	'쉼표 구분 2건 파싱',
	inquiry_board_parse_email_list( 'a@example.com, b@example.com' ) === [ 'a@example.com', 'b@example.com' ]
);
$check(
	'세미콜론·공백 구분 + 중복 제거 + 무효 주소 탈락',
	inquiry_board_parse_email_list( 'a@example.com; a@example.com  notanemail  c@example.com' ) === [ 'a@example.com', 'c@example.com' ]
);
$check( '빈 문자열은 빈 배열', inquiry_board_parse_email_list( '' ) === [] );

// 2. 폴백
$check(
	'notify_email 미설정 시 admin_email 폴백',
	inquiry_board_notify_recipients( [ 'notify_email' => '' ] ) === [ get_option( 'admin_email' ) ]
);
$check(
	'notify_email 설정 시 그 값 사용',
	inquiry_board_notify_recipients( [ 'notify_email' => 'x@example.com' ] ) === [ 'x@example.com' ]
);

// 2-1. 저장 왕복 — 구 sanitize_email() 은 쉼표 목록을 통째로 무효 판정해 값을 날렸다(회귀 방지).
$check(
	'다중 이메일이 저장 sanitize 를 통과',
	inquiry_board_settings_sanitize( [ 'notify_email' => 'a@example.com, b@example.com' ] )['notify_email'] === 'a@example.com, b@example.com'
);
$check(
	'무효 주소만 있으면 빈 값',
	inquiry_board_settings_sanitize( [ 'notify_email' => 'notanemail' ] )['notify_email'] === ''
);

// 2-2. github_token 형식 검증 — 브라우저가 채운 사이트 비밀번호가 평문 저장되는 사고 방어.
$check(
	'PAT 형식이 아닌 값은 저장 거부',
	inquiry_board_settings_sanitize( [ 'github_token' => 'S0meS1teP@ssword' ] )['github_token'] === ''
);
$check(
	'ghp_ PAT 는 통과',
	inquiry_board_settings_sanitize( [ 'github_token' => 'ghp_' . str_repeat( 'a', 36 ) ] )['github_token'] === 'ghp_' . str_repeat( 'a', 36 )
);

// 3~4. draft → publish 전이. 실제 발송은 가로채고 호출만 기록한다.
$sent = [];
add_filter( 'pre_wp_mail', static function ( $null, array $atts ) use ( &$sent ) {
	$sent[] = $atts;
	return true; // wp_mail 을 여기서 종료 → 실제 발송 없음
}, 10, 2 );

// 재실행마다 테스트 글이 쌓이지 않게, 이전 셀프체크 글이 남아 있으면 재사용한다.
$title_marker = '[셀프체크] 알림 메일 검증';
$found        = get_posts( [
	'post_type'   => 'inquiry',
	'post_status' => 'draft',
	'title'       => $title_marker,
	'numberposts' => 1,
	'fields'      => 'ids',
] );

$post_id = $found ? (int) $found[0] : wp_insert_post( [
	'post_type'    => 'inquiry',
	'post_status'  => 'draft',
	'post_title'   => $title_marker,
	'post_content' => '테스트 본문',
], true );

if ( is_wp_error( $post_id ) ) {
	WP_CLI::error( '테스트 글 생성 실패: ' . $post_id->get_error_message() );
}
update_post_meta( $post_id, '_inquiry_author_name', '테스트작성자' );
delete_post_meta( $post_id, '_inquiry_notified' ); // 재사용 시 1회성 가드 초기화

$check( 'draft 생성 단계에서는 미발송', $sent === [] );

wp_publish_post( $post_id );
do_action( 'shutdown' ); // 발송은 shutdown 지연이므로 여기서 강제 flush

$check( 'publish 전이에서 1건 발송', count( $sent ) === 1 );
if ( $sent ) {
	$check( '수신자가 설정값과 일치', (array) $sent[0]['to'] === inquiry_board_notify_recipients( inquiry_board_get_settings() ) );
	$check( '제목에 글 제목 포함', str_contains( (string) $sent[0]['subject'], '알림 메일 검증' ) );
	$check( '본문에 작성자명 포함', str_contains( (string) $sent[0]['message'], '테스트작성자' ) );
	$check( '본문에 관리 화면 링크 포함 (권한 무관)', str_contains( (string) $sent[0]['message'], 'post.php?post=' . $post_id ) );
}

// 4. 중복 방지 — draft 로 내렸다 다시 publish.
//    do_action('shutdown') 은 등록 콜백을 제거하지 않아 재발동하면 1라운드 클로저가 다시 돈다.
//    그래서 발송 건수 대신 "shutdown 에 발송이 새로 예약되었는지"로 가드를 검사한다.
$queued = static function (): int {
	global $wp_filter;
	return isset( $wp_filter['shutdown'][10] ) ? count( $wp_filter['shutdown'][10] ) : 0;
};
wp_update_post( [ 'ID' => $post_id, 'post_status' => 'draft' ] );
$before = $queued();
wp_publish_post( $post_id );
$check( '재발행 시 발송이 다시 예약되지 않음', $queued() === $before );
$check( '_inquiry_notified 메타가 1회 발송을 기록', '1' === (string) get_post_meta( $post_id, '_inquiry_notified', true ) );

// 정리 — 이 스크립트가 만든 글만 되돌린다 (하드 삭제는 하지 않음).
wp_update_post( [ 'ID' => $post_id, 'post_status' => 'draft' ] );
WP_CLI::log( sprintf( '  ---   테스트 글 #%d 은 draft 로 남겨둠 (수동 정리 필요)', $post_id ) );

if ( $fail ) {
	WP_CLI::error( sprintf( '%d 건 실패', $fail ) );
}
WP_CLI::success( '전체 통과' );
