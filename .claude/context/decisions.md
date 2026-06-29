---
audience: dev
visibility: internal
updated: 2026-06-29
---

# 결정 기록 (Decisions) — wp-qna-board

> 경량 ADR(Architecture Decision Record). "왜 이렇게 했는가"를 남겨, 나중에 같은 논의를
> 반복하거나 무심코 되돌리는 일을 막습니다. **새 결정은 맨 위에 추가**(최신순)하세요.

## 형식

```
## YYYY-MM-DD — [결정 제목]
- **결정**: 무엇을 하기로 했는가
- **이유**: 왜 (대안 대비 장점, 제약)
- **영향**: 무엇이 바뀌는가 / 되돌릴 때 주의점
- **관련**: docs/xxx.md, PR #번호, [[memory/항목]]
```

---

## 2026-06-29 — IvyNet 표준 맥락 인계 구조(.claude/context/) 도입
- **결정**: 이 repo에 IvyNet 전사 표준 맥락 인계 구조(`.claude/context/` — STATUS.md·decisions.md·memory/)와 안전장치(guard.sh·settings.json), 진입점 CLAUDE.md를 설치한다.
- **이유**: 여러 개발자·Claude Code 세션이 진행 중 작업을 끊김 없이 이어받도록 팀 공유 맥락을 git 추적 파일로 외재화(Git as SSOT)하기 위함. auto-memory는 개인 PC에만 남아 공유되지 않는 한계를 보완.
- **영향**: 작업 시작 전 STATUS.md·decisions.md를 먼저 읽고, 종료 시 docs 갱신·STATUS 갱신·decisions 1줄·memory 승격 4단계를 수행하는 절차가 표준이 된다. 민감정보(비밀번호·키·토큰)는 평문 저장 금지.
- **관련**: ivy-context-standards ADR-001(Git as SSOT)·ADR-007, CLAUDE.md §0
