<?php
/**
 * 헤드리스 REST(qna/v1) 셀프체크 (v0.13.0).
 *
 * 실행: wp eval-file wp-content/plugins/wp-qna-board/tests/test-rest.php
 *
 * DB 트랜잭션 안에서 공개·비밀 시험 글을 만들고 끝에 ROLLBACK 한다. 알림 메일(pre_wp_mail)·
 * 외부 HTTP(CRM·Turnstile, pre_http_request)는 가로채므로 운영에서 돌려도 흔적이 남지 않는다.
 *
 * 검증
 *  1. 비밀글 상세는 본문·답변 없이 protected=true, 틀린 비밀번호 403, 맞으면 본문
 *  2. 어떤 응답에도 작성자 이메일·연락처가 없다
 *  3. 작성: 필수값 누락 400, 정상 입력 201 + 카테고리·작성자 메타 저장
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

global $wpdb;
$fail  = 0;
$check = static function ( string $label, bool $ok ) use ( &$fail ): void {
	if ( ! $ok ) {
		$fail++;
	}
	WP_CLI::log( ( $ok ? '  OK   ' : '  FAIL ' ) . $label );
};
$call = static function ( string $method, string $route, array $params = [] ) {
	$req = new WP_REST_Request( $method, '/qna/v1' . $route );
	foreach ( $params as $k => $v ) {
		$req->set_param( $k, $v );
	}
	return rest_do_request( $req );
};

add_filter( 'pre_wp_mail', '__return_true' );
add_filter( 'pre_http_request', static function () {
	return [ 'headers' => [], 'body' => '{"success":true}', 'response' => [ 'code' => 200, 'message' => 'OK' ], 'cookies' => [] ];
} );

$wpdb->query( 'START TRANSACTION' );
try {
	$cat = get_terms( [ 'taxonomy' => 'inquiry_category', 'hide_empty' => false, 'number' => 1 ] )[0];
	$mk  = static function ( string $title, string $pw ) use ( $cat ): int {
		$id = wp_insert_post( [ 'post_type' => 'inquiry', 'post_status' => 'publish', 'post_title' => $title, 'post_content' => '<p>본문-' . $title . '</p>', 'post_password' => $pw ] );
		wp_set_object_terms( $id, [ $cat->term_id ], 'inquiry_category' );
		update_post_meta( $id, '_inquiry_author_name', '시험자' );
		update_post_meta( $id, '_inquiry_author_email', 'secret-mail@example.com' );
		update_post_meta( $id, '_inquiry_author_phone', '010-9999-0000' );
		return $id;
	};
	$pub  = get_post( $mk( 'rest-selfcheck-public', '' ) );
	$priv = get_post( $mk( 'rest-selfcheck-private', 'pw1234' ) );

	// 1. 비밀글
	$d = $call( 'GET', '/inquiry', [ 'slug' => rawurldecode( $priv->post_name ) ] )->get_data();
	$check( '비밀글 상세 protected=true', ! empty( $d['protected'] ) );
	$check( '비밀글 상세에 본문 없음', ! isset( $d['content'] ) && ! isset( $d['answers'] ) );
	$r = $call( 'POST', '/inquiry/' . $priv->ID . '/unlock', [ 'password' => 'wrong' ] );
	$check( '틀린 비밀번호 403', $r->get_status() === 403 );
	$r = $call( 'POST', '/inquiry/' . $priv->ID . '/unlock', [ 'password' => 'pw1234' ] );
	$check( '맞는 비밀번호 → 본문', $r->get_status() === 200 && strpos( (string) $r->get_data()['content'], '본문-rest-selfcheck-private' ) !== false );
	$p = $call( 'GET', '/inquiry', [ 'slug' => rawurldecode( $pub->post_name ) ] )->get_data();
	$check( '공개글 상세 → 본문', strpos( (string) ( $p['content'] ?? '' ), '본문-rest-selfcheck-public' ) !== false );

	// 2. 연락처 비노출
	$all = wp_json_encode( [ $d, $r->get_data(), $p, $call( 'GET', '/inquiries' )->get_data() ] );
	$check( '응답에 이메일·연락처 없음', strpos( $all, 'secret-mail@example.com' ) === false && strpos( $all, '010-9999-0000' ) === false );

	// 3. 작성
	$ts = ( time() - 10 ) . '.' . hash_hmac( 'sha256', (string) ( time() - 10 ), inquiry_board_get_secret() );
	$r  = $call( 'POST', '/inquiries', [ 'inquiry_ts' => $ts, 'cf-turnstile-response' => 'x' ] );
	$check( '필수값 누락 400', $r->get_status() === 400 );
	delete_transient( 'inq_form_' . md5( inquiry_board_get_client_ip() ) );
	$r = $call( 'POST', '/inquiries', [
		'inquiry_ts' => $ts, 'cf-turnstile-response' => 'x', 'inquiry_title' => 'rest-selfcheck-new', 'inquiry_content' => '내용',
		'inquiry_author' => '새작성자', 'inquiry_email' => 'new@example.com', 'inquiry_phone' => '01012345678',
		'inquiry_category' => $cat->slug, 'inquiry_visibility' => 'private', 'inquiry_password' => 'abcd', 'inquiry_privacy_consent' => '1',
	] );
	$id = (int) ( $r->get_data()['id'] ?? 0 );
	$check( '정상 작성 201', $r->get_status() === 201 && $id > 0 );
	$check( '작성 글 메타·비밀번호·카테고리', $id && get_post_meta( $id, '_inquiry_author_phone', true ) === '010-1234-5678'
		&& get_post( $id )->post_password === 'abcd' && has_term( $cat->term_id, 'inquiry_category', $id ) );
	delete_transient( 'inq_form_' . md5( inquiry_board_get_client_ip() ) );
	delete_transient( 'inq_unlock_' . md5( inquiry_board_get_client_ip() ) );
} finally {
	$wpdb->query( 'ROLLBACK' );
}

WP_CLI::log( $fail ? "FAIL {$fail}건" : '전부 통과' );
