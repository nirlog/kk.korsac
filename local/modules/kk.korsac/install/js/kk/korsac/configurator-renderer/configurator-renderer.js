(function (root, factory) {
    'use strict';

    var coreExports = null;
    if (typeof module === 'object' && module.exports) {
        coreExports = require('../configurator-core/configurator-core.js');
    } else if (root && root.KorsacConfigurator) {
        coreExports = root.KorsacConfigurator;
    }
    var exports = factory(root, coreExports);
    if (typeof module === 'object' && module.exports) {
        module.exports = exports;
    }
    if (root) {
        root.KorsacConfiguratorRenderer = exports;
        if (root.BX) {
            root.BX.KK = root.BX.KK || {};
            root.BX.KK.Korsac = root.BX.KK.Korsac || {};
            root.BX.KK.Korsac.ConfiguratorRenderer = exports.ConfiguratorRenderer;
        }
    }
}(typeof globalThis !== 'undefined' ? globalThis : this, function (root, coreExports) {
    'use strict';

    var DEFAULT_LABELS = {
        CPU: 'Процессор', GPU: 'Видеокарта', MB: 'Материнская плата', RAM: 'Оперативная память',
        SSD: 'SSD-накопитель', HDD: 'Жёсткий диск', PSU: 'Блок питания', COOLER: 'Охлаждение',
        CASE: 'Корпус', OS: 'Операционная система', SOFTWARE: 'Программное обеспечение',
        SERVICE: 'Сервисы и гарантия', nullChoice: 'Не устанавливать', noExtra: 'Без доплаты',
        loading: 'Загружаем конфигуратор…', calculating: 'Пересчитываем…',
        loadError: 'Не удалось загрузить конфигуратор.',
        calculateError: 'Не удалось пересчитать конфигурацию. Попробуйте ещё раз.',
        priceError: 'Не удалось получить актуальную цену. Попробуйте ещё раз.',
        addError: 'Не удалось добавить конфигурацию в корзину. Попробуйте ещё раз.',
        invalidGroup: 'Для этой группы нет доступных вариантов.', retry: 'Повторить',
        add: 'Добавить в корзину', adding: 'Добавляем…', added: 'Добавлено в корзину'
    };
    var MODES = ['select', 'text_buttons', 'image_buttons'];
    var instanceCounter = 0;

    function assign(target) {
        for (var i = 1; i < arguments.length; i += 1) {
            var source = arguments[i] || {};
            Object.keys(source).forEach(function (key) { target[key] = source[key]; });
        }
        return target;
    }

    function presentationMode(group) {
        var mode = group && group.presentation && group.presentation.mode;
        if (group && group.mode === 'multiple' && mode === 'checkboxes') {
            return mode;
        }
        return MODES.indexOf(mode) === -1 ? 'select' : mode;
    }

    function orderedMultiple(choices, values) {
        var selected = Array.isArray(values) ? values : [];
        return (Array.isArray(choices) ? choices : []).filter(function (choice) {
            return choice && selected.indexOf(choice.xmlId) !== -1;
        }).map(function (choice) { return choice.xmlId; });
    }

    function formatMinor(value, currency, locale, signed, labels) {
        if (!Number.isInteger(value)) { return ''; }
        var amount = value;
        if (signed && amount === 0) {
            return labels.noExtra;
        }
        var major = Math.abs(amount) / 100;
        var formatted;
        try {
            formatted = new Intl.NumberFormat(locale || 'ru-RU', {
                style: 'currency', currency: currency || 'RUB', minimumFractionDigits: 0,
                maximumFractionDigits: Number.isInteger(major) ? 0 : 2
            }).format(major);
        } catch (ignore) {
            formatted = major.toFixed(Number.isInteger(major) ? 0 : 2) + ' ' + (currency || 'RUB');
        }
        if (!signed) { return formatted; }
        return (amount > 0 ? '+' : '\u2212') + formatted;
    }

    function groupEntries(groups) {
        if (Array.isArray(groups)) {
            return groups.map(function (group) { return [group.code || group.group || '', group]; });
        }
        return Object.keys(groups || {}).map(function (code) { return [code, groups[code]]; });
    }

    function validPrice(price, locale) {
        if (!price || !Number.isInteger(price.finalPriceMinor) || price.finalPriceMinor < 0 ||
            typeof price.currency !== 'string' || !/^[A-Z]{3}$/.test(price.currency)) {
            return false;
        }
        if (typeof Intl === 'object' && typeof Intl.NumberFormat === 'function') {
            try {
                new Intl.NumberFormat(locale || 'ru-RU', {style: 'currency', currency: price.currency}).format(0);
            } catch (ignore) {
                return false;
            }
        }
        return true;
    }

    function ConfiguratorRenderer(options) {
        options = options || {};
        this.root = options.root || null;
        this.document = options.document || (this.root && this.root.ownerDocument) || (root && root.document);
        this.labels = assign({}, DEFAULT_LABELS, options.labels);
        this.locale = options.locale || 'ru-RU';
        this.debounceMs = Number.isFinite(options.debounceMs) ? Math.max(0, options.debounceMs) : 150;
        this.core = options.core || this.createCore(options);
        this.destroyed = false;
        this.mounted = false;
        this.loaded = false;
        this.priceConfirmed = false;
        this.adding = false;
        this.timer = null;
        this.unsubscribe = null;
        this.listeners = [];
        this.groups = [];
        this.currency = 'RUB';
        this.priceCurrencyValid = false;
        this.instanceId = ++instanceCounter;
        this.invalidConfiguration = false;
        this.mountPromise = null;
    }

    ConfiguratorRenderer.prototype.createCore = function (options) {
        if (!coreExports || !coreExports.ConfiguratorCore || !coreExports.BitrixTransport) {
            throw new TypeError('kk.korsac.configurator-core is required');
        }
        return new coreExports.ConfiguratorCore({
            transport: new coreExports.BitrixTransport(options.bx || (root && root.BX)),
            iblockId: options.iblockId,
            productId: options.productId
        });
    };

    ConfiguratorRenderer.prototype.element = function (tag, className, text) {
        var node = this.document.createElement(tag);
        if (className) { node.className = className; }
        if (text !== undefined && text !== null) { node.textContent = String(text); }
        return node;
    };

    ConfiguratorRenderer.prototype.listen = function (node, type, callback) {
        node.addEventListener(type, callback);
        this.listeners.push([node, type, callback]);
    };

    ConfiguratorRenderer.prototype.removeListenersWithin = function (container) {
        this.listeners = this.listeners.filter(function (item) {
            var node = item[0], current = node;
            while (current) {
                if (current === container) {
                    node.removeEventListener(item[1], item[2]);
                    return false;
                }
                current = current.parentNode;
            }
            return true;
        });
    };

    ConfiguratorRenderer.prototype.mount = function () {
        var self = this;
        if (!this.root || !this.document || typeof this.root.appendChild !== 'function') {
            return Promise.reject(new TypeError('root must be a DOM element'));
        }
        if (this.mounted && !this.destroyed) { return this.mountPromise; }
        this.destroyed = false;
        this.mounted = true;
        this.buildShell();
        this.unsubscribe = this.core.subscribe(function (event) { self.onCoreEvent(event); });
        this.mountPromise = this.load();
        return this.mountPromise;
    };

    ConfiguratorRenderer.prototype.buildShell = function () {
        var self = this;
        while (this.root.firstChild) { this.root.removeChild(this.root.firstChild); }
        this.root.classList.add('kk-korsac-configurator');
        this.root.setAttribute('aria-busy', 'true');
        this.groupsNode = this.element('div', 'kk-korsac-configurator__groups');
        this.priceNode = this.element('div', 'kk-korsac-configurator__price');
        this.priceNode.setAttribute('aria-live', 'polite');
        this.statusNode = this.element('div', 'kk-korsac-configurator__status', this.labels.loading);
        this.statusNode.setAttribute('role', 'status');
        this.errorNode = this.element('div', 'kk-korsac-configurator__error');
        this.errorNode.setAttribute('role', 'alert');
        this.retryButton = this.element('button', 'kk-korsac-configurator__retry', this.labels.retry);
        this.retryButton.type = 'button'; this.retryButton.hidden = true;
        this.addButton = this.element('button', 'kk-korsac-configurator__add', this.labels.add);
        this.addButton.type = 'button'; this.addButton.disabled = true;
        this.listen(this.retryButton, 'click', function () { self.load().catch(function () { /* error event owns UI */ }); });
        this.listen(this.addButton, 'click', function () { self.addToCart(); });
        this.root.appendChild(this.groupsNode); this.root.appendChild(this.priceNode);
        this.root.appendChild(this.statusNode); this.root.appendChild(this.errorNode);
        this.root.appendChild(this.retryButton); this.root.appendChild(this.addButton);
    };

    ConfiguratorRenderer.prototype.load = function () {
        var self = this;
        if (this.destroyed) { return Promise.reject(new Error('Renderer is destroyed')); }
        this.loaded = false; this.priceConfirmed = false;
        this.setBusy(true); this.setStatus(this.labels.loading); this.clearError();
        this.retryButton.hidden = true; this.updateAdd();
        return this.core.load().then(function (result) {
            self.lastLoad = result;
            return result;
        }).catch(function (error) {
            // The accepted core error event owns visible error state.
            throw error;
        });
    };

    ConfiguratorRenderer.prototype.onCoreEvent = function (event) {
        if (this.destroyed) { return; }
        if (event.type === 'loaded') {
            var loadedPrice = event.detail && event.detail.price;
            var loadedPriceValid = validPrice(loadedPrice, this.locale);
            this.priceCurrencyValid = loadedPriceValid;
            if (loadedPriceValid) { this.currency = loadedPrice.currency; }
            this.groups = groupEntries(event.detail && event.detail.groups);
            this.loaded = true; this.priceConfirmed = loadedPriceValid;
            this.renderGroups(event.state.selection);
            if (loadedPriceValid) { this.renderPrice(loadedPrice); }
            this.priceConfirmed = loadedPriceValid && !this.invalidConfiguration;
            this.setBusy(false); this.setStatus(''); this.retryButton.hidden = true;
            if (loadedPriceValid) { this.clearError(); } else { this.showInvalidPrice(); }
            this.updateAdd();
            this.dispatch('loaded', event.detail);
        } else if (event.type === 'selection') {
            this.syncSelection(event.detail.selection);
            this.priceConfirmed = false; this.clearError(); this.setBusy(true);
            this.setStatus(this.labels.calculating); this.updateAdd(); this.scheduleCalculate();
            this.dispatch('selection', event.detail);
        } else if (event.type === 'calculated') {
            var calculatedPrice = event.detail && event.detail.price;
            this.syncSelection(event.state.selection); this.setBusy(false); this.setStatus('');
            if (validPrice(calculatedPrice, this.locale)) {
                var mustRefreshChoicePrices = !this.priceCurrencyValid || this.currency !== calculatedPrice.currency;
                this.currency = calculatedPrice.currency;
                this.priceCurrencyValid = true;
                if (mustRefreshChoicePrices) { this.renderGroups(event.state.selection); }
                this.priceConfirmed = !this.invalidConfiguration; this.renderPrice(calculatedPrice); this.clearError();
                this.dispatch('calculated', event.detail);
            } else {
                this.priceConfirmed = false; this.showInvalidPrice();
            }
            this.updateAdd();
        } else if (event.type === 'added') {
            this.adding = false; this.setBusy(false); this.setStatus(this.labels.added); this.updateAdd();
            this.dispatch('added', event.detail);
        } else if (event.type === 'error') {
            this.handleAcceptedError(event.detail);
        }
    };

    ConfiguratorRenderer.prototype.handleAcceptedError = function (error) {
        var code = error && typeof error.code === 'string' ? error.code : 'request_failed';
        if (!this.loaded) {
            this.showError(this.labels.loadError, code); this.retryButton.hidden = false;
        } else if (this.adding) {
            this.adding = false; this.showError(this.labels.addError, code);
        } else {
            this.priceConfirmed = false; this.showError(this.labels.calculateError, code);
        }
        this.setBusy(false); this.setStatus(''); this.updateAdd();
        this.dispatch('error', {code: code});
    };

    ConfiguratorRenderer.prototype.renderGroups = function (selection) {
        var self = this;
        this.invalidConfiguration = false;
        this.removeListenersWithin(this.groupsNode);
        while (this.groupsNode.firstChild) { this.groupsNode.removeChild(this.groupsNode.firstChild); }
        this.groups.forEach(function (entry) {
            var code = entry[0], group = entry[1] || {}, choices = Array.isArray(group.choices) ? group.choices : [];
            var fieldset = self.element('fieldset', 'kk-korsac-configurator__group');
            fieldset.dataset.korsacGroup = code;
            fieldset.appendChild(self.element('legend', 'kk-korsac-configurator__title', self.labels[code] || code));
            var container = self.element('div', 'kk-korsac-configurator__choices');
            var mode = presentationMode(group);
            container.classList.add('kk-korsac-configurator__choices--' + mode);
            if (mode === 'select') { self.renderSelect(container, code, group, choices, selection[code]); }
            else if (mode === 'checkboxes') { self.renderCheckboxes(container, code, choices, selection[code]); }
            else { self.renderButtons(container, code, group, choices, selection[code], mode === 'image_buttons'); }
            fieldset.appendChild(container);
            if (choices.length === 0 && !(group.mode === 'single' && group.allowNull === true)) {
                var warning = self.element('div', 'kk-korsac-configurator__group-error', self.labels.invalidGroup);
                warning.setAttribute('role', 'alert'); fieldset.appendChild(warning);
                if (group.mode === 'single') { self.priceConfirmed = false; self.invalidConfiguration = true; }
            }
            self.groupsNode.appendChild(fieldset);
        });
    };

    ConfiguratorRenderer.prototype.choiceText = function (choice) {
        var delta = this.choiceDelta(choice);
        return String(choice.name || choice.xmlId || '') + (delta ? ' — ' + delta : '');
    };

    ConfiguratorRenderer.prototype.choiceDelta = function (choice) {
        return this.priceCurrencyValid ? formatMinor(choice.deltaMinor, this.currency, this.locale, true, this.labels) : '';
    };

    ConfiguratorRenderer.prototype.renderSelect = function (container, code, group, choices, selected) {
        var self = this, select = this.element('select', 'kk-korsac-configurator__select');
        select.dataset.korsacGroup = code;
        if (group.mode === 'multiple') { select.multiple = true; }
        if (group.mode === 'single' && group.allowNull === true) {
            var empty = this.element('option', '', this.labels.nullChoice); empty.value = ''; select.appendChild(empty);
        }
        choices.forEach(function (choice) {
            var option = self.element('option', '', self.choiceText(choice)); option.value = choice.xmlId;
            option.dataset.korsacXmlId = choice.xmlId;
            option.selected = group.mode === 'multiple' ? (Array.isArray(selected) && selected.indexOf(choice.xmlId) !== -1) : selected === choice.xmlId;
            select.appendChild(option);
        });
        if (group.mode === 'single' && selected === null) { select.value = ''; }
        this.listen(select, 'change', function () {
            if (group.mode === 'multiple') {
                var values = Array.prototype.filter.call(select.options, function (option) { return option.selected; }).map(function (option) { return option.value; });
                self.core.setGroup(code, orderedMultiple(choices, values));
            } else { self.core.setGroup(code, select.value === '' ? null : select.value); }
        });
        container.appendChild(select);
    };

    ConfiguratorRenderer.prototype.renderButtons = function (container, code, group, choices, selected, withImages) {
        var self = this;
        var items = choices.slice();
        if (group.mode === 'single' && group.allowNull === true) { items.unshift({xmlId: null, name: this.labels.nullChoice, deltaMinor: 0}); }
        items.forEach(function (choice) {
            var button = self.element('button', 'kk-korsac-configurator__choice'); button.type = 'button';
            button.dataset.korsacGroup = code;
            if (choice.xmlId !== null) { button.dataset.korsacXmlId = choice.xmlId; }
            var active = group.mode === 'multiple' ? (Array.isArray(selected) && selected.indexOf(choice.xmlId) !== -1) : selected === choice.xmlId;
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
            if (active) { button.classList.add('is-selected'); }
            if (withImages && choice.image && choice.image.src) {
                var image = self.element('img', 'kk-korsac-configurator__choice-image'); image.src = choice.image.src; image.alt = '';
                image.loading = 'lazy';
                if (choice.image.width) { image.width = choice.image.width; }
                if (choice.image.height) { image.height = choice.image.height; }
                self.listen(image, 'error', function () { image.hidden = true; }); button.appendChild(image);
            }
            button.appendChild(self.element('span', 'kk-korsac-configurator__choice-name', choice.name || choice.xmlId || self.labels.nullChoice));
            button.appendChild(self.element('span', 'kk-korsac-configurator__choice-delta', self.choiceDelta(choice)));
            if (choice.description) { button.appendChild(self.element('span', 'kk-korsac-configurator__choice-description', choice.description)); }
            self.listen(button, 'click', function () {
                if (group.mode === 'multiple') {
                    var current = self.core.getState().selection[code] || [], next = current.slice(), position = next.indexOf(choice.xmlId);
                    if (position === -1) { next.push(choice.xmlId); } else { next.splice(position, 1); }
                    self.core.setGroup(code, orderedMultiple(choices, next));
                } else { self.core.setGroup(code, choice.xmlId); }
            });
            container.appendChild(button);
        });
    };

    ConfiguratorRenderer.prototype.renderCheckboxes = function (container, code, choices, selected) {
        var self = this;
        choices.forEach(function (choice, index) {
            var wrapper = self.element('div', 'kk-korsac-configurator__checkbox');
            var input = self.element('input'); input.type = 'checkbox'; input.id = 'kk-korsac-' + self.instanceId + '-' + code.toLowerCase() + '-' + index;
            input.dataset.korsacGroup = code; input.dataset.korsacXmlId = choice.xmlId;
            input.checked = Array.isArray(selected) && selected.indexOf(choice.xmlId) !== -1;
            var label = self.element('label', 'kk-korsac-configurator__checkbox-label'); label.setAttribute('for', input.id);
            label.appendChild(self.element('span', 'kk-korsac-configurator__choice-name', choice.name || choice.xmlId));
            label.appendChild(self.element('span', 'kk-korsac-configurator__choice-delta', self.choiceDelta(choice)));
            if (choice.description) { label.appendChild(self.element('span', 'kk-korsac-configurator__choice-description', choice.description)); }
            self.listen(input, 'change', function () {
                var values = Array.prototype.filter.call(container.querySelectorAll('input[type="checkbox"]'), function (item) { return item.checked; }).map(function (item) { return item.dataset.korsacXmlId; });
                self.core.setGroup(code, orderedMultiple(choices, values));
            });
            wrapper.appendChild(input); wrapper.appendChild(label); container.appendChild(wrapper);
        });
    };

    ConfiguratorRenderer.prototype.syncSelection = function (selection) {
        var self = this;
        this.groups.forEach(function (entry) {
            var code = entry[0], group = entry[1], value = selection[code];
            var controls = self.groupsNode.querySelectorAll('[data-korsac-group="' + code + '"]');
            Array.prototype.forEach.call(controls, function (control) {
                var xmlId = control.dataset.korsacXmlId;
                if (control.tagName === 'SELECT') {
                    Array.prototype.forEach.call(control.options, function (option) { option.selected = group.mode === 'multiple' ? (Array.isArray(value) && value.indexOf(option.value) !== -1) : option.value === (value === null ? '' : value); });
                } else if (control.type === 'checkbox') { control.checked = Array.isArray(value) && value.indexOf(xmlId) !== -1; }
                else if (control.tagName === 'BUTTON' && control.classList.contains('kk-korsac-configurator__choice')) {
                    var active = group.mode === 'multiple' ? (Array.isArray(value) && value.indexOf(xmlId) !== -1) : (xmlId === undefined ? value === null : value === xmlId);
                    control.setAttribute('aria-pressed', active ? 'true' : 'false'); control.classList.toggle('is-selected', active);
                }
            });
        });
    };

    ConfiguratorRenderer.prototype.scheduleCalculate = function () {
        var self = this;
        if (this.timer !== null) { clearTimeout(this.timer); }
        this.timer = setTimeout(function () {
            self.timer = null;
            if (!self.destroyed) { self.core.calculate().catch(function () { /* accepted core events own errors */ }); }
        }, this.debounceMs);
    };

    ConfiguratorRenderer.prototype.addToCart = function () {
        var self = this;
        if (this.destroyed || !this.loaded || !this.priceConfirmed || this.adding) { return Promise.resolve(null); }
        this.adding = true; this.setBusy(true); this.setStatus(this.labels.adding); this.clearError(); this.updateAdd();
        return this.core.addToCart().catch(function () { return null; }).then(function (result) {
            if (self.destroyed) { return null; }
            return result;
        });
    };

    ConfiguratorRenderer.prototype.renderPrice = function (price) {
        price = price || {}; this.currency = price.currency || this.currency;
        while (this.priceNode.firstChild) { this.priceNode.removeChild(this.priceNode.firstChild); }
        this.priceNode.appendChild(this.element('strong', 'kk-korsac-configurator__price-final', formatMinor(price.finalPriceMinor, this.currency, this.locale, false, this.labels)));
        if (price.configurationDeltaMinor) {
            this.priceNode.appendChild(this.element('span', 'kk-korsac-configurator__price-delta', formatMinor(price.configurationDeltaMinor, this.currency, this.locale, true, this.labels)));
        }
    };

    ConfiguratorRenderer.prototype.setBusy = function (busy) { this.root.setAttribute('aria-busy', busy ? 'true' : 'false'); };
    ConfiguratorRenderer.prototype.setStatus = function (text) { this.statusNode.textContent = text || ''; };
    ConfiguratorRenderer.prototype.clearError = function () { this.errorNode.textContent = ''; this.errorNode.removeAttribute('data-error-code'); this.root.classList.remove('is-error'); };
    ConfiguratorRenderer.prototype.showError = function (text, code) { this.errorNode.textContent = text; this.errorNode.dataset.errorCode = code; this.root.classList.add('is-error'); };
    ConfiguratorRenderer.prototype.showInvalidPrice = function () { this.showError(this.labels.priceError, 'invalid_price_payload'); this.dispatch('error', {code: 'invalid_price_payload'}); };
    ConfiguratorRenderer.prototype.updateAdd = function () { this.addButton.disabled = !this.loaded || !this.priceConfirmed || this.adding; this.addButton.textContent = this.adding ? this.labels.adding : this.labels.add; };
    ConfiguratorRenderer.prototype.dispatch = function (name, detail) {
        var EventConstructor = (this.document.defaultView && this.document.defaultView.CustomEvent) || (root && root.CustomEvent);
        if (EventConstructor) { this.root.dispatchEvent(new EventConstructor('korsac:configurator:' + name, {detail: detail})); }
    };

    ConfiguratorRenderer.prototype.destroy = function () {
        if (this.destroyed) { return; }
        this.destroyed = true; this.mounted = false;
        this.mountPromise = null;
        if (this.timer !== null) { clearTimeout(this.timer); this.timer = null; }
        if (this.unsubscribe) { this.unsubscribe(); this.unsubscribe = null; }
        this.listeners.forEach(function (item) { item[0].removeEventListener(item[1], item[2]); });
        this.listeners = [];
    };

    return {
        ConfiguratorRenderer: ConfiguratorRenderer,
        formatMinor: formatMinor,
        orderedMultiple: orderedMultiple,
        presentationMode: presentationMode,
        validPrice: validPrice,
        DEFAULT_LABELS: assign({}, DEFAULT_LABELS)
    };
}));
