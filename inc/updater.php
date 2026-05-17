<?php
/**
 * GitHub Releases 기반 플러그인 자동 업데이트.
 *
 * - 대상 저장소: ivystation/wp-qna-board
 * - 동작: GitHub Releases 의 latest 태그를 조회해 현재 버전보다 높으면
 *         WP 플러그인 업데이트 화면에 새 버전 정보를 주입한다.
 * - 캐시: 6시간 (set_site_transient).
 * - private 저장소 대비: wp-config.php 의 INQUIRY_BOARD_GH_TOKEN 또는
 *   설정 옵션 inquiry_board_settings.github_token 을 인증 토큰으로 사용.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const INQUIRY_BOARD_GH_OWNER = 'ivystation';
const INQUIRY_BOARD_GH_REPO  = 'wp-qna-board';
const INQUIRY_BOARD_GH_CACHE = 'inquiry_board_gh_release';
const INQUIRY_BOARD_GH_TTL   = 6 * HOUR_IN_SECONDS;

/**
 * 플러그인 기본 슬러그 (예: wp-qna-board/wp-qna-board.php).
 */
function inquiry_board_plugin_basename(): string {
	static $basename = null;
	if ( $basename === null ) {
		$basename = plugin_basename( INQUIRY_BOARD_FILE );
	}
	return $basename;
}

/**
 * GitHub 인증 토큰. 정의된 경우에만 헤더에 첨부.
 */
function inquiry_board_gh_token(): string {
	if ( defined( 'INQUIRY_BOARD_GH_TOKEN' ) && INQUIRY_BOARD_GH_TOKEN ) {
		return (string) INQUIRY_BOARD_GH_TOKEN;
	}
	$opts = get_option( 'inquiry_board_settings', [] );
	return (string) ( $opts['github_token'] ?? '' );
}

/**
 * GitHub Releases latest 조회 (캐시).
 *
 * 반환 배열 구조 (성공 시):
 *   - version:      string  (v 접두사 제거)
 *   - tag:          string  원본 태그
 *   - zip_url:      string  다운로드 URL (자산 zip 우선, 없으면 zipball)
 *   - changelog:    string  body 텍스트
 *   - published_at: string  ISO8601
 *   - html_url:     string  릴리스 페이지 URL
 *
 * 실패 시 빈 배열.
 *
 * @param bool $force 캐시 무시 강제 갱신.
 */
function inquiry_board_fetch_latest_release( bool $force = false ): array {
	if ( ! $force ) {
		$cached = get_site_transient( INQUIRY_BOARD_GH_CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}
	}

	$url  = sprintf(
		'https://api.github.com/repos/%s/%s/releases/latest',
		INQUIRY_BOARD_GH_OWNER,
		INQUIRY_BOARD_GH_REPO
	);
	$args = [
		'timeout' => 15,
		'headers' => [
			'Accept'     => 'application/vnd.github+json',
			'User-Agent' => 'WP-QnA-Board-Updater',
		],
	];
	$token = inquiry_board_gh_token();
	if ( $token ) {
		$args['headers']['Authorization'] = 'Bearer ' . $token;
	}

	$res = wp_remote_get( $url, $args );
	if ( is_wp_error( $res ) ) {
		set_site_transient( INQUIRY_BOARD_GH_CACHE, [], 15 * MINUTE_IN_SECONDS );
		return [];
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	$body = wp_remote_retrieve_body( $res );
	if ( $code !== 200 || ! $body ) {
		set_site_transient( INQUIRY_BOARD_GH_CACHE, [], 15 * MINUTE_IN_SECONDS );
		return [];
	}

	$data = json_decode( $body, true );
	if ( ! is_array( $data ) || empty( $data['tag_name'] ) ) {
		set_site_transient( INQUIRY_BOARD_GH_CACHE, [], 15 * MINUTE_IN_SECONDS );
		return [];
	}

	$tag     = (string) $data['tag_name'];
	$version = ltrim( $tag, 'vV' );

	// 자산 zip(파일명에 wp-qna-board 포함) 우선, 없으면 zipball_url.
	$zip_url = '';
	if ( ! empty( $data['assets'] ) && is_array( $data['assets'] ) ) {
		foreach ( $data['assets'] as $asset ) {
			$name = (string) ( $asset['name'] ?? '' );
			if ( $name && str_ends_with( strtolower( $name ), '.zip' ) ) {
				$zip_url = (string) ( $asset['browser_download_url'] ?? '' );
				break;
			}
		}
	}
	if ( ! $zip_url ) {
		$zip_url = (string) ( $data['zipball_url'] ?? '' );
	}

	$result = [
		'version'      => $version,
		'tag'          => $tag,
		'zip_url'      => $zip_url,
		'changelog'    => (string) ( $data['body'] ?? '' ),
		'published_at' => (string) ( $data['published_at'] ?? '' ),
		'html_url'     => (string) ( $data['html_url'] ?? '' ),
	];

	set_site_transient( INQUIRY_BOARD_GH_CACHE, $result, INQUIRY_BOARD_GH_TTL );
	return $result;
}

/**
 * 업데이트 transient 주입.
 */
add_filter( 'pre_set_site_transient_update_plugins', 'inquiry_board_inject_update' );
function inquiry_board_inject_update( $transient ) {
	if ( empty( $transient ) || ! is_object( $transient ) ) {
		$transient = new stdClass();
	}
	if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
		$transient->response = [];
	}
	if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
		$transient->no_update = [];
	}

	$release = inquiry_board_fetch_latest_release();
	if ( empty( $release['version'] ) || empty( $release['zip_url'] ) ) {
		return $transient;
	}

	$basename = inquiry_board_plugin_basename();
	$current  = INQUIRY_BOARD_VERSION;
	$slug     = dirname( $basename );

	$payload = (object) [
		'id'            => $basename,
		'slug'          => $slug,
		'plugin'        => $basename,
		'new_version'   => $release['version'],
		'url'           => $release['html_url'] ?: 'https://github.com/' . INQUIRY_BOARD_GH_OWNER . '/' . INQUIRY_BOARD_GH_REPO,
		'package'       => $release['zip_url'],
		'tested'        => '',
		'requires_php'  => '8.0',
		'compatibility' => new stdClass(),
	];

	if ( version_compare( $release['version'], $current, '>' ) ) {
		$transient->response[ $basename ] = $payload;
		unset( $transient->no_update[ $basename ] );
	} else {
		$transient->no_update[ $basename ] = $payload;
		unset( $transient->response[ $basename ] );
	}
	return $transient;
}

/**
 * "자세한 정보" 모달용 메타 응답.
 */
add_filter( 'plugins_api', 'inquiry_board_plugins_api', 10, 3 );
function inquiry_board_plugins_api( $result, $action, $args ) {
	if ( $action !== 'plugin_information' ) {
		return $result;
	}
	if ( empty( $args->slug ) || $args->slug !== dirname( inquiry_board_plugin_basename() ) ) {
		return $result;
	}
	$release = inquiry_board_fetch_latest_release();
	if ( empty( $release['version'] ) ) {
		return $result;
	}

	$info = new stdClass();
	$info->name           = 'Q&A 게시판';
	$info->slug           = dirname( inquiry_board_plugin_basename() );
	$info->version        = $release['version'];
	$info->author         = '<a href="https://github.com/' . INQUIRY_BOARD_GH_OWNER . '">ivynet</a>';
	$info->homepage       = $release['html_url'] ?: 'https://github.com/' . INQUIRY_BOARD_GH_OWNER . '/' . INQUIRY_BOARD_GH_REPO;
	$info->requires       = '6.0';
	$info->tested         = '';
	$info->requires_php   = '8.0';
	$info->last_updated   = $release['published_at'];
	$info->download_link  = $release['zip_url'];
	$info->trunk          = $release['zip_url'];
	$info->sections       = [
		'description' => '비회원 작성 가능한 Q&A 게시판. CPT(inquiry) + 비밀번호 보호 + IP·쿠키 본인 세션 + 관리자 답변 댓글.',
		'changelog'   => $release['changelog']
			? '<pre style="white-space:pre-wrap;">' . esc_html( $release['changelog'] ) . '</pre>'
			: '릴리스 노트가 비어 있습니다.',
	];
	return $info;
}

/**
 * GitHub zip 은 압축 해제 시 owner-repo-{sha}/ 형태이므로
 * 원래 플러그인 슬러그 폴더로 재명명한다.
 */
add_filter( 'upgrader_source_selection', 'inquiry_board_fix_source_dir', 10, 4 );
function inquiry_board_fix_source_dir( $source, $remote_source, $upgrader, $hook_extra = [] ) {
	if ( is_wp_error( $source ) ) {
		return $source;
	}
	$basename = inquiry_board_plugin_basename();
	$slug     = dirname( $basename );

	$is_target = false;
	if ( ! empty( $hook_extra['plugin'] ) && $hook_extra['plugin'] === $basename ) {
		$is_target = true;
	} elseif ( str_contains( $source, INQUIRY_BOARD_GH_REPO ) ) {
		$is_target = true;
	}
	if ( ! $is_target ) {
		return $source;
	}

	$current = trailingslashit( $source );
	$desired = trailingslashit( $remote_source ) . $slug . '/';
	if ( $current === $desired ) {
		return $source;
	}

	global $wp_filesystem;
	if ( ! $wp_filesystem ) {
		return $source;
	}
	if ( $wp_filesystem->is_dir( $desired ) ) {
		$wp_filesystem->delete( $desired, true );
	}
	if ( $wp_filesystem->move( $current, $desired ) ) {
		return $desired;
	}
	return $source;
}

/**
 * 캐시 강제 갱신: ?inquiry-board-flush-update=1 (관리자 전용).
 *
 * 단순히 transient 만 비우면 plugins.php 로 돌아갔을 때 wp_update_plugins() 가
 * 즉시 호출되지 않아 한 사이클 동안 빈 상태가 유지된다. 그래서 캐시 삭제 직후
 * wp_update_plugins() 를 직접 호출해 새 transient 를 작성하고 업데이트 가능
 * 화면으로 리다이렉트한다.
 */
add_action( 'admin_init', static function (): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( empty( $_GET['inquiry-board-flush-update'] ) ) {
		return;
	}
	delete_site_transient( INQUIRY_BOARD_GH_CACHE );
	delete_site_transient( 'update_plugins' );
	if ( function_exists( 'wp_clean_plugins_cache' ) ) {
		wp_clean_plugins_cache( true );
	}
	if ( ! function_exists( 'wp_update_plugins' ) ) {
		require_once ABSPATH . WPINC . '/update.php';
	}
	if ( function_exists( 'wp_update_plugins' ) ) {
		wp_update_plugins();
	}
	wp_safe_redirect( admin_url( 'plugins.php?plugin_status=upgrade' ) );
	exit;
} );

/**
 * 설정 화면 자동 업데이트 섹션에 노출할 진단 데이터.
 *
 * GitHub API 응답이 정상인지, 현재 버전과 비교 결과가 무엇인지,
 * 캐시·토큰 상태가 어떤지 한눈에 보여준다. 실제 업데이트가 노출되지
 * 않을 때 어디서 막혔는지 추적하는 용도.
 */
function inquiry_board_updater_diagnostics(): array {
	$release = inquiry_board_fetch_latest_release();
	$current = INQUIRY_BOARD_VERSION;
	$latest  = (string) ( $release['version'] ?? '' );

	$transient = get_site_transient( 'update_plugins' );
	$basename  = inquiry_board_plugin_basename();
	$in_resp   = is_object( $transient ) && isset( $transient->response[ $basename ] );
	$in_noupd  = is_object( $transient ) && isset( $transient->no_update[ $basename ] );

	return [
		'current_version' => $current,
		'latest_version'  => $latest,
		'latest_tag'      => (string) ( $release['tag'] ?? '' ),
		'zip_url'         => (string) ( $release['zip_url'] ?? '' ),
		'published_at'    => (string) ( $release['published_at'] ?? '' ),
		'html_url'        => (string) ( $release['html_url'] ?? '' ),
		'is_newer'        => $latest !== '' && version_compare( $latest, $current, '>' ),
		'cache_hit'       => (bool) get_site_transient( INQUIRY_BOARD_GH_CACHE ),
		'token_set'       => (bool) inquiry_board_gh_token(),
		'token_const'     => defined( 'INQUIRY_BOARD_GH_TOKEN' ) && INQUIRY_BOARD_GH_TOKEN,
		'basename'        => $basename,
		'in_response'     => $in_resp,
		'in_no_update'    => $in_noupd,
	];
}
