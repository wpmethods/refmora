=== Refmora - Affiliate Management for WooCommerce ===
Contributors: wpmethods
Tags: woocommerce, affiliate, commission, referral, payout
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WooCommerce referral tracking, product commissions and manual affiliate withdrawals. No license required.

== Description ==

Refmora runs a WooCommerce affiliate program using customer accounts and your store's base currency. All functionality included in this Free package is available without payment or activation.

= Free features =

* My Account Affiliate Area with optional application review and Terms & Conditions.
* Percentage or fixed-per-unit commissions, product overrides and exclusions; parent rules cover variations.
* Adjustable signed referral cookies, first/last attribution and repeat-purchase policy.
* Custom referral parameter and Affiliate Area URL slug.
* Immediate commission availability on Completed orders or a 1-365-day waiting period.
* Refund adjustments, self-referral checks and serialized withdrawal reservations.
* Manual payout methods with minimum withdrawal and manager approval/settlement.
* Share & Earn icons, same-store link generator and top-selling product links.
* Referral click/registration counters and customer contacts masked for affiliates.
* Ten-item admin and affiliate history lists with pagination; admin search and status filters. Overview summaries show recent activity.
* Editable plain-text notifications, appearance settings and scoped custom CSS.
* JSON settings backup, restore and reset, plus offline Docs/index.html documentation.
* WooCommerce order CRUD, HPOS and classic/Checkout Blocks attribution hooks.

= Optional Refmora Pro add-on (not included) =

The features below are NOT included in this WordPress.org download. They are implemented by the separately distributed Refmora Pro add-on, which requires the Free plugin and an active Pro license. The Free plugin is useful on its own and all Free features work without activation.

* PayPal Payouts API payments with Sandbox and Live environments.
* Stripe Connect account onboarding and API payments with Test and Live environments.
* Explicit payment sending after withdrawal approval, provider status checks and payment reconciliation.
* Exchange-rate conversion using FOX/WOOCS, CURCY or manually configured currency pairs.
* CSV exports for affiliates, commissions, withdrawals, referred customers and daily referral clicks, with optional date ranges.

Free and Pro share the same affiliate accounts, settings and financial history. General settings, JSON settings backups and all other Free features remain available without a license. Free core updates use WordPress.org after directory publication; licensed Pro add-on updates use the separate WP Methods service.

Premium implementation code is not included in this WordPress.org package. Free features do not expire or require a key.

== Installation ==

1. Install and activate WooCommerce.
2. Install Refmora through Plugins and activate it.
3. Open WooCommerce -> Refmora Affiliate-> Settings; no license is needed.
4. Configure enrollment, commissions, referral tracking and manual payout methods.
5. Customers join at My Account -> Affiliate Area.
6. Test a referral order using a separate buyer account and review Docs/index.html.

If the account URL returns 404, resave settings or WordPress permalinks and purge page caches.

== Frequently Asked Questions ==

= Does Free need a license? =
No. Enrollment, referrals, commissions, settings, sharing, manual payout methods and settings backup/restore/reset are available without activation.

= How do payouts work? =
Affiliates request their entire available balance. One open request is allowed; its amount is reserved. Approve, send the manual payment outside the plugin, then Mark paid. Free does not send automatic transfers. A method named PayPal is still manual unless the separate Pro provider is installed.

= Which currency is used? =
The base currency saved under WooCommerce -> Settings -> General. Free does not perform exchange-rate conversion; a different-currency order requires the optional Pro add-on. Historical saved currency/rate snapshots and financial records are retained.

= When do commissions become available? =
When the order is Completed, immediately by default or after the saved waiting period. Tax and shipping are excluded; product totals after discounts are used. Refunds adjust earnings; already paid refunds can reduce future balances.

= What is premium? =
The separately distributed Pro add-on implements automatic PayPal/Stripe payouts, currency conversion, and CSV exports. Existing accounts and records are shared. The Free package contains contextual information about these extensions, not license-locked premium implementations.

= Are updates available without a key? =
Yes. Directory-hosted Free updates use the normal WordPress.org update mechanism after publication. Pro add-on updates are separate and never replace the Free package with externally downloaded code.

= How should caching be configured? =
Exclude refm_ref and any custom referral parameter from full-page cache. Keep My Account, Cart, Checkout and logged-in personalized pages uncached. In LiteSpeed, use Do Not Cache Query Strings, not Drop Query String. Apply corresponding CDN/hosting exclusions and purge caches.

= What does settings backup contain? =
Program, sharing, emails and appearance. It is not a database backup. Financial records, license state, provider credentials and product overrides are excluded. Restore validates a JSON file up to 1 MB; reset requires typing RESET and preserves financial history. Only administrators manage settings backups.

== Privacy and external services ==

The Free core does not contact WP Methods for license verification or updates, does not send telemetry and does not load external assets. Standard WordPress.org updates are handled by WordPress. Social links open a platform sharing page only when a visitor clicks; Facebook, WhatsApp, Telegram, X and LinkedIn then process that visit under their own terms. No social SDKs are included.

Referral clicks are stored as daily aggregate counts without IP/browser logs. Signed refm_referral and refm_click cookies attribute orders and suppress consecutive duplicate clicks. New customer accounts registered with a valid referral cookie can be associated with an affiliate. Users can integrate their cookie consent manager through the refm_tracking_allowed filter; consent behavior must match the store's policy.

Affiliates see first names and masked referred-customer email/phone details. Managers access affiliate and financial information to process withdrawals. WordPress data export/erasure hooks are supported; financial records are retained for store reconciliation. Plugin deactivation does not delete records. Review Settings -> Privacy and establish a retention policy.

Notifications use WordPress wp_mail and the site's configured mail service. No separate SMTP service is bundled. The separately installed Pro add-on documents its own WP Methods, Stripe and PayPal communications and credentials.

== Documentation ==

The merchant guide is bundled in the Docs folder of this plugin download. Open Docs/index.html for the searchable merchant and affiliate guide, mobile navigation and print view. Settings, tracking, commissions, manual payouts, caching, privacy and troubleshooting are covered. Pro-only chapters are marked as add-on features.

== Developer notes ==

Tables use the site's prefix: refm_commissions, refm_payouts, refm_clicks and refm_customers. Database transactions and named locks serialize balance mutations. Financial data is deliberately preserved on deactivation; back up before changing the site or removing records.

Extension hooks include refm_effective_settings, refm_exchange_rate, refm_clean_exchange_rates, refm_exchange_settings, refm_export_data_request and refm_export_data_panel. Premium code lives in a separate plugin, not a hidden directory inside Free.

== Changelog ==

= 1.0.0 =
* Initial release.