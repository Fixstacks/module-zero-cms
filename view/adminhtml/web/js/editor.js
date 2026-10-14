/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 *
 * Zero CMS editor (admin), plain JavaScript; config from
 * #zero-cms-editor-config (ViewModel\Adminhtml\Editor).
 * Left: components (drag or arrow buttons) and the selected one's fields.
 * Right: a live preview of the unsaved content (signed draft link).
 * AI, when on: build a page, write/improve/shorten text, translate; results
 * load as unsaved changes. The server re-cleans everything (Validator).
 */
(function () {
    'use strict';

    var root = document.getElementById('zero-cms-editor');
    var configEl = document.getElementById('zero-cms-editor-config');
    if (!root || !configEl) {
        return;
    }
    var config = JSON.parse(configEl.textContent);
    var t = config.i18n;
    var state = {
        components: JSON.parse(JSON.stringify(config.content.components || [])),
        share: shareOf(config.content),
        selected: 0,
        dirty: false,
        revisions: config.revisions || []
    };
    var ids = 0;
    var previewTimer = 0;
    var dragFrom = -1;
    var ui = {};

    /* ---------- helpers ---------- */

    /** A page's link preview settings (content.share), always complete. */
    function shareOf(content) {
        var share = (content && content.share) || {};
        return {
            image: { path: (share.image && share.image.path) || '', alt: (share.image && share.image.alt) || '' },
            title: share.title || '',
            description: share.description || ''
        };
    }

    /** What save and preview send. */
    function payload() {
        return JSON.stringify({ components: state.components, share: state.share });
    }

    function el(tag, attrs, children) {
        var node = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (key) {
            var value = attrs[key];
            if (value === null || value === undefined || value === false) {
                return;
            }
            if (key === 'text') {
                node.textContent = value;
            } else if (key.indexOf('on') === 0) {
                node.addEventListener(key.slice(2), value);
            } else if (key === 'className') {
                node.className = value;
            } else {
                node.setAttribute(key, value === true ? '' : value);
            }
        });
        (children || []).forEach(function (child) {
            if (child) {
                node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
            }
        });
        return node;
    }

    function uid(prefix) {
        ids += 1;
        return (prefix || 'zcms') + '-' + ids;
    }

    function format(text, value) {
        return String(text).replace('%1', value);
    }

    function post(url, data) {
        var body = new FormData();
        body.append('form_key', config.formKey);
        Object.keys(data).forEach(function (key) {
            body.append(key, data[key]);
        });
        return fetch(url, { method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (response) {
                return response.json().then(function (json) {
                    if (!response.ok || json.error) {
                        throw new Error(json.error || response.statusText);
                    }
                    return json;
                });
            });
    }

    function withParams(url, params) {
        var query = Object.keys(params).map(function (key) {
            return encodeURIComponent(key) + '=' + encodeURIComponent(params[key]);
        }).join('&');
        return url + (url.indexOf('?') === -1 ? '?' : '&') + query;
    }

    function defaults(fields) {
        var data = {};
        Object.keys(fields || {}).forEach(function (name) {
            var field = fields[name];
            switch (field.type) {
                case 'link': data[name] = { url: '', label: '' }; break;
                case 'image': data[name] = { path: '', alt: '' }; break;
                case 'products': data[name] = { source: 'category', category_id: 0, skus: [] }; break;
                case 'repeater': data[name] = [defaults(field.fields)]; break;
                case 'boolean': data[name] = !!field['default']; break;
                case 'number': data[name] = field['default'] !== undefined ? field['default'] : (field.min || 0); break;
                case 'select': data[name] = field['default'] !== undefined ? String(field['default']) : Object.keys(field.options || {})[0] || ''; break;
                default: data[name] = '';
            }
        });
        return data;
    }

    function summary(component) {
        var definition = config.components[component.type];
        var data = component.data || {};
        var fields = definition ? definition.fields : {};
        var names = Object.keys(fields);
        for (var i = 0; i < names.length; i++) {
            if ((fields[names[i]].type === 'text' || !fields[names[i]].type) && data[names[i]]) {
                return data[names[i]];
            }
        }
        return '';
    }

    function changed(rerenderList) {
        state.dirty = true;
        ui.status.textContent = t.unsaved;
        ui.save.disabled = false;
        if (rerenderList) {
            renderList();
        }
        clearTimeout(previewTimer);
        previewTimer = setTimeout(refreshPreview, 700);
    }

    /* ---------- fields ---------- */

    function labelled(field, input, id) {
        input.id = id;
        return el('div', { className: 'zcms-field' }, [
            el('label', { 'for': id, className: 'zcms-label' }, [
                field.label,
                field.required ? el('span', { className: 'zcms-required', text: ' (' + t.required + ')' }) : null
            ]),
            input
        ]);
    }

    function fieldElement(name, field, data, onChange) {
        var id = uid('zcms-' + name);
        var value = data[name];
        var set = function (newValue, rerender) {
            data[name] = newValue;
            onChange(rerender);
        };
        switch (field.type) {
            case 'textarea':
                var area = el('textarea', {
                    rows: 5, maxlength: field.max || 5000, className: 'admin__control-textarea',
                    oninput: function (e) { set(e.target.value); }
                }, [value || '']);
                return withAssist(labelled(field, area, id), field, area, data, true, set);
            case 'markdown':
            case 'html':
                // Long text: Markdown (with AI help) or HTML (imported posts, cleaned on save).
                var long = el('textarea', {
                    rows: 16, maxlength: field.max || (field.type === 'html' ? 200000 : 40000),
                    className: 'admin__control-textarea zcms-long' + (field.type === 'html' ? ' zcms-code' : ''),
                    spellcheck: field.type === 'markdown',
                    oninput: function (e) { set(e.target.value); }
                }, [value || '']);
                var wrapped = labelled(field, long, id);
                if (field.type === 'markdown') {
                    wrapped.appendChild(el('span', { className: 'zcms-hint', text: t.markdownHint }));
                    return withAssist(wrapped, field, long, data, true, set);
                }
                return wrapped;
            case 'datetime':
                var when = labelled(field, el('input', {
                    type: 'datetime-local', value: value || '', className: 'admin__control-text',
                    oninput: function (e) { set(e.target.value); }
                }), id);
                when.appendChild(el('span', { className: 'zcms-hint', text: format(t.storeTime, config.timezone) }));
                return when;
            case 'number':
                return labelled(field, el('input', {
                    type: 'number', min: field.min, max: field.max, value: value, className: 'admin__control-text',
                    oninput: function (e) { set(e.target.value === '' ? '' : Number(e.target.value)); }
                }), id);
            case 'boolean':
                var box = el('input', { type: 'checkbox', id: id, checked: !!value, onchange: function (e) { set(e.target.checked); } });
                return el('div', { className: 'zcms-field zcms-checkbox' }, [box, el('label', { 'for': id, text: field.label })]);
            case 'select':
                var select = el('select', { className: 'admin__control-select', onchange: function (e) { set(e.target.value); } },
                    Object.keys(field.options || {}).map(function (key) {
                        return el('option', { value: key, selected: String(value) === key, text: field.options[key] });
                    }));
                return labelled(field, select, id);
            case 'link':
                value = value || { url: '', label: '' };
                return el('fieldset', { className: 'zcms-group' }, [
                    el('legend', { text: field.label + (field.required ? ' (' + t.required + ')' : '') }),
                    labelled({ label: t.label }, el('input', { type: 'text', value: value.label, maxlength: 120, className: 'admin__control-text',
                        oninput: function (e) { value.label = e.target.value; set(value); } }), uid('zcms-label')),
                    labelled({ label: t.url }, el('input', { type: 'text', value: value.url, placeholder: '/women.html', className: 'admin__control-text',
                        oninput: function (e) { value.url = e.target.value; set(value); } }), uid('zcms-url'))
                ]);
            case 'image':
                return imageField(field, value || { path: '', alt: '' }, set);
            case 'products':
                return productsField(field, value || { source: 'category', category_id: 0, skus: [] }, set);
            case 'repeater':
                return repeaterField(field, Array.isArray(value) ? value : [], set);
            default:
                var input = el('input', {
                    type: 'text', value: value || '', maxlength: field.max || 255, className: 'admin__control-text',
                    oninput: function (e) { set(e.target.value, true); }
                });
                return withAssist(labelled(field, input, id), field, input, data, false, set);
        }
    }

    /* ---------- AI (config.ai: on, and allowed for this admin user) ---------- */

    /** Runs one AI request; while it runs, the given buttons are disabled. */
    function ask(url, data, buttons, status) {
        buttons.forEach(function (button) { button.disabled = true; });
        status.textContent = t.aiWorking;
        return post(url, data)
            .then(function (json) {
                status.textContent = '';
                return json;
            })
            .catch(function (error) {
                status.textContent = format(t.error, error.message);
                throw error;
            })
            .finally(function () {
                buttons.forEach(function (button) { button.disabled = false; });
            });
    }

    /** Write / Improve / Shorten under a text field. */
    function withAssist(wrapper, field, input, data, multiline, set) {
        if (!config.ai) {
            return wrapper;
        }
        var status = el('span', { className: 'zcms-hint', role: 'status' });
        var buttons = [];
        var button = function (action, label) {
            var b = el('button', { type: 'button', className: 'action-tertiary zcms-ai-action', text: label,
                'aria-label': label + ': ' + field.label, onclick: function () {
                    var component = state.components[state.selected] || {};
                    var siblings = {};
                    Object.keys(data).forEach(function (key) {
                        if (typeof data[key] === 'string' && data[key] && data[key] !== input.value) {
                            siblings[key] = data[key];
                        }
                    });
                    ask(config.ai.assist, {
                        action: action,
                        text: input.value,
                        context: JSON.stringify({ component: component.type, field: field.label, max: field.max || (multiline ? 5000 : 255), multiline: multiline, siblings: siblings })
                    }, buttons, status).then(function (json) {
                        input.value = json.text;
                        set(json.text, !multiline);
                    }).catch(function () {});
                } });
            buttons.push(b);
            return b;
        };
        wrapper.appendChild(el('div', { className: 'zcms-row zcms-ai-row' }, [
            el('span', { className: 'zcms-ai-badge', 'aria-hidden': 'true', text: 'AI' }),
            button('write', t.aiWrite), button('improve', t.aiImprove), button('shorten', t.aiShorten), status
        ]));
        return wrapper;
    }

    /** Replaces the editor's components with an AI answer (unsaved). */
    function loadComponents(components, message) {
        state.components = components;
        state.selected = 0;
        renderList();
        renderForm();
        changed();
        ui.status.textContent = message;
    }

    function generatePanel() {
        var briefId = uid('zcms-brief');
        var brief = el('textarea', { id: briefId, rows: 4, maxlength: 4000, className: 'admin__control-textarea', placeholder: t.aiBriefExample });
        var status = el('span', { className: 'zcms-hint', role: 'status' });
        var go = el('button', { type: 'button', className: 'action-secondary', text: t.aiGenerate, onclick: function () {
            if (!brief.value.trim()) {
                brief.focus();
                return;
            }
            if (state.components.length && !window.confirm(t.aiReplace)) {
                return;
            }
            ask(config.ai.generate, { brief: brief.value }, [go], status).then(function (json) {
                loadComponents(json.content.components, t.aiDone);
            }).catch(function () {});
        } });
        return el('details', { className: 'zcms-ai' }, [
            el('summary', { text: t.aiBuild }),
            el('label', { 'for': briefId, className: 'zcms-label', text: t.aiBrief }),
            brief,
            el('p', { className: 'zcms-hint', text: format(t.aiBriefHint, config.ai.language) }),
            el('div', { className: 'zcms-row' }, [go, status])
        ]);
    }

    /** Translate into any language; the store view's own is preselected. */
    function translateControl() {
        var selectId = uid('zcms-language');
        var select = el('select', { id: selectId, className: 'admin__control-select' }, Object.keys(config.ai.locales).map(function (code) {
            return el('option', { value: code, selected: code === config.ai.locale, text: config.ai.locales[code] });
        }));
        var button = el('button', { type: 'button', className: 'action-secondary', text: t.aiTranslate, onclick: function () {
            var language = select.options[select.selectedIndex].text;
            if (!state.components.length || !window.confirm(format(t.aiTranslateConfirm, language))) {
                return;
            }
            ask(config.ai.translate, { locale: select.value, content: JSON.stringify({ components: state.components }) }, [button, select], ui.status).then(function (json) {
                loadComponents(json.content.components, format(t.aiTranslated, language));
            }).catch(function () {});
        } });
        return el('span', { className: 'zcms-row zcms-translate' }, [
            el('label', { 'for': selectId, text: t.aiTranslateInto }), select, button
        ]);
    }


    function imageField(field, value, set) {
        var preview = el('img', { className: 'zcms-image-preview', alt: '', hidden: !value.path, src: value.path ? config.mediaUrl + value.path : null });
        var status = el('span', { className: 'zcms-hint', 'aria-live': 'polite' });
        var fileId = uid('zcms-file');
        var file = el('input', { type: 'file', id: fileId, accept: 'image/jpeg,image/png,image/webp,image/gif', className: 'zcms-file',
            onchange: function (e) {
                if (!e.target.files.length) {
                    return;
                }
                status.textContent = t.uploading;
                var body = new FormData();
                body.append('form_key', config.formKey);
                body.append('image', e.target.files[0]);
                fetch(config.urls.upload, { method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function (response) { return response.json(); })
                    .then(function (json) {
                        if (json.error) {
                            throw new Error(json.error);
                        }
                        value.path = json.path;
                        preview.src = json.url;
                        preview.hidden = false;
                        status.textContent = '';
                        set(value);
                    })
                    .catch(function (error) { status.textContent = format(t.error, error.message); });
            } });
        return el('fieldset', { className: 'zcms-group' }, [
            el('legend', { text: field.label + (field.required ? ' (' + t.required + ')' : '') }),
            preview,
            el('div', { className: 'zcms-row' }, [
                el('label', { 'for': fileId, className: 'action-secondary zcms-upload', text: t.upload }),
                file,
                el('button', { type: 'button', className: 'action-tertiary', text: t.removeImage, onclick: function () {
                    value.path = '';
                    preview.hidden = true;
                    set(value);
                } }),
                status
            ]),
            labelled({ label: t.alt }, el('input', { type: 'text', value: value.alt, maxlength: 255, className: 'admin__control-text',
                oninput: function (e) { value.alt = e.target.value; set(value); } }), uid('zcms-alt'))
        ]);
    }

    function productsField(field, value, set) {
        var name = uid('zcms-source');
        var radio = function (source, label) {
            var id = uid('zcms-radio');
            return el('span', { className: 'zcms-checkbox' }, [
                el('input', { type: 'radio', name: name, id: id, value: source, checked: value.source === source,
                    onchange: function () { value.source = source; set(value); } }),
                el('label', { 'for': id, text: label })
            ]);
        };
        return el('fieldset', { className: 'zcms-group' }, [
            el('legend', { text: field.label }),
            el('div', { className: 'zcms-row' }, [radio('category', t.fromCategory), radio('skus', t.fromSkus)]),
            labelled({ label: t.categoryId }, el('input', { type: 'number', min: 1, value: value.category_id || '', className: 'admin__control-text',
                oninput: function (e) { value.category_id = Number(e.target.value) || 0; set(value); } }), uid('zcms-category')),
            labelled({ label: t.skus }, el('input', { type: 'text', value: (value.skus || []).join(', '), className: 'admin__control-text',
                oninput: function (e) { value.skus = e.target.value.split(',').map(function (s) { return s.trim(); }).filter(Boolean); set(value); } }), uid('zcms-skus'))
        ]);
    }

    function repeaterField(field, items, set) {
        var group = el('fieldset', { className: 'zcms-group' }, [el('legend', { text: field.label })]);
        var render = function () {
            while (group.childNodes.length > 1) {
                group.removeChild(group.lastChild);
            }
            items.forEach(function (item, index) {
                var box = el('fieldset', { className: 'zcms-item' }, [el('legend', { text: format(t.item, index + 1) })]);
                Object.keys(field.fields || {}).forEach(function (sub) {
                    box.appendChild(fieldElement(sub, field.fields[sub], item, function (rerender) { set(items, rerender); }));
                });
                box.appendChild(el('div', { className: 'zcms-row' }, [
                    el('button', { type: 'button', className: 'action-tertiary', text: t.moveUp, disabled: index === 0,
                        onclick: function () { items.splice(index - 1, 0, items.splice(index, 1)[0]); set(items); render(); } }),
                    el('button', { type: 'button', className: 'action-tertiary', text: t.moveDown, disabled: index === items.length - 1,
                        onclick: function () { items.splice(index + 1, 0, items.splice(index, 1)[0]); set(items); render(); } }),
                    el('button', { type: 'button', className: 'action-tertiary', text: t.remove,
                        onclick: function () { items.splice(index, 1); set(items); render(); } })
                ]));
                group.appendChild(box);
            });
            if (!field.max || items.length < field.max) {
                group.appendChild(el('button', { type: 'button', className: 'action-secondary', text: t.addItem,
                    onclick: function () { items.push(defaults(field.fields)); set(items); render(); } }));
            }
        };
        render();
        return group;
    }

    /* ---------- list, form, preview ---------- */

    function move(from, to) {
        if (to < 0 || to >= state.components.length || from === to) {
            return;
        }
        state.components.splice(to, 0, state.components.splice(from, 1)[0]);
        state.selected = to;
        changed();
        renderList();
        renderForm();
    }

    function renderList() {
        ui.list.textContent = '';
        if (!state.components.length) {
            ui.list.appendChild(el('li', { className: 'zcms-empty', text: t.empty }));
        }
        state.components.forEach(function (component, index) {
            var definition = config.components[component.type] || { label: component.type };
            var label = definition.label + (summary(component) ? ': ' + summary(component) : '');
            var item = el('li', {
                className: 'zcms-list-item' + (index === state.selected ? ' is-selected' : ''),
                draggable: 'true',
                'data-index': index,
                ondragstart: function (e) { dragFrom = index; e.dataTransfer.effectAllowed = 'move'; },
                ondragover: function (e) { e.preventDefault(); },
                ondrop: function (e) { e.preventDefault(); move(dragFrom, index); }
            }, [
                el('button', { type: 'button', className: 'zcms-select', 'aria-pressed': index === state.selected ? 'true' : 'false',
                    text: label, onclick: function () { state.selected = index; renderList(); renderForm(); } }),
                el('span', { className: 'zcms-actions' }, [
                    el('button', { type: 'button', className: 'action-tertiary', 'aria-label': t.moveUp + ': ' + label, text: '↑',
                        disabled: index === 0, onclick: function () { move(index, index - 1); } }),
                    el('button', { type: 'button', className: 'action-tertiary', 'aria-label': t.moveDown + ': ' + label, text: '↓',
                        disabled: index === state.components.length - 1, onclick: function () { move(index, index + 1); } }),
                    el('button', { type: 'button', className: 'action-tertiary', 'aria-label': t.remove + ': ' + label, text: '✕',
                        onclick: function () {
                            state.components.splice(index, 1);
                            state.selected = Math.max(0, Math.min(state.selected, state.components.length - 1));
                            changed();
                            renderList();
                            renderForm();
                        } })
                ])
            ]);
            ui.list.appendChild(item);
        });
    }

    function renderForm() {
        ui.form.textContent = '';
        var component = state.components[state.selected];
        if (!component) {
            return;
        }
        var definition = config.components[component.type];
        if (!definition) {
            return;
        }
        component.data = component.data || {};
        ui.form.appendChild(el('h3', { className: 'zcms-form-title', text: definition.label }));
        if (definition.description) {
            ui.form.appendChild(el('p', { className: 'zcms-hint', text: definition.description }));
        }
        Object.keys(definition.fields).forEach(function (name) {
            ui.form.appendChild(fieldElement(name, definition.fields[name], component.data, function (rerender) {
                changed(rerender);
            }));
        });
    }

    function refreshPreview() {
        post(config.urls.preview, { content: payload() })
            .then(function (json) { ui.preview.src = json.url; })
            .catch(function (error) { ui.status.textContent = format(t.error, error.message); });
    }

    function renderVersions() {
        ui.versions.textContent = '';
        if (!state.revisions.length) {
            ui.versions.appendChild(el('li', { text: t.noVersions }));
        }
        state.revisions.forEach(function (revision) {
            ui.versions.appendChild(el('li', {}, [
                el('span', { text: revision.created_at + (revision.created_by ? ' · ' + revision.created_by : '') + (revision.note ? ' · ' + revision.note : '') }),
                el('button', { type: 'button', className: 'action-tertiary', text: t.restore, onclick: function () {
                    fetch(withParams(config.urls.revision, { revision: revision.revision_id }), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                        .then(function (response) { return response.json(); })
                        .then(function (json) {
                            if (json.error) {
                                throw new Error(json.error);
                            }
                            state.components = json.content.components;
                            state.share = shareOf(json.content);
                            state.selected = 0;
                            renderList();
                            renderForm();
                            renderShare();
                            changed();
                            ui.status.textContent = t.restored;
                        })
                        .catch(function (error) { ui.status.textContent = format(t.error, error.message); });
                } })
            ]));
        });
    }

    function save() {
        ui.save.disabled = true;
        ui.status.textContent = t.saving;
        post(config.urls.save, { content: payload() })
            .then(function (json) {
                state.components = json.content.components;
                state.share = shareOf(json.content);
                state.revisions = json.revisions;
                state.dirty = false;
                config.state = 'own';
                ui.status.textContent = t.saved + (json.warnings && json.warnings.length ? ' — ' + json.warnings.join(' ') : '');
                ui.notice.textContent = t.own;
                renderVersions();
                renderList();
                renderForm();
            })
            .catch(function (error) {
                ui.save.disabled = false;
                ui.status.textContent = format(t.error, error.message);
            });
    }

    /* ---------- sharing (CMS pages) ---------- */

    /**
     * How the page looks when its link is shared (Zero_Core's social tags).
     * Empty: the page's meta title and description, its first image.
     */
    function renderShare() {
        if (!ui.share) {
            return;
        }
        ui.share.textContent = '';
        var set = function () { changed(); };
        ui.share.appendChild(el('p', { className: 'zcms-hint', text: t.shareHint }));
        ui.share.appendChild(imageField({ label: t.shareImage }, state.share.image, set));
        ui.share.appendChild(labelled({ label: t.shareTitle }, el('input', { type: 'text', value: state.share.title, maxlength: 120,
            className: 'admin__control-text', oninput: function (e) { state.share.title = e.target.value; set(); } }), uid('zcms-share-title')));
        ui.share.appendChild(labelled({ label: t.shareDescription }, el('textarea', { rows: 3, maxlength: 300,
            className: 'admin__control-textarea', oninput: function (e) { state.share.description = e.target.value; set(); } },
            [state.share.description]), uid('zcms-share-description')));
    }

    /* ---------- page ---------- */

    function build() {
        root.textContent = '';
        var storeId = uid('zcms-store');
        var addId = uid('zcms-add');
        ui.status = el('span', { className: 'zcms-status', role: 'status' });
        ui.save = el('button', { type: 'button', className: 'action-primary', text: t.save, disabled: true, onclick: save });
        ui.list = el('ol', { className: 'zcms-list', 'aria-describedby': 'zcms-drag-hint' });
        ui.form = el('div', { className: 'zcms-form' });
        ui.versions = el('ul', { className: 'zcms-versions' });
        ui.preview = el('iframe', { className: 'zcms-preview', title: t.preview });
        ui.frame = el('div', { className: 'zcms-frame is-desktop' }, [ui.preview]);
        var device = function (mode) {
            return el('button', { type: 'button', className: 'action-tertiary', 'aria-pressed': mode === 'desktop' ? 'true' : 'false',
                text: mode === 'desktop' ? t.desktop : t.mobile, onclick: function (e) {
                    ui.frame.className = 'zcms-frame is-' + mode;
                    Array.prototype.forEach.call(e.target.parentNode.children, function (b) { b.setAttribute('aria-pressed', b === e.target ? 'true' : 'false'); });
                    fitPreview();
                } });
        };
        ui.notice = el('p', { className: 'zcms-notice', text: config.state === 'own' ? t.own : (config.state === 'inherited' ? t.inherited : t.newContent) });

        var addSelect = el('select', { id: addId, className: 'admin__control-select' }, Object.keys(config.components).map(function (code) {
            return el('option', { value: code, text: config.components[code].label });
        }));

        root.appendChild(el('div', { className: 'zcms-toolbar' }, [
            el('a', { href: config.urls.back, className: 'action-tertiary', text: '← ' + t.back }),
            el('label', { 'for': storeId, text: t.storeView }),
            el('select', { id: storeId, className: 'admin__control-select', onchange: function (e) {
                if (!state.dirty || window.confirm(t.leave)) {
                    state.dirty = false;
                    window.location.href = withParams(config.urls.editor, { store: e.target.value });
                } else {
                    e.target.value = String(config.target.store);
                }
            } }, config.stores.map(function (store) {
                return el('option', { value: store.id, selected: store.id === config.target.store, text: store.label });
            })),
            config.target.storefrontUrl ? el('a', { href: config.target.storefrontUrl, target: '_blank', rel: 'noopener', text: t.openPage }) : null,
            el('details', { className: 'zcms-versions-box' }, [el('summary', { text: t.versions }), ui.versions]),
            config.state === 'own' && config.target.store !== 0 ? el('button', { type: 'button', className: 'action-tertiary', text: t.reset, onclick: function () {
                if (window.confirm(t.resetConfirm)) {
                    post(config.urls.reset, {}).then(function () {
                        state.dirty = false;
                        window.location.reload();
                    });
                }
            } }) : null,
            config.ai ? translateControl() : null,
            ui.status,
            ui.save
        ]));
        root.appendChild(ui.notice);
        root.appendChild(el('div', { className: 'zcms-layout' }, [
            el('div', { className: 'zcms-side' }, [
                config.ai ? generatePanel() : null,
                el('p', { id: 'zcms-drag-hint', className: 'zcms-hint', text: t.dragHint }),
                ui.list,
                el('div', { className: 'zcms-add' }, [
                    el('label', { 'for': addId, text: t.addComponent }),
                    addSelect,
                    el('button', { type: 'button', className: 'action-secondary', text: t.add, onclick: function () {
                        var type = addSelect.value;
                        state.components.splice(state.selected + 1, 0, { type: type, data: defaults(config.components[type].fields) });
                        state.selected = Math.min(state.selected + 1, state.components.length - 1);
                        if (state.components.length === 1) {
                            state.selected = 0;
                        }
                        changed();
                        renderList();
                        renderForm();
                    } })
                ]),
                ui.form,
                config.target.type === 'cms_page'
                    ? el('details', { className: 'zcms-share-box' }, [el('summary', { text: t.share }), ui.share = el('div', { className: 'zcms-share' })])
                    : null
            ]),
            el('div', { className: 'zcms-main' }, [
                el('div', { className: 'zcms-row zcms-devices', role: 'group', 'aria-label': t.preview }, [device('desktop'), device('mobile')]),
                ui.frame
            ])
        ]));
        renderList();
        renderForm();
        renderShare();
        renderVersions();
        refreshPreview();
    }

    /** Desktop: the storefront at 1280 px, scaled to the frame's width. */
    function fitPreview() {
        if (!ui.frame) {
            return;
        }
        var desktop = ui.frame.classList.contains('is-desktop');
        var scale = desktop ? Math.min(1, ui.frame.clientWidth / 1280) : 1;
        ui.preview.style.width = desktop ? '1280px' : '390px';
        ui.preview.style.transform = desktop ? 'scale(' + scale + ')' : '';
        ui.preview.style.height = desktop ? (ui.frame.clientHeight / scale) + 'px' : '100%';
    }
    window.addEventListener('resize', fitPreview);

    window.addEventListener('beforeunload', function (event) {
        if (state.dirty) {
            event.preventDefault();
            event.returnValue = '';
        }
    });

    build();
    fitPreview();
})();
