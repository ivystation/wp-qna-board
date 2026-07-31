<?php
/**
 * 설정 페이지: Q&A게시판 → 설정.
 *
 * CPT(inquiry) 메뉴 하위에 submenu 로 등록되며, 하나의 페이지 안에서
 * 일반설정 / 마이그레이션 / 사용방법 3개 탭으로 라우팅된다.
 *
 *   ?post_type=inquiry&page=wp-qna-board&tab=general|migration|usage
 *
 * 마이그레이션 탭은 좌측에 소스 게시판 리스트(서브탭)를 두고,
 * 우측에 해당 소스 설정/안내 패널을 노출한다. 현재 KBoard 만 활성.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const INQUIRY_BOARD_SETTINGS_PAGE = 'wp-qna-board';
const INQUIRY_BOARD_PARENT_SLUG   = 'edit.php?post_type=inquiry';

add_action( 'admin_menu', 'inquiry_board_settings_menu' );
add_action( 'admin_init', 'inquiry_board_settings_register' );
add_action( 'admin_enqueue_scripts', 'inquiry_board_settings_enqueue' );

/**
 * 마이그레이션 탭(KBoard) 진입 시 JS/CSS 와 부트스트랩 데이터 enqueue.
 */
function inquiry_board_settings_enqueue( string $hook_suffix ): void {
	if ( ! isset( $_GET['page'] ) || $_GET['page'] !== INQUIRY_BOARD_SETTINGS_PAGE ) {
		return;
	}
	$tab    = isset( $_GET['tab'] ) ? sanitize_key( (string) $_GET['tab'] ) : 'general';
	$source = isset( $_GET['source'] ) ? sanitize_key( (string) $_GET['source'] ) : 'kboard';
	if ( $tab !== 'migration' || $source !== 'kboard' ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	wp_enqueue_style(
		'inquiry-migration',
		INQUIRY_BOARD_URL . 'assets/migration.css',
		[],
		INQUIRY_BOARD_VERSION
	);
	wp_enqueue_script(
		'inquiry-migration',
		INQUIRY_BOARD_URL . 'assets/migration.js',
		[],
		INQUIRY_BOARD_VERSION,
		true
	);

	$mig      = wp_parse_args( get_option( 'inquiry_board_migration', [] ), inquiry_board_migration_defaults() );
	$board_id = (int) ( $mig['kboard_board_id'] ?? 0 );
	$batch    = max( 10, min( 500, (int) ( $mig['kboard_batch'] ?? 100 ) ) );

	$board_name = '';
	if ( $board_id > 0 ) {
		global $wpdb;
		$board_name = (string) $wpdb->get_var( $wpdb->prepare(
			"SELECT board_name FROM {$wpdb->prefix}kboard_board_setting WHERE uid=%d",
			$board_id
		) );
	}

	wp_localize_script( 'inquiry-migration', 'InquiryMigration', [
		'ajax_url'   => admin_url( 'admin-ajax.php' ),
		'nonce'      => wp_create_nonce( INQUIRY_BOARD_RUNNER_NONCE ),
		'board_id'   => $board_id,
		'board_name' => $board_name,
		'batch'      => $batch,
	] );
}

function inquiry_board_settings_menu(): void {
	add_submenu_page(
		INQUIRY_BOARD_PARENT_SLUG,
		__( 'Q&A 게시판 설정', 'wp-qna-board' ),
		__( '설정', 'wp-qna-board' ),
		'manage_options',
		INQUIRY_BOARD_SETTINGS_PAGE,
		'inquiry_board_settings_page'
	);
}

function inquiry_board_settings_register(): void {
	register_setting( 'inquiry_board', 'inquiry_board_settings', [
		'type'              => 'array',
		'sanitize_callback' => 'inquiry_board_settings_sanitize',
		'default'           => inquiry_board_settings_defaults(),
	] );

	register_setting( 'inquiry_board_migration', 'inquiry_board_migration', [
		'type'              => 'array',
		'sanitize_callback' => 'inquiry_board_migration_sanitize',
		'default'           => inquiry_board_migration_defaults(),
	] );
}

function inquiry_board_settings_defaults(): array {
	return [
		'posts_per_page'       => 20,
		'recaptcha_site_key'   => '',
		'recaptcha_secret'     => '',
		'recaptcha_min_score'  => 0.3,
		'allowed_ext'          => 'jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,hwp,zip',
		'max_upload_mb'        => 10,
		'session_ttl'          => 86400,
		'notify_email'         => '',
		'password_min_length'  => 4,
		'password_required'    => 1,
		'github_token'         => '',
		'legacy_redirect_enabled' => 0,
	];
}

function inquiry_board_migration_defaults(): array {
	return [
		'kboard_board_id'  => 0,
		'kboard_batch'     => 200,
		'kboard_only'      => '',
		'kboard_since'     => '',
	];
}

function inquiry_board_settings_sanitize( $raw ): array {
	$d   = inquiry_board_settings_defaults();
	$out = $d;
	$raw = is_array( $raw ) ? $raw : [];

	$out['posts_per_page']      = max( 1, min( 200, (int) ( $raw['posts_per_page'] ?? $d['posts_per_page'] ) ) );
	$out['recaptcha_site_key']  = sanitize_text_field( (string) ( $raw['recaptcha_site_key']  ?? '' ) );
	$out['recaptcha_secret']    = sanitize_text_field( (string) ( $raw['recaptcha_secret']    ?? '' ) );
	$out['recaptcha_min_score'] = max( 0.0, min( 1.0, (float) ( $raw['recaptcha_min_score'] ?? 0.3 ) ) );

	$exts = preg_replace( '/[^a-z0-9,]+/', '', strtolower( (string) ( $raw['allowed_ext'] ?? $d['allowed_ext'] ) ) );
	$out['allowed_ext']         = $exts ?: $d['allowed_ext'];
	$out['max_upload_mb']       = max( 1, (int) ( $raw['max_upload_mb'] ?? 10 ) );
	$out['session_ttl']         = max( 600, (int) ( $raw['session_ttl'] ?? 86400 ) );
	// 쉼표 구분 다중 수신자 허용. 유효하지 않은 주소는 조용히 탈락시킨다.
	$out['notify_email']        = implode( ', ', inquiry_board_parse_email_list( (string) ( $raw['notify_email'] ?? '' ) ) );
	$out['password_min_length'] = max( 1, (int) ( $raw['password_min_length'] ?? 4 ) );
	$out['password_required']   = ! empty( $raw['password_required'] ) ? 1 : 0;
	// PAT 형식만 통과시킨다. 브라우저 비밀번호 자동완성이 사이트 로그인 비밀번호를 이 필드에
	// 흘려넣어 평문으로 DB 에 저장되는 사고가 반복됐다(2026-05-17, 2026-07-31). 형식 검증이 그 방어선.
	$gh                         = trim( sanitize_text_field( (string) ( $raw['github_token'] ?? '' ) ) );
	$out['github_token']        = preg_match( '/^(ghp_[A-Za-z0-9]{36,}|github_pat_[A-Za-z0-9_]{22,}|[0-9a-f]{40})$/', $gh ) ? $gh : '';
	// sanitize 는 WP-CLI 의 update_option 경로에서도 돌기 때문에 admin 전용 함수는 가드가 필요하다.
	if ( '' !== $gh && '' === $out['github_token'] && function_exists( 'add_settings_error' ) ) {
		add_settings_error(
			'inquiry_board_settings',
			'ib_gh_token_format',
			__( 'GitHub Token 형식이 올바르지 않아 저장하지 않았습니다 (ghp_… / github_pat_… 만 허용). 브라우저 비밀번호 자동완성이 값을 채웠는지 확인하세요.', 'wp-qna-board' ),
			'error'
		);
	}
	$out['legacy_redirect_enabled'] = ! empty( $raw['legacy_redirect_enabled'] ) ? 1 : 0;

	// 토큰이 바뀌면 GitHub 캐시를 즉시 폐기.
	if ( function_exists( 'delete_site_transient' ) ) {
		delete_site_transient( 'inquiry_board_gh_release' );
	}
	return $out;
}

function inquiry_board_migration_sanitize( $raw ): array {
	$d   = inquiry_board_migration_defaults();
	$out = $d;
	$raw = is_array( $raw ) ? $raw : [];

	$out['kboard_board_id'] = max( 0, (int) ( $raw['kboard_board_id'] ?? 0 ) );
	$out['kboard_batch']    = max( 1, min( 1000, (int) ( $raw['kboard_batch'] ?? 200 ) ) );

	$only = sanitize_key( (string) ( $raw['kboard_only'] ?? '' ) );
	$out['kboard_only'] = in_array( $only, [ '', 'posts', 'attachments', 'comments' ], true ) ? $only : '';

	$since = trim( (string) ( $raw['kboard_since'] ?? '' ) );
	$out['kboard_since'] = $since !== '' && strtotime( $since ) ? $since : '';

	return $out;
}

/**
 * 일반 설정 옵션을 기본값과 병합해 반환.
 */
function inquiry_board_get_settings(): array {
	return wp_parse_args( get_option( 'inquiry_board_settings', [] ), inquiry_board_settings_defaults() );
}

/**
 * 목록 페이지당 노출 글 수.
 */
function inquiry_board_get_posts_per_page(): int {
	$opts = inquiry_board_get_settings();
	return max( 1, (int) $opts['posts_per_page'] );
}

function inquiry_board_settings_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$tabs = [
		'general'   => __( '일반설정',  'wp-qna-board' ),
		'migration' => __( '마이그레이션', 'wp-qna-board' ),
		'usage'     => __( '사용방법',  'wp-qna-board' ),
	];
	$current = isset( $_GET['tab'] ) ? sanitize_key( (string) $_GET['tab'] ) : 'general';
	if ( ! isset( $tabs[ $current ] ) ) {
		$current = 'general';
	}
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Q&A 게시판 설정', 'wp-qna-board' ); ?></h1>

		<h2 class="nav-tab-wrapper">
			<?php foreach ( $tabs as $slug => $label ) :
				$url = add_query_arg( [
					'post_type' => 'inquiry',
					'page'      => INQUIRY_BOARD_SETTINGS_PAGE,
					'tab'       => $slug,
				], admin_url( 'edit.php' ) );
				$cls = 'nav-tab' . ( $slug === $current ? ' nav-tab-active' : '' );
				?>
				<a class="<?php echo esc_attr( $cls ); ?>" href="<?php echo esc_url( $url ); ?>">
					<?php echo esc_html( $label ); ?>
				</a>
			<?php endforeach; ?>
		</h2>

		<?php
		switch ( $current ) {
			case 'migration':
				inquiry_board_settings_render_migration();
				break;
			case 'usage':
				inquiry_board_settings_render_usage();
				break;
			case 'general':
			default:
				inquiry_board_settings_render_general();
				break;
		}
		?>
	</div>
	<?php
}

function inquiry_board_settings_render_general(): void {
	$opts = inquiry_board_get_settings();
	?>
	<form method="post" action="options.php">
		<?php settings_fields( 'inquiry_board' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th colspan="2"><h2 class="title"><?php esc_html_e( '목록 표시', 'wp-qna-board' ); ?></h2></th>
			</tr>
			<tr>
				<th scope="row"><label for="ibs_ppp"><?php esc_html_e( '페이지당 게시물 수', 'wp-qna-board' ); ?></label></th>
				<td>
					<input type="number" min="1" max="200" id="ibs_ppp" name="inquiry_board_settings[posts_per_page]" value="<?php echo esc_attr( (string) $opts['posts_per_page'] ); ?>">
					<p class="description"><?php esc_html_e( '[inquiry_form] 숏코드에 posts_per_page 속성이 명시되면 그 값이 우선합니다.', 'wp-qna-board' ); ?></p>
				</td>
			</tr>

			<tr>
				<th colspan="2"><h2 class="title"><?php esc_html_e( '작성 폼', 'wp-qna-board' ); ?></h2></th>
			</tr>
			<tr>
				<th scope="row"><label for="ibs_pwd_min"><?php esc_html_e( '비밀번호 최소 자릿수', 'wp-qna-board' ); ?></label></th>
				<td><input type="number" min="1" id="ibs_pwd_min" name="inquiry_board_settings[password_min_length]" value="<?php echo esc_attr( (string) $opts['password_min_length'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( '글 공개 설정', 'wp-qna-board' ); ?></th>
				<td>
					<label><input type="checkbox" name="inquiry_board_settings[password_required]" value="1" <?php checked( ! empty( $opts['password_required'] ) ); ?>> <?php esc_html_e( '모든 글을 비공개로 강제 (비밀번호 필수)', 'wp-qna-board' ); ?></label><br>
					<small><?php esc_html_e( '체크 해제 시 글쓰기 폼에 「공개 / 비공개」 선택이 나타나고 공개가 기본값이 됩니다. 비공개를 고를 때만 비밀번호를 받습니다.', 'wp-qna-board' ); ?></small>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ibs_ttl"><?php esc_html_e( '본인 세션 lifetime (초)', 'wp-qna-board' ); ?></label></th>
				<td><input type="number" min="600" id="ibs_ttl" name="inquiry_board_settings[session_ttl]" value="<?php echo esc_attr( (string) $opts['session_ttl'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="ibs_notify"><?php esc_html_e( '관리자 알림 수신 이메일', 'wp-qna-board' ); ?></label></th>
				<td><input type="email" multiple id="ibs_notify" name="inquiry_board_settings[notify_email]" class="regular-text" value="<?php echo esc_attr( $opts['notify_email'] ); ?>" placeholder="a@example.com, b@example.com"><br><small><?php esc_html_e( '여러 명에게 보내려면 쉼표로 구분합니다. 미입력 시 사이트 관리자 이메일로 발송', 'wp-qna-board' ); ?></small></td>
			</tr>

			<tr>
				<th colspan="2"><h2 class="title"><?php esc_html_e( '첨부 파일', 'wp-qna-board' ); ?></h2></th>
			</tr>
			<tr>
				<th scope="row"><label for="ibs_ext"><?php esc_html_e( '허용 확장자', 'wp-qna-board' ); ?></label></th>
				<td><input type="text" id="ibs_ext" name="inquiry_board_settings[allowed_ext]" class="regular-text" value="<?php echo esc_attr( $opts['allowed_ext'] ); ?>"><br><small><?php esc_html_e( '소문자, 쉼표 구분. 예: jpg,png,pdf', 'wp-qna-board' ); ?></small></td>
			</tr>
			<tr>
				<th scope="row"><label for="ibs_size"><?php esc_html_e( '용량 상한 (MB)', 'wp-qna-board' ); ?></label></th>
				<td><input type="number" min="1" id="ibs_size" name="inquiry_board_settings[max_upload_mb]" value="<?php echo esc_attr( (string) $opts['max_upload_mb'] ); ?>"></td>
			</tr>

			<tr>
				<th colspan="2"><h2 class="title"><?php esc_html_e( 'reCAPTCHA', 'wp-qna-board' ); ?></h2></th>
			</tr>
			<tr>
				<th scope="row"><label for="ibs_site_key"><?php esc_html_e( 'Site Key', 'wp-qna-board' ); ?></label></th>
				<td><input type="text" id="ibs_site_key" name="inquiry_board_settings[recaptcha_site_key]" class="regular-text" value="<?php echo esc_attr( $opts['recaptcha_site_key'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="ibs_secret"><?php esc_html_e( 'Secret', 'wp-qna-board' ); ?></label></th>
				<td><input type="text" id="ibs_secret" name="inquiry_board_settings[recaptcha_secret]" class="regular-text" value="<?php echo esc_attr( $opts['recaptcha_secret'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="ibs_score"><?php esc_html_e( '최소 점수 (0.0~1.0)', 'wp-qna-board' ); ?></label></th>
				<td><input type="number" step="0.1" min="0" max="1" id="ibs_score" name="inquiry_board_settings[recaptcha_min_score]" value="<?php echo esc_attr( (string) $opts['recaptcha_min_score'] ); ?>"></td>
			</tr>

			<tr>
				<th colspan="2"><h2 class="title"><?php esc_html_e( '레거시 URL 처리', 'wp-qna-board' ); ?></h2></th>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( '레거시 URL → 새 글 자동 리다이렉트', 'wp-qna-board' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="inquiry_board_settings[legacy_redirect_enabled]" value="1" <?php checked( ! empty( $opts['legacy_redirect_enabled'] ) ); ?>>
						<?php esc_html_e( '활성화 (KBoard 등 레거시 URL을 마이그레이션된 새 글로 301 리다이렉트)', 'wp-qna-board' ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( '인식 패턴: ?mod=document&uid=NNN / ?uid=NNN. 끄면 원본 게시판 글이 그대로 노출됩니다. SEO 보존 목적이라면 켜두고, 원본·신규 게시판을 병행 운영하려면 끄세요. (기본: 끄기)', 'wp-qna-board' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th colspan="2"><h2 class="title"><?php esc_html_e( '자동 업데이트 (GitHub)', 'wp-qna-board' ); ?></h2></th>
			</tr>
			<tr>
				<th scope="row"><label for="ibs_gh_token"><?php esc_html_e( 'GitHub Token (선택)', 'wp-qna-board' ); ?></label></th>
				<td>
					<?php $has_const = defined( 'INQUIRY_BOARD_GH_TOKEN' ) && INQUIRY_BOARD_GH_TOKEN; ?>
					<?php // autocomplete="off" 는 브라우저 비밀번호 매니저가 무시한다. new-password 라야 사이트 로그인 비밀번호가 흘러들어오지 않는다. ?>
					<input type="password" id="ibs_gh_token" name="inquiry_board_settings[github_token]" class="regular-text" value="<?php echo esc_attr( $opts['github_token'] ); ?>" autocomplete="new-password" <?php disabled( $has_const ); ?>>
					<br>
					<small>
						<?php esc_html_e( 'Public 저장소(ivystation/wp-qna-board)는 토큰 없이도 동작. 비공개 저장소나 API 율 제한 회피용으로만 입력. wp-config.php 에 INQUIRY_BOARD_GH_TOKEN 정의 시 그 값이 우선.', 'wp-qna-board' ); ?>
					</small>
					<p>
						<a class="button button-secondary" href="<?php echo esc_url( add_query_arg( 'inquiry-board-flush-update', '1', admin_url( 'plugins.php' ) ) ); ?>"><?php esc_html_e( '업데이트 캐시 강제 갱신', 'wp-qna-board' ); ?></a>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( '업데이트 진단', 'wp-qna-board' ); ?></th>
				<td>
					<?php
					$diag = function_exists( 'inquiry_board_updater_diagnostics' ) ? inquiry_board_updater_diagnostics() : [];
					if ( ! $diag ) {
						echo '<em>' . esc_html__( '진단 함수를 로드하지 못했습니다.', 'wp-qna-board' ) . '</em>';
					} else {
						$ok = static function ( bool $b ): string {
							return $b ? '<span style="color:#1e7e34;">✓</span>' : '<span style="color:#b32d2e;">✗</span>';
						};
						?>
						<table class="widefat striped" style="max-width:720px;">
							<tbody>
								<tr>
									<th style="width:240px;"><?php esc_html_e( '현재 설치 버전', 'wp-qna-board' ); ?></th>
									<td><code><?php echo esc_html( $diag['current_version'] ); ?></code></td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'GitHub 최신 버전', 'wp-qna-board' ); ?></th>
									<td>
										<?php if ( $diag['latest_version'] !== '' ) : ?>
											<code><?php echo esc_html( $diag['latest_version'] ); ?></code>
											<small>(tag: <code><?php echo esc_html( $diag['latest_tag'] ); ?></code>)</small>
										<?php else : ?>
											<em><?php esc_html_e( '응답을 받지 못했습니다 (API 호출 실패 또는 빈 캐시).', 'wp-qna-board' ); ?></em>
										<?php endif; ?>
									</td>
								</tr>
								<tr>
									<th><?php esc_html_e( '새 버전이 더 높은가', 'wp-qna-board' ); ?></th>
									<td><?php echo $ok( (bool) $diag['is_newer'] ); ?></td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'update_plugins.response 등록', 'wp-qna-board' ); ?></th>
									<td>
										<?php echo $ok( (bool) $diag['in_response'] ); ?>
										<?php if ( ! $diag['in_response'] && $diag['in_no_update'] ) : ?>
											<small>(<?php esc_html_e( 'no_update 에 들어가 있음 — 비교상 새 버전이 더 높지 않다고 판단된 상태', 'wp-qna-board' ); ?>)</small>
										<?php endif; ?>
									</td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'GitHub 캐시 상태', 'wp-qna-board' ); ?></th>
									<td><?php echo $diag['cache_hit'] ? esc_html__( 'HIT (캐시 사용 중)', 'wp-qna-board' ) : esc_html__( 'MISS', 'wp-qna-board' ); ?></td>
								</tr>
								<tr>
									<th><?php esc_html_e( '인증 토큰', 'wp-qna-board' ); ?></th>
									<td>
										<?php if ( $diag['token_const'] ) : ?>
											<?php esc_html_e( 'wp-config.php 상수 사용', 'wp-qna-board' ); ?>
										<?php elseif ( $diag['token_set'] ) : ?>
											<?php esc_html_e( '옵션값 사용', 'wp-qna-board' ); ?>
										<?php else : ?>
											<?php esc_html_e( '없음 (public repo 라 정상)', 'wp-qna-board' ); ?>
										<?php endif; ?>
									</td>
								</tr>
								<tr>
									<th><?php esc_html_e( '플러그인 basename', 'wp-qna-board' ); ?></th>
									<td><code><?php echo esc_html( $diag['basename'] ); ?></code></td>
								</tr>
								<tr>
									<th><?php esc_html_e( '배포물 zip URL', 'wp-qna-board' ); ?></th>
									<td>
										<?php if ( $diag['zip_url'] ) : ?>
											<a href="<?php echo esc_url( $diag['zip_url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $diag['zip_url'] ); ?></a>
										<?php else : ?>
											<em>—</em>
										<?php endif; ?>
									</td>
								</tr>
								<?php if ( $diag['html_url'] ) : ?>
									<tr>
										<th><?php esc_html_e( '릴리스 페이지', 'wp-qna-board' ); ?></th>
										<td><a href="<?php echo esc_url( $diag['html_url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $diag['html_url'] ); ?></a></td>
									</tr>
								<?php endif; ?>
							</tbody>
						</table>
						<?php if ( $diag['is_newer'] && ! $diag['in_response'] ) : ?>
							<p style="margin-top:8px;">
								<?php esc_html_e( '새 버전이 인식됐지만 아직 update_plugins transient 에 주입되지 않았습니다. 위의 "업데이트 캐시 강제 갱신" 버튼을 누르거나 새로고침하면 즉시 반영됩니다.', 'wp-qna-board' ); ?>
							</p>
						<?php endif; ?>
						<?php
					}
					?>
				</td>
			</tr>
		</table>
		<?php submit_button(); ?>
	</form>
	<?php
}

function inquiry_board_settings_render_migration(): void {
	$sources = [
		'kboard'    => [
			'label'  => __( 'KBoard',    'wp-qna-board' ),
			'status' => 'active',
		],
		'mangboard' => [
			'label'  => __( 'MangBoard', 'wp-qna-board' ),
			'status' => 'planned',
		],
		'xe'        => [
			'label'  => __( 'XpressEngine / Rhymix', 'wp-qna-board' ),
			'status' => 'planned',
		],
		'gravity'   => [
			'label'  => __( 'Gravity Forms 엔트리', 'wp-qna-board' ),
			'status' => 'planned',
		],
	];

	$current = isset( $_GET['source'] ) ? sanitize_key( (string) $_GET['source'] ) : 'kboard';
	if ( ! isset( $sources[ $current ] ) ) {
		$current = 'kboard';
	}
	?>
	<div class="inquiry-mig-wrap" style="display:flex;gap:24px;margin-top:16px;align-items:flex-start;">
		<aside style="min-width:200px;">
			<h3 style="margin-top:0;"><?php esc_html_e( '소스 게시판', 'wp-qna-board' ); ?></h3>
			<ul class="subsubsub" style="display:flex;flex-direction:column;gap:6px;float:none;">
				<?php foreach ( $sources as $slug => $meta ) :
					$url = add_query_arg( [
						'post_type' => 'inquiry',
						'page'      => INQUIRY_BOARD_SETTINGS_PAGE,
						'tab'       => 'migration',
						'source'    => $slug,
					], admin_url( 'edit.php' ) );
					$is_active = $slug === $current;
					$is_ready  = $meta['status'] === 'active';
					?>
					<li>
						<a href="<?php echo esc_url( $url ); ?>" class="<?php echo $is_active ? 'current' : ''; ?>" style="font-weight:<?php echo $is_active ? '600' : '400'; ?>;">
							<?php echo esc_html( $meta['label'] ); ?>
						</a>
						<?php if ( ! $is_ready ) : ?>
							<span class="inq-mig-badge" style="margin-left:6px;padding:1px 6px;background:#f0f0f1;color:#646970;border-radius:10px;font-size:11px;"><?php esc_html_e( '준비중', 'wp-qna-board' ); ?></span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</aside>

		<div class="inquiry-mig-panel" style="flex:1;background:#fff;border:1px solid #c3c4c7;padding:20px 24px;">
			<?php
			if ( $sources[ $current ]['status'] === 'active' && $current === 'kboard' ) {
				inquiry_board_settings_render_migration_kboard();
			} else {
				inquiry_board_settings_render_migration_placeholder( $sources[ $current ]['label'] );
			}
			?>
		</div>
	</div>
	<?php
}

function inquiry_board_settings_render_migration_kboard(): void {
	global $wpdb;
	$mig = wp_parse_args( get_option( 'inquiry_board_migration', [] ), inquiry_board_migration_defaults() );

	// KBoard 의 게시판 목록 테이블은 실제로 `{prefix}kboard_board_setting` 이다.
	$has_kboard = (bool) $wpdb->get_var( $wpdb->prepare(
		'SHOW TABLES LIKE %s',
		$wpdb->prefix . 'kboard_board_setting'
	) );

	$boards = [];
	if ( $has_kboard ) {
		$rows = $wpdb->get_results( "SELECT uid, board_name FROM {$wpdb->prefix}kboard_board_setting ORDER BY uid ASC", ARRAY_A );
		foreach ( (array) $rows as $r ) {
			$boards[ (int) $r['uid'] ] = (string) $r['board_name'];
		}
	}
	?>
	<h2 style="margin-top:0;"><?php esc_html_e( 'KBoard → Q&A 게시판 마이그레이션', 'wp-qna-board' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'KBoard 게시판의 글 · 첨부 · 댓글을 inquiry CPT 로 이관합니다. 본 화면에서는 옵션을 저장하고 WP-CLI 명령을 생성합니다. 실제 실행은 안정성을 위해 WP-CLI 로 진행하는 것을 권장합니다.', 'wp-qna-board' ); ?>
	</p>

	<?php if ( ! $has_kboard ) : ?>
		<div class="notice notice-warning inline" style="margin:12px 0;">
			<p>
				<?php
				echo esc_html( sprintf(
					/* translators: %s: table name */
					__( '현재 사이트에서 KBoard 테이블(%s)을 찾지 못했습니다. KBoard 플러그인이 설치된 동일 DB 에서만 실행됩니다.', 'wp-qna-board' ),
					$wpdb->prefix . 'kboard_board_setting'
				) );
				?>
			</p>
		</div>
	<?php endif; ?>

	<form method="post" action="options.php">
		<?php settings_fields( 'inquiry_board_migration' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="ibm_board"><?php esc_html_e( '대상 KBoard 게시판', 'wp-qna-board' ); ?></label></th>
				<td>
					<?php if ( $boards ) : ?>
						<select id="ibm_board" name="inquiry_board_migration[kboard_board_id]">
							<option value="0"><?php esc_html_e( '— 선택 —', 'wp-qna-board' ); ?></option>
							<?php foreach ( $boards as $uid => $name ) : ?>
								<option value="<?php echo (int) $uid; ?>" <?php selected( (int) $mig['kboard_board_id'], (int) $uid ); ?>>
									<?php echo esc_html( sprintf( '#%d — %s', $uid, $name ?: '(이름 없음)' ) ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					<?php else : ?>
						<input type="number" min="0" id="ibm_board" name="inquiry_board_migration[kboard_board_id]" value="<?php echo esc_attr( (string) $mig['kboard_board_id'] ); ?>">
						<p class="description"><?php esc_html_e( 'KBoard 게시판 uid (wp_kboard_board_setting.uid) 를 입력하세요.', 'wp-qna-board' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ibm_batch"><?php esc_html_e( '배치 크기', 'wp-qna-board' ); ?></label></th>
				<td>
					<input type="number" min="1" max="1000" id="ibm_batch" name="inquiry_board_migration[kboard_batch]" value="<?php echo esc_attr( (string) $mig['kboard_batch'] ); ?>">
					<p class="description"><?php esc_html_e( '한 번에 처리할 row 수 (기본 200).', 'wp-qna-board' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ibm_only"><?php esc_html_e( '실행 범위', 'wp-qna-board' ); ?></label></th>
				<td>
					<select id="ibm_only" name="inquiry_board_migration[kboard_only]">
						<option value=""            <?php selected( $mig['kboard_only'], '' ); ?>><?php esc_html_e( '전체 (글 + 첨부 + 댓글)', 'wp-qna-board' ); ?></option>
						<option value="posts"       <?php selected( $mig['kboard_only'], 'posts' ); ?>><?php esc_html_e( '글만',     'wp-qna-board' ); ?></option>
						<option value="attachments" <?php selected( $mig['kboard_only'], 'attachments' ); ?>><?php esc_html_e( '첨부만',   'wp-qna-board' ); ?></option>
						<option value="comments"    <?php selected( $mig['kboard_only'], 'comments' ); ?>><?php esc_html_e( '댓글만',   'wp-qna-board' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ibm_since"><?php esc_html_e( '이후 데이터만 (delta)', 'wp-qna-board' ); ?></label></th>
				<td>
					<input type="text" id="ibm_since" name="inquiry_board_migration[kboard_since]" class="regular-text" value="<?php echo esc_attr( (string) $mig['kboard_since'] ); ?>" placeholder="YYYY-MM-DD HH:MM:SS">
					<p class="description"><?php esc_html_e( '지정 시 해당 시각 이후 created/updated 된 행만 처리합니다.', 'wp-qna-board' ); ?></p>
				</td>
			</tr>
		</table>
		<?php submit_button( __( '옵션 저장', 'wp-qna-board' ) ); ?>
	</form>

	<hr>

	<h3><?php esc_html_e( '관리 UI 에서 실행 (권장)', 'wp-qna-board' ); ?></h3>
	<p class="description">
		<?php esc_html_e( '브라우저를 열어둔 상태에서 단계별로 진행하며, 진행률·통계·로그가 실시간 표시됩니다. 페이지를 닫거나 새로고침해도 cursor 가 보존되어 이어 진행할 수 있습니다.', 'wp-qna-board' ); ?>
	</p>

	<?php inquiry_board_settings_render_migration_runner_ui( (int) $mig['kboard_board_id'] ); ?>

	<hr>

	<h3><?php esc_html_e( '실행 명령 (WP-CLI · SSH 직접 실행 시)', 'wp-qna-board' ); ?></h3>
	<?php
	$dry_cmd  = inquiry_board_build_kboard_cli( $mig, true );
	$run_cmd  = inquiry_board_build_kboard_cli( $mig, false );
	?>
	<p>
		<strong><?php esc_html_e( '① Dry-run (변경 없이 통계만)', 'wp-qna-board' ); ?></strong>
	</p>
	<pre style="background:#1d2327;color:#f0f0f1;padding:12px 14px;border-radius:4px;overflow-x:auto;"><code><?php echo esc_html( $dry_cmd ); ?></code></pre>

	<p>
		<strong><?php esc_html_e( '② 실제 실행', 'wp-qna-board' ); ?></strong>
	</p>
	<pre style="background:#1d2327;color:#f0f0f1;padding:12px 14px;border-radius:4px;overflow-x:auto;"><code><?php echo esc_html( $run_cmd ); ?></code></pre>

	<p class="description">
		<?php esc_html_e( '카테고리 매핑 룰은 inc/migration-map.php 의 inquiry_board_category_rules() 에서 조정합니다. dry-run 출력 하단의 "미명시 매핑(→etc)" 카운트를 보고 룰을 보강한 뒤 본 실행하세요.', 'wp-qna-board' ); ?>
	</p>
	<?php
}

/**
 * 현재 옵션 + dry-run 여부로 wp-cli 명령 문자열 생성.
 */
function inquiry_board_build_kboard_cli( array $mig, bool $dry ): string {
	$parts = [ 'wp inquiry migrate-kboard' ];
	$parts[] = '--board=' . (int) ( $mig['kboard_board_id'] ?? 0 );
	$parts[] = '--batch=' . (int) ( $mig['kboard_batch'] ?? 200 );
	if ( ! empty( $mig['kboard_only'] ) ) {
		$parts[] = '--only=' . preg_replace( '/[^a-z]/', '', (string) $mig['kboard_only'] );
	}
	if ( ! empty( $mig['kboard_since'] ) ) {
		$parts[] = '--since="' . str_replace( '"', '', (string) $mig['kboard_since'] ) . '"';
	}
	if ( $dry ) {
		$parts[] = '--dry-run';
	}
	return implode( ' ', $parts );
}

function inquiry_board_settings_render_migration_placeholder( string $label ): void {
	?>
	<h2 style="margin-top:0;">
		<?php echo esc_html( sprintf( /* translators: %s: source label */ __( '%s 마이그레이션', 'wp-qna-board' ), $label ) ); ?>
		<span style="margin-left:8px;padding:2px 10px;background:#f0f0f1;color:#646970;border-radius:10px;font-size:12px;vertical-align:middle;"><?php esc_html_e( '준비중', 'wp-qna-board' ); ?></span>
	</h2>
	<p><?php esc_html_e( '이 소스 게시판은 아직 지원하지 않습니다. 우선순위 요청은 README 또는 GitHub 이슈로 남겨주세요.', 'wp-qna-board' ); ?></p>
	<p>
		<a class="button button-secondary" href="https://github.com/ivystation/wp-qna-board/issues" target="_blank" rel="noopener">
			<?php esc_html_e( 'GitHub 이슈 열기', 'wp-qna-board' ); ?>
		</a>
	</p>
	<?php
}

function inquiry_board_settings_render_usage(): void {
	$archive = get_post_type_archive_link( 'inquiry' );
	?>
	<div class="inquiry-usage" style="max-width:900px;background:#fff;border:1px solid #c3c4c7;padding:20px 24px;margin-top:16px;">

		<h2 style="margin-top:0;"><?php esc_html_e( '1. 게시판 페이지 만들기', 'wp-qna-board' ); ?></h2>
		<p><?php esc_html_e( '원하는 페이지(예: /inquiries)를 만들고 본문에 아래 숏코드를 넣으세요. 기본은 목록 화면이며, "글쓰기" 버튼을 누르면 같은 페이지에서 작성 폼으로 전환됩니다.', 'wp-qna-board' ); ?></p>
		<pre style="background:#1d2327;color:#f0f0f1;padding:12px 14px;border-radius:4px;"><code>[inquiry_form]</code></pre>
		<p><?php esc_html_e( '주요 속성:', 'wp-qna-board' ); ?></p>
		<ul style="list-style:disc;padding-left:20px;">
			<li><code>posts_per_page</code> — <?php esc_html_e( '목록 페이지당 노출 글 수. 미지정 시 일반설정의 값 사용.', 'wp-qna-board' ); ?></li>
			<li><code>show_write_button</code> — <?php esc_html_e( '목록 상단 글쓰기 버튼 노출 여부 (1 또는 0).', 'wp-qna-board' ); ?></li>
			<li><code>category</code> — <?php esc_html_e( '특정 카테고리 슬러그로 한정.', 'wp-qna-board' ); ?></li>
			<li><code>view</code> — <code>auto</code> / <code>list</code> / <code>write</code> <?php esc_html_e( '강제 지정.', 'wp-qna-board' ); ?></li>
		</ul>

		<h2><?php esc_html_e( '2. 카테고리', 'wp-qna-board' ); ?></h2>
		<p>
			<?php esc_html_e( '플러그인 활성화 시 5개 기본 카테고리가 시드됩니다: 어학연수 / 대학·대학원 / 조기유학 / 비자 / 기타. 필요 시', 'wp-qna-board' ); ?>
			<a href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=inquiry_category&post_type=inquiry' ) ); ?>"><?php esc_html_e( '문의 카테고리 관리 화면', 'wp-qna-board' ); ?></a>
			<?php esc_html_e( '에서 추가·수정할 수 있습니다.', 'wp-qna-board' ); ?>
		</p>

		<h2><?php esc_html_e( '3. 비밀글과 본인 세션', 'wp-qna-board' ); ?></h2>
		<ul style="list-style:disc;padding-left:20px;">
			<li><?php esc_html_e( '작성자는 비밀번호를 직접 입력합니다 (일반설정에서 최소 자릿수·필수 여부 조정).', 'wp-qna-board' ); ?></li>
			<li><?php esc_html_e( '작성 직후에는 IP + 쿠키로 24시간(기본) 동안 본인 글로 인식되어, 비밀번호 입력 없이 열람·수정·첨부 추가가 가능합니다.', 'wp-qna-board' ); ?></li>
			<li><?php esc_html_e( '세션 lifetime 은 일반설정에서 초 단위로 조정합니다.', 'wp-qna-board' ); ?></li>
		</ul>

		<h2><?php esc_html_e( '4. 관리자 답변', 'wp-qna-board' ); ?></h2>
		<p><?php esc_html_e( '단일 글 페이지 하단의 댓글 영역에 관리자가 댓글을 남기면 자동으로 _is_admin_reply 메타가 부여되어 답변으로 표시됩니다.', 'wp-qna-board' ); ?></p>

		<h2><?php esc_html_e( '5. 알림 메일', 'wp-qna-board' ); ?></h2>
		<p><?php esc_html_e( '새 문의가 처음 발행(publish)되면 일반설정의 "관리자 알림 수신 이메일"(쉼표 구분 다중 지정 가능, 미입력 시 사이트 admin_email) 로 알림이 전송됩니다.', 'wp-qna-board' ); ?></p>
		<ul style="list-style:disc;padding-left:20px;">
			<li><?php esc_html_e( '프론트엔드 글쓰기 폼뿐 아니라 관리 화면 직접 작성 · WP-CLI · REST 등 모든 등록 경로에서 발송됩니다.', 'wp-qna-board' ); ?></li>
			<li><?php esc_html_e( '글 1건당 1회만 발송됩니다(_inquiry_notified 메타 기록). 수정 저장이나 휴지통 복구로는 재발송되지 않습니다.', 'wp-qna-board' ); ?></li>
			<li><?php esc_html_e( '초안(draft)·비공개 상태로 저장한 글은 발행 시점에 발송됩니다.', 'wp-qna-board' ); ?></li>
		</ul>

		<h2><?php esc_html_e( '6. 마이그레이션', 'wp-qna-board' ); ?></h2>
		<p><?php esc_html_e( 'KBoard 데이터는 [마이그레이션] 탭에서 옵션을 저장한 후, 생성된 WP-CLI 명령을 SSH 에서 실행합니다. idempotent 하므로 같은 명령을 여러 번 실행해도 중복 INSERT 가 발생하지 않습니다.', 'wp-qna-board' ); ?></p>

		<h2><?php esc_html_e( '7. 바로가기', 'wp-qna-board' ); ?></h2>
		<ul style="list-style:disc;padding-left:20px;">
			<li><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=inquiry' ) ); ?>"><?php esc_html_e( '문의 목록', 'wp-qna-board' ); ?></a></li>
			<li><a href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=inquiry_category&post_type=inquiry' ) ); ?>"><?php esc_html_e( '카테고리 관리', 'wp-qna-board' ); ?></a></li>
			<?php if ( $archive ) : ?>
				<li><a href="<?php echo esc_url( $archive ); ?>" target="_blank" rel="noopener"><?php esc_html_e( '프론트엔드 아카이브 보기', 'wp-qna-board' ); ?></a></li>
			<?php endif; ?>
		</ul>
	</div>
	<?php
}

/**
 * KBoard 마이그레이션 러너 UI (Dry-run → 시작 → 진행률 모니터링).
 *
 * AJAX 엔드포인트는 inc/migration-runner.php 가 처리한다.
 */
function inquiry_board_settings_render_migration_runner_ui( int $board_id ): void {
	if ( $board_id <= 0 ) {
		?>
		<div class="ibm-warning">
			<?php esc_html_e( '먼저 위에서 KBoard 게시판을 선택하고 "옵션 저장" 버튼을 누른 뒤 다시 이 화면으로 돌아오세요. 그 다음 단계가 활성화됩니다.', 'wp-qna-board' ); ?>
		</div>
		<?php
		return;
	}
	?>
	<div class="ibm-card">
		<h3><?php esc_html_e( '1. Dry-run 검증', 'wp-qna-board' ); ?></h3>
		<p class="description">
			<?php esc_html_e( '데이터를 변경하지 않고 글/댓글/첨부의 전체 건수, 카테고리 매핑 분포, 휴지통·미매핑 카테고리 카운트를 확인합니다. 매핑이 부족하면 inc/migration-map.php 의 inquiry_board_category_rules() 를 보강한 후 다시 실행하세요.', 'wp-qna-board' ); ?>
		</p>
		<div class="ibm-actions">
			<button type="button" class="button button-secondary" id="ibm-dryrun-btn"><?php esc_html_e( 'Dry-run 실행', 'wp-qna-board' ); ?></button>
		</div>
		<div id="ibm-dryrun-panel" hidden>
			<h4><?php esc_html_e( '요약', 'wp-qna-board' ); ?></h4>
			<div id="ibm-dryrun-summary" class="ibm-dryrun-block"></div>
			<h4><?php esc_html_e( '카테고리 매핑 분포', 'wp-qna-board' ); ?></h4>
			<div id="ibm-dryrun-categories" class="ibm-dryrun-block"></div>
		</div>
	</div>

	<div class="ibm-card">
		<h3><?php esc_html_e( '2. 시작 전 확인', 'wp-qna-board' ); ?></h3>
		<div class="ibm-warning">
			<strong><?php esc_html_e( '필수', 'wp-qna-board' ); ?>:</strong>
			<?php esc_html_e( '시작 전 DB 백업을 수행하세요. (예: SSH 에서', 'wp-qna-board' ); ?>
			<code>wp db export ~/private_html/backup-pre-kboard-$(date +%Y%m%d-%H%M).sql.gz</code>
			<?php esc_html_e( ') 마이그레이션은 idempotent 하므로 중간 취소 후 재시작해도 안전합니다 (이미 INSERT 된 글은 _legacy_kboard_uid 메타로 자동 skip).', 'wp-qna-board' ); ?>
		</div>
		<div class="ibm-row">
			<label>
				<input type="checkbox" id="ibm-backup-check">
				<?php esc_html_e( 'DB 백업을 완료했고, 운영 데이터에 영향을 줄 수 있음을 이해합니다.', 'wp-qna-board' ); ?>
			</label>
		</div>
		<div class="ibm-row">
			<label for="ibm-batch"><?php esc_html_e( 'tick 당 batch 크기', 'wp-qna-board' ); ?></label>
			<input type="number" id="ibm-batch" min="10" max="500" step="10" value="100" style="width:90px;">
			<span class="description"><?php esc_html_e( '한 번의 폴링에서 처리할 행 수. 100 권장.', 'wp-qna-board' ); ?></span>
		</div>
	</div>

	<div class="ibm-card">
		<h3><?php esc_html_e( '3. 실행', 'wp-qna-board' ); ?></h3>
		<div class="ibm-stage-row">
			<span><?php esc_html_e( '상태:', 'wp-qna-board' ); ?></span>
			<span id="ibm-status-badge" class="ibm-badge" data-status="idle"><?php esc_html_e( '대기', 'wp-qna-board' ); ?></span>
			<span id="ibm-status-msg" class="description"></span>
			<span><?php esc_html_e( '· 현재 단계:', 'wp-qna-board' ); ?> <strong id="ibm-stage-label">-</strong></span>
		</div>

		<div class="ibm-actions">
			<button type="button" class="button button-primary" id="ibm-start-btn" disabled><?php esc_html_e( '마이그레이션 시작', 'wp-qna-board' ); ?></button>
			<button type="button" class="button button-secondary" id="ibm-cancel-btn" disabled><?php esc_html_e( '취소', 'wp-qna-board' ); ?></button>
			<button type="button" class="button button-secondary" id="ibm-reset-btn" disabled><?php esc_html_e( '상태 리셋', 'wp-qna-board' ); ?></button>
		</div>

		<div id="ibm-progress" style="display:none;margin-top:14px;">
			<div class="ibm-progress-row">
				<span class="ibm-progress-label"><?php esc_html_e( '글', 'wp-qna-board' ); ?></span>
				<div class="ibm-progress-bar"><div class="ibm-progress-fill" id="ibm-bar-posts" role="progressbar">0 / 0</div></div>
			</div>
			<div class="ibm-progress-row">
				<span class="ibm-progress-label"><?php esc_html_e( '첨부', 'wp-qna-board' ); ?></span>
				<div class="ibm-progress-bar"><div class="ibm-progress-fill" id="ibm-bar-attachments" role="progressbar">0 / 0</div></div>
			</div>
			<div class="ibm-progress-row">
				<span class="ibm-progress-label"><?php esc_html_e( '댓글', 'wp-qna-board' ); ?></span>
				<div class="ibm-progress-bar"><div class="ibm-progress-fill" id="ibm-bar-comments" role="progressbar">0 / 0</div></div>
			</div>

			<div class="ibm-stats">
				<div><span><?php esc_html_e( '글 inserted', 'wp-qna-board' ); ?></span><strong id="ibm-stat-posts-inserted">0</strong></div>
				<div><span><?php esc_html_e( '글 skipped', 'wp-qna-board' ); ?></span><strong id="ibm-stat-posts-skipped">0</strong></div>
				<div><span><?php esc_html_e( '글 trashed', 'wp-qna-board' ); ?></span><strong id="ibm-stat-posts-trashed">0</strong></div>
				<div><span><?php esc_html_e( '첨부 ok', 'wp-qna-board' ); ?></span><strong id="ibm-stat-att-ok">0</strong></div>
				<div><span><?php esc_html_e( '첨부 fail', 'wp-qna-board' ); ?></span><strong id="ibm-stat-att-fail">0</strong></div>
				<div><span><?php esc_html_e( '댓글 inserted', 'wp-qna-board' ); ?></span><strong id="ibm-stat-cmt-inserted">0</strong></div>
				<div><span><?php esc_html_e( '댓글 trashed', 'wp-qna-board' ); ?></span><strong id="ibm-stat-cmt-trashed">0</strong></div>
				<div><span><?php esc_html_e( '에러 누적', 'wp-qna-board' ); ?></span><strong id="ibm-stat-errors" class="ibm-stat-errors">0</strong></div>
			</div>

			<h4 style="margin:14px 0 4px;"><?php esc_html_e( '최근 로그', 'wp-qna-board' ); ?></h4>
			<div id="ibm-log" class="ibm-log"></div>
			<h4 style="margin:14px 0 4px;"><?php esc_html_e( '최근 에러', 'wp-qna-board' ); ?></h4>
			<div id="ibm-errors" class="ibm-errors"></div>
		</div>
	</div>
	<?php
}
