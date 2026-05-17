<?php
/**
 * WP-CLI: wp inquiry migrate-kboard
 *
 * KBoard 게시판 데이터를 CPT inquiry 로 마이그레이션한다.
 * 실제 처리 로직은 Inquiry_Board_Migrator(inc/migrator.php) 코어를 호출하며,
 * 본 파일은 CLI 옵션 파싱 + 진행 로그 출력 용도다.
 *
 * 관리화면 AJAX 러너(inc/migration-runner.php) 와 동일한 코어를 공유한다.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

class Inquiry_Board_Migrate_Command {

	private Inquiry_Board_Migrator $core;

	public function __construct() {
		$this->core = new Inquiry_Board_Migrator();
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
	 * [--only=<part>]
	 * : posts|attachments|comments 중 하나만 실행. 기본 전부.
	 *
	 * ## EXAMPLES
	 *
	 *   wp inquiry migrate-kboard --board=1 --dry-run
	 *   wp inquiry migrate-kboard --board=1 --batch=200
	 *   wp inquiry migrate-kboard --board=1 --only=comments
	 */
	public function migrate_kboard( array $args, array $assoc ): void {
		$board_id = (int) ( $assoc['board'] ?? 0 );
		if ( $board_id <= 0 ) {
			WP_CLI::error( '--board=<kboard_uid> 가 필요합니다.' );
		}
		if ( ! $this->core->kboard_available() ) {
			WP_CLI::error( 'KBoard 테이블({prefix}kboard_board_setting) 을 찾지 못했습니다.' );
		}

		$dry    = ! empty( $assoc['dry-run'] );
		$batch  = max( 1, (int) ( $assoc['batch'] ?? 200 ) );
		$resume = (int) ( $assoc['resume-from'] ?? 0 );
		$only   = isset( $assoc['only'] ) ? sanitize_key( (string) $assoc['only'] ) : '';

		WP_CLI::log( sprintf(
			'시작: board=%d, dry-run=%s, batch=%d, resume=%d, only=%s',
			$board_id, $dry ? 'yes' : 'no', $batch, $resume, $only ?: 'all'
		) );

		$this->report_categories( $board_id );

		if ( ! $only || $only === 'posts' ) {
			$this->run_stage( 'posts', $board_id, $batch, $resume, $dry );
		}
		if ( ! $only || $only === 'attachments' ) {
			$this->run_stage( 'attachments', $board_id, $batch, 0, $dry );
		}
		if ( ! $only || $only === 'comments' ) {
			$this->run_stage( 'comments', $board_id, $batch, 0, $dry );
		}

		WP_CLI::success( '완료.' );
	}

	private function report_categories( int $board_id ): void {
		$summary = $this->core->dry_run_summary( $board_id );
		WP_CLI::log( 'distinct(category1, category2) 목록:' );
		foreach ( $summary['categories'] as $c ) {
			WP_CLI::log( sprintf( '  cat1=%s | cat2=%s  →  %s  (%d)', $c['category1'], $c['category2'], $c['slug'], $c['count'] ) );
		}
		WP_CLI::log( sprintf( '미명시 매핑(→etc)으로 떨어진 row 수: %d', $summary['unmapped_categories'] ) );
	}

	private function run_stage( string $stage, int $board_id, int $batch, int $cursor, bool $dry ): void {
		$totals = [ 'processed' => 0, 'errors' => 0 ];
		$agg    = [];
		while ( true ) {
			$deadline = microtime( true ) + 60.0; // CLI 는 큰 deadline 으로 batch 처리.
			$res = $this->run_one( $stage, $board_id, $batch, $cursor, $deadline, $dry );
			$cursor = $res['cursor'];
			$totals['processed'] += $res['processed'];
			$totals['errors']    += count( $res['errors'] );
			foreach ( $res['stats'] as $k => $v ) {
				$agg[ $k ] = ( $agg[ $k ] ?? 0 ) + $v;
			}
			foreach ( $res['errors'] as $e ) {
				WP_CLI::warning( $e );
			}
			WP_CLI::log( sprintf( '%s 진행: cursor=%d processed=%d', $stage, $cursor, $totals['processed'] ) );
			if ( $res['done'] ) {
				break;
			}
		}
		$parts = [];
		foreach ( $agg as $k => $v ) {
			$parts[] = "$k=$v";
		}
		WP_CLI::success( sprintf( '%s 완료: %s, errors=%d', $stage, implode( ', ', $parts ), $totals['errors'] ) );
	}

	private function run_one( string $stage, int $board_id, int $batch, int $cursor, float $deadline, bool $dry ): array {
		switch ( $stage ) {
			case 'posts':
				return $this->core->migrate_posts_batch( $board_id, $batch, $cursor, $deadline, $dry );
			case 'attachments':
				return $this->core->migrate_attachments_batch( $board_id, $batch, $cursor, $deadline, $dry );
			case 'comments':
				return $this->core->migrate_comments_batch( $board_id, $batch, $cursor, $deadline, $dry );
		}
		WP_CLI::error( "unknown stage: $stage" );
	}
}

WP_CLI::add_command( 'inquiry migrate-kboard', static function ( array $args, array $assoc ): void {
	( new Inquiry_Board_Migrate_Command() )->migrate_kboard( $args, $assoc );
} );
