/**
 * Acquired.com Payments Integration for Magento2
 *
 * Copyright (c) 2024 Acquired Limited (https://acquired.com/)
 *
 * This file is open source under the MIT license.
 * Please see LICENSE file for more details.
 */
define(
    [
        'underscore',
        'jquery',
        'Magento_Payment/js/view/payment/cc-form',
        'Magento_Checkout/js/model/quote',
        'Magento_Customer/js/customer-data',
        'mage/translate',
        'Magento_Checkout/js/model/full-screen-loader',
        'mage/storage',
        'Magento_Checkout/js/model/url-builder',
        'Magento_Ui/js/model/messageList',
        'mage/url',
        'acquiredLoader',
        'Magento_Checkout/js/model/payment/additional-validators',
        'Magento_CheckoutAgreements/js/model/agreement-validator'
    ],
    function (
        _,
        $,
        Component,
        quote,
        customerData,
        $t,
        fullScreenLoader,
        storage,
        urlBuilder,
        messageList,
        mageUrl,
        acquiredLoader,
        additionalValidators,
        agreementValidator
    ) {
        'use strict';

        let acquired = null;

        return Component.extend({
            defaults: {
                acquiredComponent: null,
                template: 'Acquired_Payments/payment/card',
                active: false,
                checkoutType: '',
                code: 'acquired_card',
                placeholder: '#acquired-payments-card-component',
                /**
                 * Additional payment data
                 *
                 * {Object}
                 */
                orderId: '',
                transactionId: '',
                timestamp: '',
                hash: '',
                imports: {
                    onActiveChange: 'active'
                }
            },

            initializeSdk: async function (onSuccess) {
                var self = this;

                // SDK is already initialized
                if(acquired) {
                    return Promise.resolve(acquired);
                }

                return new Promise(function(resolve, reject) {
                    acquiredLoader.waitForAcquired.then(function () {
                        // generate nonce with math random and Date.now()
                        self.updateNonce();
                        acquired = new Acquired(window.checkoutConfig.payment[self.getCode()].public_key);
                        resolve(acquired);
                    }).catch(function (error) {
                        messageList.addErrorMessage({
                            message: $t('There was an issue initializing the Acquired payment method.')
                        });
                        reject(error);
                    });
                });
            },

            /**
             * Initialize acquired lib
             *
             * @returns {*}
             */
            initialize: function () {
                this._super();
                this.initializeSdk();

                return this;
            },

            updateNonce: function() {
                this.nonce = this.generateNonce();
            },

            getNonce: function() {
                if(!this.nonce) {
                    this.nonce = this.generateNonce();
                }
                return this.nonce;
            },

            generateNonce: function () {
                const generateHash = function (input) {
                    let hash = 0;
                    for (let i = 0; i < input.length; i++) {
                        hash = Math.imul(31, hash) + input.charCodeAt(i) | 0;
                    }
                    return ('00000000' + (hash >>> 0).toString(16)).slice(-8);
                };

                return generateHash(Math.random().toString(36).substring(2) + Date.now());
            },

            /**
             * Set list of observable attributes
             *
             * @returns {exports.initObservable}
             */
            initObservable: function () {
                this._super()
                    .observe([
                        'active',
                        'orderId',
                        'transactionId',
                        'timestamp',
                        'hash',
                    ]);

                return this;
            },

            /**
             * Get payment name
             *
             * @returns {String}
             */
            getCode: function () {
                return this.code;
            },

            /**
             * Check if payment is active
             *
             * @returns {Boolean}
             */
            isActive: function () {
                let active = this.getCode() === this.isChecked();
                this.active(active);

                return active;
            },

            /**
             * Triggers when payment method change
             * @param {Boolean} isActive
             */
            onActiveChange: function (isActive) {
                if (!isActive) {
                    return;
                }

                this.initAcquired();
            },

            /**
             * Create session and initialize component
             */
            initAcquired: async function (reset = false) {
                let _this = this;
                let placeholder = $(this.placeholder);

                // prevent multiple calls during initialization
                if(_this.initializing) {
                    return;
                }

                _this.initializing = true;


                // make sure SDK is initialized
                await this.initializeSdk();

                if (reset) {
                    this.updateNonce();
                    this.acquiredComponent = null;
                    placeholder.find('iframe').remove();
                }

                if (placeholder.find('iframe').length) {
                    _this.initializing = false;
                    return;
                }

                try {
                    const session = await this.getSession(reset);
                    this._initAcquiredComponent({
                        session: session,
                        environment: _this.getMode()
                    });
                } catch (error) {
                    messageList.addErrorMessage({
                        message: $t('There was an issue initializing the Acquired payment method.')
                    });
                }

                _this.initializing = false;
            },

            /**
             * Initialize acquired component
             *
             * @param options
             * @private
             */
            _initAcquiredComponent: function (options) {
                this.acquiredComponent = acquired.components(options);
                this.acquiredComponent.create('payment', { style: this.getStyle() }).mount(this.placeholder);

                const self = this;

                window.addEventListener('message', async function(message) {
                    if(message && message.data && message.data.event && message.data.event == "change-form") {
                        const response = message.data.response;

                        // we switched to apple / google pay, switch to new flow
                        self.checkoutType = response.toComponent;
                    }

                    if(message && message.data && message.data.event && message.data.event == "submit-response") {
                        const response = message.data.response;

                        if(response.status == 'error') {
                            messageList.addErrorMessage({
                                message: $t("An error occurred during the payment process. Please try again.")
                            });

                            await self.initAcquired(true);
                        }
                    }
                }, false);
            },

            /**
             * Get component mode
             *
             * @returns {*}
             */
            getMode: function () {
                return window.checkoutConfig.payment[this.getCode()].mode;
            },

            /**
             * Get custom style if set
             * @returns {*}
             */
            getStyle: function () {
                if (window.checkoutConfig.payment[this.getCode()].style) {
                    try {
                        return JSON.parse(window.checkoutConfig.payment[this.getCode()].style)
                    } catch (error) {
                        return null;
                    }
                }

                return null;
            },

            /**
             *
             * Get 3ds challenge window size
             * @returns {String}
             */
            getTdsWindowSize: function () {
                return window.checkoutConfig.payment[this.getCode()].tds_window_size;
            },

            /**
             * Get session id
             *
             * @returns {String}
             */
            getSession: async function (createNewSession = false) {
                const sessionIdCookie = this.sessionId;

                if (!sessionIdCookie || createNewSession) {
                    const acquiredGetSessionUrl = urlBuilder.createUrl('/acquired/session/' + this.getNonce(), {});
                    const result = await storage.post(acquiredGetSessionUrl);
                    this.sessionId = result.session_id;

                    return result.session_id;
                } else {
                    return sessionIdCookie;
                }
            },

            /**
             * Push update to acquired
             *
             * @param sessionId
             */
            updateSession: async function () {
                const sessionId = await this.getSession();
                const acquiredUpdateSessionUrl = urlBuilder.createUrl(
                    '/acquired/session/update/' + this.getNonce() + "/" + sessionId,
                    {}
                );

                await storage.post(acquiredUpdateSessionUrl, {});
            },

            /**
             * Get data
             * @returns {Object}
             */
            getData: function () {
                return {
                    'method': this.getCode(),
                    'additional_data': {
                        'order_first': true
                    }
                };
            },

            isWalletPayment: function() {
                return this.checkoutType == "payment";
            },

            isCardPayment: function() {
                return this.checkoutType == "cardForm" || this.checkoutType == "oneClick";
            },

            /**
             * Action to place order
             *
             * The Magento order is placed before any payment is taken, so a payment can never exist without its
             * order. If the payment then fails, the order is cancelled and the shopper's cart restored.
             */
            placeOrder: async function () {
                let self = this;
                let orderPlaced = false;

                if (!this.validate() || !additionalValidators.validate() || !agreementValidator.validate()) {
                    return false;
                }

                fullScreenLoader.startLoader();
                this.isPlaceOrderActionAllowed(false);

                // if we are in card form mode, we need to update the session before we can submit the form
                if(self.isCardPayment()) {
                    try {
                        await this.updateSession();
                    } catch (error) {
                        messageList.addErrorMessage({
                            message: error.message || $t('Payment session expired, please re-enter the payment data.')
                        });
                        await this.initAcquired(true);
                        this.resetPlaceOrder();
                        return false;
                    }
                }

                try {
                    await this.placeMagentoOrder();
                    orderPlaced = true;

                    const prepared = await this.postOrderPayment('prepare');
                    await this.formSubmitListener(prepared.confirm_params);

                    const completed = await this.postOrderPayment('complete', {
                        transaction_id: this.transactionId()
                    });

                    this.afterPlaceOrder();
                    window.location.replace(completed.redirect);

                    return true;
                } catch (error) {
                    if (orderPlaced) {
                        try {
                            await this.postOrderPayment('cancel');
                        } catch (cancelError) {
                            error = cancelError;
                        }
                    }

                    // a failed Magento order placement has already shown its own message
                    if (error) {
                        messageList.addErrorMessage({
                            message: error.message || $t('An unexpected error occurred during the order placement. Please try again later.')
                        });
                    }

                    this.resetPlaceOrder();
                    await this.initAcquired(true);
                }

                return false;
            },

            /**
             * Place the Magento order, which waits in payment review until the payment completes
             *
             * @returns {Promise}
             */
            placeMagentoOrder: function () {
                const self = this;

                return new Promise(function (resolve, reject) {
                    self.getPlaceOrderDeferredObject()
                        .done(resolve)
                        .fail(function () {
                            reject(null);
                        });
                });
            },

            /**
             * Run a payment step for the order just placed
             *
             * @param {String} action prepare, complete or cancel
             * @param {Object} data
             * @returns {Promise<Object>}
             */
            postOrderPayment: function (action, data) {
                return new Promise(function (resolve, reject) {
                    $.ajax({
                        url: mageUrl.build('acquired/orderpayment/' + action),
                        type: 'POST',
                        contentType: 'application/json',
                        dataType: 'json',
                        data: JSON.stringify(data || {})
                    }).done(function (response) {
                        if (!response || response.error) {
                            reject(new Error(response && response.message));
                            return;
                        }
                        resolve(response);
                    }).fail(function () {
                        reject(new Error($t('There was an issue submitting your payment.')));
                    });
                });
            },

            /**
             * Submit request to acquired
             *
             * @param {Object} confirmParams
             * @returns {Object}
             */
            formSubmitListener: async function (confirmParams) {
                try {
                    let response = await acquired.confirmPayment(
                        {
                            components: this.acquiredComponent,
                            confirmParams: confirmParams
                        });

                    // handle validation which came back from the request
                    if (response.data.status === 'error') {
                        throw new Error(response.data.message);
                    }

                    if (response.isError()) {
                        if (response.data.status === 'declined' ||
                            response.data.status === 'blocked' ||
                            response.data.status === 'tds_failed') {

                            let message = $t('Your credit card was ' + response.data.status);

                            if (response.data.status === 'tds_failed') {
                                message = $t('Payment failed as your card is not enrolled in 3-D Secure. Please check with your bank.');
                            }

                            throw new Error(message);
                        }
                    }

                    if (response.isTdsPending()) {
                        await this.handleTdsChallenge(response.data.redirect_url);
                    }

                    if (response.isSuccess()) {
                        this.orderId(response.data.order_id);
                        this.transactionId(response.data.transaction_id);
                        this.timestamp(response.data.timestamp);
                        this.hash(response.data.hash);
                    }
                } catch (error) {
                    throw new Error(error.message || $t('There was an issue submitting your payment.'));
                }
            },

            /**
             * Handles the 3-D Secure challenge by opening an iframe and waiting for a response
             *
             * @param {string} redirectUrl - The URL to be loaded in the iframe for the 3-D Secure process
             * @returns {Promise} A promise that resolves when the 3-D Secure process is completed
             */
            handleTdsChallenge: function (redirectUrl) {
                return new Promise((resolve, reject) => {
                    this.openTdsIframe(redirectUrl);
                    const messageHandler = (event) => {

                        // Check if the message is from the same origin as 3DS response will always originate from the same domain
                        if(event.origin !== window.location.origin) {
                            return;
                        }

                        if (event.data && event.data.type === 'TdsResponse') {
                            if (event.data.status === 'success') {
                                let responseData = event.data.data;
                                this.orderId(responseData.order_id);
                                this.transactionId(responseData.transaction_id);
                                this.timestamp(responseData.timestamp);
                                this.hash(responseData.hash);

                                this.closeTdsIframe();
                                resolve();
                            } else {
                                let message = event.data.message || $t('An error occurred during 3-D Secure verification.');
                                this.closeTdsIframe();
                                reject(new Error(message));
                            }
                            window.removeEventListener('message', messageHandler, false);
                        }
                    };
                    window.addEventListener('message', messageHandler, false);
                });
            },

            /**
             * Opens an iframe for the 3-D Secure challenge
             *
             * @param {string} redirectUrl - The URL to be loaded in the iframe
             */
            openTdsIframe: function (redirectUrl) {
                let iframe = document.createElement('iframe');
                iframe.src = redirectUrl;
                iframe.id = 'TdsIframe';
                iframe.sandbox = 'allow-scripts allow-same-origin allow-forms';

                document.body.appendChild(iframe);
                iframe.style.height = this.getTdsWindowSize() + "px";

                this.iframe = iframe;
            },

            /**
             * Closes the 3-D Secure iframe and cleans up references to it
             */
            closeTdsIframe: function () {
                if (this.iframe) {
                    this.iframe.parentNode.removeChild(this.iframe);
                    this.iframe = null;
                }
            },

            /**
             * Clear session cookie
             */
            afterPlaceOrder: function () {
                fullScreenLoader.stopLoader();
            },

            /**
             * Reset place order
             */
            resetPlaceOrder: function () {
                fullScreenLoader.stopLoader();
                this.isPlaceOrderActionAllowed(true);
                this.updateNonce();
            }
        });
    });
