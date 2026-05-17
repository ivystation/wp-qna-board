# wp-qna-board — DESIGN.md(Stripe) 스타일 전면 적용 (v0.5.0)

## 작업 목적

플러그인이 사용자에게 노출되는 모든 화면(목록 / 단일 / 글쓰기 / 수정 / 비밀번호 / 답변 스레드)을
`/Users/stanley/workspace/WordPress/wp-qna-board/DESIGN.md` 에 정의된 Stripe 디자인 시스템 톤으로 전환한다.

기존 v0.4.12 는 amber(`#fbbf24`) 채움 primary + 회색 hairline + 작은 라디우스 위주의 자체 톤이었다.
v0.5.0 부터는 다음 4가지 시그니처를 따른다.

1. **Single-indigo CTA hierarchy**: 한 화면에 채움 indigo pill(`#533afd`) CTA 1개만.
2. **Thin display + ss01**: 본문은 Inter weight 300, `font-feature-settings: "ss01"` 전역.
3. **Pill 9999px**: 모든 버튼은 알약형.
4. **Hairline 카드**: 1px `#e3e8ee` 보더 + 12px 라디우스 + `0 1px 3px rgba(0,55,112,.08)` Level 1 그림자.

## 변경 파일

- `assets/wp-qna-board.css` — 전면 재작성. 토큰부터 모든 컴포넌트(목록 / 폼 / pagination / 메시지 버블 / 비번 폼 / 삭제) 까지.
- `wp-qna-board.php` —
  - Plugin Header `Version` 0.4.12 → 0.5.0
  - `INQUIRY_BOARD_VERSION` 상수 0.4.10 → 0.5.0 (v0.4.12 에서 plugin header 만 갱신되고 상수가 0.4.10 에 멈춰 있어 cache buster 가 동작하지 않던 버그도 함께 해소)
  - `wp_enqueue_style('wp-qna-board-inter', ...)` 로 Inter 300/400 Google Fonts 로드, 메인 CSS 가 이에 의존하도록 설정
  - `fonts.googleapis.com` / `fonts.gstatic.com` 에 `<link rel="preconnect">` 추가
- `readme.txt` —
  - `Stable tag: 0.5.0`
  - `== Changelog ==` 에 `= 0.5.0 =` 항목 추가 (위 모든 변경 요약)

> 템플릿(`templates/*.php`)과 PHP(`inc/*.php`) 는 손대지 않았다. 모든 변경은 클래스 이름·구조를 유지한 채 CSS 와 enqueue 만 교체.

## 디자인 토큰 매핑 (DESIGN.md → CSS 변수)

### Brand & Accent
| DESIGN.md | CSS 변수 | 값 |
|---|---|---|
| `{colors.primary}` | `--inq-primary` | `#533afd` |
| `{colors.primary-deep}` | `--inq-primary-deep` | `#4434d4` |
| `{colors.primary-press}` | `--inq-primary-press` | `#2e2b8c` |
| `{colors.primary-soft}` | `--inq-primary-soft` | `#665efd` |
| `{colors.primary-bg-subdued-hover}` | `--inq-primary-subdued` | `#b9b9f9` |
| `{colors.brand-dark-900}` | `--inq-brand-dark` | `#1c1e54` |
| `{colors.ruby}` | `--inq-ruby` | `#ea2261` |

### Surface / Text
| DESIGN.md | CSS 변수 | 값 |
|---|---|---|
| `{colors.canvas}` | `--inq-canvas` | `#ffffff` |
| `{colors.canvas-soft}` | `--inq-canvas-soft` | `#f6f9fc` |
| `{colors.canvas-cream}` | `--inq-canvas-cream` | `#f5e9d4` |
| `{colors.hairline}` | `--inq-hairline` | `#e3e8ee` |
| `{colors.hairline-input}` | `--inq-hairline-input` | `#a8c3de` |
| `{colors.ink}` | `--inq-ink` | `#0d253d` |
| `{colors.ink-secondary}` | `--inq-ink-secondary` | `#273951` |
| `{colors.ink-mute}` | `--inq-ink-mute` | `#64748d` |
| `{colors.on-primary}` | `--inq-on-primary` | `#ffffff` |

### Shape / Spacing / Shadow
- `--inq-radius-pill: 9999px` — DESIGN.md `{rounded.pill}` 모든 버튼.
- `--inq-radius-lg: 12px` — `{rounded.lg}` 카드 / 테이블.
- `--inq-radius-md: 8px` — `{rounded.md}` 알림 / 컴팩트 카드.
- `--inq-radius-sm: 6px` — `{rounded.sm}` 폼 인풋.
- `--inq-shadow-1: 0 1px 3px rgba(0,55,112,.08)` — Level 1.
- `--inq-shadow-2: 0 8px 24px rgba(0,55,112,.08), 0 2px 6px rgba(0,55,112,.04)` — Level 2.
- `--inq-primary-shadow: 0 6px 16px rgba(83,58,253,.22)` — primary pill hover lift.

### Typography
- `--inq-font-stack: "Inter", "SF Pro Display", -apple-system, BlinkMacSystemFont, "Helvetica Neue", system-ui, sans-serif`
- 본문 weight 300, ss01 전역, 숫자 셀에는 `tnum` 추가.
- `--inq-font-body: 15px` (기본), `--inq-font-tabular: 14px` (숫자 셀), `--inq-font-caption: 13px` (헬퍼 텍스트).

## 컴포넌트별 적용

### 1. 목록(`.inquiry-board-list`)
- 상단에 **그라데이션 mesh backdrop** 을 `::before` 의사요소로 출력.
  - cream(`#fce8d4`) → sherbet orange(`#f5c39d`) → lavender(`#d6c2f0`) → indigo subdued(`#b9b9f9`) → magenta(`#f96bee`) 5-stop radial-gradient + `filter: blur(28px) saturate(112%)`.
  - 모바일에서는 높이를 320 → 220px 로 축소.
- 큰 글쓰기 버튼(`.inquiry-write-btn--lg`): filled indigo pill + Level shadow.
- 작은 글쓰기 버튼(`.inquiry-write-btn`): outline indigo pill (DESIGN.md `button-secondary`).
- 테이블: rounded-lg 12px + hairline + Level 1 shadow. thead 는 canvas-soft 배경. 숫자 셀(번호·날짜)에 `font-feature-settings: "ss01" on, "tnum" on; letter-spacing: -0.42px`.
- 댓글 카운트 뱃지(`.inq-comment-count`): DESIGN.md `pill-tag-soft` 톤 (subdued indigo bg + deep indigo text).

### 2. 페이지네이션(`.inquiry-pagination`)
- 각 페이지 번호: pill 모양 (`--inq-radius-pill`).
- `current`: filled indigo + 흰 텍스트.
- hover: canvas-soft bg + indigo 텍스트.

### 3. 단일 페이지(`.inquiry-single-wrap`)
- 본문 폭 75% (모바일 100%) 유지.
- 「← 목록으로」(`.inquiry-back-link`): outline indigo pill.
- 첨부 박스(`.inquiry-attachments`): canvas-soft + hairline + rounded-md + 📎 prefix.

### 4. 답변 스레드(`#inquiry-thread`)
- 컨테이너: canvas-soft + hairline + rounded-lg (DESIGN.md `card-feature-light`).
- 일반 메시지(`.inquiry-msg`): white + hairline + rounded-lg + Level 1 shadow.
- **관리자 답변(`.inquiry-msg-admin`)**: DESIGN.md `card-pricing-featured` 톤 — `#1c1e54` brand-dark fill + 흰 텍스트 + Level 2 shadow. featured tier 처럼 강조.
- role 배지:
  - `inquiry-msg-role-admin`: filled indigo pill (관리자 답변 안에서는 lighter indigo soft).
  - `inquiry-msg-role-owner`: subdued indigo pill.
- 「답글 등록」(`.inquiry-reply-submit`): filled indigo pill.

### 5. 글쓰기 / 수정 폼
- 인풋: rounded-sm 6px + hairline-input 보더, focus 시 indigo 보더 + `0 0 0 3px rgba(83,58,253,.12)` ring.
- 라벨: weight 400 (Inter regular).
- 파일 첨부:
  - 트리거(`.inquiry-file-trigger`): outline indigo pill + 클립 아이콘.
  - 칩 리스트: 회색 박스 → subdued indigo pill 칩.
- 「등록 하기」 / 「수정 저장」: filled indigo pill (`--inq-primary-bg`).
- 삭제(`.inquiry-delete-form button`): ruby outline pill, hover 시 ruby fill — destructive 의미.

### 6. 비밀번호 폼(`.inquiry-password-form`)
- bare 단락 → **card 화** (canvas + hairline + rounded-lg + Level 1 shadow + 24px padding).
- 「확인」 버튼: filled indigo pill.

## DESIGN.md 와의 미세한 일탈

1. **Gradient mesh 는 CSS 근사치**. DESIGN.md 는 SVG 또는 large bg image 권장이지만 플러그인 자산 부담을 줄이기 위해 다중 `radial-gradient` 5스톱 + blur 로 근사. 실제 Stripe 의 organic blob 은 재현하지 않음. 추후 SVG 자산 추가 가능.
2. **Sohne 대신 Inter**. Sohne 는 proprietary 라 DESIGN.md 의 fallback 권장대로 Inter 300/400 으로 대체.
3. **Cream-band 카드**(DESIGN.md `card-cream-band`) 는 미사용. Q&A 플러그인에는 별도로 warmth interlude 가 필요한 자리가 없어 토큰만 정의해 두고 활용은 보류.

## QA 체크리스트

배포 후 사이트에서 확인할 것:
- [ ] 목록 페이지 진입 시 상단에 그라데이션 mesh 가 흐릿하게 보이는가
- [ ] 「글쓰기」 큰 버튼이 indigo pill 로 표시되는가
- [ ] 테이블 행 hover 시 배경이 `#f6f9fc` 로 변하고 숫자 셀이 tabular figures(0/1/2 의 폭이 동일) 인가
- [ ] 페이지네이션 현재 페이지가 filled indigo 인가
- [ ] 단일 페이지 「← 목록으로」 가 outline indigo pill 인가
- [ ] 답변 스레드의 **관리자 답변** 이 deep navy 배경 + 흰 텍스트로 표시되는가
- [ ] 「답글 등록」 / 「등록 하기」 / 「수정 저장」 이 모두 동일한 filled indigo pill 인가
- [ ] 비번 입력 폼이 카드형으로 표시되고 「확인」 이 indigo pill 인가
- [ ] 글쓰기 폼의 「파일 첨부」 가 outline indigo pill + 칩 리스트가 subdued indigo 인가
- [ ] 「삭제」 버튼이 ruby outline (hover 시 ruby fill) 인가
- [ ] 본문 텍스트가 Inter 300 + `ss01` (단층 a) 으로 렌더링 되는가

## 자식 테마 오버라이드 가이드

`assets/wp-qna-board.css` 최상단 토큰 블록은 `:where(...)` 로 specificity 0 이라 자식 테마에서 다음과 같이 단순 덮어쓰기 가능.

```css
/* 자식 테마 style.css 예시 — primary 톤만 변경 */
.inquiry-board-list,
.inquiry-single-wrap,
.inquiry-form-wrap {
  --inq-primary: #006bff;       /* 브랜드 블루 */
  --inq-primary-deep: #0058d6;
  --inq-primary-press: #003e9b;
  --inq-primary-subdued: #c5dcff;
}
```

후속 패치(v0.5.x) 에서 관리자 설정 페이지의 color picker 가 위 토큰을 동적으로 덮어쓰도록 `wp_head` 인라인 `:root` 블록을 출력할 예정.
