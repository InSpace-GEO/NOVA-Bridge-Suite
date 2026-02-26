(function (window, document) {
	'use strict';

	function fieldHasValue(field) {
		if (!field || field.disabled) {
			return false;
		}

		if ('checkbox' === field.type || 'radio' === field.type) {
			return Boolean(field.checked);
		}

		return Boolean(field.value && String(field.value).trim() !== '');
	}

	function sectionHasValue(section) {
		var fields = section.querySelectorAll('.quarantined-cpt-blog-editor-field, .wp-editor-area');
		var hasValue = false;

		fields.forEach(function (field) {
			if (fieldHasValue(field)) {
				hasValue = true;
			}
		});

		return hasValue;
	}

	function bindAutoToggle(section) {
		if (!section) {
			return;
		}

		var fields = section.querySelectorAll('.quarantined-cpt-blog-editor-field, .wp-editor-area');
		if (!fields.length) {
			return;
		}

		var updateState = function () {
			if (sectionHasValue(section)) {
				section.open = true;
			}
		};

		updateState();
		fields.forEach(function (field) {
			field.addEventListener('input', updateState);
			field.addEventListener('change', updateState);
		});
	}

	function bindRelatedSearch(root) {
		var search = root.querySelector('.quarantined-cpt-blog-related-search');
		var items = Array.prototype.slice.call(root.querySelectorAll('[data-blog-related-item]'));

		if (!search || !items.length) {
			return;
		}

		var update = function () {
			var term = (search.value || '').trim().toLowerCase();
			items.forEach(function (item) {
				var title = (item.getAttribute('data-blog-related-title') || '').toLowerCase();
				item.style.display = !term || title.indexOf(term) !== -1 ? 'flex' : 'none';
			});
		};

		search.addEventListener('input', update);
	}

	function bindTinyMceSections(root) {
		if (!window.tinymce) {
			return;
		}

		var openEditorSection = function (editor) {
			if (!editor || !editor.id || editor.id.indexOf('quarantined_cpt_blog_') !== 0) {
				return;
			}

			var container = editor.getContainer ? editor.getContainer() : null;
			if (!container) {
				return;
			}

			var section = container.closest('.quarantined-cpt-blog-section');
			if (section && root.contains(section)) {
				section.open = true;
			}
		};

		tinymce.editors.forEach(function (editor) {
			openEditorSection(editor);
			editor.on('change keyup input SetContent', function () {
				openEditorSection(editor);
			});
		});

		tinymce.on('AddEditor', function (event) {
			var editor = event.editor;
			openEditorSection(editor);
			editor.on('change keyup input SetContent', function () {
				openEditorSection(editor);
			});
		});
	}

	window.addEventListener('DOMContentLoaded', function () {
		var root = document.querySelector('.quarantined-cpt-blog-editor');
		if (!root) {
			return;
		}

		root.querySelectorAll('.quarantined-cpt-blog-section[data-autotoggle="1"]').forEach(bindAutoToggle);
		bindRelatedSearch(root);
		bindTinyMceSections(root);
	});
}(window, document));
