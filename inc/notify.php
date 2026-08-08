<?php
/**
 * 새 문의 알림 메일 — 수신자 파싱 · 발송 · HTML 템플릿.
 *
 * 발송 지점은 transition_post_status 다. 프론트엔드 글쓰기 폼뿐 아니라 관리 화면 직접 작성 ·
 * WP-CLI · REST 등 모든 등록 경로가 이 훅을 지나므로 한 곳에서 전부 커버된다.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 콤마·세미콜론·공백 구분 문자열 → 유효 이메일 배열 (중복 제거).
 */
function inquiry_board_parse_email_list( string $raw ): array {
	$parts = preg_split( '/[,;\s]+/', $raw, -1, PREG_SPLIT_NO_EMPTY ) ?: [];
	return array_values( array_unique( array_filter( array_map( 'sanitize_email', $parts ), 'is_email' ) ) );
}

/**
 * 알림 수신자 목록. 미설정이면 사이트 admin_email 로 폴백.
 */
function inquiry_board_notify_recipients( array $opts ): array {
	$list = inquiry_board_parse_email_list( (string) ( $opts['notify_email'] ?? '' ) );
	if ( $list ) {
		return $list;
	}
	return inquiry_board_parse_email_list( (string) get_option( 'admin_email' ) );
}

/**
 * 메일에 쓸 문의 요약 데이터.
 */
function inquiry_board_notify_data( int $post_id ): array {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return [];
	}

	$terms      = get_the_terms( $post_id, 'inquiry_category' );
	$categories = ( $terms && ! is_wp_error( $terms ) ) ? wp_list_pluck( $terms, 'name' ) : [];

	$plain   = trim( wp_strip_all_tags( (string) $post->post_content ) );
	$limit   = 600;
	$trimmed = mb_strlen( $plain ) > $limit;

	$attachments = [];
	foreach ( inquiry_board_get_attachment_ids( $post_id ) as $att_id ) {
		$file = get_attached_file( $att_id );
		$attachments[] = [
			'name' => $file ? basename( $file ) : (string) get_the_title( $att_id ),
			'url'  => (string) wp_get_attachment_url( $att_id ),
			'kb'   => ( $file && file_exists( $file ) ) ? (int) round( filesize( $file ) / 1024 ) : 0,
		];
	}

	return [
		// get_the_title() 은 비밀글에 "보호된 글: " 접두어를 붙인다(protected_title_format).
		// 관리자에게 가는 메일이므로 원제목을 쓴다.
		'title'      => (string) $post->post_title !== '' ? (string) $post->post_title : __( '(제목 없음)', 'wp-qna-board' ),
		'author'     => (string) get_post_meta( $post_id, '_inquiry_author_name', true ),
		'email'      => (string) get_post_meta( $post_id, '_inquiry_author_email', true ),
		'phone'      => (string) get_post_meta( $post_id, '_inquiry_author_phone', true ),
		'marketing'  => '1' === (string) get_post_meta( $post_id, '_inquiry_marketing_consent', true ),
		'categories' => $categories,
		'date'       => mysql2date( 'Y-m-d H:i', $post->post_date, false ),
		'secret'     => '' !== (string) $post->post_password,
		'excerpt'    => $trimmed ? mb_substr( $plain, 0, $limit ) . ' …' : $plain,
		'trimmed'    => $trimmed,
		'attach'     => $attachments,
		'edit_url'   => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
		'view_url'   => (string) get_permalink( $post_id ),
		'site'       => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
		'home_url'   => home_url( '/' ),
	];
}

function inquiry_board_notify_subject( array $d ): string {
	$cat = $d['categories'] ? ' · ' . $d['categories'][0] : '';
	return sprintf( '[%s] 새 문의%s — %s', $d['site'], $cat, $d['title'] );
}

/**
 * HTML 메일 본문.
 *
 * 메일 클라이언트 호환을 위해 table 레이아웃 + 인라인 스타일만 쓴다(외부 CSS·flex·grid 불가).
 * Outlook 은 border-radius 를 무시하므로 각진 카드로 자연스럽게 폴백된다.
 * 색은 플러그인 디자인 토큰과 맞춘다 — indigo #533afd 액센트, deep ink #0f172a CTA.
 */
function inquiry_board_notify_html( array $d ): string {
	$font   = "-apple-system,BlinkMacSystemFont,'Segoe UI','Apple SD Gothic Neo','Malgun Gothic','Noto Sans KR',sans-serif";
	$ink    = '#0d253d';
	$muted  = '#64748b';
	$line   = '#e3e8ee';
	$indigo = '#533afd';

	// 메타 행
	$rows = [
		__( '작성자', 'wp-qna-board' ) => esc_html( $d['author'] !== '' ? $d['author'] : __( '익명', 'wp-qna-board' ) ),
		__( '이메일', 'wp-qna-board' ) => $d['email'] !== ''
			? '<a href="mailto:' . esc_attr( $d['email'] ) . '" style="color:' . $indigo . ';text-decoration:none;">' . esc_html( $d['email'] ) . '</a>'
			: '<span style="color:' . $muted . ';">' . esc_html__( '미입력', 'wp-qna-board' ) . '</span>',
		// 연락처는 v0.10.0 부터 필수 — 그 이전 글에는 메타가 없으므로 "미입력" 으로 폴백한다.
		__( '연락처', 'wp-qna-board' ) => $d['phone'] !== ''
			? '<a href="tel:' . esc_attr( preg_replace( '/[^\d+]+/', '', $d['phone'] ) ) . '" style="color:' . $indigo . ';text-decoration:none;">' . esc_html( $d['phone'] ) . '</a>'
			: '<span style="color:' . $muted . ';">' . esc_html__( '미입력', 'wp-qna-board' ) . '</span>',
		__( '카테고리', 'wp-qna-board' ) => esc_html( $d['categories'] ? implode( ', ', $d['categories'] ) : '—' ),
		// 광고성 정보 발송 대상 판단 근거 — 동의한 건에만 마케팅 발송이 가능하다.
		__( '마케팅 수신', 'wp-qna-board' ) => $d['marketing']
			? '<span style="color:#166534;font-weight:600;">' . esc_html__( '동의', 'wp-qna-board' ) . '</span>'
			: '<span style="color:' . $muted . ';">' . esc_html__( '미동의', 'wp-qna-board' ) . '</span>',
		__( '등록일시', 'wp-qna-board' ) => esc_html( $d['date'] ),
	];

	$meta_html = '';
	foreach ( $rows as $label => $value ) {
		$meta_html .= sprintf(
			'<tr>
				<td style="padding:7px 0;width:88px;vertical-align:top;font-size:13px;color:%1$s;">%2$s</td>
				<td style="padding:7px 0;font-size:14px;color:%3$s;">%4$s</td>
			</tr>',
			$muted,
			esc_html( $label ),
			$ink,
			$value
		);
	}

	// 첨부
	$attach_html = '';
	if ( $d['attach'] ) {
		$items = '';
		foreach ( $d['attach'] as $a ) {
			$size   = $a['kb'] > 0 ? ' <span style="color:#94a3b8;font-size:12px;">' . esc_html( (string) $a['kb'] ) . ' KB</span>' : '';
			$items .= sprintf(
				'<div style="padding:4px 0;font-size:14px;"><a href="%1$s" style="color:%2$s;text-decoration:none;">%3$s</a>%4$s</div>',
				esc_url( $a['url'] ),
				$indigo,
				esc_html( $a['name'] ),
				$size
			);
		}
		$attach_html = sprintf(
			'<tr>
				<td style="padding:7px 0;width:88px;vertical-align:top;font-size:13px;color:%1$s;">%2$s</td>
				<td style="padding:7px 0;">%3$s</td>
			</tr>',
			$muted,
			esc_html__( '첨부', 'wp-qna-board' ),
			$items
		);
	}

	// 배지
	$badges = '';
	foreach ( $d['categories'] as $cat ) {
		$badges .= '<span style="display:inline-block;padding:3px 10px;margin:0 6px 6px 0;border-radius:999px;background:#eef0ff;color:' . $indigo . ';font-size:12px;font-weight:600;">' . esc_html( $cat ) . '</span>';
	}
	if ( $d['secret'] ) {
		$badges .= '<span style="display:inline-block;padding:3px 10px;margin:0 6px 6px 0;border-radius:999px;background:#ffeef4;color:#ea2261;font-size:12px;font-weight:600;">' . esc_html__( '비밀글', 'wp-qna-board' ) . '</span>';
	}

	$excerpt = nl2br( esc_html( $d['excerpt'] !== '' ? $d['excerpt'] : __( '(본문 없음)', 'wp-qna-board' ) ) );
	$more    = $d['trimmed']
		? '<div style="margin-top:10px;font-size:13px;color:' . $muted . ';">' . esc_html__( '본문이 길어 일부만 표시했습니다. 전체 내용은 관리 화면에서 확인하세요.', 'wp-qna-board' ) . '</div>'
		: '';

	return sprintf(
		'<!DOCTYPE html>
<html lang="ko"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"><title>%1$s</title></head>
<body style="margin:0;padding:0;background:#f4f6f9;">
<table role="presentation" width="100%%" cellpadding="0" cellspacing="0" style="background:#f4f6f9;padding:28px 12px;">
<tr><td align="center">
	<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%%;max-width:600px;background:#ffffff;border:1px solid %2$s;border-radius:14px;overflow:hidden;font-family:%3$s;">

		<tr><td style="height:4px;background:%4$s;font-size:0;line-height:0;">&nbsp;</td></tr>

		<tr><td style="padding:26px 28px 0 28px;">
			<div style="font-size:11px;font-weight:700;letter-spacing:1.4px;text-transform:uppercase;color:%4$s;">%5$s</div>
			<h1 style="margin:10px 0 12px 0;font-size:21px;line-height:1.4;font-weight:600;color:%6$s;">%7$s</h1>
			<div>%8$s</div>
		</td></tr>

		<tr><td style="padding:6px 28px 0 28px;">
			<table role="presentation" width="100%%" cellpadding="0" cellspacing="0" style="border-top:1px solid %2$s;">%9$s%10$s</table>
		</td></tr>

		<tr><td style="padding:18px 28px 0 28px;">
			<div style="font-size:11px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:%11$s;margin-bottom:8px;">%12$s</div>
			<div style="padding:16px 18px;background:#f8fafc;border:1px solid %2$s;border-radius:10px;font-size:14px;line-height:1.75;color:%6$s;">%13$s</div>
			%14$s
		</td></tr>

		<tr><td style="padding:22px 28px 26px 28px;">
			<table role="presentation" cellpadding="0" cellspacing="0"><tr>
				<td style="border-radius:8px;background:#0f172a;">
					<a href="%15$s" style="display:inline-block;padding:13px 26px;font-size:14px;font-weight:600;color:#ffffff;text-decoration:none;">%16$s</a>
				</td>
				<td style="padding-left:14px;">
					<a href="%17$s" style="font-size:14px;color:%4$s;text-decoration:none;">%18$s</a>
				</td>
			</tr></table>
		</td></tr>

		<tr><td style="padding:16px 28px 22px 28px;border-top:1px solid %2$s;">
			<div style="font-size:12px;line-height:1.7;color:%11$s;">
				%19$s<br>
				<a href="%20$s" style="color:%11$s;text-decoration:underline;">%21$s</a>
			</div>
		</td></tr>

	</table>
</td></tr>
</table>
</body></html>',
		/*  1 */ esc_html( $d['title'] ),
		/*  2 */ $line,
		/*  3 */ $font,
		/*  4 */ $indigo,
		/*  5 */ esc_html__( '새 문의 접수', 'wp-qna-board' ),
		/*  6 */ $ink,
		/*  7 */ esc_html( $d['title'] ),
		/*  8 */ $badges,
		/*  9 */ $meta_html,
		/* 10 */ $attach_html,
		/* 11 */ $muted,
		/* 12 */ esc_html__( '문의 내용', 'wp-qna-board' ),
		/* 13 */ $excerpt,
		/* 14 */ $more,
		/* 15 */ esc_url( $d['edit_url'] ),
		/* 16 */ esc_html__( '관리 화면에서 답변하기', 'wp-qna-board' ),
		/* 17 */ esc_url( $d['view_url'] ),
		/* 18 */ esc_html__( '문의 페이지 보기', 'wp-qna-board' ),
		/* 19 */ esc_html__( '이 메일은 Q&A 게시판에 새 문의가 등록되어 자동 발송되었습니다. 수신자는 설정 → Q&A 게시판 → 일반설정에서 변경할 수 있습니다.', 'wp-qna-board' ),
		/* 20 */ esc_url( $d['home_url'] ),
		/* 21 */ esc_html( $d['site'] )
	);
}

function inquiry_board_notify_admin( int $post_id, array $opts ): void {
	$to = inquiry_board_notify_recipients( $opts );
	if ( ! $to ) {
		return;
	}
	$d = inquiry_board_notify_data( $post_id );
	if ( ! $d ) {
		return;
	}

	$headers = [ 'Content-Type: text/html; charset=UTF-8' ];
	// 문의자 주소가 있으면 관리자가 메일에서 바로 회신할 수 있게 한다.
	if ( $d['email'] !== '' && is_email( $d['email'] ) ) {
		$headers[] = sprintf( 'Reply-To: %s <%s>', $d['author'] !== '' ? $d['author'] : $d['email'], $d['email'] );
	}

	wp_mail( $to, inquiry_board_notify_subject( $d ), inquiry_board_notify_html( $d ), $headers );
}

/**
 * inquiry 글이 처음 publish 될 때 알림 메일을 보낸다.
 *
 * `_inquiry_notified` 메타로 1회만 발송하므로 수정 저장 · 휴지통 복구 시에는 재발송되지 않는다.
 * 발송은 shutdown 으로 미룬다 — 폼 경로는 wp_insert_post 이후에 작성자명·첨부 메타를 저장하므로
 * 전이 시점에 즉시 보내면 작성자 이름과 첨부가 비어 나간다.
 */
function inquiry_board_notify_on_publish( string $new_status, string $old_status, WP_Post $post ): void {
	if ( 'publish' !== $new_status || 'inquiry' !== $post->post_type ) {
		return;
	}
	if ( get_post_meta( $post->ID, '_inquiry_notified', true ) ) {
		return;
	}
	update_post_meta( $post->ID, '_inquiry_notified', 1 );

	$post_id = (int) $post->ID;
	add_action( 'shutdown', static function () use ( $post_id ): void {
		inquiry_board_notify_admin( $post_id, inquiry_board_get_settings() );
	} );
}
add_action( 'transition_post_status', 'inquiry_board_notify_on_publish', 10, 3 );
