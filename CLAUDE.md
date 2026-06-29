# CLAUDE.md — wp-qna-board

이 파일은 Claude Code가 세션 시작 시 자동으로 읽는 진입점입니다. 전사 공통 규칙은
`~/.claude/CLAUDE.md`(글로벌)를 따르고, 이 파일은 **이 프로젝트만의 맥락**을 담습니다.

---

## 0. 작업 전·후 필수 절차 (맥락 유지의 핵심)

- **작업 시작 전**: 반드시 [.claude/context/STATUS.md](.claude/context/STATUS.md) 와
  [.claude/context/decisions.md](.claude/context/decisions.md) 를 먼저 읽고
  "지금까지의 진행 상황·막힌 지점·다음 할 일"을 파악한 뒤 착수한다.
- **작업 종료 시**: 아래 4가지를 빠짐없이 수행한다.
  1. `docs/`에 작업 문서 갱신 (명명: `wp-qna-board-한글제목.md`)
  2. `.claude/context/STATUS.md` 갱신 (진행 상황·다음 단계·날짜)
  3. 중요한 결정은 `.claude/context/decisions.md`에 1줄 추가
  4. auto-memory 중 팀이 알아야 할 학습은 `.claude/context/memory/`로 **승격**
- 위 절차의 이유와 상세는 docs.ivynet.co.kr → 가이드 → "맥락 유지 규칙" 참고.

## 1. 프로젝트 정체성

- **한 줄 소개**: 비회원 작성 가능한 Q&A 게시판 WordPress 플러그인. CPT(inquiry) + WP 기본 비밀번호 보호 + 쿠키·IP 24시간 본인 세션 + 관리자 답변 댓글. KBoard 마이그레이션 WP-CLI 포함.
- **Git remote**: git@github.com:ivystation/wp-qna-board.git
- **주 브랜치 / 배포 트리거**: main (GitHub Releases 태그 → 플러그인 자동 업데이트)
- **기술 스택**: WordPress 플러그인 (PHP 8.0+, WP 6.0+), Custom Post Type `inquiry` + 택소노미 `inquiry_category`, WP-CLI 마이그레이션 커맨드
- **담당**: ivynet

## 2. 실행 · 개발

```bash
# 로컬 개발: WP 플러그인이므로 wp-content/plugins/wp-qna-board/ 에 배치 후 활성화
# 활성화 시 CPT·택소노미·카테고리 5개·capability 자동 설치

# KBoard 마이그레이션 (WP-CLI 활성 환경)
wp inquiry migrate-kboard --board=<kboard_uid> --dry-run   # 통계만
wp inquiry migrate-kboard --board=<uid> --batch=200        # 본 작업
```

## 3. 배포 · 인프라

- **배포 방식**: GitHub Releases에 버전 태그를 올리면 `inc/updater.php`가 워드프레스 관리자 화면에서 자동 업데이트 노출 (Update URI: github.com/ivystation/wp-qna-board)
- **운영 사이트**: ukuhak.com (테마 Uncode — 테마 글로벌 CSS가 버튼/폼 스타일을 덮어쓰는 함정 주의)
- **환경변수**: 별도 .env 없음. reCAPTCHA v3 키 등은 Settings → Q&A 게시판 관리화면에서 설정
- **주의사항(함정)**:
  - 작업 완료 시 버전 업(`wp-qna-board.php` 헤더 + `INQUIRY_BOARD_VERSION` + `readme.txt` Stable tag + Changelog) 후 git 태그 생성까지 진행한다.
  - 테마 Uncode의 `button[type=submit]` 등 글로벌 룰이 플러그인 스타일을 덮어쓸 수 있어 selector specificity·`!important` 방어가 필요할 수 있다.
  - 비밀글·첨부 마이그레이션 시 무비번 글은 임시 비번을 `wp-content/private/inquiry/`에 CSV로 산출 → 평문 자격증명이므로 git 커밋 금지.

## 4. 프로젝트 고유 규칙

- 기능 구현 코어는 `inc/`(cpt·session·form·comments·migrator·updater 등), 화면은 `templates/`, 정적 자산은 `assets/`.
- 본인 인증은 HMAC-SHA256 쿠키 토큰 + 요청 IP 일치, 24시간 lifetime.
- 외부 디자인 참고 자료(DESIGN.md)는 본체와 무관하므로 git 추적 제외(`.gitignore`).
- 마이그레이션 산출 CSV(비밀번호 평문) 및 `.env`·로그는 절대 커밋하지 않는다.

## 5. 핵심 문서 바로가기

- 개발 기록: docs/inquiry-board-개발기록.md
- 설정 페이지 구현: docs/wp-qna-board-설정페이지구현.md
- GitHub 자동 업데이트 구축: docs/wp-qna-board-GitHub-자동업데이트-구축.md
- 디자인(DESIGN.md) 스타일 적용: docs/wp-qna-board-DESIGN.md 스타일 적용.md
