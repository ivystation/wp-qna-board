# wp-qna-board — GitHub Releases 자동 업데이트 구축

## 목표
플러그인을 설치한 워드프레스 사이트의 **플러그인 화면 → 업데이트 알림**이 GitHub 태그(릴리스)와 자동으로 연동되도록 한다.

## 구성 요약

| 구성 요소 | 역할 |
| --- | --- |
| `inc/updater.php` | GitHub Releases API 호출 → WP 업데이트 transient 주입 |
| `.github/workflows/release.yml` | `v*.*.*` 태그 push 시 zip 빌드 + Release 생성 자동화 |
| `inc/settings.php` (확장) | GitHub Token 입력 / 캐시 강제 갱신 버튼 |
| `wp-qna-board.php` | `Update URI` 헤더 + 버전 0.3.0 + updater 로드 |

## 동작 흐름

1. 워드프레스가 `wp_update_plugins()` 트랜지언트를 갱신할 때 우리 필터(`pre_set_site_transient_update_plugins`)가 호출된다.
2. 필터는 `inquiry_board_fetch_latest_release()` 로 GitHub `releases/latest` 를 조회 (6시간 캐시).
3. 응답의 `tag_name` 에서 `v` 를 떼어내 버전(예: `v0.3.1` → `0.3.1`)으로 만든 뒤 `INQUIRY_BOARD_VERSION` 과 비교.
4. 더 큰 버전이면 `$transient->response[ basename ]` 에 새 버전 페이로드를 주입 → 대시보드에 "Update available" 표시.
5. 사용자가 "지금 업데이트" 를 누르면 WP 업그레이더가 `package` URL(릴리스 자산 zip 또는 zipball)을 다운로드 → 압축 해제.
6. GitHub zip 은 최상위 폴더가 `owner-repo-{sha}/` 라서 그대로 두면 슬러그가 깨진다. `upgrader_source_selection` 필터가 그 폴더를 `wp-qna-board/` 로 rename 한다.
7. WP 가 활성 플러그인을 안전하게 교체.

## 새 릴리스 배포 절차

```bash
# 1) 버전 올리기
#    - wp-qna-board.php  의 Version: 헤더와 INQUIRY_BOARD_VERSION 상수
#    - readme.txt        의 Stable tag 와 Changelog
# 2) 커밋 후 푸시
git commit -am "chore(release): v0.3.1"
git push origin main
# 3) 태그 푸시 → GitHub Actions 가 zip 빌드 + Release 자동 생성
git tag v0.3.1
git push origin v0.3.1
```

태그가 push 되면 `release.yml` 워크플로우가 자동으로
- `wp-qna-board/` 슬러그 폴더 구조로 zip 빌드
- `wp-qna-board.zip` 자산을 첨부한 Release 를 생성한다.

자산 zip 이 있으면 updater 는 그것을 우선 사용한다(폴더명 정합성이 보장돼 더 안정적). 자산이 없으면 자동으로 zipball 로 fallback 한다.

## 운영 메모

- **캐시**: 6시간. 즉시 반영이 필요하면 `Settings → Q&A 게시판 → 업데이트 캐시 강제 갱신` 클릭.
- **Private 저장소 전환 시**: `wp-config.php` 에
  ```php
  define( 'INQUIRY_BOARD_GH_TOKEN', 'ghp_xxx' );
  ```
  를 추가하거나 설정 페이지에 토큰을 입력한다. 토큰은 `repo:read` 권한이면 충분.
- **율 제한**: 미인증 GitHub API 는 IP 당 시간당 60회. 6시간 캐시이므로 한 사이트가 한 도메인에서 율 제한을 칠 일은 거의 없음.
- **첫 릴리스**: 이 작업 직후 `v0.3.0` 태그를 만들고 push 하면 GitHub Actions 가 첫 자산 zip 을 만든다. 이후 설치된 사이트들은 6시간 내 업데이트 알림을 받는다.

## 파일 변경 내역

- 추가: `inc/updater.php`, `.github/workflows/release.yml`, `docs/wp-qna-board-GitHub-자동업데이트-구축.md`
- 수정: `wp-qna-board.php` (헤더 + 버전 + updater 로드), `inc/settings.php` (토큰 필드 + 캐시 무효화 + UI), `readme.txt` (Stable tag + Changelog)
