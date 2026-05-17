<?php
/**
 * Inquiry_Board_Migrator — WP-CLI 비의존 마이그레이션 코어.
 *
 * KBoard 실제 스키마(`{prefix}kboard_board_setting`, `kboard_board_content`,
 * `kboard_comments`, `kboard_board_attached`) 를 기준으로 cursor 기반 배치
 * 처리를 제공한다. WP-CLI 명령(`wp inquiry migrate-kboard`) 과 관리화면
 * AJAX runner(inc/migration-runner.php) 모두 이 클래스를 호출한다.
 *
 * 단계:
 *   1) posts        — wp_kboard_board_content → inquiry CPT (cursor=last uid)
 *   2) attachments  — wp_kboard_board_attached → 글 메타 _inquiry_attachments
 *   3) comments     — wp_kboard_comments → wp_comments
 *
 * 각 batch 메서드는 deadline(microtime) 까지 처리한 뒤 cursor·통계를 반환한다.
 * 시간 만료 또는 batch 행 한도 도달 시 즉시 반환하여 PHP 타임아웃을 회피.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Inquiry_Board_Migrator {

	private string $tbl_content;
	private string $tbl_board;
	private string $tbl_comment;
	private string $tbl_attached;

	public function __construct() {
		global $wpdb;
		$this->tbl_content  = $wpdb->prefix . 'kboard_board_content';
		$this->tbl_board    = $wpdb->prefix . 'kboard_board_setting';
		$this->tbl_comment  = $wpdb->prefix . 'kboard_comments';
		$this->tbl_attached = $wpdb->prefix . 'kboard_board_attached';
	}

	/**
	 * 현재 DB에 KBoard 가 존재하는지.
	 */
	public function kboard_available(): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->tbl_board ) );
	}

	/**
	 * 게시판 메타 (uid, board_name).
	 */
	public function get_board( int $board_id ): ?array {
		global $wpdb;
		if ( $board_id <= 0 ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT uid, board_name FROM {$this->tbl_board} WHERE uid=%d",
			$board_id
		), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * Dry-run 요약 — 관리화면 사전 검증용.
	 *
	 * @return array{
	 *   board: array|null,
	 *   total_posts: int,
	 *   trashed_posts: int,
	 *   total_comments: int,
	 *   trashed_comments: int,
	 *   total_attachments: int,
	 *   categories: array,
	 *   unmapped_categories: int,
	 *   already_migrated_posts: int,
	 * }
	 */
	public function dry_run_summary( int $board_id ): array {
		global $wpdb;
		$board = $this->get_board( $board_id );

		$total_posts = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$this->tbl_content} WHERE board_id=%d",
			$board_id
		) );
		$trashed_posts = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$this->tbl_content} WHERE board_id=%d AND status='trash'",
			$board_id
		) );

		$total_comments = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$this->tbl_comment} m
			   JOIN {$this->tbl_content} c ON c.uid = m.content_uid
			  WHERE c.board_id=%d",
			$board_id
		) );
		$trashed_comments = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$this->tbl_comment} m
			   JOIN {$this->tbl_content} c ON c.uid = m.content_uid
			  WHERE c.board_id=%d AND m.status='trash'",
			$board_id
		) );

		$total_attachments = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$this->tbl_attached} a
			   JOIN {$this->tbl_content} c ON c.uid = a.content_uid
			  WHERE c.board_id=%d
			    AND a.content_uid > 0
			    AND ( a.comment_uid IS NULL OR a.comment_uid = 0 )",
			$board_id
		) );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT category1, category2, COUNT(*) AS cnt
			   FROM {$this->tbl_content}
			  WHERE board_id=%d
			  GROUP BY category1, category2
			  ORDER BY cnt DESC",
			$board_id
		), ARRAY_A );
		$cats = [];
		$unmapped = 0;
		foreach ( (array) $rows as $r ) {
			$slug = inquiry_board_resolve_category( $r['category1'] ?? null, $r['category2'] ?? null );
			$has_text = ( ( $r['category1'] ?? '' ) !== '' ) || ( ( $r['category2'] ?? '' ) !== '' );
			if ( $slug === 'etc' && $has_text ) {
				$unmapped += (int) $r['cnt'];
			}
			$cats[] = [
				'category1' => (string) ( $r['category1'] ?? '' ),
				'category2' => (string) ( $r['category2'] ?? '' ),
				'slug'      => $slug,
				'count'     => (int) $r['cnt'],
			];
		}

		$already = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key='_legacy_kboard_uid'"
		);

		return [
			'board'                  => $board,
			'total_posts'            => $total_posts,
			'trashed_posts'          => $trashed_posts,
			'total_comments'         => $total_comments,
			'trashed_comments'       => $trashed_comments,
			'total_attachments'      => $total_attachments,
			'categories'             => $cats,
			'unmapped_categories'    => $unmapped,
			'already_migrated_posts' => $already,
		];
	}

	/**
	 * posts 단계 1회 배치.
	 *
	 * @return array{cursor:int,done:bool,processed:int,stats:array,errors:array,logs:array}
	 */
	public function migrate_posts_batch( int $board_id, int $batch, int $cursor, float $deadline, bool $dry ): array {
		global $wpdb;
		$stats  = [ 'inserted' => 0, 'skipped' => 0, 'trashed' => 0 ];
		$errors = [];
		$logs   = [];
		$processed = 0;

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$this->tbl_content}
			  WHERE board_id=%d AND uid > %d
			  ORDER BY uid ASC
			  LIMIT %d",
			$board_id, $cursor, $batch
		), ARRAY_A );

		if ( ! $rows ) {
			return [
				'cursor' => $cursor, 'done' => true, 'processed' => 0,
				'stats' => $stats, 'errors' => $errors, 'logs' => $logs,
			];
		}

		foreach ( $rows as $r ) {
			$cursor = (int) $r['uid'];
			$processed++;
			$status_raw = strtolower( trim( (string) ( $r['status'] ?? '' ) ) );
			if ( $status_raw === 'trash' ) {
				$stats['trashed']++;
				if ( microtime( true ) >= $deadline ) {
					break;
				}
				continue;
			}
			$existing = $this->find_post_by_legacy_uid( (int) $r['uid'] );
			if ( $existing ) {
				$stats['skipped']++;
				if ( microtime( true ) >= $deadline ) {
					break;
				}
				continue;
			}

			$is_secret   = $this->yn_to_bool( $r['secret'] ?? '' );
			$is_notice   = $this->yn_to_bool( $r['notice'] ?? '' );
			$post_status = $is_secret ? 'private' : 'publish';
			$cat_slug    = inquiry_board_resolve_category( $r['category1'] ?? null, $r['category2'] ?? null );
			$body_clean  = $this->sanitize_legacy_body( (string) ( $r['content'] ?? '' ) );

			$password = $is_secret ? wp_generate_password( 8, false, false ) : '';

			$created_kst = $this->kboard_dt_to_mysql( (string) ( $r['date'] ?? '' ) );
			$updated_kst = $this->kboard_dt_to_mysql( (string) ( $r['update'] ?? '' ) );

			if ( $dry ) {
				$stats['inserted']++;
				if ( microtime( true ) >= $deadline ) {
					break;
				}
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
				'post_modified'     => $updated_kst ?: ( $created_kst ?: current_time( 'mysql' ) ),
				'post_modified_gmt' => $updated_kst
					? get_gmt_from_date( $updated_kst )
					: ( $created_kst ? get_gmt_from_date( $created_kst ) : '' ),
				'comment_status'    => 'open',
				'ping_status'       => 'closed',
			], true );

			if ( is_wp_error( $post_id ) ) {
				$errors[] = sprintf( 'posts uid=%d INSERT 실패: %s', (int) $r['uid'], $post_id->get_error_message() );
				if ( microtime( true ) >= $deadline ) {
					break;
				}
				continue;
			}

			update_post_meta( $post_id, '_legacy_kboard_uid',         (int) $r['uid'] );
			update_post_meta( $post_id, '_inquiry_legacy_user_uid',   (int) ( $r['member_uid'] ?? 0 ) );
			update_post_meta( $post_id, '_inquiry_author_name',       (string) ( $r['member_display'] ?? '' ) );
			update_post_meta( $post_id, '_inquiry_view',              (int) ( $r['view'] ?? 0 ) );
			update_post_meta( $post_id, '_inquiry_vote',              (int) ( $r['vote'] ?? 0 ) );

			if ( $is_notice ) {
				update_post_meta( $post_id, '_inquiry_notice', 1 );
			}

			$cat_term = get_term_by( 'slug', $cat_slug, 'inquiry_category' );
			if ( $cat_term ) {
				wp_set_object_terms( $post_id, [ (int) $cat_term->term_id ], 'inquiry_category', false );
			}

			if ( $password !== '' ) {
				$this->append_password_csv( (int) $r['uid'], (int) $post_id, $password );
			}

			$stats['inserted']++;

			if ( microtime( true ) >= $deadline ) {
				break;
			}
		}

		$done = count( $rows ) < $batch && microtime( true ) < $deadline;
		$logs[] = sprintf( 'posts batch: cursor=%d processed=%d inserted=%d skipped=%d trashed=%d', $cursor, $processed, $stats['inserted'], $stats['skipped'], $stats['trashed'] );

		return [
			'cursor'    => $cursor,
			'done'      => $done,
			'processed' => $processed,
			'stats'     => $stats,
			'errors'    => $errors,
			'logs'      => $logs,
		];
	}

	/**
	 * attachments 단계 1회 배치 — content_uid 기준 cursor.
	 */
	public function migrate_attachments_batch( int $board_id, int $batch, int $cursor, float $deadline, bool $dry ): array {
		global $wpdb;
		$stats     = [ 'ok' => 0, 'fail' => 0 ];
		$errors    = [];
		$logs      = [];
		$processed = 0;

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT a.uid AS att_uid, a.content_uid, a.file_path, a.file_name, a.file_size
			   FROM {$this->tbl_attached} a
			   JOIN {$this->tbl_content} c ON c.uid = a.content_uid
			  WHERE c.board_id = %d
			    AND a.content_uid > 0
			    AND ( a.comment_uid IS NULL OR a.comment_uid = 0 )
			    AND a.uid > %d
			  ORDER BY a.uid ASC
			  LIMIT %d",
			$board_id, $cursor, $batch
		), ARRAY_A );

		if ( ! $rows ) {
			return [
				'cursor' => $cursor, 'done' => true, 'processed' => 0,
				'stats' => $stats, 'errors' => $errors, 'logs' => $logs,
			];
		}

		if ( ! $dry ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}

		// 한 글에 첨부가 여러개 붙는 경우 메타 누적 병합.
		$post_attachments_buffer = [];

		foreach ( $rows as $a ) {
			$cursor = (int) $a['att_uid'];
			$processed++;
			$kb_uid  = (int) $a['content_uid'];
			$post_id = $this->find_post_by_legacy_uid( $kb_uid );
			if ( ! $post_id ) {
				$stats['fail']++;
				$errors[] = sprintf( 'attachments: 글(_legacy_kboard_uid=%d) 미존재 → skip att_uid=%d', $kb_uid, $cursor );
				if ( microtime( true ) >= $deadline ) {
					break;
				}
				continue;
			}
			$src = $this->resolve_attachment_url( (string) $a['file_path'] );
			if ( $src === '' ) {
				$stats['fail']++;
				if ( microtime( true ) >= $deadline ) {
					break;
				}
				continue;
			}
			if ( $dry ) {
				$stats['ok']++;
				if ( microtime( true ) >= $deadline ) {
					break;
				}
				continue;
			}

			$att_id = $this->sideload_to_post( $src, (int) $post_id, (string) ( $a['file_name'] ?? '' ) );
			if ( $att_id ) {
				$post_attachments_buffer[ $post_id ][] = $att_id;
				$stats['ok']++;
			} else {
				$stats['fail']++;
				$errors[] = sprintf( 'attachments: sideload 실패 att_uid=%d src=%s', (int) $a['att_uid'], $src );
			}

			if ( microtime( true ) >= $deadline ) {
				break;
			}
		}

		if ( ! $dry && $post_attachments_buffer ) {
			foreach ( $post_attachments_buffer as $pid => $ids ) {
				$existing = (array) get_post_meta( $pid, '_inquiry_attachments', true );
				update_post_meta( $pid, '_inquiry_attachments', array_values( array_unique( array_merge( $existing, $ids ) ) ) );
			}
		}

		$done = count( $rows ) < $batch && microtime( true ) < $deadline;
		$logs[] = sprintf( 'attachments batch: cursor=%d processed=%d ok=%d fail=%d', $cursor, $processed, $stats['ok'], $stats['fail'] );

		return [
			'cursor'    => $cursor,
			'done'      => $done,
			'processed' => $processed,
			'stats'     => $stats,
			'errors'    => $errors,
			'logs'      => $logs,
		];
	}

	/**
	 * comments 단계 1회 배치 — comments.uid 기준 cursor.
	 *
	 * 부모-자식 연결은 batch 내에서 INSERT 후 즉시 검색해 wp_update_comment 처리.
	 */
	public function migrate_comments_batch( int $board_id, int $batch, int $cursor, float $deadline, bool $dry ): array {
		global $wpdb;
		$stats     = [ 'inserted' => 0, 'trashed' => 0 ];
		$errors    = [];
		$logs      = [];
		$processed = 0;

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT m.*
			   FROM {$this->tbl_comment} m
			   JOIN {$this->tbl_content} c ON c.uid = m.content_uid
			  WHERE c.board_id=%d AND m.uid > %d
			  ORDER BY m.uid ASC
			  LIMIT %d",
			$board_id, $cursor, $batch
		), ARRAY_A );

		if ( ! $rows ) {
			return [
				'cursor' => $cursor, 'done' => true, 'processed' => 0,
				'stats' => $stats, 'errors' => $errors, 'logs' => $logs,
			];
		}

		// 같은 batch 안에서 발생한 (kboard_uid → wp comment_id) 매핑.
		$pending_parent_links = [];

		foreach ( $rows as $r ) {
			$cursor = (int) $r['uid'];
			$processed++;
			$status_raw = strtolower( trim( (string) ( $r['status'] ?? '' ) ) );
			if ( $status_raw === 'trash' ) {
				$stats['trashed']++;
				if ( microtime( true ) >= $deadline ) {
					break;
				}
				continue;
			}
			$post_id = $this->find_post_by_legacy_uid( (int) $r['content_uid'] );
			if ( ! $post_id ) {
				if ( microtime( true ) >= $deadline ) {
					break;
				}
				continue;
			}
			$author    = (string) ( $r['user_display'] ?? '' );
			$user_uid  = (int) ( $r['user_uid'] ?? 0 );
			$wp_user   = $user_uid > 0 ? get_user_by( 'id', $user_uid ) : null;
			$is_admin  = $wp_user && user_can( $wp_user, 'moderate_comments' );

			if ( $dry ) {
				$stats['inserted']++;
				if ( microtime( true ) >= $deadline ) {
					break;
				}
				continue;
			}

			$existing = $this->find_comment_by_legacy_uid( (int) $r['uid'] );
			if ( $existing ) {
				$pending_parent_links[ (int) $r['uid'] ] = $existing;
				if ( microtime( true ) >= $deadline ) {
					break;
				}
				continue;
			}

			$created_kst = $this->kboard_dt_to_mysql( (string) ( $r['created'] ?? '' ) );
			$comment_id  = wp_insert_comment( [
				'comment_post_ID'      => $post_id,
				'comment_author'       => $author,
				'comment_author_email' => '',
				'comment_content'      => wp_kses_post( (string) ( $r['content'] ?? '' ) ),
				'comment_date'         => $created_kst ?: current_time( 'mysql' ),
				'comment_date_gmt'     => $created_kst ? get_gmt_from_date( $created_kst ) : current_time( 'mysql', 1 ),
				'comment_approved'     => 1,
				'comment_parent'       => 0,
				'user_id'              => $wp_user ? (int) $wp_user->ID : 0,
			] );
			if ( ! $comment_id ) {
				$errors[] = sprintf( 'comments uid=%d INSERT 실패', (int) $r['uid'] );
				if ( microtime( true ) >= $deadline ) {
					break;
				}
				continue;
			}
			add_comment_meta( $comment_id, '_legacy_kboard_comment_uid', (int) $r['uid'], true );
			if ( $is_admin ) {
				add_comment_meta( $comment_id, '_is_admin_reply', 1, true );
			}
			$pending_parent_links[ (int) $r['uid'] ] = (int) $comment_id;
			$stats['inserted']++;

			if ( microtime( true ) >= $deadline ) {
				break;
			}
		}

		// 부모 연결: 같은 batch 내, 또는 이전 batch 에 이미 INSERT 된 댓글을 메타로 재검색.
		if ( ! $dry ) {
			foreach ( $rows as $r ) {
				$parent_uid = (int) ( $r['parent_uid'] ?? 0 );
				if ( $parent_uid <= 0 ) {
					continue;
				}
				$me  = $pending_parent_links[ (int) $r['uid'] ] ?? 0;
				if ( ! $me ) {
					continue;
				}
				$dad = $pending_parent_links[ $parent_uid ] ?? $this->find_comment_by_legacy_uid( $parent_uid );
				if ( $dad ) {
					wp_update_comment( [ 'comment_ID' => $me, 'comment_parent' => $dad ] );
				}
			}
		}

		$done = count( $rows ) < $batch && microtime( true ) < $deadline;
		$logs[] = sprintf( 'comments batch: cursor=%d processed=%d inserted=%d trashed=%d', $cursor, $processed, $stats['inserted'], $stats['trashed'] );

		return [
			'cursor'    => $cursor,
			'done'      => $done,
			'processed' => $processed,
			'stats'     => $stats,
			'errors'    => $errors,
			'logs'      => $logs,
		];
	}

	private function find_post_by_legacy_uid( int $uid ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_legacy_kboard_uid' AND meta_value=%d LIMIT 1",
			$uid
		) );
	}

	private function find_comment_by_legacy_uid( int $uid ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT comment_id FROM {$wpdb->commentmeta} WHERE meta_key='_legacy_kboard_comment_uid' AND meta_value=%d LIMIT 1",
			$uid
		) );
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

	private function resolve_attachment_url( string $path ): string {
		$path = trim( $path );
		if ( $path === '' ) {
			return '';
		}
		if ( preg_match( '#^https?://#i', $path ) ) {
			return $path;
		}
		$rel = '/' . ltrim( $path, '/' );
		return home_url( $rel );
	}

	private function sideload_to_post( string $src, int $post_id, string $orig_name = '' ): int {
		$tmp = download_url( $src, 30 );
		if ( is_wp_error( $tmp ) ) {
			return 0;
		}
		$name = $orig_name !== '' ? sanitize_file_name( $orig_name ) : '';
		if ( $name === '' ) {
			$name = basename( parse_url( $src, PHP_URL_PATH ) ?: 'attachment' );
		}
		$file = [ 'name' => $name, 'tmp_name' => $tmp ];
		$att  = media_handle_sideload( $file, $post_id );
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

	public function kboard_dt_to_mysql( string $raw ): string {
		$raw = trim( $raw );
		if ( $raw === '' || ! preg_match( '/^\d{14}$/', $raw ) ) {
			return '';
		}
		return sprintf(
			'%s-%s-%s %s:%s:%s',
			substr( $raw, 0, 4 ),
			substr( $raw, 4, 2 ),
			substr( $raw, 6, 2 ),
			substr( $raw, 8, 2 ),
			substr( $raw, 10, 2 ),
			substr( $raw, 12, 2 )
		);
	}

	public function mysql_to_kboard_dt( string $kst ): string {
		$ts = strtotime( $kst );
		if ( ! $ts ) {
			return '';
		}
		return wp_date( 'YmdHis', $ts, wp_timezone() );
	}

	private function yn_to_bool( $v ): bool {
		$s = strtolower( trim( (string) $v ) );
		return in_array( $s, [ 'true', '1', 'y', 'yes' ], true );
	}
}
