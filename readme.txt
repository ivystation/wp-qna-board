=== Q&A 게시판 (WP Q&A Board) ===
Contributors: ivynet
Tags: q-and-a, anonymous, board, kboard-migration, password-protected
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 8.0
Stable tag: 0.7.0
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

= 0.7.0 =
* feat(admin): **문의 편집 화면(post.php)에 「답글 작성 · 대화 스레드」 메타박스 추가** (`inc/admin-reply.php`). 그동안 관리 화면에는 답글 UI 가 아예 없어서 WP 기본 댓글 박스로만 답변할 수 있었다. 이제 한 화면에서 원문 + 첨부 + 전체 스레드를 보고 바로 답변을 등록한다.
  * 상단 요약: 작성자 · 이메일 · 카테고리 · **답변 상태 배지** · 답변 수(관리자/전체)
  * 스레드: 문의 원문(파란 라벨) + 댓글 시간순. 관리자 답변은 파란 배경(`#eff6ff`), 작성자 답글은 흰 배경으로 구분
  * 첨부: 이미지 썸네일 / 그 외 확장자 칩 + 파일 크기. `_inquiry_attachments` 메타 우선, 없으면 attachment `post_parent` 폴백(마이그레이션 글 대응)
  * 편집 화면은 이미 `<form id="post">` 로 감싸져 있어 중첩 form 을 만들 수 없으므로 **admin-ajax.php 로 제출**(nonce + `edit_post` cap 검사). 성공 시 `?inquiry_reply_notice=ok` 로 리로드
* feat(admin): inquiry 를 **Classic Editor** 로 연다(`use_block_editor_for_post_type` 필터). 익명 문의라 본문 편집 수요가 낮고, 블록 에디터에서는 classic 메타박스가 본문 아래로 밀려 답글 UI 접근성이 떨어진다. 되돌리려면 해당 필터만 제거.
* 답변 상태는 **새 메타를 만들지 않고** 관리자 답변 댓글 유무로 계산한다(「답변대기 / 답변완료」). 기존 글에도 즉시 적용되고 목록·프론트·마이그레이션에 파급이 없다.
* 답글 저장은 `wp_new_comment()` 가 아니라 `wp_insert_comment()` 를 쓴다 — 전자는 flood/duplicate 검사로 관리자의 연속 답변을 거부할 수 있고 승인 상태도 필터 체인이 덮어쓴다. 대신 `comment_post` 훅이 돌지 않으므로 `_is_admin_reply` 는 직접 마킹한다.
* 참조 구현: ivynet-16 / atumdgbktx 의 mu-plugin "Headless Support Tickets"(`support_ticket` CPT). 화면 구성을 맞추되 익명 작성자 메타 · `inquiry_category` 택소노미 · `_is_admin_reply` 등 inquiry 도메인에 맞게 매핑했다.

= 0.6.0 =
* feat(notify): 알림 메일 수신자를 **쉼표 구분 다중 지정** 지원으로 확장. 일반설정 「관리자 알림 수신 이메일」 에 `a@x.com, b@y.com` 형식으로 여러 명을 넣을 수 있다(`input type="email" multiple`). 세미콜론·공백 구분도 관대하게 파싱하며 유효하지 않은 주소는 저장 시 탈락. 미입력 시 기존대로 사이트 `admin_email` 폴백.
* fix(notify): 알림 발송 지점을 프론트엔드 폼 핸들러 내부 호출에서 **`transition_post_status` 훅**으로 이동. 기존에는 프론트엔드 글쓰기 폼으로 등록한 경우에만 메일이 나가고 **관리 화면 직접 작성 · WP-CLI · REST 로 등록한 문의는 알림이 누락**되었다. 이제 모든 등록 경로가 한 곳에서 커버된다.
* fix(notify): 알림 메일의 「관리 화면」 링크가 항상 비어 있던 버그 수정. `get_edit_post_link()` 는 현재 사용자의 `edit_post` 권한을 검사하므로 비로그인 방문자가 폼을 제출하는 경로에서 빈 문자열을 반환했다. `admin_url( 'post.php?post=ID&action=edit' )` 로 교체.
* `_inquiry_notified` 메타로 글 1건당 1회만 발송 — 수정 저장·휴지통 복구 시 재발송되지 않는다. 발송은 `shutdown` 으로 미뤄 폼 경로에서 `wp_insert_post` 이후 저장되는 작성자명 메타가 메일에 정상 포함되게 한다.
* 설정 화면 [사용법] 탭 §5 알림 메일 설명을 위 동작에 맞게 갱신.
* fix(security): 「GitHub Token (선택)」 필드에 **PAT 형식 검증** 추가 (`ghp_…` / `github_pat_…` / 40자 hex 만 허용, 그 외는 저장 거부 + 관리 화면 오류 안내). 브라우저 비밀번호 매니저가 `type="password"` 필드에 **사이트 로그인 비밀번호를 자동완성해 평문으로 DB 옵션에 저장되는 사고**가 반복됐다(2026-05-17 발견, 2026-07-31 재발). 아울러 `autocomplete="off"` → `autocomplete="new-password"` 로 교체 — 브라우저는 `off` 를 무시한다. sanitize 는 WP-CLI 의 `update_option` 경로에서도 실행되므로 `add_settings_error()` 는 `function_exists` 가드로 감쌌다.
* test: `tests/test-notify.php` 셀프체크 추가 (`wp eval-file` 로 실행). 수신자 파싱·폴백·저장 왕복 회귀·발행 1회 발송·중복 방지·토큰 형식 검증 17항목. 실제 메일은 `pre_wp_mail` 로 가로채므로 발송되지 않는다.

= 0.5.5 =
* refine(list): 목록 테이블 모바일(≤640px) 다듬기 — 카드 border/shadow/radius 제거하여 풀폭, row 좌우 padding 16px → 4px 로 최소화. 작성자·작성일 메타를 caption(13px) → micro(11px) 로 축소하고 가운데점 마진 8px → 6px 로 더 컴팩트하게.

= 0.5.4 =
* feat(list): 목록 테이블 모바일(≤640px) 카드형 stack 레이아웃. `<thead>` 숨김, 행 단위로 풀폭 제목 + 그 아래 「작성자 · 작성일」 메타 라인. col-num, col-cat 은 모바일에서 계속 숨김. 좌우 패딩을 row 단위 16px 로 통일해 컨테이너 내부 여백 균일화. 데스크탑(>640px) 그리드·헤더는 무수정.

= 0.5.3 =
* fix(button): primary CTA(글쓰기·등록 하기·수정 저장·답글 등록·확인) 톤을 indigo `#533afd` → **검정(deep ink) `#0f172a` fill + 흰 텍스트** 로 통일. ukuhak.com 에서 테마(Uncode) 의 `button[type=submit]` 글로벌 룰이 indigo bg 를 흰색에 가깝게 덮어써서 흰 텍스트가 안 보이던 문제 해결. hover `#1e293b`, active `#000000`.
* fix(button): primary CTA selector specificity 를 `.inquiry-reply-submit.inquiry-reply-submit` 처럼 클래스 두 번 기재로 (0,2,0) 까지 올리고 핵심 속성(background, color, border, border-radius) 에 `!important` 부여하여 테마 override 방어. `box-shadow: none`, `text-shadow: none` 명시.
* fix(reply-submit): 단일 페이지 「답글 등록」 의 라운드·hover 가 다른 primary CTA 와 달라 보이던 문제 해결. 동일 통합 selector 그룹에 묶어 hero 글쓰기 버튼과 시각·인터랙션 완전 일치.
* fix(reply-submit hover): hover 시 텍스트 색이 흰색에서 안 보이게 변하던 문제 해결 — hover 룰에서 `color: var(--inq-primary-fg) !important` 명시. hover 시 -1px translateY + 부드러운 deep shadow 로 lift 피드백 추가.
* feat(form): 글쓰기 폼의 「이름 / 이메일 / 글 비밀번호」 입력 행을 **데스크탑 3-column / 모바일(≤768px) 1-column** grid 로 묶음. `templates/inquiry-form.php` 에 `<div class="inquiry-form-grid inquiry-form-grid--3col">` wrapper 추가, CSS 에 `.inquiry-form-grid--2col` / `.inquiry-form-grid--3col` 토큰 추가. 비밀번호 안내문은 grid 바깥의 `.inquiry-form-help` 단락으로 분리.
* hero 글쓰기 버튼(`.inquiry-write-btn--lg`) 사이즈: padding 12/32 → 14/36, min-height 44 → 48 로 hero 급으로 키움. 색·라디우스는 통합 selector 가 담당.

= 0.5.2 =
* 글쓰기 / 수정 폼의 가로 폭을 목록 페이지(`.inquiry-board-list`) 와 동일한 컨테이너 100% 로 변경 (v0.5.0 까지 적용된 75% 제한 해제). ukuhak.com 피드백 반영. 단일 페이지(`.inquiry-single-wrap`) 는 기존대로 75% 유지.
* 글쓰기 폼 헤더(`.inquiry-form-header`) 를 3-grid(`목록으로 | 타이틀 | 여백`) 로 재구성 + 하단 hairline 1px 구분선. 타이틀(`.inquiry-form-title`) 은 display-lg(32px, weight 300) → heading-md(20px, weight 400) 로 컴팩트하게.
* 「목록으로」 (`.inquiry-list-btn`) 사이즈 다운(min-height 36 → 32px, padding 8/16 → 6/14, font tabular → caption) — primary CTA 「등록 하기」 와 시각적 위계 분명히.
* 카테고리·라벨 select(`<select>`) 에 네이티브 chevron 제거 + indigo SVG chevron(`%234434d4`) 으로 교체. `appearance: none` + `padding-right: 40px` + 우측 14px 위치.
* 입력 필드 padding 8/12 → 10/14, min-height 40 → 42 로 살짝 키워 타이핑 가독성 ↑.
* 라벨 톤을 caption(13px, ink-secondary, -0.39px 트래킹) 으로 정리, 필수 표식(`*`) 은 `--inq-ruby` 컬러.
* 「등록 하기」 / 「수정 저장」 행(`.inquiry-form-actions`): hairline 분리선 위에 우측 정렬. 모바일(≤640px) 에서는 column + full-width 로 터치 영역 확보.
* body class 필터 추가: `[inquiry_form]` 숏코드가 박힌 페이지에 자동으로 `inquiry-shortcode-page` 클래스 부여 (`inc/form.php`). 단일 inquiry CPT 페이지(`body.single-inquiry`) 와 동일한 hero-hide CSS 가 글쓰기·목록 페이지에도 적용된다.
* Uncode 호환 hero·share·breadcrumb 영역 hide 확대: `.share-on-side`, `.share-on-hover`, `.post-share`, `.uncode-share`, `.header-share`, `.breadcrumb(s)`, `.breadcrumb-trail`, `.uncode-breadcrumb`, `.post-header`, `.page-hero`, `.uncode-page-hero`, `.post-title-wrapper`, `.page-title-wrapper`, `.single-header` 모두 inquiry 관련 페이지에서 숨김. 본문 상단 padding 도 `.post-content` 64px → `--inq-gap-xl` (24px) 로 줄임.

= 0.5.1 =
* fix(thread): 관리자 답변 박스(`.inquiry-msg-admin`) 배경이 deep navy `#1c1e54` fill 이라 사이트 라이트 톤과 너무 대비되어 어둡게 보이던 문제 해결. 본문 배경을 ultra-light lavender `#f6f5ff`, 헤더 띠를 한 톤 진한 `#eceafd`, 보더를 `--inq-primary-subdued` (`#b9b9f9`) 로 변경. 좌측에 4px indigo accent bar(`::before`) 를 추가해 admin reply 정체성은 indigo 시그니처로 유지. 본문 텍스트는 `--inq-ink` (`#0d253d`) deep navy 그대로 두어 가독성 향상.
* role pill `inquiry-msg-role-admin`: 어디서나 동일한 filled indigo 톤으로 단순화 (이전에는 admin bubble 안에서 lighter indigo soft 로 전환했으나 light bg 에서는 구분이 약해 원래 톤 유지).
* admin reply 내부 링크: indigo deep (`#4434d4`) + 1px underline 으로 light bg 에서도 명확히 보이도록.

= 0.5.0 =
* DESIGN.md(Stripe 디자인 시스템) 적용 — 플러그인 전 화면(목록 / 단일 / 글쓰기 / 수정 / 비밀번호 / 답변 스레드) UI 를 indigo CTA + deep navy ink + Inter 300 + pill 버튼 + hairline 카드 톤으로 재구성.
* color tokens: amber primary(`#fbbf24`) → indigo `#533afd` (`--inq-primary`), hover `#4434d4` (`--inq-primary-deep`), pressed `#2e2b8c` (`--inq-primary-press`). 본문 텍스트는 deep navy `#0d253d` (`--inq-ink`). 보더는 hairline `#e3e8ee` (`--inq-hairline`), 입력 보더는 `#a8c3de` (`--inq-hairline-input`). 그라데이션 스톱(cream/orange/lavender/indigo/magenta)을 토큰화.
* shape: 모든 버튼은 pill (`--inq-radius-pill: 9999px`). DESIGN.md "Don't shrink button padding below 8px 16px" 준수. 카드·테이블 컨테이너는 rounded-lg(12px) + Level 1 shadow `0 1px 3px rgba(0,55,112,.08)`.
* typography: Inter 300/400 을 Google Fonts 로 enqueue (`wp-qna-board-inter`). `font-feature-settings: "ss01"` 전역, 숫자 셀(번호·날짜·페이지·카운트 뱃지)에는 추가로 `tnum`. display tier 는 weight 300 + negative letter-spacing.
* hero mesh backdrop: 목록 페이지(`.inquiry-board-list`) 상단에 cream/orange/lavender/indigo/magenta 5-stop radial-gradient 를 CSS 로 근사 출력하여 DESIGN.md 시그니처 mesh 분위기 부여 (blur + saturate).
* 관리자 답변 메시지: DESIGN.md `card-pricing-featured` 톤(`--inq-brand-dark: #1c1e54` fill + 흰 텍스트 + Level 2 shadow) 으로 featured tier 처럼 표시.
* role 배지: pill 형태. admin 은 filled indigo, owner 는 subdued indigo(`--inq-primary-subdued: #b9b9f9` + `--inq-primary-deep`).
* 파일 첨부 칩: 회색 박스 → `pill-tag-soft` 톤(subdued indigo) pill 칩.
* 비번 입력 폼(`.inquiry-password-form`): bare → card 형 (canvas + hairline + rounded-lg + Level 1 shadow + indigo pill 「확인」 버튼).
* 삭제 폼: ruby outline pill 로 destructive 의미 부여, hover 시 ruby fill.
* preconnect: fonts.googleapis.com / fonts.gstatic.com 에 `<link rel="preconnect">` 헤더 추가 (Inter 첫 paint 지연 최소화).
* version 상수 동기화: `INQUIRY_BOARD_VERSION` 도 `0.5.0`. (이전 v0.4.12 에서는 plugin header 만 0.4.12 였고 상수는 0.4.10 에 머물러 있어 `wp_enqueue_*` 의 cache buster 가 갱신되지 않던 버그도 함께 해소.)

= 0.4.12 =
* design tokens: 전역 CSS 변수 도입. `assets/wp-qna-board.css` 최상단 `:where(...)` 블록에 `--inq-bg`, `--inq-text`, `--inq-border`, `--inq-primary-bg`, `--inq-secondary-*`, `--inq-radius-*`, `--inq-font-*`, `--inq-gap-*` 등 색·라디우스·폰트·간격을 토큰화. specificity 0 으로 노출해 테마/자식 테마에서 단순 오버라이드 가능. 후속 패치(v0.4.13 예정) 에서 관리자 설정 페이지의 color picker 가 이 값을 동적으로 덮어쓰도록 wp_head 인라인 `:root` 블록을 출력할 예정.
* primary action: 큰 글쓰기 버튼(`.inquiry-write-btn--lg`), 단일 페이지 답글 등록(`.inquiry-reply-submit`), 글쓰기/수정 폼 제출(`.inquiry-form button[type="submit"]`) 의 톤을 amber 채움(`#fbbf24` / hover `#f59e0b` / active `#d97706` + 흰 글자 + `box-shadow`) 으로 통일. 단일 페이지 「답글 등록」 = 목록 「큰 글쓰기」 = 글쓰기 「등록 하기」 동일 시각.
* secondary action: 단일 페이지 「← 목록으로」(`.inquiry-back-link`), 목록 하단 작은 글쓰기(`.inquiry-write-btn`), 글쓰기 화면 「← 목록으로」(`.inquiry-list-btn`) 의 톤을 secondary 토큰(흰 박스 + `--inq-border` + hover `--inq-bg-admin`) 으로 통일.
* 큰 글쓰기 버튼 사이즈: v0.4.11 의 `padding: 16px 32px / font-size: 22px` 가 너무 크다는 피드백에 따라 가로(좌우 padding 32px) 는 유지하면서 상하 padding 10px + font-size `var(--inq-font-xl=16px)` 로 축소(높이 약 60% 수준).
* 글쓰기 화면 컨테이너 폭: `.inquiry-form-wrap`, `.inquiry-form-header`, `.inquiry-form`, `.inquiry-edit-form` 의 폭을 단일 페이지(`.inquiry-single-wrap`) 와 동일하게 75% (모바일 ≤768px 100%). 글쓰기 폼을 `<div class="inquiry-form-wrap">…</div>` 로 wrap 추가.
* 파일 첨부 UI: 글쓰기 폼의 `<input type="file">` 을 클립 아이콘(SVG) + 「파일 첨부 (선택수/최대)」 카운트 라벨 + 확장자·용량 안내 + 선택 파일 칩 리스트 UI 로 교체. `assets/wp-qna-board.js` 에 file picker 핸들러 추가 — 선택 시 카운트·칩 갱신, 최대 개수(현재 5 하드코딩) 초과 시 alert + input 초기화. 후속 패치에서 `max_attachments` 옵션 분리 예정.
* form input/textarea/select 톤 통일: 모든 입력 요소가 `--inq-input-*` 토큰 사용 (`padding 10px 12px`, `border-radius: var(--inq-radius-sm)`, focus 시 `border-color: var(--inq-input-border-focus)`).
* 「등록」 라벨을 「등록 하기」 로 변경.

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
