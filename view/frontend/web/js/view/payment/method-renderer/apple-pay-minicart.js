/**
 * Acquired.com Payments Integration for Magento2
 *
 * Copyright (c) 2024 Acquired Limited (https://acquired.com/)
 *
 * This file is open source under the MIT license.
 * Please see LICENSE file for more details.
 */
define([
    'uiComponent',
    'jquery',
    'Magento_Customer/js/customer-data',
    'mage/url',
    'https://applepay.cdn-apple.com/jsapi/1.latest/apple-pay-sdk.js'
], function(Component, $, customerData, url) {
    'use strict';

    return Component.extend({
        defaults: {
            grandTotalAmount: 0,
            storeName: null,
            shippingMethods: null,
            selectedShippingMethod: null,
            quoteTotals: null,
            storeCountry: null,
            postalCode: null,
            countryState: null,
            countryCode: null,
            city: null,
            supportedNetworks: null,
            storeCurrency: null,
            cartId: null,
            shippingMethods: [],
            session: null,
            totals: [],
            target: 'acquired_payment_applepay_minicart',
            buttonType: 'minicart'
        },

        initObservable: function () {
            this._super().observe([
                'applePayToken'
            ]);

            return this;
        },

        initialize: function() {
            this._super();

            if (this.buttonType === 'cart') {
                this.target = 'acquired_payment_applepay_cart';
            }

            /** Check if apple pay is available on the device */
            if (window.ApplePaySession && window.ApplePaySession.canMakePayments()) {
                var applePayElement = document.getElementById(this.target);
                const isSafari = /^((?!chrome|android).)*safari/i.test(navigator.userAgent);

                if (applePayElement) {
                    applePayElement.classList.remove('acquired-payments-hide-button');

                    if (!isSafari) {
                        applePayElement.innerHTML = `
                            <apple-pay-button
                                buttonstyle="black"
                                type="plain"
                                locale="en-UK">
                            </apple-pay-button>
                        `;

                        const btn = applePayElement.querySelector('apple-pay-button');
                        btn.addEventListener('click', this.createPayment.bind(this));
                    } else {
                        applePayElement.addEventListener('click', this.createPayment.bind(this));
                    }
                }
            }
        },

        toCamelCase: function(obj) {
            var newObj = {};
            for (let d in obj) {
                if (obj.hasOwnProperty(d)) {
                    newObj[d.replace(/(\_\w)/g, function(k) {
                        return k[1].toUpperCase();
                    })] = obj[d];
                }
            }
            return newObj;
        },

        resetSession: function () {
            try { this.session = null; } catch (e) {}
            this.shippingMethods = null;
            this.selectedShippingMethod = null;
            this.countryCode = null;
            this.postalCode = null;
            this.countryState = null;
            this.city = null;
        },

        /**
         * Get line items
         */
        getLineItems: function () {
            const totals = Array.isArray(this.quoteTotals) ? [...this.quoteTotals] : [];
            totals.splice(totals.findIndex(total => total.code === 'grand_total'), 1);

            return totals;
        },

        /**
         * Get totals
         */
        getTotal: function () {
            const totals = Array.isArray(this.quoteTotals) ? [...this.quoteTotals] : [];
            const total = totals.find(total => total.code === 'grand_total') || { amount: 0, label: this.storeName };
            total.label = this.storeName;

            return total;
        },

        /**
         * Handle ajax errors
         */
        handleAjaxError: function (message) {
            if (this.session) {
                try {
                    this.resetSession();
                } catch (e) {
                    console.error('Error aborting Apple Pay session:', e);
                }
            }

            var customerMessages = customerData.get('messages')() || {},
                messages = customerMessages.messages || [];

            messages.push({
                text: message,
                type: 'error'
            });

            customerMessages.messages = messages;
            customerData.set('messages', customerMessages);

            $('[data-block="minicart"]').find('[data-role="dropdownDialog"]').dropdownDialog("close");
        },

        /**
         * Create payment
         */
        createPayment: function() {
            if (this.session) {
                try {
                    this.session.abort();
                } catch (e) {
                    console.error("Error when aborting the session: " + e);
                }
            }

            this.resetSession();

            // Reset everything
            this.shippingMethods = null;
            this.selectedShippingMethod = null;
            this.countryCode = null;
            this.postalCode = null;
            this.countryState = null;
            this.city = null;

            var request = {
                countryCode: this.storeCountry,
                currencyCode: this.storeCurrency,
                supportedNetworks: this.supportedNetworks,
                merchantCapabilities: ['supports3DS'],
                total: {
                    label: this.storeName,
                    amount: Number(this.grandTotalAmount).toFixed(2)
                },
                shippingType: 'shipping',
                requiredBillingContactFields: [
                    'postalAddress',
                    'name',
                    'email',
                    'phone'
                ],
                requiredShippingContactFields: [
                    'postalAddress',
                    'name',
                    'email',
                    'phone'
                ]
            }

            try {
                this.session = new ApplePaySession(3, request);
            } catch (error) {
                console.error('Failed to create Apple Pay session:', error);
                this.handleAjaxError('Unable to start Apple Pay. Please ensure you have a valid payment method.');
                return;
            }

            //  onvalidatemerchant
            this.session.onvalidatemerchant = async function (event) {
                try {
                    const response = await fetch(url.build('rest/V1/acquired/apple-pay/session'), {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            validationURL: event.validationURL
                        })
                    });

                    const result = await response.json();

                    if (result && result.merchant_session_identifier) {
                        try {
                            this.session.completeMerchantValidation(this.toCamelCase(result));
                        } catch (validationError) {
                            console.error('Merchant validation completion failed:', validationError);
                            this.handleAjaxError('Validation failed. Please try again.');
                        }

                    } else {
                        this.handleAjaxError('Something went wrong, please try again.');
                    }
                } catch (error) {
                    this.handleAjaxError('Something went wrong, please try again.');
                }
            }.bind(this);

            // onpaymentmethodselected
            this.session.onpaymentmethodselected = function () {
                if (!Array.isArray(this.shippingMethods) || this.shippingMethods.length === 0) {
                    return;
                }

                this.session.completePaymentMethodSelection(this.getTotal(), []);
            }.bind(this);

            // onShippingContactSelected
            this.session.onshippingcontactselected = async function (event) {
                this.countryCode  = event.shippingContact?.countryCode || null;
                this.postalCode   = event.shippingContact?.postalCode || null;
                this.countryState = event.shippingContact?.administrativeArea || null;
                this.city         = event.shippingContact?.locality || null;

                try {
                    const response = await fetch(url.build('acquired/applepay/shipping'), {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            shippingAddress: event.shippingContact,
                            countryCode: this.countryCode,
                            postalCode: this.postalCode,
                            countryState: this.countryState,
                            city: this.city
                        })
                    });

                    const result = await response.json();
                    const shippingMethods = Array.isArray(result.shipping_methods) ? result.shipping_methods : [];
                    this.quoteTotals = result?.totals || this.quoteTotals;

                    if (shippingMethods.length === 0) {
                        this.session.completeShippingContactSelection({
                            status: ApplePaySession.STATUS_FAILURE,
                            newTotal: this.getTotal(),
                            errors: [
                              new ApplePayError(
                                'shippingContactInvalid',
                                'countryCode',
                                'We do not ship to this country. Change the address and try again.'
                              )
                            ]
                        });

                        this.shippingMethods = [];
                        this.selectedShippingMethod = null;

                        return;
                    }

                    this.shippingMethods = shippingMethods;
                    this.selectedShippingMethod = shippingMethods[0];
                    this.quoteTotals = result.totals || this.quoteTotals;

                    this.session.completeShippingContactSelection(
                        ApplePaySession.STATUS_SUCCESS,
                        this.shippingMethods,
                        this.getTotal(),
                        this.getLineItems()
                    );
                } catch (error) {
                    console.error('Shipping contact selection error:', error);

                    this.shippingMethods = [];
                    this.selectedShippingMethod = null;

                    const genericError = new ApplePayError(
                        'shippingContactInvalid',
                        'countryCode',
                        'Unable to calculate shipping. Please try again.'
                    );

                    try {
                        this.session.completeShippingContactSelection({
                            errors: [genericError],
                            newShippingMethods: [],
                            newTotal: this.getTotal(),
                            newLineItems: this.getLineItems()
                        });
                    } catch (e) {
                        console.error('Error completing shipping contact selection:', e);
                        this.handleAjaxError('Something went wrong, please try again.');
                    }
                }
            }.bind(this);

            // onshippingmethodselected
            this.session.onshippingmethodselected = async function (event) {
                this.selectedShippingMethod = event.shippingMethod;

                try {
                    const response = await fetch(url.build('acquired/applepay/shipping'), {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            shippingMethod: this.selectedShippingMethod,
                            countryCode: this.countryCode,
                            postalCode: this.postalCode,
                            countryState: this.countryState,
                            city: this.city
                        })
                    });

                    const result = await response.json();

                    if (!result) {
                        this.session.completeShippingMethodSelection(ApplePaySession.STATUS_FAILURE, this.getTotal(), this.getLineItems());
                        var customerMessages = customerData.get('messages')() || {};
                        var messages = customerMessages.messages || [];
                        messages.push({ text: 'Something went wrong, please try again.', type: 'notice' });
                        customerMessages.messages = messages;
                        customerData.set('messages', customerMessages);

                        $('[data-block="minicart"]').find('[data-role="dropdownDialog"]').dropdownDialog("close");
                        return;
                    }

                    this.quoteTotals = result.totals;
                    this.session.completeShippingMethodSelection(
                        this.session.STATUS_SUCCESS,
                        this.getTotal(),
                        this.getLineItems()
                    );
                } catch (error) {
                    this.session.completeShippingMethodSelection(ApplePaySession.STATUS_FAILURE, this.getTotal(), this.getLineItems());

                    var customerMessages = customerData.get('messages')() || {};
                    var messages = customerMessages.messages || [];
                    messages.push({ text: 'Something went wrong, please try again.', type: 'notice' });
                    customerMessages.messages = messages;
                    customerData.set('messages', customerMessages);

                    $('[data-block="minicart"]').find('[data-role="dropdownDialog"]').dropdownDialog("close");
                }
            }.bind(this);

            // onpaymentauthorized - take payment here
            this.session.onpaymentauthorized = async function (event) {

                if (!this.shippingMethods || !this.shippingMethods.length ||
                    this.selectedShippingMethod?.identifier === 'unavailable' ||
                    this.selectedShippingMethod?.identifier === 'error') {

                    const paymentError = new ApplePayError(
                        'shippingContactInvalid',
                        'countryCode',
                        'Please select a valid shipping address before completing payment.'
                    );

                    this.session.completePayment({
                        status: ApplePaySession.STATUS_FAILURE,
                        errors: [paymentError]
                    });
                    return;
                }

                try {
                    const response = await fetch(url.build('acquired/applepay/transaction'), {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            shippingMethod: this.selectedShippingMethod,
                            billingAddress: event.payment.billingContact,
                            shippingAddress: event.payment.shippingContact,
                            applePayPaymentToken: event.payment.token
                        })
                    });

                    const result = await response.json();

                    if (!this.session) {
                        this.session.completePayment(ApplePaySession.STATUS_FAILURE);
                        this.handleAjaxError('Payment has been cancelled.');
                        return;
                    }

                    if (result.error) {
                        this.session.completePayment(ApplePaySession.STATUS_FAILURE);
                        this.handleAjaxError(result.message || 'Payment failed - please try a different card or address.');
                        return;
                    }

                    this.session.completePayment(ApplePaySession.STATUS_SUCCESS);

                    // Invalidate customer data
                    customerData.invalidate(['cart']);

                    this.resetSession();

                    // Redirect to the result URL
                    setTimeout(() => {
                        location.href = result.url;
                    }, 1000);

                } catch (error) {
                    try { this.session.completePayment(ApplePaySession.STATUS_FAILURE); } catch (e) {}
                    this.handleAjaxError('Something went wrong, please try again.');
                }
            }.bind(this);

            this.session.oncomplete = function () {
                this.resetSession();
            }.bind(this);

            this.session.onabort = function () {
                this.resetSession();
            }.bind(this);

            this.session.oncancel = function () {
                this.resetSession();

                var customerMessages = customerData.get('messages')() || {};
                var messages = customerMessages.messages || [];
                messages.push({ text: 'Payment was cancelled.', type: 'notice' });
                customerMessages.messages = messages;
                customerData.set('messages', customerMessages);

                $('[data-block="minicart"]').find('[data-role="dropdownDialog"]').dropdownDialog("close");
            }.bind(this);

            try {
                this.session.begin();
            } catch (error) {
                console.error('Failed to begin Apple Pay session:', error);
                this.handleAjaxError('Unable to start Apple Pay session. Please try again.');
                this.resetSession();
            }
        }
    });
});
