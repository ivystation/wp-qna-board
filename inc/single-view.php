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

add_filter( 'the_content', 'inquiry_board_decorate_content', 20 );

function inquiry_board_decorate_content( string $content ): string {
	if ( is_admin() || ! is_singular( 'inquiry' ) || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}
	$post_id = (int) get_the_ID();
	if ( $post_id <= 0 ) {
		return $content;
	}

	$is_owner  = inquiry_board_is_owner( $post_id );
	$want_edit = isset( $_GET['inquiry_action'] ) && $_GET['inquiry_action'] === 'edit';

	// 본인 + 편집 모드: 본문 자리를 수정 폼으로 교체.
	if ( $is_owner && $want_edit ) {
		return inquiry_board_render_edit_form( $post_id );
	}

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

	return $content . $suffix;
}
