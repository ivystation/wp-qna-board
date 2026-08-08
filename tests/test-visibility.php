<?php
/**
 * 공개/비공개 선택 셀프체크.
 *
 * 실행: wp eval-file wp-content/plugins/wp-qna-board/tests/test-visibility.php
 *
 * 실제 폼 제출(admin-post.php)은 성공 시 redirect + exit 라 CLI 에서 끝까지 돌릴 수 없고,
 * 제출하면 실제 문의가 생성되며 관리자 알림 메일까지 나간다. 그래서 판정 로직
 * inquiry_board_is_private_submission() 과 폼 렌더 결과를 검증한다.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

$fail  = 0;
$check = static function ( string $label, bool $ok ) use ( &$fail ): void {
	if ( ! $ok ) {
		$fail++;
	}
	WP_CLI::log( ( $ok ? '  OK   ' : '  FAIL ' ) . $label );
};

// 1. 판정 로직
$free   = [ 'password_required' => 0 ];
$forced = [ 'password_required' => 1 ];

$check( '옵션 off + 값 없음 → 공개',            false === inquiry_board_is_private_submission( $free, '' ) );
$check( '옵션 off + public → 공개',             false === inquiry_board_is_private_submission( $free, 'public' ) );
$check( '옵션 off + private → 비공개',          true  === inquiry_board_is_private_submission( $free, 'private' ) );
$check( '옵션 off + 알 수 없는 값 → 공개(기본)', false === inquiry_board_is_private_submission( $free, 'nonsense' ) );
$check( '옵션 on → 폼 값 무관하게 비공개',       true  === inquiry_board_is_private_submission( $forced, 'public' ) );
$check( '옵션 on + 값 없음 → 비공개',            true  === inquiry_board_is_private_submission( $forced, '' ) );

// 2. 폼 렌더 — 옵션 상태를 임시로 바꿔가며 확인하고 원복한다.
$saved = get_option( 'inquiry_board_settings', [] );

// [inquiry_form] 은 라우터다 — 기본은 목록이고 ?ipv=write 일 때만 글쓰기 폼을 렌더한다.
$render_form = static function ( int $required ) use ( $saved ): string {
	$o                      = is_array( $saved ) ? $saved : [];
	$o['password_required'] = $required;
	update_option( 'inquiry_board_settings', $o );
	$_GET['ipv'] = 'write';
	$html        = (string) do_shortcode( '[inquiry_form]' );
	unset( $_GET['ipv'] );
	return $html;
};

$html_free = $render_form( 0 );
$check( '옵션 off: 공개/비공개 라디오 노출', str_contains( $html_free, 'name="inquiry_visibility"' ) );
$check( '옵션 off: 공개가 기본 선택', (bool) preg_match( '/value="public"[^>]*checked/', $html_free ) );
$check( '옵션 off: 비밀번호 필드가 hidden 으로 시작', (bool) preg_match( '/class="inquiry-password-field"[^>]*hidden/', $html_free ) );
$check( '옵션 off: 비밀번호 필드에 required 없음', ! (bool) preg_match( '/id="inq_password"[^>]*required/', $html_free ) );
$check( '옵션 off: 3-col 그리드(이름·이메일·연락처)', str_contains( $html_free, 'inquiry-form-grid--3col' ) );

$html_forced = $render_form( 1 );
$check( '옵션 on: 라디오 미노출', ! str_contains( $html_forced, 'name="inquiry_visibility"' ) );
$check( '옵션 on: 비밀번호 필드 required', (bool) preg_match( '/id="inq_password"[^>]*required/', $html_forced ) );
$check( '옵션 on: 4-col 그리드(비밀번호 필드 포함)', str_contains( $html_forced, 'inquiry-form-grid--4col' ) );

update_option( 'inquiry_board_settings', $saved );
$restored = get_option( 'inquiry_board_settings', [] );
$check(
	'옵션 원복 확인',
	(int) ( $restored['password_required'] ?? -1 ) === (int) ( $saved['password_required'] ?? -1 )
);

// 3. 공개글이 잠금 없이 표시되는지 — 렌더 헬퍼가 post_password 기준으로 동작하는지 확인
$found = get_posts( [
	'post_type'   => 'inquiry',
	'post_status' => [ 'draft', 'publish' ],
	'title'       => '[셀프체크] 알림 메일 검증',
	'numberposts' => 1,
	'fields'      => 'ids',
] );
if ( $found ) {
	$pid = (int) $found[0];
	wp_update_post( [ 'ID' => $pid, 'post_password' => '' ] );
	$check( '공개글은 post_password_required 가 false', ! post_password_required( get_post( $pid ) ) );
	wp_update_post( [ 'ID' => $pid, 'post_password' => 'tempPW' ] );
	$check( '비공개글은 post_password_required 가 true', post_password_required( get_post( $pid ) ) );
	wp_update_post( [ 'ID' => $pid, 'post_password' => '' ] );
	WP_CLI::log( sprintf( '  ---   픽스처 #%d 는 공개 상태로 남겨둠(draft)', $pid ) );
} else {
	WP_CLI::log( '  SKIP  픽스처 글이 없어 공개/비공개 표시 검증 생략' );
}

if ( $fail ) {
	WP_CLI::error( sprintf( '%d 건 실패', $fail ) );
}
WP_CLI::success( '전체 통과' );
