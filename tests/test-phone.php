<?php
/**
 * 연락처(휴대폰) 필드 셀프체크.
 *
 * 실행: wp eval-file wp-content/plugins/wp-qna-board/tests/test-phone.php
 *
 * 실제 폼 제출(admin-post.php)은 성공 시 redirect + exit 라 CLI 에서 끝까지 돌릴 수 없으므로
 * ① 정규화 함수 ② 폼 렌더 결과 ③ 알림 메일 데이터 세 지점을 검증한다.
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

// 1. 정규화 — 국내 휴대폰은 하이픈 표기로 통일, 그 외는 입력값 보존, 자릿수 밖은 실패.
$n = 'inquiry_board_normalize_phone';

$check( '01012345678 → 010-1234-5678',      $n( '01012345678' ) === '010-1234-5678' );
$check( '010-1234-5678 유지',                $n( '010-1234-5678' ) === '010-1234-5678' );
$check( '010 1234 5678 → 하이픈',            $n( '010 1234 5678' ) === '010-1234-5678' );
$check( '0111234567(3-3-4) → 011-123-4567', $n( '0111234567' ) === '011-123-4567' );
$check( '앞뒤 공백 제거',                     $n( '  010-1234-5678  ' ) === '010-1234-5678' );
$check( '02 지역번호는 입력값 보존',           $n( '02-3456-7890' ) === '02-3456-7890' );
$check( '국제표기(+44) 입력값 보존',           $n( '+44 20 7946 0958' ) === '+44 20 7946 0958' );

$check( '빈 값 → 실패',           $n( '' ) === '' );
$check( '숫자 8자리 → 실패',      $n( '12345678' ) === '' );
$check( '숫자 16자리 → 실패',     $n( '1234567890123456' ) === '' );
$check( '문자만 → 실패',          $n( '연락처없음' ) === '' );
$check( '하이픈만 → 실패',        $n( '---' ) === '' );

// 2. 폼 렌더 — 연락처 입력란이 필수로 나오는지.
$_GET['ipv'] = 'write';
$html        = (string) do_shortcode( '[inquiry_form]' );
unset( $_GET['ipv'] );

$check( '폼에 inquiry_phone 필드 존재', str_contains( $html, 'name="inquiry_phone"' ) );
$check( '연락처 필드 required',         (bool) preg_match( '/id="inq_phone"[^>]*required/', $html ) );
$check( 'type=tel',                    (bool) preg_match( '/id="inq_phone"[^>]*type="tel"|type="tel"[^>]*id="inq_phone"/', $html ) );

// 3. 알림 메일 — 메타에 저장된 연락처가 데이터·HTML 양쪽에 실리는지.
//    기존 셀프체크 픽스처(draft)를 재사용한다. 없으면 이 구간은 건너뛴다.
$found = get_posts( [
	'post_type'   => 'inquiry',
	'post_status' => [ 'draft', 'publish' ],
	'title'       => '[셀프체크] 알림 메일 검증',
	'numberposts' => 1,
	'fields'      => 'ids',
] );

if ( $found ) {
	$post_id = (int) $found[0];
	$saved   = (string) get_post_meta( $post_id, '_inquiry_author_phone', true );

	update_post_meta( $post_id, '_inquiry_author_phone', '010-1234-5678' );
	$d = inquiry_board_notify_data( $post_id );
	$check( '메일 데이터에 phone 포함', ( $d['phone'] ?? '' ) === '010-1234-5678' );
	$check( '메일 HTML 에 tel: 링크',   str_contains( inquiry_board_notify_html( $d ), 'href="tel:01012345678"' ) );

	if ( $saved === '' ) {
		delete_post_meta( $post_id, '_inquiry_author_phone' );
	} else {
		update_post_meta( $post_id, '_inquiry_author_phone', $saved );
	}
} else {
	WP_CLI::log( '  SKIP  알림 메일 픽스처 없음 ([셀프체크] 알림 메일 검증)' );
}

WP_CLI::log( '' );
if ( $fail > 0 ) {
	WP_CLI::error( sprintf( '%d 항목 실패', $fail ) );
}
WP_CLI::success( '전 항목 통과' );
