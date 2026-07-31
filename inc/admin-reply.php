<?php
/**
 * 관리 화면(post.php) 편집화면의 답글 UI — 대화 스레드 + 답글 작성 메타박스.
 *
 * 참조 구현: ivynet-16 / atumdgbktx 의 mu-plugin "Headless Support Tickets"
 * (support_ticket CPT). 화면 구성을 맞추되 inquiry 도메인에 맞게 매핑했다.
 *
 *   support_ticket            →  inquiry
 *   post_author (WP 계정)     →  _inquiry_author_name / _inquiry_author_email 메타 (익명 문의)
 *   _ticket_status 5종 select →  관리자 답변 유무로 계산하는 「답변대기 / 답변완료」 배지 (새 메타 없음)
 *   _ticket_category 메타     →  inquiry_category 택소노미
 *   _ticket_attachments 메타  →  _inquiry_attachments (attachment ID 배열)
 *   user_can('edit_posts')    →  _is_admin_reply 코멘트 메타 (comments.php 가 이미 마킹)
 *
 * 편집 화면 전체가 이미 <form id="post"> 로 감싸져 있어 중첩 form 을 만들 수 없다.
 * 그래서 참조 구현과 동일하게 admin-ajax.php 로 제출한다.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * inquiry 는 Classic Editor 로 연다.
 *
 * 익명 문의라 관리자가 본문을 편집할 일이 거의 없고, 블록 에디터에서는 classic 메타박스가
 * 본문 블록 아래로 밀려 답글 UI 접근성이 떨어진다.
 *
 * ukuhak.com 은 Classic Editor 플러그인이 사이트 전역으로 블록 에디터를 끄고 있어(priority 100
 * `__return_false`) 지금은 이 필터가 없어도 classic 이다. 그 플러그인이 빠지거나 설정이 바뀌어도
 * 이 화면만은 classic 을 유지하도록 명시해 둔다. 되돌리려면 이 필터만 제거하면 된다.
 */
add_filter( 'use_block_editor_for_post_type', static function ( $use, $post_type ) {
	return 'inquiry' === $post_type ? false : $use;
}, 10, 2 );

add_action( 'add_meta_boxes_inquiry', static function (): void {
	add_meta_box(
		'inquiry_admin_reply',
		__( '답글 작성 · 대화 스레드', 'wp-qna-board' ),
		'inquiry_board_render_reply_metabox',
		'inquiry',
		'normal',
		'high'
	);
} );

/**
 * 관리자 답변 여부. comments.php 가 등록 시점에 마킹한 _is_admin_reply 가 정본이고,
 * 마킹 이전에 쌓인 댓글(마이그레이션·수동 등록)은 작성자 권한으로 폴백 판정한다.
 */
function inquiry_board_is_admin_reply( $comment ): bool {
	if ( ! $comment ) {
		return false;
	}
	if ( get_comment_meta( (int) $comment->comment_ID, '_is_admin_reply', true ) ) {
		return true;
	}
	$user_id = (int) $comment->user_id;
	return $user_id > 0 && user_can( $user_id, 'moderate_comments' );
}

/**
 * 글에 딸린 첨부 ID 목록. 폼 업로드는 _inquiry_attachments 메타에 기록되지만,
 * 그 메타가 없던 시기의 글을 위해 attachment 의 post_parent 로 폴백한다.
 */
function inquiry_board_get_attachment_ids( int $post_id ): array {
	$ids = get_post_meta( $post_id, '_inquiry_attachments', true );
	$ids = is_array( $ids ) ? array_values( array_filter( array_map( 'intval', $ids ) ) ) : [];
	if ( $ids ) {
		return $ids;
	}
	return array_map( 'intval', (array) get_posts( [
		'post_type'      => 'attachment',
		'post_parent'    => $post_id,
		'post_status'    => 'inherit',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'orderby'        => 'ID',
		'order'          => 'ASC',
	] ) );
}

function inquiry_board_render_admin_attachments( array $ids ): void {
	if ( ! $ids ) {
		return;
	}
	echo '<div class="ib-att">';
	foreach ( $ids as $id ) {
		$url = wp_get_attachment_url( $id );
		if ( ! $url ) {
			continue;
		}
		$file    = get_attached_file( $id );
		$name    = $file ? basename( $file ) : (string) get_the_title( $id );
		$size_kb = ( $file && file_exists( $file ) ) ? (int) round( filesize( $file ) / 1024 ) : 0;
		$ext     = strtoupper( (string) pathinfo( $name, PATHINFO_EXTENSION ) );

		if ( wp_attachment_is_image( $id ) ) {
			printf(
				'<a class="ib-att-img" href="%s" target="_blank" rel="noopener" title="%s">%s</a>',
				esc_url( $url ),
				esc_attr( $name ),
				wp_get_attachment_image( $id, 'thumbnail', false, [ 'alt' => $name ] )
			);
			continue;
		}
		printf(
			'<a class="ib-att-file" href="%s" target="_blank" rel="noopener"><span class="ib-att-ext">%s</span><span class="ib-att-name">%s</span>%s</a>',
			esc_url( $url ),
			esc_html( $ext ?: 'FILE' ),
			esc_html( $name ),
			$size_kb > 0 ? '<span class="ib-att-size">' . esc_html( (string) $size_kb ) . ' KB</span>' : ''
		);
	}
	echo '</div>';
}

function inquiry_board_render_reply_metabox( $post ): void {
	if ( ! $post || 'inquiry' !== $post->post_type ) {
		return;
	}

	$author_name  = (string) get_post_meta( $post->ID, '_inquiry_author_name', true );
	$author_email = (string) get_post_meta( $post->ID, '_inquiry_author_email', true );
	$display_name = $author_name !== '' ? $author_name : __( '익명', 'wp-qna-board' );

	$terms    = get_the_terms( $post->ID, 'inquiry_category' );
	$cat_name = ( $terms && ! is_wp_error( $terms ) ) ? implode( ', ', wp_list_pluck( $terms, 'name' ) ) : '—';

	$comments = get_comments( [
		'post_id' => $post->ID,
		'status'  => 'approve',
		'order'   => 'ASC',
		'orderby' => 'comment_date_gmt',
	] );

	$admin_replies = 0;
	foreach ( $comments as $c ) {
		if ( inquiry_board_is_admin_reply( $c ) ) {
			$admin_replies++;
		}
	}
	$answered = $admin_replies > 0;

	$notice = isset( $_GET['inquiry_reply_notice'] ) ? sanitize_key( wp_unslash( $_GET['inquiry_reply_notice'] ) ) : '';
	$nonce  = wp_create_nonce( 'inquiry_board_admin_reply_' . $post->ID );
	?>
	<style>
		.ib-summary { display:flex; gap:16px; flex-wrap:wrap; margin-bottom:10px; font-size:13px; color:#334155; }
		.ib-badge { display:inline-block; padding:2px 10px; border-radius:999px; font-size:12px; font-weight:700; }
		.ib-badge-wait { background:#fef3c7; color:#92400e; }
		.ib-badge-done { background:#dcfce7; color:#166534; }
		.ib-thread { display:flex; flex-direction:column; gap:10px; margin:8px 0 18px; }
		.ib-msg { padding:12px 14px; border:1px solid #e2e8f0; border-radius:10px; background:#fff; }
		.ib-msg-admin { background:#eff6ff; border-color:#bfdbfe; }
		.ib-msg-head { display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-bottom:6px; font-size:12px; color:#475569; }
		.ib-msg-head strong { color:#0f172a; font-size:13px; }
		.ib-msg-role { font-weight:600; }
		.ib-msg-body { color:#0f172a; line-height:1.6; font-size:13px; word-break:break-word; }
		.ib-msg-body p:first-child { margin-top:0; }
		.ib-msg-body p:last-child { margin-bottom:0; }
		.ib-att { display:flex; flex-wrap:wrap; gap:8px; margin-top:10px; }
		.ib-att-img { display:inline-block; max-width:140px; border:1px solid #e2e8f0; border-radius:8px; overflow:hidden; line-height:0; }
		.ib-att-img img { display:block; width:140px; height:100px; object-fit:cover; }
		.ib-att-file { display:inline-flex; align-items:center; gap:6px; padding:6px 10px; background:#fff; border:1px solid #e2e8f0; border-radius:6px; font-size:12px; color:#0f172a; text-decoration:none; max-width:420px; }
		.ib-att-ext { padding:2px 6px; background:#fee2e2; color:#b91c1c; font-weight:700; font-size:11px; border-radius:4px; }
		.ib-att-name { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
		.ib-att-size { color:#94a3b8; font-size:11px; }
		.ib-reply-row { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-top:10px; }
		.ib-notice { padding:8px 12px; border-radius:8px; font-size:13px; margin-bottom:12px; }
		.ib-notice-success { background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; }
		.ib-hint { color:#94a3b8; font-size:12px; margin-top:6px; }
		.ib-empty { color:#94a3b8; font-size:13px; }
	</style>

	<?php if ( 'ok' === $notice ) : ?>
		<div class="ib-notice ib-notice-success"><?php esc_html_e( '답글이 등록되었습니다.', 'wp-qna-board' ); ?></div>
	<?php endif; ?>

	<div class="ib-summary">
		<span><strong><?php esc_html_e( '작성자:', 'wp-qna-board' ); ?></strong> <?php echo esc_html( $display_name ); ?></span>
		<?php if ( $author_email !== '' ) : ?>
			<span><strong><?php esc_html_e( '이메일:', 'wp-qna-board' ); ?></strong> <?php echo esc_html( $author_email ); ?></span>
		<?php endif; ?>
		<span><strong><?php esc_html_e( '카테고리:', 'wp-qna-board' ); ?></strong> <?php echo esc_html( $cat_name ); ?></span>
		<span><strong><?php esc_html_e( '상태:', 'wp-qna-board' ); ?></strong>
			<span class="ib-badge <?php echo $answered ? 'ib-badge-done' : 'ib-badge-wait'; ?>">
				<?php echo $answered ? esc_html__( '답변완료', 'wp-qna-board' ) : esc_html__( '답변대기', 'wp-qna-board' ); ?>
			</span>
		</span>
		<span><strong><?php esc_html_e( '답변 수:', 'wp-qna-board' ); ?></strong> <?php echo (int) $admin_replies; ?> / <?php echo esc_html( sprintf( __( '전체 %d', 'wp-qna-board' ), count( $comments ) ) ); ?></span>
	</div>

	<div class="ib-thread">
		<div class="ib-msg">
			<div class="ib-msg-head">
				<strong><?php echo esc_html( $display_name ); ?></strong>
				<span><?php echo esc_html( mysql2date( 'Y-m-d H:i', $post->post_date, false ) ); ?></span>
				<span class="ib-msg-role" style="color:#3b82f6;">· <?php esc_html_e( '문의 원문', 'wp-qna-board' ); ?></span>
			</div>
			<div class="ib-msg-body"><?php echo wpautop( wp_kses_post( (string) $post->post_content ) ); ?></div>
			<?php inquiry_board_render_admin_attachments( inquiry_board_get_attachment_ids( (int) $post->ID ) ); ?>
		</div>

		<?php if ( ! $comments ) : ?>
			<p class="ib-empty"><?php esc_html_e( '아직 답글이 없습니다.', 'wp-qna-board' ); ?></p>
		<?php endif; ?>

		<?php foreach ( $comments as $c ) :
			$is_admin_msg = inquiry_board_is_admin_reply( $c );
			?>
			<div class="ib-msg <?php echo $is_admin_msg ? 'ib-msg-admin' : ''; ?>">
				<div class="ib-msg-head">
					<strong><?php echo esc_html( $c->comment_author ); ?></strong>
					<span><?php echo esc_html( mysql2date( 'Y-m-d H:i', $c->comment_date, false ) ); ?></span>
					<span class="ib-msg-role" style="<?php echo $is_admin_msg ? 'color:#1d4ed8;' : 'color:#059669;'; ?>">
						· <?php echo $is_admin_msg ? esc_html__( '관리자 답변', 'wp-qna-board' ) : esc_html__( '작성자 답글', 'wp-qna-board' ); ?>
					</span>
				</div>
				<div class="ib-msg-body"><?php echo wpautop( wp_kses_post( (string) $c->comment_content ) ); ?></div>
			</div>
		<?php endforeach; ?>
	</div>

	<div id="ib-reply-wrap">
		<input type="hidden" id="ib_reply_nonce" value="<?php echo esc_attr( $nonce ); ?>">
		<input type="hidden" id="ib_reply_post_id" value="<?php echo (int) $post->ID; ?>">

		<label for="ib_reply_content" style="font-weight:600;"><?php esc_html_e( '답변 내용', 'wp-qna-board' ); ?></label>
		<textarea id="ib_reply_content" rows="6" style="width:100%;margin-top:6px;"
			placeholder="<?php esc_attr_e( '문의자에게 전달할 답변을 입력하세요. 줄바꿈은 그대로 유지됩니다.', 'wp-qna-board' ); ?>"></textarea>
		<p class="ib-hint"><?php esc_html_e( '등록하면 관리자 답변으로 표시되고 문의 상세 페이지에 즉시 노출됩니다. 별도의 알림 메일은 발송하지 않습니다.', 'wp-qna-board' ); ?></p>

		<div class="ib-reply-row">
			<button type="button" id="ib-reply-submit" class="button button-primary"><?php esc_html_e( '답변 등록', 'wp-qna-board' ); ?></button>
			<span id="ib-reply-msg" style="color:#b91c1c;font-size:12px;"></span>
		</div>
	</div>

	<script>
	(function () {
		var btn = document.getElementById('ib-reply-submit');
		if (!btn) { return; }
		btn.addEventListener('click', function () {
			var contentEl = document.getElementById('ib_reply_content');
			var msgEl     = document.getElementById('ib-reply-msg');
			var content   = (contentEl.value || '').trim();
			msgEl.textContent = '';

			if (!content) {
				msgEl.textContent = <?php echo wp_json_encode( __( '내용을 입력해 주세요.', 'wp-qna-board' ) ); ?>;
				contentEl.focus();
				return;
			}

			var oldLabel = btn.textContent;
			btn.disabled = true;
			btn.textContent = <?php echo wp_json_encode( __( '등록 중...', 'wp-qna-board' ) ); ?>;

			var fd = new FormData();
			fd.append('action', 'inquiry_board_admin_reply');
			fd.append('nonce', document.getElementById('ib_reply_nonce').value);
			fd.append('post_id', document.getElementById('ib_reply_post_id').value);
			fd.append('content', content);

			fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
				.then(function (res) { return res.json().then(function (j) { return { ok: res.ok, json: j }; }); })
				.then(function (r) {
					if (!r.ok || !r.json || !r.json.success) {
						var m = (r.json && r.json.data && r.json.data.message) || <?php echo wp_json_encode( __( '등록에 실패했습니다.', 'wp-qna-board' ) ); ?>;
						msgEl.textContent = m;
						btn.disabled = false;
						btn.textContent = oldLabel;
						return;
					}
					var url = new URL(window.location.href);
					url.searchParams.set('inquiry_reply_notice', 'ok');
					window.location.assign(url.toString());
				})
				.catch(function (e) {
					msgEl.textContent = (e && e.message) ? e.message : 'network error';
					btn.disabled = false;
					btn.textContent = oldLabel;
				});
		});
	})();
	</script>
	<?php
}

/**
 * admin-ajax.php?action=inquiry_board_admin_reply
 *
 * wp_new_comment() 대신 wp_insert_comment() 를 쓴다 — 전자는 flood/duplicate 검사를 거쳐
 * 관리자가 연속으로 답변할 때 거부될 수 있고, 승인 상태도 필터 체인이 덮어쓴다.
 * 대신 comment_post 훅이 돌지 않으므로 _is_admin_reply 는 여기서 직접 마킹한다.
 */
add_action( 'wp_ajax_inquiry_board_admin_reply', 'inquiry_board_ajax_admin_reply' );

function inquiry_board_ajax_admin_reply(): void {
	$post_id = absint( $_POST['post_id'] ?? 0 );
	$nonce   = isset( $_POST['nonce'] ) ? (string) wp_unslash( $_POST['nonce'] ) : '';

	if ( ! $post_id || ! wp_verify_nonce( $nonce, 'inquiry_board_admin_reply_' . $post_id ) ) {
		wp_send_json_error( [ 'message' => __( '요청이 만료되었거나 인증에 실패했습니다. 새로고침 후 다시 시도해 주세요.', 'wp-qna-board' ) ], 403 );
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		wp_send_json_error( [ 'message' => __( '권한이 없습니다.', 'wp-qna-board' ) ], 403 );
	}

	$post = get_post( $post_id );
	if ( ! $post || 'inquiry' !== $post->post_type ) {
		wp_send_json_error( [ 'message' => __( '문의를 찾을 수 없습니다.', 'wp-qna-board' ) ], 404 );
	}

	$content = wp_kses_post( (string) wp_unslash( $_POST['content'] ?? '' ) );
	if ( trim( wp_strip_all_tags( $content ) ) === '' ) {
		wp_send_json_error( [ 'message' => __( '내용을 입력해 주세요.', 'wp-qna-board' ) ], 400 );
	}

	$user       = wp_get_current_user();
	$comment_id = wp_insert_comment( [
		'comment_post_ID'      => $post_id,
		'comment_author'       => $user->display_name ?: $user->user_login,
		'comment_author_email' => (string) $user->user_email,
		'comment_author_IP'    => inquiry_board_get_client_ip(),
		'comment_content'      => $content,
		'comment_type'         => 'comment',
		'comment_parent'       => 0,
		'user_id'              => (int) $user->ID,
		'comment_approved'     => 1,
		'comment_agent'        => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( (string) $_SERVER['HTTP_USER_AGENT'], 0, 255 ) : '',
	] );

	if ( ! $comment_id ) {
		wp_send_json_error( [ 'message' => __( '답글 저장에 실패했습니다.', 'wp-qna-board' ) ], 500 );
	}

	add_comment_meta( (int) $comment_id, '_is_admin_reply', 1, true );

	wp_send_json_success( [ 'comment_id' => (int) $comment_id ] );
}
