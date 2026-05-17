<?php
/**
 * 단일 inquiry 페이지의 추가 UI 를 the_content 필터로 본문 영역에 주입.
 *
 *  - 비번 보호 + 미인증: WP 코어가 the_password_form 으로 대체 출력(이 필터 미실행).
 *  - 본인 세션 + ?inquiry_action=edit: 본문 자리를 수정 폼으로 교체.
 *  - 그 외: 본문 끝에 첨부 파일 목록 + (본인이면) 수정 버튼 append.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * inquiry 글의 작성자 표시명을 익명 작성자 이름(_inquiry_author_name) 으로 치환.
 * post_author 자체는 administrator user id 가 박혀 있지만 화면 표시는 익명 이름으로.
 */
add_filter( 'the_author',         'inquiry_board_filter_author_name' );
add_filter( 'get_the_author',     'inquiry_board_filter_author_name' );
add_filter( 'the_author_meta',    'inquiry_board_filter_author_meta', 10, 3 );

function inquiry_board_filter_author_name( $name ) {
	$pid = get_the_ID();
	if ( ! $pid ) {
		return $name;
	}
	$post = get_post( $pid );
	if ( ! $post || $post->post_type !== 'inquiry' ) {
		return $name;
	}
	$meta = (string) get_post_meta( $pid, '_inquiry_author_name', true );
	return $meta !== '' ? $meta : $name;
}

function inquiry_board_filter_author_meta( $value, $field, $user_id ) {
	if ( ! in_array( $field, [ 'display_name', 'nickname', 'first_name', 'user_nicename' ], true ) ) {
		return $value;
	}
	$pid = get_the_ID();
	if ( ! $pid ) {
		return $value;
	}
	$post = get_post( $pid );
	if ( ! $post || $post->post_type !== 'inquiry' ) {
		return $value;
	}
	$meta = (string) get_post_meta( $pid, '_inquiry_author_name', true );
	return $meta !== '' ? $meta : $value;
}

add_filter( 'the_content', 'inquiry_board_decorate_content', 20 );

function inquiry_board_decorate_content( string $content ): string {
	if ( is_admin() || ! is_singular( 'inquiry' ) || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}
	$post_id = (int) get_the_ID();
	if ( $post_id <= 0 ) {
		return $content;
	}

	// 비번 보호 + 미인증 상태에서는 $content 가 이미 the_password_form 결과로 대체되어 있다.
	// 그 뒤에 첨부 목록·수정 버튼을 append 하면 비번 입력 화면에서 첨부 URL 이 노출되므로 차단.
	if ( post_password_required( $post_id ) ) {
		return $content;
	}

	$is_owner  = inquiry_board_is_owner( $post_id );
	$want_edit = isset( $_GET['inquiry_action'] ) && $_GET['inquiry_action'] === 'edit';

	// 본인 + 편집 모드: 본문 자리를 수정 폼으로 교체.
	if ( $is_owner && $want_edit ) {
		return inquiry_board_render_edit_form( $post_id );
	}

	// 본문 상단: 목록으로 돌아가는 링크. 쇼트코드 [inquiry_form] 이 박힌 페이지로 이동.
	$prefix = inquiry_board_render_back_to_list();

	$suffix = '';

	// 첨부 파일 목록
	$atts = (array) get_post_meta( $post_id, '_inquiry_attachments', true );
	if ( $atts ) {
		$items = [];
		foreach ( $atts as $att_id ) {
			$url = wp_get_attachment_url( (int) $att_id );
			if ( ! $url ) {
				continue;
			}
			$name = basename( (string) parse_url( $url, PHP_URL_PATH ) );
			$items[] = '<li><a href="' . esc_url( $url ) . '" rel="noopener">' . esc_html( $name ) . '</a></li>';
		}
		if ( $items ) {
			$suffix .= '<ul class="inquiry-attachments">' . implode( '', $items ) . '</ul>';
		}
	}

	// 본인 액션 (수정 버튼)
	if ( $is_owner ) {
		$edit_url = add_query_arg( 'inquiry_action', 'edit', get_permalink( $post_id ) );
		$suffix  .= '<p class="inquiry-owner-actions"><a class="button" href="' . esc_url( $edit_url ) . '">' . esc_html__( '수정', 'wp-qna-board' ) . '</a></p>';
	}

	// 댓글 스레드(답변) — 테마 single.php 의 comments_template 호출 여부와 무관하게
	// 플러그인이 자체적으로 출력하여 inquiry 글에서 항상 답변이 보이도록 한다.
	$suffix .= inquiry_board_render_comment_thread( $post_id, $is_owner );

	// 본문 폭을 좁히는 래퍼 — 테마와 독립적으로 inquiry 단일 페이지를 75% 폭으로 묶는다.
	return '<div class="inquiry-single-wrap">' . $prefix . $content . $suffix . '</div>';
}

/**
 * 단일 inquiry 페이지 상단에 노출할 "목록으로" 링크.
 * 쇼트코드 [inquiry_form] 이 박힌 페이지로 이동한다. 페이지 위치가 변경되어도
 * 자동 탐지되도록 inquiry_board_get_list_page_url() 헬퍼를 사용.
 */
function inquiry_board_render_back_to_list(): string {
	$url = inquiry_board_get_list_page_url();
	if ( $url === '' ) {
		return '';
	}
	return sprintf(
		'<p class="inquiry-back-to-list"><a class="inquiry-back-link" href="%s"><span aria-hidden="true">&larr;</span> %s</a></p>',
		esc_url( $url ),
		esc_html__( '목록으로', 'wp-qna-board' )
	);
}

/**
 * [inquiry_form] 쇼트코드가 박힌 가장 빠른 ID 의 publish 페이지 URL 을 반환.
 * 6시간 transient 로 캐시하고, 페이지 저장/삭제 훅에서 무효화한다.
 *
 *  - 폴백: 옵션 'page_for_inquiry_board' (관리 화면에서 명시 지정 가능)
 *  - 모두 실패하면 빈 문자열 반환 (버튼 자체 미노출).
 */
function inquiry_board_get_list_page_url(): string {
	$override = (int) get_option( 'inquiry_board_list_page_id', 0 );
	if ( $override > 0 ) {
		$url = (string) get_permalink( $override );
		if ( $url ) {
			return $url;
		}
	}

	$cached = get_transient( 'inquiry_board_list_page_url' );
	if ( is_string( $cached ) ) {
		return $cached;
	}

	global $wpdb;
	$page_id = (int) $wpdb->get_var(
		"SELECT ID FROM {$wpdb->posts}
		 WHERE post_type = 'page' AND post_status = 'publish'
		   AND post_content LIKE '%[inquiry_form%'
		 ORDER BY ID ASC
		 LIMIT 1"
	);

	$url = $page_id ? (string) get_permalink( $page_id ) : '';
	set_transient( 'inquiry_board_list_page_url', $url, 6 * HOUR_IN_SECONDS );
	return $url;
}

// 페이지 저장/삭제 시 자동 탐지 캐시 무효화.
add_action( 'save_post_page', static function (): void {
	delete_transient( 'inquiry_board_list_page_url' );
} );
add_action( 'delete_post', static function ( $post_id ): void {
	if ( get_post_type( (int) $post_id ) === 'page' ) {
		delete_transient( 'inquiry_board_list_page_url' );
	}
} );

/**
 * inquiry 단일 페이지용 댓글 스레드(메시지 버블) + 본인 답글 폼 렌더링.
 *
 * 마이그레이션된 댓글은 wp_commentmeta._is_admin_reply 메타로 관리자 답변 여부가
 * 마킹되어 있다. 메타가 없으면 user_id 의 권한으로 폴백 판정한다.
 */
function inquiry_board_render_comment_thread( int $post_id, bool $is_owner ): string {
	if ( $post_id <= 0 ) {
		return '';
	}

	$comments = get_comments( [
		'post_id' => $post_id,
		'status'  => 'approve',
		'order'   => 'ASC',
		'orderby' => 'comment_date_gmt',
		'type'    => 'comment',
	] );

	$total            = is_array( $comments ) ? count( $comments ) : 0;
	$reply_nonce_html = $is_owner ? wp_nonce_field( 'inquiry_board_reply', 'inquiry_board_reply_nonce', true, false ) : '';
	$admin_user       = current_user_can( 'moderate_comments' );
	$date_fmt         = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

	ob_start();
	?>
	<section id="inquiry-thread" class="inquiry-thread" aria-label="<?php esc_attr_e( '답변 스레드', 'wp-qna-board' ); ?>">
		<h2 class="inquiry-thread-heading">
			<?php esc_html_e( '답변', 'wp-qna-board' ); ?>
			<span class="inquiry-thread-count"><?php echo (int) $total; ?></span>
		</h2>

		<?php if ( $total === 0 ) : ?>
			<p class="inquiry-thread-empty"><?php esc_html_e( '아직 답변이 등록되지 않았습니다.', 'wp-qna-board' ); ?></p>
		<?php else : ?>
			<ol class="inquiry-thread-list">
			<?php foreach ( $comments as $c ) :
				$cid            = (int) $c->comment_ID;
				$is_admin_reply = (bool) get_comment_meta( $cid, '_is_admin_reply', true );
				if ( ! $is_admin_reply && (int) $c->user_id > 0 ) {
					// 마이그레이션 누락분 폴백: 작성자가 관리자 권한 사용자면 관리자 답변으로 간주.
					$is_admin_reply = (bool) user_can( (int) $c->user_id, 'moderate_comments' );
				}
				$role_label = $is_admin_reply
					? __( '관리자 답변', 'wp-qna-board' )
					: __( '답글', 'wp-qna-board' );
				$role_cls   = $is_admin_reply ? 'inquiry-msg-role-admin' : 'inquiry-msg-role-owner';
				$bubble_cls = $is_admin_reply ? 'inquiry-msg inquiry-msg-admin' : 'inquiry-msg';
				?>
				<li>
					<article id="inquiry-comment-<?php echo esc_attr( (string) $cid ); ?>" class="<?php echo esc_attr( $bubble_cls ); ?>">
						<header class="inquiry-msg-head">
							<strong><?php echo esc_html( (string) $c->comment_author ); ?></strong>
							<time datetime="<?php echo esc_attr( mysql2date( DATE_W3C, $c->comment_date_gmt, false ) ); ?>"><?php echo esc_html( mysql2date( $date_fmt, $c->comment_date, true ) ); ?></time>
							<span class="inquiry-msg-role <?php echo esc_attr( $role_cls ); ?>"><?php echo esc_html( $role_label ); ?></span>
						</header>
						<div class="inquiry-msg-body">
							<?php
							// 3rd party `the_content` 훅 Fatal 회피 위해 wpautop(wp_kses_post()) 사용.
							echo wpautop( wp_kses_post( (string) $c->comment_content ) );
							?>
						</div>
					</article>
				</li>
			<?php endforeach; ?>
			</ol>
		<?php endif; ?>

		<?php if ( $is_owner ) : ?>
			<form class="inquiry-reply-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="inquiry_reply" />
				<input type="hidden" name="inquiry_post_id" value="<?php echo (int) $post_id; ?>" />
				<?php echo $reply_nonce_html; // nonce 필드(이미 escape 처리됨) ?>
				<label for="inquiry-reply-content"><?php echo $admin_user ? esc_html__( '관리자 답변 작성', 'wp-qna-board' ) : esc_html__( '추가 답글 작성', 'wp-qna-board' ); ?></label>
				<textarea id="inquiry-reply-content" name="inquiry_reply_content" rows="5" required></textarea>
				<button type="submit" class="inquiry-reply-submit"><?php esc_html_e( '답글 등록', 'wp-qna-board' ); ?></button>
			</form>
		<?php endif; ?>
	</section>
	<?php
	return (string) ob_get_clean();
}
