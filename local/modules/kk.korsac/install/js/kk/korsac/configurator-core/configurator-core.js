(function (root, factory) {
    'use strict';

    var exports = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = exports;
    }
    if (root) {
        root.KorsacConfigurator = exports;
        if (root.BX) {
            root.BX.KK = root.BX.KK || {};
            root.BX.KK.Korsac = root.BX.KK.Korsac || {};
            root.BX.KK.Korsac.ConfiguratorCore = exports.ConfiguratorCore;
            root.BX.KK.Korsac.BitrixTransport = exports.BitrixTransport;
        }
    }
}(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    'use strict';

    var ACTIONS = {
        get: 'kk:korsac.Configurator.get',
        calculate: 'kk:korsac.Configurator.calculate',
        add: 'kk:korsac.Cart.add'
    };
    var SINGLE_GROUPS = ['CPU', 'GPU', 'MB', 'RAM', 'SSD', 'HDD', 'PSU', 'COOLER', 'CASE', 'OS'];
    var MULTIPLE_GROUPS = ['SOFTWARE', 'SERVICE'];

    function copy(value) {
        if (typeof structuredClone === 'function') {
            return structuredClone(value);
        }
        return JSON.parse(JSON.stringify(value));
    }

    function positiveInteger(value, name) {
        if (!Number.isInteger(value) || value <= 0) {
            throw new TypeError(name + ' must be a positive integer');
        }
        return value;
    }

    function selection(value) {
        if (value === null || typeof value !== 'object' || Array.isArray(value)) {
            throw new TypeError('selection must be an object');
        }
        var normalized = {};
        Object.keys(value).forEach(function (group) {
            if (SINGLE_GROUPS.indexOf(group) !== -1) {
                if (value[group] !== null && (typeof value[group] !== 'string' || value[group] === '')) {
                    throw new TypeError(group + ' must be a non-empty string or null');
                }
                normalized[group] = value[group];
                return;
            }
            if (MULTIPLE_GROUPS.indexOf(group) !== -1) {
                if (!Array.isArray(value[group]) || value[group].some(function (id) { return typeof id !== 'string' || id === ''; })) {
                    throw new TypeError(group + ' must be an array of non-empty strings');
                }
                normalized[group] = value[group].slice();
                return;
            }
            throw new TypeError('Unknown configuration group: ' + group);
        });
        return normalized;
    }

    function ApiError(errors) {
        var list = Array.isArray(errors) ? errors : [];
        var first = list[0] || {};
        this.name = 'KorsacConfiguratorApiError';
        this.code = typeof first.code === 'string' ? first.code : 'request_failed';
        this.message = typeof first.message === 'string' ? first.message : 'KORSAC request failed';
        this.customData = first.customData && typeof first.customData === 'object' ? copy(first.customData) : {};
        this.errors = copy(list);
        if (Error.captureStackTrace) {
            Error.captureStackTrace(this, ApiError);
        }
    }
    ApiError.prototype = Object.create(Error.prototype);
    ApiError.prototype.constructor = ApiError;

    function BitrixTransport(bx) {
        this.bx = bx || (typeof BX !== 'undefined' ? BX : null);
        if (!this.bx || !this.bx.ajax || typeof this.bx.ajax.runAction !== 'function') {
            throw new TypeError('BX.ajax.runAction is required');
        }
    }

    BitrixTransport.prototype.request = function (action, payload, method) {
        var options = method === 'GET'
            ? {method: 'GET', getParameters: payload}
            : {method: 'POST', data: payload};
        return this.bx.ajax.runAction(action, options).then(function (response) {
            if (!response || !Object.prototype.hasOwnProperty.call(response, 'data')) {
                throw new ApiError(response && response.errors);
            }
            return response.data;
        }, function (response) {
            throw new ApiError(response && response.errors);
        });
    };

    function ConfiguratorCore(options) {
        options = options || {};
        if (!options.transport || typeof options.transport.request !== 'function') {
            throw new TypeError('transport.request is required');
        }
        this.transport = options.transport;
        this.iblockId = positiveInteger(options.iblockId, 'iblockId');
        this.productId = positiveInteger(options.productId, 'productId');
        this.currentSelection = selection(options.selection || {});
        this.listeners = [];
        this.calculateSequence = 0;
        this.selectionRevision = 0;
    }

    ConfiguratorCore.prototype.getState = function () {
        return {
            iblockId: this.iblockId,
            productId: this.productId,
            selection: copy(this.currentSelection)
        };
    };

    ConfiguratorCore.prototype.setSelection = function (value) {
        this.currentSelection = selection(value);
        this.selectionRevision += 1;
        this.emit('selection', {selection: copy(this.currentSelection)});
        return this.getState();
    };

    ConfiguratorCore.prototype.setGroup = function (group, value) {
        if (typeof group !== 'string' || group === '') {
            throw new TypeError('group must be a non-empty string');
        }
        var next = copy(this.currentSelection);
        next[group] = value;
        return this.setSelection(next);
    };

    ConfiguratorCore.prototype.subscribe = function (listener) {
        if (typeof listener !== 'function') {
            throw new TypeError('listener must be a function');
        }
        this.listeners.push(listener);
        var self = this;
        return function () {
            self.listeners = self.listeners.filter(function (candidate) { return candidate !== listener; });
        };
    }

    ConfiguratorCore.prototype.emit = function (type, detail) {
        var event = {type: type, detail: detail, state: this.getState()};
        this.listeners.slice().forEach(function (listener) { listener(event); });
    };

    ConfiguratorCore.prototype.payload = function () {
        return this.getState();
    };

    ConfiguratorCore.prototype.load = function () {
        var self = this;
        var revision = this.selectionRevision;
        return this.transport.request(ACTIONS.get, {
            iblockId: this.iblockId,
            productId: this.productId
        }, 'GET').then(function (result) {
            if (result && result.selection && revision === self.selectionRevision) {
                self.currentSelection = selection(result.selection);
            }
            self.emit('loaded', result);
            return result;
        }, function (error) {
            self.emit('error', error);
            throw error;
        });
    };

    ConfiguratorCore.prototype.calculate = function () {
        var self = this;
        var sequence = ++this.calculateSequence;
        var revision = this.selectionRevision;
        return this.transport.request(ACTIONS.calculate, this.payload(), 'POST').then(function (result) {
            if (sequence === self.calculateSequence && revision === self.selectionRevision) {
                if (result && result.selection) {
                    self.currentSelection = selection(result.selection);
                }
                self.emit('calculated', result);
            }
            return result;
        }, function (error) {
            if (sequence === self.calculateSequence && revision === self.selectionRevision) {
                self.emit('error', error);
            }
            throw error;
        });
    };

    ConfiguratorCore.prototype.addToCart = function () {
        var self = this;
        return this.transport.request(ACTIONS.add, this.payload(), 'POST').then(function (result) {
            self.emit('added', result);
            return result;
        }, function (error) {
            self.emit('error', error);
            throw error;
        });
    };

    return {
        ACTIONS: ACTIONS,
        SINGLE_GROUPS: SINGLE_GROUPS.slice(),
        MULTIPLE_GROUPS: MULTIPLE_GROUPS.slice(),
        ApiError: ApiError,
        BitrixTransport: BitrixTransport,
        ConfiguratorCore: ConfiguratorCore
    };
}));
