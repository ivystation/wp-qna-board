<?php
/**
 * WP-CLI: wp inquiry migrate-kboard
 *
 * KBoard 게시판 데이터를 CPT inquiry 로 마이그레이션한다. idempotent (legacy uid 기준 skip/upsert).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

class Inquiry_Board_Migrate_Command {

	private string $tbl_content;
	private string $tbl_board;
	private string $tbl_comment;

	public function __construct() {
		global $wpdb;
		$this->tbl_content = $wpdb->prefix . 'kboard_board_content';
		$this->tbl_board   = $wpdb->prefix . 'kboard_board';
		$this->tbl_comment = $wpdb->prefix . 'kboard_comments';
	}

	/**
	 * KBoard 게시판 한 개의 글·첨부·댓글을 inquiry CPT 로 이관.
	 *
	 * ## OPTIONS
	 *
	 * --board=<uid>
	 * : KBoard 게시판 uid (wp_kboard_board.uid). 필수.
	 *
	 * [--dry-run]
	 * : 변경 없이 통계만 출력.
	 *
	 * [--batch=<n>]
	 * : 배치 크기 (기본 200).
	 *
	 * [--resume-from=<uid>]
	 * : 특정 KBoard content uid 부터 재개.
	 *
	 * [--since=<datetime>]
	 * : 이 시각 이후 created/updated 된 행만 처리 (delta 모드).
	 *
	 * [--only=<part>]
	 * : posts|attachments|comments 중 하나만 실행. 기본 전부.
	 *
	 * ## EXAMPLES
	 *
	 *   wp inquiry migrate-kboard --board=3 --dry-run
	 *   wp inquiry migrate-kboard --board=3 --batch=200
	 *   wp inquiry migrate-kboard --board=3 --only=comments
	 *   wp inquiry migrate-kboard --board=3 --since="2026-05-17 00:00:00"
	 */
	public function migrate_kboard( array $args, array $assoc ): void {
		$board_id = (int) ( $assoc['board'] ?? 0 );
		if ( $board_id <= 0 ) {
			WP_CLI::error( '--board=<kboard_uid> 가 필요합니다.' );
		}
		$dry       = ! empty( $assoc['dry-run'] );
		$batch     = max( 1, (int) ( $assoc['batch'] ?? 200 ) );
		$resume    = (int) ( $assoc['resume-from'] ?? 0 );
		$since     = isset( $assoc['since'] ) ? sanitize_text_field( (string) $assoc['since'] ) : '';
		$only      = isset( $assoc['only'] ) ? sanitize_key( (string) $assoc['only'] ) : '';

		WP_CLI::log( sprintf(
			'시작: board=%d, dry-run=%s, batch=%d, resume=%d, since=%s, only=%s',
			$board_id, $dry ? 'yes' : 'no', $batch, $resume, $since ?: '-', $only ?: 'all'
		) );

		$this->report_distinct_categories( $board_id );

		if ( ! $only || $only === 'posts' ) {
			$this->migrate_posts( $board_id, $batch, $resume, $since, $dry );
		}
		if ( ! $only || $only === 'attachments' ) {
			$this->migrate_attachments( $board_id, $batch, $dry );
		}
		if ( ! $only || $only === 'comments' ) {
			$this->migrate_comments( $board_id, $batch, $since, $dry );
		}

		WP_CLI::success( '완료.' );
	}

	private function report_distinct_categories( int $board_id ): void {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT DISTINCT category1, category2 FROM {$this->tbl_content} WHERE board_id=%d ORDER BY category1, category2",
			$board_id
		), ARRAY_A );
		WP_CLI::log( 'distinct(category1, category2) 목록:' );
		$unmapped = 0;
		foreach ( $rows as $r ) {
			$slug = inquiry_board_resolve_category( $r['category1'] ?? null, $r['category2'] ?? null );
			if ( $slug === 'etc' && ( ( $r['category1'] ?? '' ) !== '' || ( $r['category2'] ?? '' ) !== '' ) ) {
				$unmapped++;
			}
			WP_CLI::log( sprintf( '  cat1=%s | cat2=%s  →  %s', $r['category1'] ?? '', $r['category2'] ?? '', $slug ) );
		}
		WP_CLI::log( sprintf( '미명시 매핑(→etc)으로 떨어진 distinct 조합: %d', $unmapped ) );
	}

	private function migrate_posts( int $board_id, int $batch, int $resume, string $since, bool $dry ): void {
		global $wpdb;
		$offset_uid = $resume;
		$inserted   = 0;
		$updated    = 0;
		$skipped    = 0;

		while ( true ) {
			$where  = $wpdb->prepare( 'board_id=%d AND uid > %d', $board_id, $offset_uid );
			if ( $since ) {
				$where .= $wpdb->prepare( ' AND (created >= %s OR updated >= %s)', $since, $since );
			}
			$rows = $wpdb->get_results( "SELECT * FROM {$this->tbl_content} WHERE {$where} ORDER BY uid ASC LIMIT {$batch}", ARRAY_A );
			if ( ! $rows ) {
				break;
			}
			foreach ( $rows as $r ) {
				$offset_uid = (int) $r['uid'];
				$existing   = $this->find_post_by_legacy_uid( (int) $r['uid'] );
				if ( $existing ) {
					$skipped++;
					continue; // idempotent skip. 강제 갱신 옵션은 후속 작업.
				}

				$post_status = ( (int) ( $r['status'] ?? 0 ) === 0 ) ? 'publish' : 'private';
				$cat_slug    = inquiry_board_resolve_category( $r['category1'] ?? null, $r['category2'] ?? null );
				$body_clean  = $this->sanitize_legacy_body( (string) $r['content'] );
				$password    = '';
				if ( (int) ( $r['secret'] ?? 0 ) === 1 ) {
					$password = wp_generate_password( 8, false, false );
				}

				$created_kst = (string) ( $r['created'] ?? '' );
				$updated_kst = (string) ( $r['updated'] ?? '' );

				if ( $dry ) {
					$inserted++;
					continue;
				}

				$post_id = wp_insert_post( [
					'post_type'         => 'inquiry',
					'post_status'       => $post_status,
					'post_author'       => inquiry_board_anon_author_id(),
					'post_title'        => (string) ( $r['title'] ?? '' ),
					'post_content'      => $body_clean,
					'post_password'     => $password,
					'post_date'         => $created_kst ?: current_time( 'mysql' ),
					'post_date_gmt'     => $created_kst ? get_gmt_from_date( $created_kst ) : '',
					'post_modified'     => $updated_kst ?: $created_kst,
					'post_modified_gmt' => $updated_kst ? get_gmt_from_date( $updated_kst ) : '',
					'comment_status'    => 'open',
					'ping_status'       => 'closed',
				], true );

				if ( is_wp_error( $post_id ) ) {
					WP_CLI::warning( sprintf( 'INSERT 실패 uid=%d: %s', $r['uid'], $post_id->get_error_message() ) );
					continue;
				}

				update_post_meta( $post_id, '_legacy_kboard_uid',         (int) $r['uid'] );
				update_post_meta( $post_id, '_inquiry_legacy_user_uid',   (int) ( $r['user_uid'] ?? 0 ) );
				update_post_meta( $post_id, '_inquiry_author_name',       (string) ( $r['member_display'] ?? '' ) );
				update_post_meta( $post_id, '_inquiry_view',              (int) ( $r['view'] ?? 0 ) );
				update_post_meta( $post_id, '_inquiry_vote',              (int) ( $r['vote'] ?? 0 ) );

				if ( (int) ( $r['notice'] ?? 0 ) === 1 ) {
					update_post_meta( $post_id, '_inquiry_notice', 1 );
				}

				$cat_term = get_term_by( 'slug', $cat_slug, 'inquiry_category' );
				if ( $cat_term ) {
					wp_set_object_terms( $post_id, [ (int) $cat_term->term_id ], 'inquiry_category', false );
				}

				if ( $password !== '' ) {
					$this->append_password_csv( (int) $r['uid'], (int) $post_id, $password );
				}

				$inserted++;
			}
			WP_CLI::log( sprintf( '진행: 마지막 uid=%d, inserted=%d, updated=%d, skipped=%d', $offset_uid, $inserted, $updated, $skipped ) );
		}

		WP_CLI::success( sprintf( 'posts 완료: inserted=%d, updated=%d, skipped=%d', $inserted, $updated, $skipped ) );
	}

	private function migrate_attachments( int $board_id, int $batch, bool $dry ): void {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT uid, file FROM {$this->tbl_content} WHERE board_id=%d AND file IS NOT NULL AND file <> ''",
			$board_id
		), ARRAY_A );

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$ok = 0; $fail = 0;
		foreach ( $rows as $r ) {
			$post_id = $this->find_post_by_legacy_uid( (int) $r['uid'] );
			if ( ! $post_id ) {
				continue;
			}
			$files = maybe_unserialize( $r['file'] );
			if ( ! is_array( $files ) ) {
				continue;
			}
			$ids = [];
			foreach ( $files as $f ) {
				if ( empty( $f['path'] ) && empty( $f['url'] ) ) {
					continue;
				}
				if ( $dry ) {
					$ok++;
					continue;
				}
				$src = $f['url'] ?? content_url( ltrim( (string) $f['path'], '/' ) );
				$att = $this->sideload_to_post( (string) $src, $post_id );
				if ( $att ) {
					$ids[] = $att;
					$ok++;
				} else {
					$fail++;
				}
			}
			if ( $ids ) {
				update_post_meta( $post_id, '_inquiry_attachments', $ids );
			}
		}
		WP_CLI::success( sprintf( 'attachments 완료: ok=%d, fail=%d', $ok, $fail ) );
	}

	private function migrate_comments( int $board_id, int $batch, string $since, bool $dry ): void {
		global $wpdb;
		$where = $wpdb->prepare( 'board_id=%d', $board_id );
		if ( $since ) {
			$where .= $wpdb->prepare( ' AND created >= %s', $since );
		}
		$rows = $wpdb->get_results( "SELECT * FROM {$this->tbl_comment} WHERE {$where} ORDER BY uid ASC", ARRAY_A );

		$kb_uid_to_comment_id = [];
		$inserted = 0;
		foreach ( $rows as $r ) {
			$post_id = $this->find_post_by_legacy_uid( (int) $r['content_uid'] );
			if ( ! $post_id ) {
				continue;
			}
			$author    = (string) ( $r['member_display'] ?? '' );
			$user_uid  = (int) ( $r['user_uid'] ?? 0 );
			$wp_user   = $user_uid > 0 ? get_user_by( 'id', $user_uid ) : null;
			$is_admin  = $wp_user && user_can( $wp_user, 'moderate_comments' );

			if ( $dry ) {
				$inserted++;
				continue;
			}

			$comment_id = wp_insert_comment( [
				'comment_post_ID'      => $post_id,
				'comment_author'       => $author,
				'comment_author_email' => '',
				'comment_content'      => wp_kses_post( (string) $r['content'] ),
				'comment_date'         => (string) $r['created'],
				'comment_date_gmt'     => get_gmt_from_date( (string) $r['created'] ),
				'comment_approved'     => ( (int) ( $r['status'] ?? 1 ) === 1 ) ? 1 : '0',
				'comment_parent'       => 0,
				'user_id'              => $wp_user ? (int) $wp_user->ID : 0,
			] );
			if ( ! $comment_id ) {
				continue;
			}
			add_comment_meta( $comment_id, '_legacy_kboard_comment_uid', (int) $r['uid'], true );
			if ( $is_admin ) {
				add_comment_meta( $comment_id, '_is_admin_reply', 1, true );
			}
			$kb_uid_to_comment_id[ (int) $r['uid'] ] = (int) $comment_id;
			$inserted++;
		}

		// 2-pass: 부모 연결.
		if ( ! $dry ) {
			foreach ( $rows as $r ) {
				$parent_uid = (int) ( $r['parent'] ?? 0 );
				if ( $parent_uid <= 0 ) {
					continue;
				}
				$me    = $kb_uid_to_comment_id[ (int) $r['uid'] ] ?? 0;
				$dad   = $kb_uid_to_comment_id[ $parent_uid ] ?? 0;
				if ( $me && $dad ) {
					wp_update_comment( [ 'comment_ID' => $me, 'comment_parent' => $dad ] );
				}
			}
		}
		WP_CLI::success( sprintf( 'comments 완료: inserted=%d', $inserted ) );
	}

	private function find_post_by_legacy_uid( int $uid ): int {
		global $wpdb;
		$id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_legacy_kboard_uid' AND meta_value=%d LIMIT 1",
			$uid
		) );
		return $id;
	}

	private function sanitize_legacy_body( string $raw ): string {
		$allowed = [
			'p'  => [],
			'br' => [],
			'a'  => [ 'href' => true, 'title' => true, 'rel' => true, 'target' => true ],
		];
		$clean = wp_kses( $raw, $allowed );
		if ( strpos( $clean, '<p>' ) === false ) {
			$clean = wpautop( $clean );
		}
		return $clean;
	}

	private function sideload_to_post( string $src, int $post_id ): int {
		$tmp = download_url( $src, 30 );
		if ( is_wp_error( $tmp ) ) {
			return 0;
		}
		$name  = basename( parse_url( $src, PHP_URL_PATH ) ?: 'attachment' );
		$file  = [ 'name' => $name, 'tmp_name' => $tmp ];
		$att   = media_handle_sideload( $file, $post_id );
		if ( is_wp_error( $att ) ) {
			@unlink( $tmp );
			return 0;
		}
		return (int) $att;
	}

	private function append_password_csv( int $kboard_uid, int $post_id, string $pwd ): void {
		$dir = WP_CONTENT_DIR . '/private/inquiry';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			@file_put_contents( $dir . '/.htaccess', "Require all denied\n" );
		}
		$path = $dir . '/inquiry-migration-passwords.csv';
		$line = sprintf( "%d,%d,%s\n", $kboard_uid, $post_id, $pwd );
		@file_put_contents( $path, $line, FILE_APPEND | LOCK_EX );
	}
}

WP_CLI::add_command( 'inquiry migrate-kboard', static function ( array $args, array $assoc ): void {
	( new Inquiry_Board_Migrate_Command() )->migrate_kboard( $args, $assoc );
} );
