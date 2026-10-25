(function () {
	'use strict';

	var cfg = window.onespaceToolkit;

	if (!cfg) {
		return;
	}

	var panel = document.getElementById('onespace-progress');
	var bar = panel ? panel.querySelector('.onespace-progress__bar span') : null;
	var barWrap = panel ? panel.querySelector('.onespace-progress__bar') : null;
	var text = panel ? panel.querySelector('.onespace-progress__text') : null;
	var activeTimer = null;
	var activeJob = null;

	function jobId() {
		return 'job-' + Date.now() + '-' + Math.random().toString(36).slice(2, 10);
	}

	function setProgress(data) {
		if (!panel) {
			return;
		}

		panel.hidden = false;
		panel.classList.toggle('is-error', Boolean(data.error));

		var current = Number(data.current || 0);
		var total = Number(data.total || 0);
		var percent = total > 0 ? Math.min(100, Math.round((current / total) * 100)) : 0;

		if (bar && barWrap) {
			if (total > 0) {
				barWrap.classList.remove('is-indeterminate');
				bar.style.width = percent + '%';
			} else {
				barWrap.classList.add('is-indeterminate');
				bar.style.width = '35%';
			}
		}

		var label = data.stage || 'Выполняется';

		if (current > 0 || total > 0) {
			label += ' ' + current + (total > 0 ? '/' + total : '');
		}

		if (data.error) {
			label = 'Ошибка: ' + data.error;
		} else if (data.message) {
			label += ' — ' + data.message;
		}

		if (text) {
			text.textContent = label;
		}
	}

	function stopPolling() {
		if (activeTimer) {
			clearInterval(activeTimer);
			activeTimer = null;
		}

		activeJob = null;
	}

	function poll(job, onDone) {
		activeJob = job;

		activeTimer = setInterval(function () {
			if (activeJob !== job) {
				return;
			}

			fetch(cfg.ajaxUrl + '?action=onespace_toolkit_job&job=' + encodeURIComponent(job) + '&nonce=' + encodeURIComponent(cfg.nonce), {
				credentials: 'same-origin'
			})
				.then(function (response) {
					return response.json();
				})
				.then(function (json) {
					if (!json || !json.success) {
						return;
					}

					setProgress(json.data);

					if (json.data.done) {
						stopPolling();

						if (json.data.error) {
							onDone(null, json.data.error);
						} else {
							onDone(json.data.result || {});
						}
					}
				})
				.catch(function () {
				});
		}, 800);
	}

	function start(form, action, onDone) {
		var button = form.querySelector('button[type="submit"], input[type="submit"]');
		var job = jobId();
		var data = new FormData(form);

		data.set('action', 'onespace_toolkit_' + action + '_start');
		data.set('job', job);
		data.set('nonce', cfg.nonce);

		if (button) {
			button.disabled = true;
		}

		setProgress({ stage: 'Подготовка', current: 0, total: 0 });

		if (panel && panel.scrollIntoView) {
			panel.scrollIntoView({ behavior: 'smooth', block: 'center' });
		}

		poll(job, function (result, error) {
			if (button) {
				button.disabled = false;
			}

			if (error) {
				setProgress({ error: error });
				return;
			}

			onDone(result);
		});

		fetch(cfg.ajaxUrl, {
			method: 'POST',
			body: data,
			credentials: 'same-origin'
		})
			.then(function (response) {
				return response.json();
			})
			.then(function (json) {
				if (!json || json.success) {
					return;
				}

				stopPolling();

				if (button) {
					button.disabled = false;
				}

				setProgress({ error: (json.data && json.data.message) || 'Неизвестная ошибка' });
			})
			.catch(function () {
				if (text) {
					text.textContent = 'Соединение прервано, ждём завершения задачи…';
				}
			});
	}

	function handleResult(action, result) {
		if (!result) {
			return;
		}

		if (action === 'export' || action === 'backup') {
			if (result.download) {
				window.location.href = result.download;
			}
			return;
		}

		if (action === 'import') {
			var message = (result.database ? 'БД импортирована' : 'БД не импортировалась') + ', файлов: ' + (result.files || 0);

			if (result.database) {
				message += ', замен URL: ' + (result.replaced || 0);
			}

			window.location.href = cfg.adminUrl + '?page=onespace-toolkit&onespace_notice=imported&onespace_message=' + encodeURIComponent(message);
		}
	}

	document.querySelectorAll('form[data-job-action]').forEach(function (form) {
		form.addEventListener('submit', function (event) {
			event.preventDefault();

			var action = form.getAttribute('data-job-action');

			start(form, action, function (result) {
				handleResult(action, result);
			});
		});
	});

	(function initBulkSelection() {
		var selectAll = document.getElementById('onespace-select-all');
		var checks = Array.prototype.slice.call(document.querySelectorAll('.onespace-toolkit__file-check'));

		if (!selectAll || !checks.length) {
			return;
		}

		function sync() {
			var checked = checks.filter(function (checkbox) {
				return checkbox.checked;
			}).length;

			selectAll.checked = checked === checks.length;
			selectAll.indeterminate = checked > 0 && checked < checks.length;
		}

		selectAll.addEventListener('change', function () {
			checks.forEach(function (checkbox) {
				checkbox.checked = selectAll.checked;
			});

			sync();
		});

		checks.forEach(function (checkbox) {
			checkbox.addEventListener('change', sync);
		});
	})();
})();
