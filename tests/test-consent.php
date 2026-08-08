<?php
/**
 * 동의 체크박스(개인정보 수집·이용 / 마케팅 수신) 셀프체크.
 *
 * 실행: wp eval-file wp-content/plugins/wp-qna-board/tests/test-consent.php
 *
 * 실제 폼 제출(admin-post.php)은 성공 시 redirect + exit 라 CLI 에서 끝까지 돌릴 수 없으므로
 * ① 폼 렌더 ② 알림 메일 데이터·HTML ③ wowbiz-sync 가 인정하는 값 형식을 검증한다.
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

// 1. 폼 렌더 — 두 항목이 분리돼 있고, 필수만 required, 둘 다 기본 미체크.
$_GET['ipv'] = 'write';
$html        = (string) do_shortcode( '[inquiry_form]' );
unset( $_GET['ipv'] );

preg_match( '/<input[^>]*name="inquiry_privacy_consent"[^>]*>/',   $html, $m_priv );
preg_match( '/<input[^>]*name="inquiry_marketing_consent"[^>]*>/', $html, $m_mkt );
$priv_tag = $m_priv[0] ?? '';
$mkt_tag  = $m_mkt[0] ?? '';

$check( '개인정보 동의 체크박스 존재',   $priv_tag !== '' );
$check( '마케팅 동의 체크박스 존재',     $mkt_tag !== '' );
$check( '두 항목이 분리돼 있음',         $priv_tag !== '' && $mkt_tag !== '' && $priv_tag !== $mkt_tag );
$check( '개인정보 동의는 required',      str_contains( $priv_tag, 'required' ) );
$check( '마케팅 동의는 required 아님',   $mkt_tag !== '' && ! str_contains( $mkt_tag, 'required' ) );
// 사전 선택된 동의는 유효한 동의로 보지 않는다 — 기본값이 checked 로 되돌아가면 여기서 잡힌다.
$check( '개인정보 동의 기본 미체크',     $priv_tag !== '' && ! str_contains( $priv_tag, 'checked' ) );
$check( '마케팅 동의 기본 미체크',       $mkt_tag !== ''  && ! str_contains( $mkt_tag, 'checked' ) );
$check( 'value="1" (wowbiz 동의 인정값)', str_contains( $priv_tag, 'value="1"' ) && str_contains( $mkt_tag, 'value="1"' ) );
$check( '개인정보취급방침 링크 노출',    (bool) preg_match( '/class="inquiry-consent-opt"[\s\S]{0,900}?개인정보취급방침/u', $html ) );

// 2. CSS — 동의 카드 스타일이 셀렉터 목록에 들어있는지(입력 스타일 화이트리스트와 같은 함정).
$css = (string) file_get_contents( INQUIRY_BOARD_DIR . 'assets/wp-qna-board.css' );
// 카드 스타일은 공개/비공개 선택과 셀렉터 그룹을 공유한다(그룹 4곳 + flex-basis 1곳).
// 누군가 그룹에서 빼면 등장 횟수가 줄어 여기서 잡힌다.
$check( 'CSS 셀렉터 그룹에 .inquiry-consent-opt', substr_count( $css, '.inquiry-consent-opt' ) >= 5 );

// 3. 알림 메일 — 마케팅 동의 여부가 데이터·HTML 양쪽에 실리는지.
$found = get_posts( [
	'post_type'   => 'inquiry',
	'post_status' => [ 'draft', 'publish' ],
	'title'       => '[셀프체크] 알림 메일 검증',
	'numberposts' => 1,
	'fields'      => 'ids',
] );

if ( $found ) {
	$post_id = (int) $found[0];
	$saved   = (string) get_post_meta( $post_id, '_inquiry_marketing_consent', true );

	update_post_meta( $post_id, '_inquiry_marketing_consent', '1' );
	$d = inquiry_board_notify_data( $post_id );
	$check( '동의 시 marketing=true',  true === ( $d['marketing'] ?? null ) );
	$check( '메일 HTML 에 "동의" 표기', str_contains( inquiry_board_notify_html( $d ), '마케팅 수신' ) );

	update_post_meta( $post_id, '_inquiry_marketing_consent', '0' );
	$d0 = inquiry_board_notify_data( $post_id );
	$check( '미동의 시 marketing=false', false === ( $d0['marketing'] ?? null ) );

	// 메타가 아예 없는 기존 글(v0.11.0 이전)도 미동의로 읽혀야 한다.
	delete_post_meta( $post_id, '_inquiry_marketing_consent' );
	$dn = inquiry_board_notify_data( $post_id );
	$check( '메타 없는 기존 글 → 미동의', false === ( $dn['marketing'] ?? null ) );

	if ( $saved !== '' ) {
		update_post_meta( $post_id, '_inquiry_marketing_consent', $saved );
	}
} else {
	WP_CLI::log( '  SKIP  알림 메일 픽스처 없음 ([셀프체크] 알림 메일 검증)' );
}

WP_CLI::log( '' );
if ( $fail > 0 ) {
	WP_CLI::error( sprintf( '%d 항목 실패', $fail ) );
}
WP_CLI::success( '전 항목 통과' );
