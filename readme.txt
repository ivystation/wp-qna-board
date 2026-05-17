=== Q&A 게시판 (WP Q&A Board) ===
Contributors: ivynet
Tags: q-and-a, anonymous, board, kboard-migration, password-protected
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 8.0
Stable tag: 0.1.0
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

= 0.1.0 =
* 최초 릴리스.
