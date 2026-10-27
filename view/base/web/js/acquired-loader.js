/**
 * Acquired.com Payments Integration for Magento2
 *
 * Copyright (c) 2024 Acquired Limited (https://acquired.com/)
 *
 * This file is open source under the MIT license.
 * Please see LICENSE file for more details.
 */

define([], function () {
    var loadAcquiredJs = new Promise(function (resolve, reject) {
        var script = document.createElement('script');
        script.src = 'https://cdn.acquired.com/sdk/v1.2/acquired.js';
        script.crossOrigin = "anonymous";
        if (window.ACQUIRED_JS_INTEGRITY_HASH) {
            script.integrity = window.ACQUIRED_JS_INTEGRITY_HASH;
        }
        script.onload = function () {
            resolve();
        };
        script.onerror = function () {
            reject();
        };
        document.head.appendChild(script);
    });

    return {
        waitForAcquired: loadAcquiredJs
    };
});