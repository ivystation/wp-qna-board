#!/usr/bin/env bash
#
# guard.sh — Claude Code PreToolUse 훅 (Bash 명령 최종 안전망)
#
# 전사 표준 안전장치. settings.json 의 deny/ask 로 1차 거르고, 여기서 정말 위험한 명령을
# 한 번 더 차단한다. Claude Code 가 Bash 도구를 실행하기 직전 이 스크립트를 호출한다.
#   - 위험 패턴 발견 → exit 2 로 차단(사유를 Claude 에게 전달)
#   - 그 외 → exit 0 으로 통과
#
# 새 위험 패턴이 생기면 BLOCK_PATTERNS 배열에 추가만 하면 된다.
# (출처: ukuhak.com 운영 체계에서 전사 표준으로 흡수 — ADR-004)

payload="$(cat)"

# 정말 되돌릴 수 없는 명령들 (대소문자 무시). 프로젝트별로 추가 가능.
BLOCK_PATTERNS=(
  'rm[[:space:]]+-[a-z]*r[a-z]*f'      # rm -rf / -fr 등
  'rm[[:space:]]+-[a-z]*f[a-z]*r'
  'rsync[^|]*--delete'                  # 원격 파일까지 삭제
  'git[[:space:]]+push[[:space:]].*--force'
  'git[[:space:]]+push[[:space:]].*-f([[:space:]]|$)'
  'git[[:space:]]+reset[[:space:]]+--hard'
  'git[[:space:]]+clean[[:space:]]+-[a-z]*f'
  'drop[[:space:]]+database'
  'drop[[:space:]]+table'
  'truncate[[:space:]]+table'
  'mkfs'
  ':\(\)\{'                             # fork bomb
  '>[[:space:]]*/dev/sd'
)

for pat in "${BLOCK_PATTERNS[@]}"; do
  if echo "$payload" | grep -iqE "$pat"; then
    echo "차단됨: 파괴적 명령으로 보입니다(패턴: ${pat}). 안전 정책상 자동 실행하지 않습니다. 정말 필요하면 사람이 직접 검토·승인해야 합니다." >&2
    exit 2
  fi
done

exit 0
