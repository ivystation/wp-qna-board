/* wp-qna-board front-end helpers. */
(function () {
	'use strict';

	/**
	 * 파일 첨부 UI — input.inquiry-file-input 의 선택을 감지해
	 *  - .inquiry-file-count 의 (현재/최대) 카운트 갱신
	 *  - .inquiry-file-list 에 선택 파일명을 칩으로 표시
	 *  - 최대 개수 초과 시 선택을 거부 (alert 후 input 초기화)
	 */
	function initFilePickers() {
		var inputs = document.querySelectorAll('.inquiry-file-input');
		Array.prototype.forEach.call(inputs, function (input) {
			var wrap  = input.closest('.inquiry-form-files');
			if (!wrap) return;
			var max   = parseInt(input.getAttribute('data-max'), 10) || 5;
			var count = wrap.querySelector('.inquiry-file-count');
			var list  = wrap.querySelector('.inquiry-file-list');

			input.addEventListener('change', function () {
				var files = input.files ? Array.prototype.slice.call(input.files) : [];

				if (files.length > max) {
					try { window.alert('파일은 최대 ' + max + '개까지 첨부할 수 있습니다.'); } catch (e) {}
					input.value = '';
					files = [];
				}

				if (count) {
					count.textContent = '(' + files.length + '/' + max + ')';
				}

				if (list) {
					while (list.firstChild) list.removeChild(list.firstChild);
					files.forEach(function (f) {
						var li   = document.createElement('li');
						var name = document.createElement('span');
						name.className   = 'inquiry-file-name';
						name.textContent = f.name;
						li.appendChild(name);
						list.appendChild(li);
					});
				}
			});
		});
	}

	/**
	 * 공개/비공개 라디오 → 비밀번호 필드 토글.
	 * 비공개일 때만 필드를 보이고 required 를 건다(공개일 때 required 가 남아 있으면
	 * 숨겨진 필드 때문에 브라우저 기본 검증이 제출을 막는다).
	 */
	function initVisibilityToggle() {
		var radios = document.querySelectorAll('.inquiry-form input[name="inquiry_visibility"]');
		if (!radios.length) return;

		var wrap = document.querySelector('.inquiry-form .inquiry-password-field');
		if (!wrap) return;
		var input = wrap.querySelector('input[type="password"]');

		function sync() {
			var checked = document.querySelector('.inquiry-form input[name="inquiry_visibility"]:checked');
			var isPrivate = !!checked && checked.value === 'private';
			wrap.hidden = !isPrivate;
			if (input) {
				if (isPrivate) {
					input.setAttribute('required', 'required');
				} else {
					input.removeAttribute('required');
					input.value = '';
				}
			}
		}

		Array.prototype.forEach.call(radios, function (r) {
			r.addEventListener('change', sync);
		});
		sync();
	}

	function init() {
		initFilePickers();
		initVisibilityToggle();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
