# wp-qna-board 플러그인 개발 기록

## 개요

워드프레스용 **재사용 가능한 익명 Q&A 게시판 플러그인**.

KBoard 같은 자체 테이블 기반 게시판을 워드프레스 표준 자산(Custom Post Type · `post_password` · `wp_comments` · 미디어 라이브러리)만으로 대체하기 위해 만든다. 최초 적용은 ukuhak.com 의 무료상담 Q&A 게시판이지만, 도메인 종속 코드는 두지 않는다.

## 기능 요약

- **CPT `inquiry`** + **택소노미 `inquiry_category`** (활성화 훅에서 5개 카테고리 자동 시드: 어학연수, 대학/대학원, 조기유학, 비자, 기타)
- 비회원이 글 작성 (이름·이메일·비밀번호 필수, 첨부 다중)
- 비밀번호로 글 보호 (WP 내장 `post_password`)
- 본인 인증: **HMAC-SHA256 쿠키 토큰 + IP 일치** 24시간 세션. 비번 입력 성공 시 세션 재발급.
- 본인 글 수정·삭제·댓글 작성 (24h 세션 또는 비번 입력)
- 댓글: **관리자 + 작성자 본인만**. "관리자 답변" 배지.
- 본문 에디터: WordPress `wp_editor()` 의 **텍스트 전용 최소 구성**. `<p>/<br>/<a>` 외 모두 정제.
- WP-CLI 마이그레이션: `wp inquiry migrate-kboard --board=<uid>` (idempotent, dry-run, batch, resume, since)
- 레거시 KBoard URL → 새 inquiry post **301**.
- 설정 페이지: reCAPTCHA, 첨부 정책, 세션 lifetime, 관리자 메일, 비번 강도.

## 작업 단계 (todo 추적)

플랜 파일: `~/.claude/plans/kboard-custom-glistening-toast.md`.

1차 작업 — 플러그인 개발 (본 폴더):
- [ ] 메인 부트스트랩 `wp-qna-board.php`
- [ ] CPT + 택소노미 등록 + 카테고리 시드
- [ ] 본인 세션 모듈
- [ ] 비회원 작성 폼 + 핸들러
- [ ] 본인 수정 화면
- [ ] 댓글 권한 게이트 + 본인 댓글 폼
- [ ] 레거시 URL 301
- [ ] 설정 페이지
- [ ] WP-CLI 마이그레이션 커맨드
- [ ] 프론트엔드 템플릿
- [ ] 기본 자산 + readme

2차 작업 — 마이그레이션:
- [ ] mmrzzhyxzr SSH 로 Q&A board uid + distinct 카테고리 + `kboard-custom` 의존 코드 실측
- [ ] staging Clone 배포 + dry-run
- [ ] 검증 9개 항목 PASS
- [ ] 운영 컷오버 + 병행 운영 1~2주
- [ ] 컷오버 종료

## 디렉터리 구조

```
wp-qna-board/
  wp-qna-board.php
  inc/
    cpt.php, form.php, session.php, permissions.php,
    comments.php, edit.php, redirect.php, settings.php,
    migration.php, migration-map.php
  templates/
    inquiry-form.php, inquiry-edit.php, single-inquiry.php,
    archive-inquiry.php, password-form.php
  assets/
    wp-qna-board.css, wp-qna-board.js
  languages/
  docs/
  readme.txt
```
