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

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initFilePickers);
	} else {
		initFilePickers();
	}
})();
