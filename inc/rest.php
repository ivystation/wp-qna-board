<?php
/**
 * 헤드리스 프론트용 REST — `qna/v1` (v0.13.0).
 *
 * inquiry CPT 는 `show_in_rest=false` 그대로 둔다(v0.12.0: wp/v2 가 비밀글 제목·전건을 노출).
 * 대신 화면에 필요한 값만 골라 내보내는 전용 라우트를 둔다 — 기존 목록 화면이 보여 주는 것 이상은 나가지 않는다.
 *
 *   GET  /qna/v1/inquiries?page=&category=   목록 (번호·카테고리·제목·작성자명·작성일·비밀글 여부·답변 수)
 *   GET  /qna/v1/inquiry?slug=               상세 (비밀글은 본문·답변 없이 protected=true)
 *   POST /qna/v1/inquiry/{id}/unlock         비밀글 열기 {password} — IP 당 10분 10회
 *   GET  /qna/v1/form                        작성 폼 정보 (카테고리·서명 시각·Turnstile 사이트키·비밀글 설정)
 *   POST /qna/v1/inquiries                   작성 — 검증·스팸 방어는 폼과 같은 inquiry_board_create_inquiry()
 *
 * 작성자 이메일·연락처·IP 는 어떤 응답에도 넣지 않는다. 응답은 캐시하지 않는다(Varnish 가 REST 를 4시간 잡는다).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const INQUIRY_BOARD_REST_NS     = 'qna/v1';
const INQUIRY_BOARD_UNLOCK_MAX  = 10;  // IP 당 시도 횟수
const INQUIRY_BOARD_UNLOCK_SPAN = 600; // 초

add_action( 'rest_api_init', static function (): void {
	$open = '__return_true'; // 비로그인 공개 — 각 콜백이 노출 범위를 스스로 제한한다

	register_rest_route( INQUIRY_BOARD_REST_NS, '/inquiries', [
		[
			'methods'             => 'GET',
			'callback'            => 'inquiry_board_rest_list',
			'permission_callback' => $open,
			'args'                => [
				'page'     => [ 'type' => 'integer', 'default' => 1, 'minimum' => 1 ],
				'category' => [ 'type' => 'string', 'default' => '' ],
			],
		],
		[
			'methods'             => 'POST',
			'callback'            => 'inquiry_board_rest_create',
			'permission_callback' => $open,
		],
	] );
	register_rest_route( INQUIRY_BOARD_REST_NS, '/inquiry', [
		'methods'             => 'GET',
		'callback'            => 'inquiry_board_rest_detail',
		'permission_callback' => $open,
		'args'                => [ 'slug' => [ 'type' => 'string', 'required' => true ] ],
	] );
	register_rest_route( INQUIRY_BOARD_REST_NS, '/inquiry/(?P<id>\d+)/unlock', [
		'methods'             => 'POST',
		'callback'            => 'inquiry_board_rest_unlock',
		'permission_callback' => $open,
	] );
	register_rest_route( INQUIRY_BOARD_REST_NS, '/form', [
		'methods'             => 'GET',
		'callback'            => 'inquiry_board_rest_form',
		'permission_callback' => $open,
	] );
} );

/** 캐시 금지 응답 */
function inquiry_board_rest_response( $data, int $status = 200 ): WP_REST_Response {
	$res = new WP_REST_Response( $data, $status );
	$res->header( 'Cache-Control', 'no-store, private' );
	return $res;
}

function inquiry_board_rest_category( int $post_id ): ?array {
	$terms = get_the_terms( $post_id, 'inquiry_category' );
	if ( ! $terms || is_wp_error( $terms ) ) {
		return null;
	}
	return [ 'name' => $terms[0]->name, 'slug' => $terms[0]->slug ];
}

/** 목록·상세 공통 필드 — 기존 목록 화면(templates/inquiry-list.php)이 보여 주는 값과 같다 */
function inquiry_board_rest_summary( WP_Post $post ): array {
	$name = (string) get_post_meta( $post->ID, '_inquiry_author_name', true );
	return [
		'id'        => (int) $post->ID,
		'slug'      => rawurldecode( $post->post_name ),
		'title'     => html_entity_decode( $post->post_title, ENT_QUOTES, 'UTF-8' ), // get_the_title 은 비밀글에 "보호된 글:" 을 붙인다
		'category'  => inquiry_board_rest_category( (int) $post->ID ),
		'author'    => $name !== '' ? $name : __( '익명', 'wp-qna-board' ),
		'date'      => mysql_to_rfc3339( $post->post_date ),
		'protected' => $post->post_password !== '',
		'answers'   => (int) get_comments_number( $post->ID ),
	];
}

/** 본문·첨부·답변 — 공개글이거나 비밀번호를 확인한 뒤에만 붙인다 */
function inquiry_board_rest_body( WP_Post $post ): array {
	$attachments = [];
	foreach ( inquiry_board_get_attachment_ids( (int) $post->ID ) as $att_id ) {
		$url = wp_get_attachment_url( $att_id );
		if ( $url ) {
			$attachments[] = [ 'name' => basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ), 'url' => $url ];
		}
	}
	$answers = [];
	foreach ( get_comments( [
		'post_id' => $post->ID,
		'status'  => 'approve',
		'type'    => 'comment',
		'order'   => 'ASC',
		'orderby' => 'comment_date_gmt',
	] ) as $c ) {
		$admin = (bool) get_comment_meta( (int) $c->comment_ID, '_is_admin_reply', true )
			|| ( (int) $c->user_id > 0 && user_can( (int) $c->user_id, 'moderate_comments' ) );
		$answers[] = [
			'author'  => (string) $c->comment_author,
			'date'    => mysql_to_rfc3339( $c->comment_date ),
			'admin'   => $admin,
			'content' => wpautop( make_clickable( wp_kses_post( (string) $c->comment_content ) ) ),
		];
	}
	return [
		'content'     => wpautop( make_clickable( wp_kses_post( (string) $post->post_content ) ) ),
		'attachments' => $attachments,
		'answers'     => $answers,
	];
}

function inquiry_board_rest_list( WP_REST_Request $req ): WP_REST_Response {
	$per_page = function_exists( 'inquiry_board_get_posts_per_page' ) ? inquiry_board_get_posts_per_page() : 20;
	$args     = [
		'post_type'           => 'inquiry',
		'post_status'         => 'publish',
		'posts_per_page'      => $per_page,
		'paged'               => (int) $req['page'],
		'ignore_sticky_posts' => true,
		'orderby'             => 'date',
		'order'               => 'DESC',
	];
	$cat = sanitize_key( (string) $req['category'] );
	if ( $cat !== '' ) {
		$args['tax_query'] = [ [ 'taxonomy' => 'inquiry_category', 'field' => 'slug', 'terms' => $cat ] ];
	}
	$q     = new WP_Query( $args );
	$no    = (int) $q->found_posts - ( (int) $req['page'] - 1 ) * $per_page; // 목록 화면과 같은 역순 번호
	$items = [];
	foreach ( $q->posts as $post ) {
		$items[] = [ 'no' => $no-- ] + inquiry_board_rest_summary( $post );
	}
	return inquiry_board_rest_response( [
		'items' => $items,
		'total' => (int) $q->found_posts,
		'pages' => (int) $q->max_num_pages,
	] );
}

function inquiry_board_rest_find( string $slug ): ?WP_Post {
	$posts = get_posts( [
		'post_type'      => 'inquiry',
		'post_status'    => 'publish',
		'name'           => sanitize_title( $slug ), // 한글 슬러그는 post_name 에 소문자 퍼센트 인코딩으로 저장돼 있다
		'posts_per_page' => 1,
	] );
	return $posts ? $posts[0] : null;
}

function inquiry_board_rest_detail( WP_REST_Request $req ): WP_REST_Response {
	$post = inquiry_board_rest_find( (string) $req['slug'] );
	if ( ! $post ) {
		return inquiry_board_rest_response( [ 'code' => 'not_found' ], 404 );
	}
	$data = inquiry_board_rest_summary( $post );
	if ( ! $data['protected'] ) {
		$data += inquiry_board_rest_body( $post );
	}
	return inquiry_board_rest_response( $data );
}

function inquiry_board_rest_unlock( WP_REST_Request $req ): WP_REST_Response {
	$post = get_post( (int) $req['id'] );
	if ( ! $post || $post->post_type !== 'inquiry' || $post->post_status !== 'publish' ) {
		return inquiry_board_rest_response( [ 'code' => 'not_found' ], 404 );
	}
	$key   = 'inq_unlock_' . md5( inquiry_board_get_client_ip() );
	$tries = (int) get_transient( $key );
	if ( $tries >= INQUIRY_BOARD_UNLOCK_MAX ) {
		return inquiry_board_rest_response( [ 'code' => 'too_many', 'message' => __( '시도 횟수를 넘었습니다. 10분 뒤 다시 시도해 주세요.', 'wp-qna-board' ) ], 429 );
	}
	if ( $post->post_password !== '' && ! hash_equals( $post->post_password, (string) $req->get_param( 'password' ) ) ) {
		set_transient( $key, $tries + 1, INQUIRY_BOARD_UNLOCK_SPAN );
		return inquiry_board_rest_response( [ 'code' => 'wrong_password', 'message' => __( '비밀번호가 맞지 않습니다.', 'wp-qna-board' ) ], 403 );
	}
	return inquiry_board_rest_response( inquiry_board_rest_summary( $post ) + inquiry_board_rest_body( $post ) );
}

function inquiry_board_rest_form(): WP_REST_Response {
	$opts  = get_option( 'inquiry_board_settings', [] );
	$cats  = get_terms( [ 'taxonomy' => 'inquiry_category', 'hide_empty' => false, 'orderby' => 'term_id' ] );
	$ts    = (string) time();
	return inquiry_board_rest_response( [
		'ts'                  => $ts . '.' . hash_hmac( 'sha256', $ts, inquiry_board_get_secret() ),
		'categories'          => is_wp_error( $cats ) ? [] : array_map( static fn( $t ) => [ 'name' => $t->name, 'slug' => $t->slug ], $cats ),
		'turnstile_sitekey'   => inquiry_board_turnstile_sitekey(),
		'password_required'   => ! empty( $opts['password_required'] ),
		'password_min_length' => max( 1, (int) ( $opts['password_min_length'] ?? 4 ) ),
	] );
}

function inquiry_board_rest_create( WP_REST_Request $req ): WP_REST_Response {
	$post_id = inquiry_board_create_inquiry( $req->get_params() );
	if ( is_wp_error( $post_id ) ) {
		$status = $post_id->get_error_code() === 'inquiry_throttled' ? 429 : 400;
		return inquiry_board_rest_response( [
			'code'    => $post_id->get_error_code(),
			'message' => str_replace( '<br>', "\n", $post_id->get_error_message() ),
		], $status );
	}
	$post = get_post( $post_id );
	return inquiry_board_rest_response( [ 'id' => (int) $post_id, 'slug' => rawurldecode( $post->post_name ) ], 201 );
}
