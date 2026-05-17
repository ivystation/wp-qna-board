<?php
/**
 * 마이그레이션 AJAX 러너 — 관리화면에서 단계별 batch 를 폴링으로 실행한다.
 *
 * 엔드포인트(모두 admin-ajax 기반, capability=manage_options + nonce=inquiry_migration):
 *   - wp_ajax_inquiry_migration_dryrun  — dry-run 요약 (게시판 별)
 *   - wp_ajax_inquiry_migration_start   — running 상태 진입 + cursor 초기화
 *   - wp_ajax_inquiry_migration_tick    — 다음 batch 1회 실행, 갱신된 상태 반환
 *   - wp_ajax_inquiry_migration_status  — 현재 상태만 조회 (페이지 재진입 시)
 *   - wp_ajax_inquiry_migration_cancel  — running → cancelled 전환
 *   - wp_ajax_inquiry_migration_reset   — cancelled/done/error → idle (새 작업 준비)
 *
 * 상태 옵션: `inquiry_board_migration_runtime` (자동로드 off).
 *
 * @phpstan-type RunnerState array{
 *   status: string,
 *   board_id: int,
 *   batch: int,
 *   stage: string,
 *   cursor: array{posts:int,attachments:int,comments:int},
 *   totals: array{posts:int,comments:int,attachments:int},
 *   progress: array,
 *   errors: array,
 *   logs: array,
 *   started_at: ?string,
 *   updated_at: ?string,
 *   finished_at: ?string,
 *   message: string
 * }
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const INQUIRY_BOARD_RUNTIME_OPTION = 'inquiry_board_migration_runtime';
const INQUIRY_BOARD_RUNNER_NONCE   = 'inquiry_migration';
const INQUIRY_BOARD_LOG_LIMIT      = 30;
const INQUIRY_BOARD_ERROR_LIMIT    = 30;

function inquiry_board_runtime_defaults(): array {
	return [
		'status'      => 'idle',   // idle|running|paused|cancelled|done|error
		'board_id'    => 0,
		'batch'       => 100,
		'stage'       => 'posts',
		'cursor'      => [ 'posts' => 0, 'attachments' => 0, 'comments' => 0 ],
		'totals'      => [ 'posts' => 0, 'comments' => 0, 'attachments' => 0 ],
		'progress'    => [
			'posts'       => [ 'inserted' => 0, 'skipped' => 0, 'trashed' => 0 ],
			'attachments' => [ 'ok' => 0, 'fail' => 0 ],
			'comments'    => [ 'inserted' => 0, 'trashed' => 0 ],
		],
		'errors'      => [],
		'logs'        => [],
		'started_at'  => null,
		'updated_at'  => null,
		'finished_at' => null,
		'message'     => '',
	];
}

function inquiry_board_runtime_get(): array {
	$raw = get_option( INQUIRY_BOARD_RUNTIME_OPTION, [] );
	if ( ! is_array( $raw ) ) {
		$raw = [];
	}
	return array_replace_recursive( inquiry_board_runtime_defaults(), $raw );
}

function inquiry_board_runtime_save( array $state ): void {
	$state['updated_at'] = current_time( 'mysql' );
	update_option( INQUIRY_BOARD_RUNTIME_OPTION, $state, false );
}

function inquiry_board_runtime_check_auth(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( [ 'message' => '권한이 없습니다.' ], 403 );
	}
	check_ajax_referer( INQUIRY_BOARD_RUNNER_NONCE, 'nonce' );
}

/**
 * AJAX: dry-run 요약 — 시작 전 검증용. 상태는 변경하지 않는다.
 */
add_action( 'wp_ajax_inquiry_migration_dryrun', static function (): void {
	inquiry_board_runtime_check_auth();
	$board_id = isset( $_POST['board_id'] ) ? (int) $_POST['board_id'] : 0;
	$core     = new Inquiry_Board_Migrator();
	if ( ! $core->kboard_available() ) {
		wp_send_json_error( [ 'message' => 'KBoard 테이블이 존재하지 않습니다.' ], 400 );
	}
	if ( $board_id <= 0 ) {
		wp_send_json_error( [ 'message' => '게시판 uid 가 필요합니다.' ], 400 );
	}
	wp_send_json_success( $core->dry_run_summary( $board_id ) );
} );

/**
 * AJAX: 시작 — 동시성 락 후 상태 초기화.
 *
 * 입력: board_id, batch, backup_confirmed(=1).
 */
add_action( 'wp_ajax_inquiry_migration_start', static function (): void {
	inquiry_board_runtime_check_auth();
	$board_id          = isset( $_POST['board_id'] ) ? (int) $_POST['board_id'] : 0;
	$batch             = isset( $_POST['batch'] ) ? max( 10, min( 500, (int) $_POST['batch'] ) ) : 100;
	$backup_confirmed  = ! empty( $_POST['backup_confirmed'] );

	if ( $board_id <= 0 ) {
		wp_send_json_error( [ 'message' => '게시판 uid 가 필요합니다.' ], 400 );
	}
	if ( ! $backup_confirmed ) {
		wp_send_json_error( [ 'message' => 'DB 백업 확인 체크가 필요합니다.' ], 400 );
	}

	$core = new Inquiry_Board_Migrator();
	if ( ! $core->kboard_available() ) {
		wp_send_json_error( [ 'message' => 'KBoard 테이블이 존재하지 않습니다.' ], 400 );
	}
	$board = $core->get_board( $board_id );
	if ( ! $board ) {
		wp_send_json_error( [ 'message' => '해당 uid 의 게시판이 없습니다.' ], 400 );
	}

	$current = inquiry_board_runtime_get();
	if ( $current['status'] === 'running' ) {
		wp_send_json_error( [
			'message' => '이미 다른 마이그레이션이 진행 중입니다. 완료 또는 취소 후 다시 시작하세요.',
			'state'   => $current,
		], 409 );
	}

	$summary = $core->dry_run_summary( $board_id );

	$state = inquiry_board_runtime_defaults();
	$state['status']     = 'running';
	$state['board_id']   = $board_id;
	$state['batch']      = $batch;
	$state['stage']      = 'posts';
	$state['totals']     = [
		'posts'       => $summary['total_posts'],
		'comments'    => $summary['total_comments'],
		'attachments' => $summary['total_attachments'],
	];
	$state['started_at'] = current_time( 'mysql' );
	$state['logs'][]     = sprintf(
		'[%s] 시작: board=%d (%s), batch=%d',
		current_time( 'mysql' ),
		$board_id,
		(string) $board['board_name'],
		$batch
	);
	inquiry_board_runtime_save( $state );

	wp_send_json_success( $state );
} );

/**
 * AJAX: 다음 batch 1회 실행.
 *
 * 응답: 갱신된 상태(state) + 이번 tick 의 batch 결과.
 */
add_action( 'wp_ajax_inquiry_migration_tick', static function (): void {
	inquiry_board_runtime_check_auth();
	$state = inquiry_board_runtime_get();

	if ( $state['status'] === 'cancelled' ) {
		wp_send_json_success( [ 'state' => $state, 'batch' => null ] );
	}
	if ( $state['status'] !== 'running' ) {
		wp_send_json_error( [
			'message' => 'running 상태가 아닙니다. 먼저 시작하세요.',
			'state'   => $state,
		], 409 );
	}

	$core = new Inquiry_Board_Migrator();
	if ( ! $core->kboard_available() ) {
		$state['status']      = 'error';
		$state['message']     = 'KBoard 테이블이 사라졌습니다.';
		$state['finished_at'] = current_time( 'mysql' );
		inquiry_board_runtime_save( $state );
		wp_send_json_error( [ 'message' => $state['message'], 'state' => $state ], 500 );
	}

	$stage     = $state['stage'];
	$board_id  = (int) $state['board_id'];
	$batch     = (int) $state['batch'];
	$deadline  = microtime( true ) + 12.0; // tick 당 처리 한도 (PHP 30초 / Cloudways 일반 기준 안전치).

	$cursor = (int) ( $state['cursor'][ $stage ] ?? 0 );
	switch ( $stage ) {
		case 'posts':
			$res = $core->migrate_posts_batch( $board_id, $batch, $cursor, $deadline, false );
			break;
		case 'attachments':
			$res = $core->migrate_attachments_batch( $board_id, $batch, $cursor, $deadline, false );
			break;
		case 'comments':
			$res = $core->migrate_comments_batch( $board_id, $batch, $cursor, $deadline, false );
			break;
		default:
			$state['status']      = 'done';
			$state['finished_at'] = current_time( 'mysql' );
			$state['logs'][]      = sprintf( '[%s] 모든 단계 완료', current_time( 'mysql' ) );
			inquiry_board_runtime_save( $state );
			wp_send_json_success( [ 'state' => $state, 'batch' => null ] );
	}

	$state['cursor'][ $stage ] = (int) $res['cursor'];
	foreach ( $res['stats'] as $k => $v ) {
		$state['progress'][ $stage ][ $k ] = ( $state['progress'][ $stage ][ $k ] ?? 0 ) + (int) $v;
	}
	foreach ( $res['logs'] as $line ) {
		$state['logs'][] = '[' . current_time( 'mysql' ) . '] ' . $line;
	}
	if ( count( $state['logs'] ) > INQUIRY_BOARD_LOG_LIMIT ) {
		$state['logs'] = array_slice( $state['logs'], - INQUIRY_BOARD_LOG_LIMIT );
	}
	foreach ( $res['errors'] as $e ) {
		$state['errors'][] = '[' . current_time( 'mysql' ) . '] ' . $e;
	}
	if ( count( $state['errors'] ) > INQUIRY_BOARD_ERROR_LIMIT ) {
		$state['errors'] = array_slice( $state['errors'], - INQUIRY_BOARD_ERROR_LIMIT );
	}

	if ( $res['done'] ) {
		$next = inquiry_board_runtime_next_stage( $stage );
		if ( $next === null ) {
			$state['status']      = 'done';
			$state['stage']       = 'done';
			$state['finished_at'] = current_time( 'mysql' );
			$state['logs'][]      = '[' . current_time( 'mysql' ) . '] 모든 단계 완료';
		} else {
			$state['stage']  = $next;
			$state['logs'][] = '[' . current_time( 'mysql' ) . '] stage 전환 → ' . $next;
		}
	}

	inquiry_board_runtime_save( $state );
	wp_send_json_success( [ 'state' => $state, 'batch' => $res ] );
} );

function inquiry_board_runtime_next_stage( string $current ): ?string {
	$flow = [ 'posts' => 'attachments', 'attachments' => 'comments', 'comments' => null ];
	return $flow[ $current ] ?? null;
}

/**
 * AJAX: 상태 조회 — 페이지 재진입/리프레시 시.
 */
add_action( 'wp_ajax_inquiry_migration_status', static function (): void {
	inquiry_board_runtime_check_auth();
	wp_send_json_success( inquiry_board_runtime_get() );
} );

/**
 * AJAX: 취소.
 */
add_action( 'wp_ajax_inquiry_migration_cancel', static function (): void {
	inquiry_board_runtime_check_auth();
	$state = inquiry_board_runtime_get();
	if ( $state['status'] !== 'running' ) {
		wp_send_json_error( [ 'message' => 'running 상태가 아닙니다.', 'state' => $state ], 409 );
	}
	$state['status']      = 'cancelled';
	$state['finished_at'] = current_time( 'mysql' );
	$state['logs'][]      = '[' . current_time( 'mysql' ) . '] 사용자 취소';
	inquiry_board_runtime_save( $state );
	wp_send_json_success( $state );
} );

/**
 * AJAX: 리셋 — 다음 실행을 위해 상태 초기화.
 *
 * 데이터 자체는 그대로 두고 runtime 옵션만 비운다. 이미 INSERT 된 글/댓글은 idempotent
 * 메타 덕분에 다음 실행 시 자동으로 skip 된다.
 */
add_action( 'wp_ajax_inquiry_migration_reset', static function (): void {
	inquiry_board_runtime_check_auth();
	$state = inquiry_board_runtime_get();
	if ( $state['status'] === 'running' ) {
		wp_send_json_error( [ 'message' => '진행 중인 작업은 취소부터 하세요.', 'state' => $state ], 409 );
	}
	delete_option( INQUIRY_BOARD_RUNTIME_OPTION );
	wp_send_json_success( inquiry_board_runtime_get() );
} );
