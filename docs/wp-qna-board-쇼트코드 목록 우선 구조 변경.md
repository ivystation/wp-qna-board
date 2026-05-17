# wp-qna-board — 쇼트코드 [inquiry_form] 목록 우선 구조 변경

## 배경 및 목표

기존 `[inquiry_form]` 쇼트코드는 페이지에 삽입되면 **곧바로 글 작성 폼**을 보여줬다.
일반적인 게시판 UX(목록 → 글쓰기 진입)와 맞지 않아 다음과 같이 구조를 바꾼다.

- 쇼트코드 삽입 시 **기본 화면은 게시글 목록**.
- 목록 우측 상단의 **"글쓰기" 버튼**을 누르면 작성 폼으로 전환.
- 작성 폼 상단에 **"← 목록으로"** 버튼을 두어 되돌아갈 수 있게 함.
- 글 등록 후에는 (기존 동작과 달리) 새로 만들어진 글 permalink 가 아니라
  **목록 페이지(쇼트코드가 박힌 페이지)** 로 redirect 되도록 기본값 변경.

쇼트코드 파라미터로 페이지당 노출 글 수를 조정할 수 있으며,
요구사항대로 **기본값은 `posts_per_page='20'`**.

## 변경된 쇼트코드 속성

| 속성 | 기본값 | 설명 |
| --- | --- | --- |
| `posts_per_page` | `20` | 목록 1페이지에 노출할 글 수 |
| `view` | `auto` | `auto`\|`list`\|`write`. `auto` 는 URL `?ipv=write` 가 있으면 작성 폼, 아니면 목록 |
| `show_write_button` | `1` | 목록 상단에 "글쓰기" 버튼 노출 여부 |
| `category` | (빈 값) | 특정 카테고리 슬러그로 목록을 한정 |
| `title` | `문의하기` | 작성 폼 헤더 텍스트 |
| `redirect` | (빈 값) | 작성 완료 후 redirect URL (기본은 쇼트코드 페이지로 자동) |

### 사용 예시

```text
[inquiry_form]
[inquiry_form posts_per_page="30"]
[inquiry_form category="visa" posts_per_page="10"]
[inquiry_form view="write"]   <!-- 작성 폼만 고정 노출 -->
```

## URL 동작

- 목록 → 작성 전환: `?ipv=write` 쿼리 파라미터 추가
- 작성 → 목록 복귀: `?ipv=write` 제거 (또는 `← 목록으로` 버튼)
- 페이지네이션: WP 메인 쿼리와의 충돌(정적 페이지 + `?paged=`) 을 피하기 위해
  **`?inq_paged=N`** 별도 변수 사용. 페이지네이션 링크가 이 변수로 생성된다.

## 변경/추가된 파일

- `inc/form.php`
  - `inquiry_board_shortcode_form()` 에 view 분기·`posts_per_page` 기본 20 처리·`category` 옵션 추가.
  - 쇼트코드 페이지 URL 계산 유틸 `inquiry_board_current_page_url()` 신설.
- `templates/inquiry-list.php` (신규)
  - 목록 화면 템플릿. `WP_Query` 로 `inquiry` CPT 를 페이지네이션과 함께 렌더링.
- `templates/inquiry-form.php`
  - 헤더(제목 + "목록으로" 버튼) 추가.
  - 폼 안에 hidden `inquiry_redirect` 를 자동 주입하여 등록 후 목록으로 복귀.
- `assets/wp-qna-board.css`
  - 글쓰기 버튼·테이블 컬럼 폭·페이지네이션·빈 상태 스타일·반응형 미디어쿼리 보강.

## 호환성 메모

- 기존 `[inquiry_form]` 만 박힌 페이지는 자동으로 목록 화면으로 바뀐다.
  과거처럼 **작성 폼만** 보이게 하려면 `[inquiry_form view="write"]` 으로 명시.
- 테마가 `inquiry-list.php` / `inquiry-form.php` 를 제공하면 `locate_template()` 으로 그쪽을 우선 사용.
