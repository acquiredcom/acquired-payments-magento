# Changelog

All notable release changes to this project will be documented in this file.

## [1.1.2] - Release Version
- Add ApplePay Express support for purchases on the cart page
- Fix refund logic to check transaction age before attempting refund (orders under 24 hours old will use void instead)
- Fix RequestBuilderValidator to properly handle Apple Pay and MOTO transactions in 3DS validation
- Improve Express checkout flow by separating shipping calculation and transaction endpoints
- Fix guest checkout email handling in Express checkout
- Fix amount formatting in Express checkout to handle comma/space separators
- Add proper quote deactivation after successful order placement
- Remove Express payments from admin order creation (set can_use_internal to 0)
- Remove locations configuration field from Express payments settings
- Add CSP nonce support for inline scripts and styles in admin payment forms
- Add module dependencies for Magento_Paypal and PayPal_Braintree
- Move Apple Pay API endpoints from webapi.xml to dedicated controllers (Shipping.php, Transaction.php)
- Add CartButton block for Apple Pay cart page integration
- Improve error handling and logging in Express checkout flow
- Code quality improvements and refactoring

## [1.1.0] - Release Version
- Add ApplePay Express support for purchases on the minicart

## [1.0.3] - Release Version
- Improve security by eliminating an attack vector
- Improve code quality
- Fix a bug when placing a multishipping order

## [1.0.2] - Release Version
- Introduce payment intent table to store payment intents from customers
- Remove saving nonce and session id values to checkout session
- Add saving nonce and session id as payment intents to separate database table

## [1.0.1] - Release Version
- Adjust system configuration labels and comments for better merchant experience

## [1.0.0] - Release Version
- Fix race condition breaking payment process
- Fix order id not being set properly for saved cards
- Remove beta from version

## [1.0.0-beta.9] - Beta version
- Update license information in all files to uniform format

## [1.0.0-beta.8] - Beta version
- Fix issue where additional validators for placing an order would not get called in some cases
- Fix issue where checkout agreements validator was not called
- Add support for checkout agreements validation for multishipping
- Fix issue where a wrong parameter type for webhook handlings would cause excess error logs in system logs
- Removed excess acquired logging

## [1.0.0-beta.7] - Beta version
- Fix issue where create_card flag would save the customer card even with the box unticked

## [1.0.0-beta.6] - Beta version
- Add unit tests for better code maintainance

## [1.0.0-beta.5] - Beta version
- Fix authorization token cache issue not being split by environment
- Fix bug where retry payment would fail if amount was not a float but a string
- Fix issue where failing payment on Google Pay
- Fix issue with save card not working correctly during checkout experience
- Add in split configuration for Mid and Company-Id by environment
- Add in check if transaction exists before trying to refund order
- Add in webhook handling for PayByBank orders
- Add in iframe sandbox options for improved security
- Adjust handling of Wallet payments to conform with ApplePay restrictions
- Add in configuration for optional sending through customer phone information during session create/update as it can cause 3ds rejection
- Code quality improvements
- Remove all but card payment options for Admin orders

## [1.0.0-beta.4] - Beta version
- Excluded payment-links API call from adding Mid information as it was causing issues
- Fix address data not being sent through on initial session creation and only on confirm params call
- Fix address data not being sent on retry flow for hosted checkout
- Code quality updates and refactoring to improve code stability and maintenance

## [1.0.0-beta.3] - Beta version
- Fix error with 3ds configuration validation when 3ds is disabled in configuration
- Company-Id and Mid are now sent when communicating with Acquired API if the values are configured
- Added payment method configuration to toggle Google/Apple pay availability when paying by cards

## [1.0.0-beta.2] - Beta version

## Added
- Hosted checkout is now configurable to allow bank payments only or allow other solutions also
- CSP policy change has been added to store configuration allowing to enforce report_only mode for Magento CSP
- Minor bug fixes
- Code quality improvements
- Updated license

## [1.0.0-beta.1] - Beta version

## Added
- Hosted checkout using Acquired hosted payments page is now available
- Multishipping checkout support for payments by cards
- Multishipping checkout support for payments by payment wallets
- Multishipping checkout support for payments made by hosted checkout solution
- Retry mechanism for hosted checkout to retry payment or restore checkout session
- Small code improvements
- Updated author and license information

## [0.9.4] - Beta version

## Added
- Working Google/Apple pay integration using components on checkout

## Modified
- Updated Acquired JS sdk to 1.1
- Updated default Javascript hash integrity value

## [0.9.3] - Beta version

## Added
- Added new file Block/AcquiredJs.php with 161 lines of code.
- Added Acquired to CSP whitelist for external resources
- Added system configuration for Javascript Hash Integrity check
- Added dynamic loading of acquired.js from CDN host using optional javascript integrity check

## Modified
- Modified file view/adminhtml/web/js/card.js to use CDN based Acquired.js sdk
- Modified file view/frontend/web/js/view/payment/method-renderer/card.js to use CDN based Acquired.js sdk

## Removed
- Removed self hosted acquired.js SDK file

## [0.9.2] - Beta Version

### Changed
- Updated the version to `0.9.2 beta`.
- Updated the acquired.js file

## [0.9.1] - Beta Version

### Changed
- Updated the version in `Model/Config.php` from `0.0.1` to `0.9.1 beta`.

### Added
- Added a new `moto` field to the data array in `Service/GetAdminPaymentSessionData.php`.
- Added a new line in `view/base/templates/info/info.phtml` to display the last 4 digits of the credit card number.

## [0.9.0] - Initial Beta Version

### Added
- Acquired payment gateway integration.
- Support for card payments.
- Support for 3D secure payments.
- Support for admin orders.

### Changed

### Deprecated

### Removed

### Fixed

### Security