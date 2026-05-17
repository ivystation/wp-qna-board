<?php
/**
 * inquiry CPT 와 inquiry_category 택소노미 등록 + 카테고리 시드.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function inquiry_board_register_cpt(): void {
	$labels = [
		'name'               => __( '문의', 'wp-qna-board' ),
		'singular_name'      => __( '문의', 'wp-qna-board' ),
		'menu_name'          => __( 'Q&A게시판', 'wp-qna-board' ),
		'add_new'            => __( '새 문의 등록', 'wp-qna-board' ),
		'add_new_item'       => __( '새 문의 작성', 'wp-qna-board' ),
		'edit_item'          => __( '문의 수정', 'wp-qna-board' ),
		'new_item'           => __( '새 문의', 'wp-qna-board' ),
		'view_item'          => __( '문의 보기', 'wp-qna-board' ),
		'search_items'       => __( '문의 검색', 'wp-qna-board' ),
		'not_found'          => __( '문의가 없습니다.', 'wp-qna-board' ),
		'not_found_in_trash' => __( '휴지통에 문의가 없습니다.', 'wp-qna-board' ),
	];

	register_post_type( 'inquiry', [
		'labels'              => $labels,
		'public'              => true,
		'has_archive'         => false, // /inquiries/ 는 별도 페이지가 차지한다.
		'show_in_rest'        => true,
		'rest_base'           => 'inquiry',
		'menu_icon'           => 'dashicons-format-chat',
		'menu_position'       => 22,
		'supports'            => [ 'title', 'editor', 'custom-fields', 'comments', 'author' ],
		'rewrite'             => [ 'slug' => 'inquiries', 'with_front' => false ],
		'capability_type'     => [ 'inquiry', 'inquiries' ],
		'map_meta_cap'        => true,
		'hierarchical'        => false,
		'show_in_nav_menus'   => true,
	] );
}

function inquiry_board_register_taxonomy(): void {
	register_taxonomy( 'inquiry_category', [ 'inquiry' ], [
		'labels'            => [
			'name'          => __( '문의 카테고리', 'wp-qna-board' ),
			'singular_name' => __( '카테고리', 'wp-qna-board' ),
			'all_items'     => __( '전체 카테고리', 'wp-qna-board' ),
			'edit_item'     => __( '카테고리 수정', 'wp-qna-board' ),
			'add_new_item'  => __( '새 카테고리', 'wp-qna-board' ),
		],
		'hierarchical'      => true,
		'public'            => true,
		'show_in_rest'      => true,
		'show_admin_column' => true,
		'rewrite'           => [ 'slug' => 'inquiry-category' ],
	] );
}

/**
 * 사전 정의 카테고리 5개 시드. 이미 존재하면 skip.
 * 마이그레이션 매핑 슬롯이므로 슬러그는 영문으로 안정화한다.
 */
function inquiry_board_default_categories(): array {
	return [
		[ 'name' => '어학연수',   'slug' => 'language-study' ],
		[ 'name' => '대학/대학원', 'slug' => 'university'    ],
		[ 'name' => '조기유학',   'slug' => 'early-study'    ],
		[ 'name' => '비자',       'slug' => 'visa'           ],
		[ 'name' => '기타',       'slug' => 'etc'            ],
	];
}

function inquiry_board_seed_categories(): void {
	if ( ! taxonomy_exists( 'inquiry_category' ) ) {
		inquiry_board_register_taxonomy();
	}
	foreach ( inquiry_board_default_categories() as $cat ) {
		if ( term_exists( $cat['slug'], 'inquiry_category' ) ) {
			continue;
		}
		wp_insert_term( $cat['name'], 'inquiry_category', [ 'slug' => $cat['slug'] ] );
	}
}

/**
 * 단일 페이지 템플릿은 테마(예: single.php) 의 표준 레이아웃을 그대로 사용한다.
 * 추가 UI(첨부·수정 버튼·편집 폼)는 the_content 필터로 본문 영역에 주입.
 * 테마가 single-inquiry.php 를 직접 제공하면 WP template hierarchy 가 자동으로 사용한다.
 *
 * 본 플러그인의 templates/single-inquiry.php 와 templates/archive-inquiry.php 는
 * 테마 개발자가 참조용으로 복사해 갈 수 있는 reference 로 남겨둔다(자동 사용 안 함).
 */
