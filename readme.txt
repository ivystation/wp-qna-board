=== Q&A 게시판 (WP Q&A Board) ===
Contributors: ivynet
Tags: q-and-a, anonymous, board, kboard-migration, password-protected
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 8.0
Stable tag: 0.4.11
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

비회원 작성 가능한 Q&A 게시판. CPT(inquiry) + WP 기본 비밀번호 보호 + 쿠키·IP 24시간 본인 세션 + 관리자 답변 댓글. KBoard 마이그레이션 WP-CLI 커맨드 포함.

== Description ==

KBoard 같은 자체 테이블 기반 게시판을 WordPress 표준 자산만으로 대체하기 위한 재사용 플러그인.

* Custom Post Type `inquiry` 등록 (`/inquiry/` 슬러그, REST 노출)
* 택소노미 `inquiry_category` (활성화 시 5개 카테고리 자동 시드: 어학연수 / 대학·대학원 / 조기유학 / 비자 / 기타)
* 비회원이 이름·이메일·비밀번호로 글 작성
* WP 내장 `post_password` 로 비밀글 보호 (본문·첨부·댓글 일괄 마스킹)
* 본인 인증: HMAC-SHA256 쿠키 토큰 + 요청 IP 일치, 24시간 lifetime
* 본인 글 수정·삭제, 비번 변경
* 댓글: 관리자 + 작성자 본인만, "관리자 답변" 배지 자동
* 본문 에디터: 텍스트 전용 최소 구성 (`<p>/<br>/<a>` 만 허용)
* 첨부 다중 업로드 (화이트리스트 확장자 + 용량 상한, WP 미디어 라이브러리에 정식 등록)
* reCAPTCHA v3 + 허니팟 + IP/Email throttle
* WP-CLI: `wp inquiry migrate-kboard --board=<uid>` (idempotent, dry-run, batch, resume, since)
* 레거시 KBoard URL (`?mod=document&uid=...`) → 새 inquiry 글로 301

== Installation ==

1. `wp-content/plugins/wp-qna-board/` 에 파일 업로드 또는 zip 으로 업로드.
2. 플러그인 활성화. (CPT, 택소노미, 카테고리 5개, capability 자동 설치.)
3. Settings → Q&A 게시판 에서 reCAPTCHA·첨부·세션 설정.
4. 폼을 노출할 페이지에 `[inquiry_form]` 숏코드 삽입.

== KBoard 마이그레이션 ==

WP-CLI 가 활성인 환경에서:

* `wp inquiry migrate-kboard --board=<kboard_uid> --dry-run` — 통계만 출력
* `wp inquiry migrate-kboard --board=<uid> --batch=200` — 본 작업
* `wp inquiry migrate-kboard --board=<uid> --only=comments` — 댓글만
* `wp inquiry migrate-kboard --board=<uid> --since="2026-05-17 00:00:00"` — delta 흡수

KBoard `category1`/`category2` 와 새 5개 슬롯의 매핑은 `inc/migration-map.php` 에서 보정한다.
`secret=1` 인데 비번 없는 글은 무작위 8자 영숫자로 발급되고 `wp-content/private/inquiry/inquiry-migration-passwords.csv` 에 산출된다 (`.htaccess deny` 자동).

== Changelog ==

= 0.4.11 =
* list: 상단 글쓰기 버튼(`.inquiry-write-btn--lg`)을 중앙 정렬 + 약 2배 크기(`font-size: 22px`, `padding: 16px 32px`)로 확대. primary blue(`#1d4ed8`) 채움 + 흰 글자 + 살짝의 그림자(`box-shadow: 0 6px 16px rgba(29,78,216,.18)`)로 시인성 강화.
* list: 하단에 원래 사이즈의 글쓰기 버튼(`.inquiry-list-actions--bottom`)을 추가해 페이지네이션과 같은 라인에 배치. 신규 `.inquiry-list-footer` 가 grid 3컬럼(`1fr / auto / 1fr`) 로 페이지네이션을 시각적 중앙에 두고 글쓰기 버튼을 우측 끝에 정렬. 모바일(≤600px) 에서는 1컬럼으로 stack.
* pagination: `nav.inquiry-pagination` 과 내부 `ul/ol/li` 의 외곽 라인(테마/플러그인이 추가하는 경우)을 `border: 0; background: transparent; box-shadow: none` 으로 명시적으로 제거. 각 페이지 숫자(`.page-numbers`)의 보더는 그대로 유지.

= 0.4.10 =
* fix(list): 페이지네이션 클릭 시 404 발생 수정. `paginate_links()` 가 `?paged=N` 으로 URL 을 생성하면 WP `redirect_canonical` 이 일반 page(쇼트코드를 박아 둔 페이지) 에서 자동으로 `/page/N/` 로 301 redirect 하는데, 해당 page 에는 `<!--nextpage-->` 분할이 없어 404 가 떴다. 자체 쿼리 변수 `?inq_paged=N` 으로 base 를 변경(템플릿이 이미 `$_GET['inq_paged']` 를 수신). canonical redirect 가 발동하지 않으면서 본문 쿼리가 정상 동작.

= 0.4.9 =
* list: 목록 페이지(`[inquiry_form]` view=list) 스타일을 단일 페이지 톤으로 통일. 테이블 `.inquiry-list` 에 둥근 모서리(`border-radius: 10px` + `overflow: hidden`), thead 진한 띠(`#f1f5f9` 배경 + `#0f172a` 진한 글자 + 하단 보더), 행 hover `#f8fafc`.
* list: 제목 셀(`.col-title`) 오른쪽 끝에 답변(댓글) 카운트 뱃지 `.inq-comment-count` 추가. 단일 페이지 `.inquiry-thread-count` 와 동일 톤(파란 pill, `#1d4ed8` 배경 + 흰 글자, `border-radius: 999px`, `font-size: 11px`). 카운트 0 인 글은 표시 안 함.
* list: 글쓰기 버튼(`.inquiry-write-btn`) 을 단일 페이지 「← 목록으로」 / 「답글 등록」 박스 톤으로 통일.
* pagination: `paginate_links( 'type' => 'list' )` 의 `<li>` marker 가 테마 CSS 영향으로 노출되던 점(•) 제거 — `.inquiry-pagination li` 에 `list-style: none !important` + `list-style-type: none !important` + `::marker { content: none }` + `::before { content: none }` 강제. 페이지 번호도 박스 톤(`.page-numbers` 36×36 박스, `current` 는 `#1d4ed8` 채움, `dots` 는 투명).
* empty: 빈 상태(`.inquiry-empty`) 도 단일 페이지 `.inquiry-thread-empty` 와 동일 톤(흰 박스 + dashed `#cbd5e1` 보더).

= 0.4.8 =
* single: 본문·댓글 안의 plain URL 을 자동으로 `<a>` 태그로 감싸도록 `make_clickable` 적용. inquiry 본문은 `the_content` 필터 priority 15 에 보장 필터(`inquiry_board_ensure_clickable`)를 추가해 다른 플러그인이 코어의 make_clickable(priority 9) 을 제거해 둔 환경에서도 동작. 댓글은 `wpautop( make_clickable( wp_kses_post(...) ) )` 순서로 처리 — 줄바꿈이 살아있는 단계에서 URL 인식이 더 정확.
* style: 「답글 등록」 버튼(`.inquiry-reply-submit`) 을 「← 목록으로」 링크(`.inquiry-back-link`) 와 동일한 박스 톤·크기로 통일 (padding `8px 14px`, font-size `13px`, `inline-flex`, `border-radius 10px`, hover `#eff6ff` / `#98bce8`).

= 0.4.7 =
* style: inquiry 단일 페이지 상단 타이틀(`body.single-inquiry .post-title-wrapper`) 도 본문 래퍼(`.inquiry-single-wrap`) 와 동일하게 75% 폭(모바일 100%) 으로 정렬. 상단부터 본문까지 일관된 컨테이너 폭 유지.

= 0.4.6 =
* single: 본문 상단에 "← 목록으로" 링크 추가. 쇼트코드 `[inquiry_form]` 이 박힌 publish 페이지를 자동 탐지(`inquiry_board_get_list_page_url()`)하여 그 URL 로 이동. 6시간 transient 캐시 + `save_post_page` / `delete_post` 훅으로 무효화. 옵션 `inquiry_board_list_page_id` 로 수동 override 가능.
* single: inquiry 본문 영역을 `.inquiry-single-wrap` 으로 감싸 테마와 독립적으로 폭을 75% (모바일 100%) 로 정돈.
* style: 답변 등록 버튼(`.inquiry-reply-submit`) 을 테마 기본 버튼 스타일 의존에서 분리. 댓글 박스(`.inquiry-msg`) 와 동일한 흰 박스 + 1px `#e2e8f0` 보더 + 10px radius. hover/focus 시 admin 박스 톤(`#eff6ff` / `#98bce8`).
* style: 답변 박스 메타 헤더(`.inquiry-msg-head`) 를 박스 상단 가득한 띠로 분리. 본문보다 진한 톤(`#f1f5f9` / admin `#dbeafe`) + 하단 보더. 본문(`.inquiry-msg-body`) 좌우 패딩 16px 로 별도 지정.
* style: admin 메시지 박스 보더 색 `#bfdbfe` → `#98bce8` (사용자 시안 적용).
* style: "목록으로" 링크 자체도 박스 톤(`.inquiry-back-link`) 으로 디자인.

= 0.4.5 =
* single: 단일 inquiry 페이지에 댓글 스레드(메시지 버블) UI 를 플러그인이 자체적으로 출력한다. 테마 single.php 가 `comments_template()` 을 호출하지 않거나 옵션으로 막아 둔 경우(예: Uncode 테마의 `_uncode_{post_type}_comments` 옵션) 에도 inquiry 글에서 답변이 항상 보이도록 보장.
* single: 본인 인증 세션 사용자(또는 관리자) 에게는 답글 폼이 함께 노출된다. POST 는 `admin-post.php?action=inquiry_reply` 로 처리되고 `wp_new_comment()` 가 기존 `preprocess_comment`·`pre_comment_approved`·`comment_post` 필터 체인을 그대로 통과하므로 권한 게이트와 자동 승인이 일관되게 작동.
* single: 마이그레이션된 댓글의 관리자 답변 판별은 `_is_admin_reply` 메타 우선, 없으면 `user_id` 의 `moderate_comments` 폴백.
* style: 메시지 버블·역할 배지·답글 폼 기본 스타일 추가 (관리자 답변 `#eff6ff` / 작성자 답글 `#ffffff`).

= 0.4.4 =
* settings: 일반설정 탭에 "레거시 URL 처리" 섹션 추가. 체크박스 `legacy_redirect_enabled` (기본값 off) 로 KBoard 등 레거시 URL(`?mod=document&uid=NNN`)을 마이그레이션된 새 inquiry 글로 301 리다이렉트할지 선택.
* redirect: `inc/redirect.php` 의 `inquiry_board_legacy_redirect()` 시작부에 옵션 가드 추가. 비활성이면 즉시 return 하여 원본 게시판 글을 그대로 노출. SEO 보존 vs 원본/신규 병행 운영 양쪽 사용 사례를 지원.

= 0.4.3 =
* admin runner: KBoard 마이그레이션을 [Q&A 게시판 → 설정 → 마이그레이션] 화면에서 직접 실행. AJAX 폴링(1.5초)으로 단계별 진행률 바·통계·최근 로그·최근 에러 실시간 갱신.
* admin runner: 시작 전 안전장치 (Dry-run 사전 검증 패널, DB 백업 확인 체크박스, 동시 실행 락, 취소 후 재시작 안전성). 페이지를 닫아도 cursor 가 옵션에 보존되어 재진입 시 이어 진행 가능.
* core: 마이그레이션 로직을 WP-CLI 비의존 코어 클래스(Inquiry_Board_Migrator) 로 분리. CLI 명령과 관리화면 AJAX 가 동일 코어를 호출. 단계별 batch 메서드는 deadline(microtime) 까지 처리한 뒤 cursor·통계 반환.
* runner state: `inquiry_board_migration_runtime` 옵션(자동로드 off) 에 status·stage·cursor·통계·최근 30로그/30에러 저장. 엔드포인트는 capability=manage_options + nonce 로 보호.

= 0.4.2 =
* migration: KBoard 실제 스키마(`{prefix}kboard_board_setting`, `kboard_board_content`, `kboard_comments`, `kboard_board_attached`)에 맞춰 감지·CLI 매핑을 전면 보정. v0.4.1 까지는 존재하지 않는 `kboard_board` 테이블을 가정해 마이그레이션 화면 감지가 실패하고 CLI 실행 시 댓글 SQL 에러가 발생했음.
* migration: 글 일자(`date`/`update`), 비밀(`secret='true'`), 공지(`notice='true'`), 상태(`status='trash'` 제외), 작성자(`member_uid`/`member_display`) 매핑 정상화.
* migration: 첨부는 `{prefix}kboard_board_attached` 테이블에서 글(`content_uid`) 단위 JOIN 으로 처리하도록 분리. 댓글은 `content_uid` JOIN 으로 게시판 필터링하며 작성자명은 `user_display` 사용.
* migration: KBoard char(14) `'YYYYMMDDHHMMSS'` ↔ MySQL datetime 변환 유틸 추가. `--since` 옵션이 KST 기준으로 정상 동작.
* migration: 댓글 idempotent 처리(legacy uid 중복 INSERT 방지). 첨부도 글 메타에 누적 병합되도록 변경.
* migration-map: `정규유학`/`정규` 카테고리 → `university` 매핑 추가.

= 0.4.1 =
* updater: "업데이트 캐시 강제 갱신" 버튼이 wp_update_plugins() 를 즉시 호출하도록 보강. 캐시 비움 직후 한 사이클 동안 빈 상태가 유지되는 문제 해결.
* settings: 자동 업데이트 섹션에 진단 표 추가 (현재 버전 · GitHub 최신 버전 · update_plugins.response 등록 여부 · 캐시 상태 · 토큰 상태 · zip URL).

= 0.4.0 =
* 대시보드 메뉴 개편: Settings → Q&A 게시판을 Q&A게시판 → 설정 으로 이동 (add_submenu_page).
* 설정 페이지 3탭 구조 도입: 일반설정 / 마이그레이션 / 사용방법.
* 일반설정에 페이지당 게시물 수(posts_per_page) 옵션 추가. 숏코드 속성 미지정 시 옵션값 사용.
* 마이그레이션 탭: KBoard 활성 (게시판 자동 감지 + 옵션 폼 + WP-CLI 명령 자동 생성), MangBoard / XE·Rhymix / Gravity Forms 는 준비중.
* 사용방법 탭: 단축코드·카테고리·세션·관리자 답변·CLI·바로가기 안내.

= 0.3.0 =
* GitHub Releases 기반 플러그인 자동 업데이트 추가 (ivystation/wp-qna-board).
* 설정 페이지에 GitHub Token 필드 및 업데이트 캐시 강제 갱신 버튼 추가.

= 0.2.0 =
* 비밀번호 보호 / 첨부 / 본인 세션 / 관리자 답변 통합 안정화 릴리스.

= 0.1.0 =
* 최초 릴리스.
