# Q&A 게시판 대시보드 설정 페이지

## 목표

`Settings → Q&A 게시판` 단일 폼이던 설정 화면을 **Q&A게시판 하위 메뉴 “설정”** 으로 옮기고
다음 3개 탭으로 분리한다.

- 일반설정 — 페이지당 게시물 수, 작성 폼·첨부·reCAPTCHA·자동 업데이트 등 기존 옵션 유지·확장
- 마이그레이션 — 좌측 소스 게시판 서브탭(KBoard 활성 / MangBoard·XE·Gravity 준비중)
- 사용방법 — 단축코드·카테고리·세션·CLI 안내

## 변경 파일

- `inc/settings.php` — 메뉴 등록과 3탭 렌더 전체 개편
- `inc/form.php` — `[inquiry_form]` 숏코드의 `posts_per_page` 기본값을 옵션에서 가져오도록 변경
- `templates/inquiry-list.php` — 동일하게 fallback 을 옵션값으로 연결

## 메뉴 위치

```text
대시보드
└─ Q&A게시판 (CPT inquiry)
   ├─ 전체 문의
   ├─ 새 문의 등록
   ├─ 문의 카테고리
   └─ 설정     ← 신규 (이 작업)
       ├─ 일반설정
       ├─ 마이그레이션
       └─ 사용방법
```

`add_submenu_page( 'edit.php?post_type=inquiry', … )` 로 CPT 메뉴 하위에 붙인다.
URL 라우팅:

- 일반설정: `?post_type=inquiry&page=wp-qna-board&tab=general`
- 마이그레이션: `…&tab=migration&source=kboard|mangboard|xe|gravity`
- 사용방법: `…&tab=usage`

## 옵션 스키마

옵션 키는 두 개로 분리해 저장한다.

### `inquiry_board_settings` (일반)

| 키 | 기본값 | 설명 |
|----|--------|------|
| `posts_per_page` | 20 | 목록 페이지당 노출 글 수 (1~200) |
| `password_min_length` | 4 | 작성 비밀번호 최소 자릿수 |
| `password_required` | 1 | 비밀번호 필수 여부 |
| `session_ttl` | 86400 | 본인 세션 lifetime (초) |
| `notify_email` | (빈값) | 새 문의 알림 수신 메일 |
| `allowed_ext` | `jpg,jpeg,…,zip` | 첨부 허용 확장자 |
| `max_upload_mb` | 10 | 첨부 용량 상한 |
| `recaptcha_site_key` | (빈값) | reCAPTCHA Site Key |
| `recaptcha_secret` | (빈값) | reCAPTCHA Secret |
| `recaptcha_min_score` | 0.3 | reCAPTCHA 최소 점수 |
| `github_token` | (빈값) | GitHub Releases 인증 토큰 |

### `inquiry_board_migration` (마이그레이션)

| 키 | 기본값 | 설명 |
|----|--------|------|
| `kboard_board_id` | 0 | 대상 KBoard 게시판 uid |
| `kboard_batch` | 200 | 배치 크기 (1~1000) |
| `kboard_only` | `''` | `''` / `posts` / `attachments` / `comments` |
| `kboard_since` | `''` | 이후 데이터만 처리 (delta) |

## 일반설정 탭

페이지당 게시물 수를 신규 추가했고, 나머지 옵션은 그룹 헤더(목록 표시 / 작성 폼 / 첨부 파일 / reCAPTCHA / 자동 업데이트)로 시각적으로 묶었다.

`[inquiry_form]` 숏코드의 `posts_per_page` 속성이 명시되면 그 값이 우선하며,
미지정 시 본 옵션값을 사용한다. (`inc/form.php`, `templates/inquiry-list.php` 모두 `inquiry_board_get_posts_per_page()` fallback 적용)

## 마이그레이션 탭

좌측 사이드바(소스 게시판 목록):

| 소스 | 상태 |
|------|------|
| KBoard | **활성** |
| MangBoard | 준비중 |
| XpressEngine / Rhymix | 준비중 |
| Gravity Forms 엔트리 | 준비중 |

준비중 항목 클릭 시 “준비중” 안내 + GitHub 이슈 링크가 노출된다.

KBoard 패널:

1. **사이트 감지** — `wp_kboard_board` 테이블 존재 여부를 `SHOW TABLES LIKE` 로 확인.
   - 테이블이 있으면 게시판 목록을 `<select>` 로 표시 (uid + name)
   - 없으면 경고 안내 + uid 직접 입력 필드
2. **옵션 폼** — 게시판 uid / 배치 크기 / 실행 범위 / since 입력 후 저장
3. **WP-CLI 명령 자동 생성** — 저장된 옵션 기반으로 dry-run / 실제 실행 두 가지 명령을 코드 블록으로 노출

```bash
# ① dry-run
wp inquiry migrate-kboard --board=3 --batch=200 --dry-run

# ② 실제 실행
wp inquiry migrate-kboard --board=3 --batch=200
```

실제 마이그레이션은 시간이 오래 걸리고 PHP 타임아웃 위험이 있으므로 admin 화면에서 직접 트리거하지 않고 WP-CLI 로 유도한다. 본 명령은 `inc/migration.php` 의 `Inquiry_Board_Migrate_Command` 에서 idempotent 하게 동작한다 (`_legacy_kboard_uid` 기준 중복 skip).

## 사용방법 탭

- 숏코드 사용 예시와 주요 속성
- 카테고리 시드 안내 (`inquiry_category` 관리 화면 링크)
- 비밀글 / 본인 세션 / 관리자 답변 / 알림 메일 설명
- 마이그레이션 흐름 요약
- 자주 쓰는 관리 화면 바로가기 (문의 목록 / 카테고리 / 프론트 아카이브)

## 호환성 메모

- 기존 옵션값은 그대로 보존된다. `posts_per_page` 만 신규로 들어가며 미저장 사용자는 기본값 20 이 사용된다.
- 메뉴 위치가 `Settings → Q&A 게시판` 에서 `Q&A게시판 → 설정` 으로 이동했으므로
  기존 북마크가 있다면 갱신이 필요하다. (URL slug 자체는 `wp-qna-board` 그대로)
