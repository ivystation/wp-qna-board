/**
 * Q&A 게시판 — 마이그레이션 러너 프론트.
 *
 * 흐름:
 *   1) dry-run 으로 시작 전 검증 → 요약 표시
 *   2) DB 백업 확인 체크 → "마이그레이션 시작" 활성화
 *   3) 시작 후 1.5s 간격으로 tick 폴링 → 단계별 진행률·통계·로그 갱신
 *   4) running 상태에서 "취소" 가능, done/cancelled/error 상태에서 "리셋"
 *
 * 전역 의존: window.InquiryMigration = { ajax_url, nonce, board_id, board_name, i18n }
 */
(function () {
	'use strict';

	const cfg = window.InquiryMigration || {};
	if (!cfg.ajax_url || !cfg.nonce) {
		return;
	}

	const POLL_INTERVAL_MS = 1500;
	let polling = false;
	let pollTimer = null;

	const $ = (sel) => document.querySelector(sel);
	const $$ = (sel) => Array.from(document.querySelectorAll(sel));

	const els = {
		dryrunBtn:        $('#ibm-dryrun-btn'),
		dryrunPanel:      $('#ibm-dryrun-panel'),
		dryrunSummary:    $('#ibm-dryrun-summary'),
		dryrunCategories: $('#ibm-dryrun-categories'),
		backupCheck:      $('#ibm-backup-check'),
		startBtn:         $('#ibm-start-btn'),
		cancelBtn:        $('#ibm-cancel-btn'),
		resetBtn:         $('#ibm-reset-btn'),
		statusBadge:      $('#ibm-status-badge'),
		statusMsg:        $('#ibm-status-msg'),
		stageLabel:       $('#ibm-stage-label'),
		progressWrap:     $('#ibm-progress'),
		barPosts:         $('#ibm-bar-posts'),
		barAtt:           $('#ibm-bar-attachments'),
		barComments:      $('#ibm-bar-comments'),
		statPostsInserted: $('#ibm-stat-posts-inserted'),
		statPostsSkipped:  $('#ibm-stat-posts-skipped'),
		statPostsTrashed:  $('#ibm-stat-posts-trashed'),
		statAttOk:         $('#ibm-stat-att-ok'),
		statAttFail:       $('#ibm-stat-att-fail'),
		statCmtInserted:   $('#ibm-stat-cmt-inserted'),
		statCmtTrashed:    $('#ibm-stat-cmt-trashed'),
		statErrors:        $('#ibm-stat-errors'),
		logBox:            $('#ibm-log'),
		errorBox:          $('#ibm-errors'),
		batchInput:        $('#ibm-batch'),
	};

	function ajax(action, data) {
		const body = new URLSearchParams();
		body.set('action', action);
		body.set('nonce', cfg.nonce);
		if (data) {
			for (const k of Object.keys(data)) {
				body.set(k, String(data[k]));
			}
		}
		return fetch(cfg.ajax_url, {
			method: 'POST',
			credentials: 'same-origin',
			body,
		}).then(async (res) => {
			let json = null;
			try { json = await res.json(); } catch (e) { /* keep null */ }
			if (!json) {
				throw new Error('서버 응답 파싱 실패 (HTTP ' + res.status + ')');
			}
			if (!json.success) {
				const msg = (json.data && json.data.message) ? json.data.message : '요청 실패';
				const err = new Error(msg);
				err.payload = json.data;
				throw err;
			}
			return json.data;
		});
	}

	function setStatusBadge(status, msg) {
		if (!els.statusBadge) return;
		els.statusBadge.dataset.status = status;
		const labels = {
			idle: '대기',
			running: '실행 중',
			paused: '일시정지',
			cancelled: '취소됨',
			done: '완료',
			error: '에러',
		};
		els.statusBadge.textContent = labels[status] || status;
		if (els.statusMsg) {
			els.statusMsg.textContent = msg || '';
		}
	}

	function pct(done, total) {
		if (!total || total <= 0) return 0;
		const p = Math.min(100, Math.max(0, Math.round((done / total) * 100)));
		return p;
	}

	function setBar(el, value, total, label) {
		if (!el) return;
		const p = pct(value, total);
		el.style.width = p + '%';
		el.setAttribute('aria-valuenow', String(p));
		el.setAttribute('aria-valuemin', '0');
		el.setAttribute('aria-valuemax', '100');
		el.textContent = label || (p + '%');
	}

	function renderState(state) {
		if (!state) return;

		setStatusBadge(state.status, state.message || '');

		const stageLabels = { posts: '글', attachments: '첨부', comments: '댓글', done: '완료' };
		if (els.stageLabel) {
			els.stageLabel.textContent = stageLabels[state.stage] || state.stage || '-';
		}

		const totals = state.totals || {};
		const progress = state.progress || {};

		const postsDone = (progress.posts?.inserted || 0) + (progress.posts?.skipped || 0) + (progress.posts?.trashed || 0);
		const attDone   = (progress.attachments?.ok || 0) + (progress.attachments?.fail || 0);
		const cmtDone   = (progress.comments?.inserted || 0) + (progress.comments?.trashed || 0);

		setBar(els.barPosts,    postsDone, totals.posts || 0,       `${postsDone} / ${totals.posts || 0}`);
		setBar(els.barAtt,      attDone,   totals.attachments || 0, `${attDone} / ${totals.attachments || 0}`);
		setBar(els.barComments, cmtDone,   totals.comments || 0,    `${cmtDone} / ${totals.comments || 0}`);

		els.statPostsInserted && (els.statPostsInserted.textContent = progress.posts?.inserted || 0);
		els.statPostsSkipped  && (els.statPostsSkipped.textContent  = progress.posts?.skipped  || 0);
		els.statPostsTrashed  && (els.statPostsTrashed.textContent  = progress.posts?.trashed  || 0);
		els.statAttOk         && (els.statAttOk.textContent         = progress.attachments?.ok   || 0);
		els.statAttFail       && (els.statAttFail.textContent       = progress.attachments?.fail || 0);
		els.statCmtInserted   && (els.statCmtInserted.textContent   = progress.comments?.inserted || 0);
		els.statCmtTrashed    && (els.statCmtTrashed.textContent    = progress.comments?.trashed  || 0);
		els.statErrors        && (els.statErrors.textContent        = (state.errors || []).length);

		if (els.logBox) {
			els.logBox.textContent = (state.logs || []).join('\n');
			els.logBox.scrollTop = els.logBox.scrollHeight;
		}
		if (els.errorBox) {
			els.errorBox.textContent = (state.errors || []).join('\n');
		}

		const running = state.status === 'running';
		const finished = state.status === 'done' || state.status === 'cancelled' || state.status === 'error';

		if (els.startBtn) {
			els.startBtn.disabled = running || !(els.backupCheck?.checked);
		}
		if (els.cancelBtn) {
			els.cancelBtn.disabled = !running;
		}
		if (els.resetBtn) {
			els.resetBtn.disabled = running || state.status === 'idle';
		}
		if (els.dryrunBtn) {
			els.dryrunBtn.disabled = running;
		}
		if (els.batchInput) {
			els.batchInput.disabled = running;
		}

		// 진행률 영역은 시작 후에 노출.
		if (els.progressWrap) {
			els.progressWrap.style.display = (state.status === 'idle') ? 'none' : '';
		}
	}

	function renderDryrun(summary) {
		if (!els.dryrunPanel || !summary) return;
		els.dryrunPanel.hidden = false;
		const lines = [];
		const b = summary.board || {};
		lines.push(`게시판: uid=${b.uid || '-'} (${b.board_name || '-'})`);
		lines.push(`전체 글: ${summary.total_posts}건 (휴지통 ${summary.trashed_posts}건 → 제외)`);
		lines.push(`전체 댓글: ${summary.total_comments}건 (휴지통 ${summary.trashed_comments}건 → 제외)`);
		lines.push(`첨부(별도 테이블): ${summary.total_attachments}건`);
		lines.push(`이미 마이그레이션된 글: ${summary.already_migrated_posts}건 (재실행 시 자동 skip)`);
		lines.push(`미명시 카테고리 매핑(→ etc): ${summary.unmapped_categories}건`);
		els.dryrunSummary.textContent = lines.join('\n');

		if (els.dryrunCategories) {
			const rows = (summary.categories || []).map((c) => {
				const cat = `cat1=${c.category1 || '∅'} | cat2=${c.category2 || '∅'}`;
				return `${cat}  →  ${c.slug}  (${c.count})`;
			});
			els.dryrunCategories.textContent = rows.join('\n');
		}
	}

	function stopPolling() {
		polling = false;
		if (pollTimer) {
			clearTimeout(pollTimer);
			pollTimer = null;
		}
	}

	function scheduleTick() {
		if (!polling) return;
		pollTimer = setTimeout(tick, POLL_INTERVAL_MS);
	}

	async function tick() {
		if (!polling) return;
		try {
			const data = await ajax('inquiry_migration_tick');
			renderState(data.state);
			if (data.state.status === 'running') {
				scheduleTick();
			} else {
				stopPolling();
			}
		} catch (e) {
			if (e.payload && e.payload.state) {
				renderState(e.payload.state);
			}
			setStatusBadge('error', e.message);
			stopPolling();
		}
	}

	async function refreshStatus() {
		try {
			const state = await ajax('inquiry_migration_status');
			renderState(state);
			if (state.status === 'running') {
				polling = true;
				scheduleTick();
			}
		} catch (e) {
			setStatusBadge('error', e.message);
		}
	}

	async function onDryrun() {
		const board_id = (cfg.board_id ? Number(cfg.board_id) : 0);
		if (!board_id) {
			alert('대상 게시판이 선택되지 않았습니다. 위 폼에서 게시판을 저장 후 다시 시도하세요.');
			return;
		}
		els.dryrunBtn.disabled = true;
		try {
			const summary = await ajax('inquiry_migration_dryrun', { board_id });
			renderDryrun(summary);
		} catch (e) {
			alert('Dry-run 실패: ' + e.message);
		} finally {
			els.dryrunBtn.disabled = false;
		}
	}

	async function onStart() {
		const board_id = (cfg.board_id ? Number(cfg.board_id) : 0);
		if (!board_id) {
			alert('대상 게시판이 선택되지 않았습니다.');
			return;
		}
		if (!els.backupCheck?.checked) {
			alert('DB 백업 확인 체크가 필요합니다.');
			return;
		}
		if (!confirm('마이그레이션을 시작합니다. 진행 중 페이지를 닫으면 자동 일시정지되며, 다시 열어 [재실행] 으로 cursor 이후를 이어 진행할 수 있습니다.\n시작할까요?')) {
			return;
		}
		const batch = Math.max(10, Math.min(500, Number(els.batchInput?.value) || 100));
		try {
			const state = await ajax('inquiry_migration_start', { board_id, batch, backup_confirmed: 1 });
			renderState(state);
			polling = true;
			scheduleTick();
		} catch (e) {
			if (e.payload && e.payload.state) {
				renderState(e.payload.state);
			}
			alert('시작 실패: ' + e.message);
		}
	}

	async function onCancel() {
		if (!confirm('현재 단계의 batch 처리가 끝난 직후 정지합니다. 계속할까요?')) return;
		try {
			const state = await ajax('inquiry_migration_cancel');
			renderState(state);
			stopPolling();
		} catch (e) {
			alert('취소 실패: ' + e.message);
		}
	}

	async function onReset() {
		if (!confirm('진행 상태(통계·cursor·로그)를 초기화합니다. 이미 마이그레이션 된 글/댓글은 영향받지 않으며, 새로 시작 시 자동 skip 됩니다. 진행할까요?')) return;
		try {
			const state = await ajax('inquiry_migration_reset');
			renderState(state);
		} catch (e) {
			alert('리셋 실패: ' + e.message);
		}
	}

	function onBackupCheckChange() {
		if (els.startBtn) {
			els.startBtn.disabled = !els.backupCheck.checked;
		}
	}

	function init() {
		if (els.dryrunBtn) els.dryrunBtn.addEventListener('click', onDryrun);
		if (els.startBtn)  els.startBtn.addEventListener('click', onStart);
		if (els.cancelBtn) els.cancelBtn.addEventListener('click', onCancel);
		if (els.resetBtn)  els.resetBtn.addEventListener('click', onReset);
		if (els.backupCheck) els.backupCheck.addEventListener('change', onBackupCheckChange);

		refreshStatus();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
