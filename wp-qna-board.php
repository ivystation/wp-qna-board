<?php
/**
 * Plugin Name:       Q&A 게시판
 * Plugin URI:        https://github.com/ivynet/wp-qna-board
 * Description:       비회원 작성 가능한 Q&A 게시판. CPT(inquiry) + 비밀번호 보호 + IP·쿠키 24시간 본인 세션 + 관리자 답변 댓글. KBoard 마이그레이션 WP-CLI 포함.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            ivynet
 * License:           GPL-2.0-or-later
 * Text Domain:       wp-qna-board
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'INQUIRY_BOARD_VERSION', '0.1.0' );
define( 'INQUIRY_BOARD_FILE', __FILE__ );
define( 'INQUIRY_BOARD_DIR', plugin_dir_path( __FILE__ ) );
define( 'INQUIRY_BOARD_URL', plugin_dir_url( __FILE__ ) );
define( 'INQUIRY_BOARD_TEMPLATES', INQUIRY_BOARD_DIR . 'templates/' );

require_once INQUIRY_BOARD_DIR . 'inc/cpt.php';
require_once INQUIRY_BOARD_DIR . 'inc/session.php';
require_once INQUIRY_BOARD_DIR . 'inc/form.php';
require_once INQUIRY_BOARD_DIR . 'inc/edit.php';
require_once INQUIRY_BOARD_DIR . 'inc/permissions.php';
require_once INQUIRY_BOARD_DIR . 'inc/comments.php';
require_once INQUIRY_BOARD_DIR . 'inc/redirect.php';
require_once INQUIRY_BOARD_DIR . 'inc/settings.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once INQUIRY_BOARD_DIR . 'inc/migration-map.php';
	require_once INQUIRY_BOARD_DIR . 'inc/migration.php';
}

register_activation_hook( __FILE__, 'inquiry_board_activate' );
register_deactivation_hook( __FILE__, 'inquiry_board_deactivate' );

function inquiry_board_activate(): void {
	inquiry_board_register_cpt();
	inquiry_board_register_taxonomy();
	inquiry_board_seed_categories();
	inquiry_board_ensure_secret();
	inquiry_board_install_caps();
	flush_rewrite_rules();
}

function inquiry_board_deactivate(): void {
	flush_rewrite_rules();
}

function inquiry_board_ensure_secret(): void {
	if ( defined( 'INQUIRY_BOARD_SECRET' ) && INQUIRY_BOARD_SECRET ) {
		return;
	}
	$existing = get_option( 'inquiry_board_secret' );
	if ( ! $existing ) {
		add_option( 'inquiry_board_secret', wp_generate_password( 64, true, true ), '', false );
	}
}

function inquiry_board_get_secret(): string {
	if ( defined( 'INQUIRY_BOARD_SECRET' ) && INQUIRY_BOARD_SECRET ) {
		return (string) INQUIRY_BOARD_SECRET;
	}
	$opt = get_option( 'inquiry_board_secret' );
	if ( ! $opt ) {
		$opt = wp_generate_password( 64, true, true );
		add_option( 'inquiry_board_secret', $opt, '', false );
	}
	return (string) $opt;
}

function inquiry_board_install_caps(): void {
	$role = get_role( 'administrator' );
	if ( ! $role ) {
		return;
	}
	$caps = [
		'edit_inquiry', 'read_inquiry', 'delete_inquiry',
		'edit_inquiries', 'edit_others_inquiries', 'publish_inquiries',
		'read_private_inquiries', 'delete_inquiries', 'delete_others_inquiries',
		'delete_private_inquiries', 'delete_published_inquiries',
		'edit_private_inquiries', 'edit_published_inquiries',
	];
	foreach ( $caps as $cap ) {
		$role->add_cap( $cap );
	}
}

add_action( 'plugins_loaded', static function (): void {
	load_plugin_textdomain( 'wp-qna-board', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
} );

add_action( 'init', 'inquiry_board_register_cpt' );
add_action( 'init', 'inquiry_board_register_taxonomy' );

add_action( 'wp_enqueue_scripts', static function (): void {
	if ( is_singular( 'inquiry' ) || is_post_type_archive( 'inquiry' ) || is_page() ) {
		wp_enqueue_style( 'wp-qna-board', INQUIRY_BOARD_URL . 'assets/wp-qna-board.css', [], INQUIRY_BOARD_VERSION );
		wp_enqueue_script( 'wp-qna-board', INQUIRY_BOARD_URL . 'assets/wp-qna-board.js', [], INQUIRY_BOARD_VERSION, true );
	}
} );

/**
 * 본문 짧은 텍스트 본문 정제 헬퍼는 form.php 에서 정의된 inquiry_board_sanitize_body() 를 사용.
 * 외부에서 활용할 가능성을 위해 이 노트만 남긴다.
 */
