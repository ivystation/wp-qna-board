<?php
/**
 * 스팸/보안 방어 셀프체크 (v0.12.0).
 *
 * 실행: wp eval-file wp-content/plugins/wp-qna-board/tests/test-security.php
 *
 * 검증
 *  1. time-trap 서명·최소 체류시간 (위조·즉시제출 거부, 정상 통과)
 *  2. Turnstile 검증 분기 (secret 미설정=null, 설정+토큰없음=false)
 *  3. inquiry CPT 가 REST 에서 비공개 (show_in_rest=false)
 *  4. 폼에 time-trap 필드 + (Turnstile 설정 시) 위젯 노출
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

require_once WP_PLUGIN_DIR . '/wp-qna-board/inc/form.php';

$fail  = 0;
$check = static function ( string $label, bool $ok ) use ( &$fail ): void {
	if ( ! $ok ) {
		$fail++;
	}
	WP_CLI::log( ( $ok ? '  OK   ' : '  FAIL ' ) . $label );
};

// 1. time-trap
$secret = inquiry_board_get_secret();
$mk     = static function ( int $ts ) use ( $secret ): string {
	return $ts . '.' . hash_hmac( 'sha256', (string) $ts, $secret );
};
$check( '빈 값 거부',            false === inquiry_board_form_timestamp_ok( '' ) );
$check( '형식 불량 거부',        false === inquiry_board_form_timestamp_ok( 'abc' ) );
$check( '위조 서명 거부',        false === inquiry_board_form_timestamp_ok( ( time() - 10 ) . '.deadbeef' ) );
$check( '즉시 제출(0초) 거부',   false === inquiry_board_form_timestamp_ok( $mk( time() ) ) );
$check( '10초 경과 통과',        true  === inquiry_board_form_timestamp_ok( $mk( time() - 10 ) ) );
$check( '2시간 경과 거부(만료)', false === inquiry_board_form_timestamp_ok( $mk( time() - 7200 ) ) );

// 2. Turnstile 분기 — $_POST 를 직접 조작해 검증한다(외부 API 는 호출 안 되도록 토큰 없음 케이스만).
$had_secret = trim( (string) get_option( 'cfturnstile_secret', '' ) ) !== '';
unset( $_POST['cf-turnstile-response'] );
if ( $had_secret ) {
	$check( 'Turnstile secret 설정됨 + 토큰 없음 → false(거부)', false === inquiry_board_verify_turnstile() );
	$check( 'Turnstile site key 노출됨', inquiry_board_turnstile_sitekey() !== '' );
} else {
	// 임시로 secret 을 비워 null 분기만 확인.
	$check( 'Turnstile secret 미설정 → null(reCAPTCHA 폴백)', null === inquiry_board_verify_turnstile() );
}

// 3. inquiry REST 비공개
$pt = get_post_type_object( 'inquiry' );
$check( 'inquiry CPT show_in_rest=false', $pt && empty( $pt->show_in_rest ) );

// 4. 폼 렌더
$_GET['ipv'] = 'write';
$html        = (string) do_shortcode( '[inquiry_form]' );
unset( $_GET['ipv'] );
$check( '폼에 time-trap 필드(inquiry_ts)', (bool) preg_match( '/name="inquiry_ts"[^>]*value="\d+\.[a-f0-9]{64}"/', $html ) );
if ( inquiry_board_turnstile_sitekey() !== '' ) {
	$check( '폼에 Turnstile 위젯', str_contains( $html, 'class="cf-turnstile"' ) );
}

WP_CLI::log( '' );
if ( $fail > 0 ) {
	WP_CLI::error( sprintf( '%d 항목 실패', $fail ) );
}
WP_CLI::success( '전 항목 통과' );
