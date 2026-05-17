<?php
/**
 * WP-CLI: wp inquiry migrate-kboard
 *
 * KBoard 게시판 데이터를 CPT inquiry 로 마이그레이션한다. idempotent (legacy uid 기준 skip/upsert).
 *
 * KBoard 실제 스키마 (v0.4.2 보정):
 *   - 게시판 목록 : {prefix}kboard_board_setting (uid, board_name)
 *   - 글         : {prefix}kboard_board_content (board_id, member_uid, member_display,
 *                  date, `update`, secret('true'/''), notice('true'/''),
 *                  status(NULL/''/'trash'), category1..5, password, view, vote, ...)
 *   - 댓글       : {prefix}kboard_comments (content_uid FK, user_uid, user_display,
 *                  created, status, password)
 *   - 첨부       : {prefix}kboard_board_attached (content_uid 또는 comment_uid FK,
 *                  file_path, file_name)
 *
 * 날짜는 char(14) 'YYYYMMDDHHMMSS' KST 로 저장된다.
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
	private string $tbl_attached;

	public function __construct() {
		global $wpdb;
		$this->tbl_content  = $wpdb->prefix . 'kboard_board_content';
		$this->tbl_board    = $wpdb->prefix . 'kboard_board_setting';
		$this->tbl_comment  = $wpdb->prefix . 'kboard_comments';
		$this->tbl_attached = $wpdb->prefix . 'kboard_board_attached';
	}

	/**
	 * KBoard 게시판 한 개의 글·첨부·댓글을 inquiry CPT 로 이관.
	 *
	 * ## OPTIONS
	 *
	 * --board=<uid>
	 * : KBoard 게시판 uid (wp_kboard_board_setting.uid). 필수.
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
	 * : 이 시각(KST) 이후 date/update 된 행만 처리. YYYY-MM-DD HH:MM:SS.
	 *
	 * [--only=<part>]
	 * : posts|attachments|comments 중 하나만 실행. 기본 전부.
	 *
	 * ## EXAMPLES
	 *
	 *   wp inquiry migrate-kboard --board=1 --dry-run
	 *   wp inquiry migrate-kboard --board=1 --batch=200
	 *   wp inquiry migrate-kboard --board=1 --only=comments
	 *   wp inquiry migrate-kboard --board=1 --since="2026-05-17 00:00:00"
	 */
	public function migrate_kboard( array $args, array $assoc ): void {
		$board_id = (int) ( $assoc['board'] ?? 0 );
		if ( $board_id <= 0 ) {
			WP_CLI::error( '--board=<kboard_uid> 가 필요합니다.' );
		}
		$dry       = ! empty( $assoc['dry-run'] );
		$batch     = max( 1, (int) ( $assoc['batch'] ?? 200 ) );
		$resume    = (int) ( $assoc['resume-from'] ?? 0 );
		$since_kst = isset( $assoc['since'] ) ? sanitize_text_field( (string) $assoc['since'] ) : '';
		$since_kb  = $since_kst !== '' ? $this->mysql_to_kboard_dt( $since_kst ) : '';
		$only      = isset( $assoc['only'] ) ? sanitize_key( (string) $assoc['only'] ) : '';

		WP_CLI::log( sprintf(
			'시작: board=%d, dry-run=%s, batch=%d, resume=%d, since=%s, only=%s',
			$board_id, $dry ? 'yes' : 'no', $batch, $resume, $since_kst ?: '-', $only ?: 'all'
		) );

		$this->report_distinct_categories( $board_id );

		if ( ! $only || $only === 'posts' ) {
			$this->migrate_posts( $board_id, $batch, $resume, $since_kb, $dry );
		}
		if ( ! $only || $only === 'attachments' ) {
			$this->migrate_attachments( $board_id, $dry );
		}
		if ( ! $only || $only === 'comments' ) {
			$this->migrate_comments( $board_id, $since_kb, $dry );
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

	private function migrate_posts( int $board_id, int $batch, int $resume, string $since_kb, bool $dry ): void {
		global $wpdb;
		$offset_uid = $resume;
		$inserted   = 0;
		$skipped    = 0;
		$trashed    = 0;

		while ( true ) {
			$where = $wpdb->prepare( 'board_id=%d AND uid > %d', $board_id, $offset_uid );
			if ( $since_kb !== '' ) {
				$where .= $wpdb->prepare( ' AND (date >= %s OR `update` >= %s)', $since_kb, $since_kb );
			}
			$rows = $wpdb->get_results( "SELECT * FROM {$this->tbl_content} WHERE {$where} ORDER BY uid ASC LIMIT {$batch}", ARRAY_A );
			if ( ! $rows ) {
				break;
			}
			foreach ( $rows as $r ) {
				$offset_uid = (int) $r['uid'];
				$status_raw = strtolower( trim( (string) ( $r['status'] ?? '' ) ) );
				if ( $status_raw === 'trash' ) {
					$trashed++;
					continue; // 휴지통은 이관 제외.
				}
				$existing = $this->find_post_by_legacy_uid( (int) $r['uid'] );
				if ( $existing ) {
					$skipped++;
					continue; // idempotent skip.
				}

				$is_secret   = $this->yn_to_bool( $r['secret'] ?? '' );
				$is_notice   = $this->yn_to_bool( $r['notice'] ?? '' );
				$post_status = $is_secret ? 'private' : 'publish';
				$cat_slug    = inquiry_board_resolve_category( $r['category1'] ?? null, $r['category2'] ?? null );
				$body_clean  = $this->sanitize_legacy_body( (string) ( $r['content'] ?? '' ) );

				// 비밀글 비밀번호: KBoard 의 password 는 평문이 아닐 가능성이 있어 그대로 신뢰하지 않는다.
				// 작성자에게 새 비밀번호를 안내하기 위해 8자리 신규 발급 + CSV 저장.
				$password = $is_secret ? wp_generate_password( 8, false, false ) : '';

				$created_kst = $this->kboard_dt_to_mysql( (string) ( $r['date'] ?? '' ) );
				$updated_kst = $this->kboard_dt_to_mysql( (string) ( $r['update'] ?? '' ) );

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
					'post_modified'     => $updated_kst ?: ( $created_kst ?: current_time( 'mysql' ) ),
					'post_modified_gmt' => $updated_kst
						? get_gmt_from_date( $updated_kst )
						: ( $created_kst ? get_gmt_from_date( $created_kst ) : '' ),
					'comment_status'    => 'open',
					'ping_status'       => 'closed',
				], true );

				if ( is_wp_error( $post_id ) ) {
					WP_CLI::warning( sprintf( 'INSERT 실패 uid=%d: %s', $r['uid'], $post_id->get_error_message() ) );
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

				$inserted++;
			}
			WP_CLI::log( sprintf( '진행: 마지막 uid=%d, inserted=%d, skipped=%d, trashed=%d', $offset_uid, $inserted, $skipped, $trashed ) );
		}

		WP_CLI::success( sprintf( 'posts 완료: inserted=%d, skipped=%d, trashed=%d', $inserted, $skipped, $trashed ) );
	}

	/**
	 * KBoard 첨부는 별도 테이블 {prefix}kboard_board_attached 에 저장된다.
	 * 글 첨부는 content_uid 로, 댓글 첨부는 comment_uid 로 식별된다.
	 * 본 함수에서는 글 첨부만 처리한다.
	 */
	private function migrate_attachments( int $board_id, bool $dry ): void {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT a.uid AS att_uid, a.content_uid, a.file_path, a.file_name, a.file_size
			   FROM {$this->tbl_attached} a
			   JOIN {$this->tbl_content} c ON c.uid = a.content_uid
			  WHERE c.board_id = %d
			    AND a.content_uid > 0
			    AND ( a.comment_uid IS NULL OR a.comment_uid = 0 )
			  ORDER BY a.uid ASC",
			$board_id
		), ARRAY_A );

		if ( ! $rows ) {
			WP_CLI::success( 'attachments 완료: ok=0, fail=0 (대상 없음)' );
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$by_post = [];
		foreach ( $rows as $r ) {
			$by_post[ (int) $r['content_uid'] ][] = $r;
		}

		$ok = 0; $fail = 0;
		foreach ( $by_post as $kboard_uid => $atts ) {
			$post_id = $this->find_post_by_legacy_uid( (int) $kboard_uid );
			if ( ! $post_id ) {
				$fail += count( $atts );
				continue;
			}
			$ids = [];
			foreach ( $atts as $a ) {
				$src = $this->resolve_attachment_url( (string) $a['file_path'] );
				if ( $src === '' ) {
					$fail++;
					continue;
				}
				if ( $dry ) {
					$ok++;
					continue;
				}
				$att = $this->sideload_to_post( $src, (int) $post_id, (string) ( $a['file_name'] ?? '' ) );
				if ( $att ) {
					$ids[] = $att;
					$ok++;
				} else {
					$fail++;
					WP_CLI::warning( sprintf( 'attachment 실패 kboard_uid=%d file=%s', $kboard_uid, $src ) );
				}
			}
			if ( ! $dry && $ids ) {
				$existing = (array) get_post_meta( $post_id, '_inquiry_attachments', true );
				update_post_meta( $post_id, '_inquiry_attachments', array_values( array_unique( array_merge( $existing, $ids ) ) ) );
			}
		}
		WP_CLI::success( sprintf( 'attachments 완료: ok=%d, fail=%d', $ok, $fail ) );
	}

	/**
	 * KBoard 댓글은 board_id 컬럼이 없다. content_uid 로 글에 매달리므로
	 * 해당 게시판의 글 uid 들과 JOIN 해서 필터링한다.
	 */
	private function migrate_comments( int $board_id, string $since_kb, bool $dry ): void {
		global $wpdb;
		$where  = 'c.board_id=%d';
		$params = [ $board_id ];
		if ( $since_kb !== '' ) {
			$where    .= ' AND m.created >= %s';
			$params[] = $since_kb;
		}
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT m.*
			   FROM {$this->tbl_comment} m
			   JOIN {$this->tbl_content} c ON c.uid = m.content_uid
			  WHERE {$where}
			  ORDER BY m.uid ASC",
			$params
		), ARRAY_A );

		$kb_uid_to_comment_id = [];
		$inserted = 0;
		$trashed  = 0;
		foreach ( $rows as $r ) {
			$status_raw = strtolower( trim( (string) ( $r['status'] ?? '' ) ) );
			if ( $status_raw === 'trash' ) {
				$trashed++;
				continue;
			}
			$post_id = $this->find_post_by_legacy_uid( (int) $r['content_uid'] );
			if ( ! $post_id ) {
				continue;
			}
			$author    = (string) ( $r['user_display'] ?? '' );
			$user_uid  = (int) ( $r['user_uid'] ?? 0 );
			$wp_user   = $user_uid > 0 ? get_user_by( 'id', $user_uid ) : null;
			$is_admin  = $wp_user && user_can( $wp_user, 'moderate_comments' );

			if ( $dry ) {
				$inserted++;
				continue;
			}

			// idempotent: legacy uid 메타로 중복 INSERT 방지.
			$existing = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT comment_id FROM {$wpdb->commentmeta} WHERE meta_key='_legacy_kboard_comment_uid' AND meta_value=%d LIMIT 1",
				(int) $r['uid']
			) );
			if ( $existing ) {
				$kb_uid_to_comment_id[ (int) $r['uid'] ] = $existing;
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
				continue;
			}
			add_comment_meta( $comment_id, '_legacy_kboard_comment_uid', (int) $r['uid'], true );
			if ( $is_admin ) {
				add_comment_meta( $comment_id, '_is_admin_reply', 1, true );
			}
			$kb_uid_to_comment_id[ (int) $r['uid'] ] = (int) $comment_id;
			$inserted++;
		}

		// 2-pass: parent_uid → comment_parent 연결.
		if ( ! $dry ) {
			foreach ( $rows as $r ) {
				$parent_uid = (int) ( $r['parent_uid'] ?? 0 );
				if ( $parent_uid <= 0 ) {
					continue;
				}
				$me  = $kb_uid_to_comment_id[ (int) $r['uid'] ] ?? 0;
				$dad = $kb_uid_to_comment_id[ $parent_uid ] ?? 0;
				if ( $me && $dad ) {
					wp_update_comment( [ 'comment_ID' => $me, 'comment_parent' => $dad ] );
				}
			}
		}
		WP_CLI::success( sprintf( 'comments 완료: inserted=%d, trashed=%d', $inserted, $trashed ) );
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

	/**
	 * KBoard 첨부 file_path 는 보통 `/media/kboard_attached/...` (사이트 절대 경로) 형태.
	 * 호스트가 비어있으므로 home_url() 로 채워 외부에서 다운로드 가능한 URL 로 만든다.
	 */
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

	/**
	 * KBoard 의 char(14) 'YYYYMMDDHHMMSS' (KST) → MySQL 'YYYY-MM-DD HH:MM:SS'.
	 * 빈 값/포맷 불일치 시 빈 문자열을 반환한다.
	 */
	private function kboard_dt_to_mysql( string $raw ): string {
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

	/**
	 * 'YYYY-MM-DD HH:MM:SS' (KST) → 'YYYYMMDDHHMMSS' (KBoard 비교용).
	 */
	private function mysql_to_kboard_dt( string $kst ): string {
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

WP_CLI::add_command( 'inquiry migrate-kboard', static function ( array $args, array $assoc ): void {
	( new Inquiry_Board_Migrate_Command() )->migrate_kboard( $args, $assoc );
} );
