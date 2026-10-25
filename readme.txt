=== Refmora - Affiliate Management for WooCommerce ===
Contributors: wpmethods
Tags: woocommerce, affiliate, commission, referral, payout
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: woocommerce
Stable tag: 1.1.1
Donate link: https://buymeacoffee.com/ajharrashed
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WooCommerce referral tracking, product commissions and manual affiliate withdrawals. No license required.

== Description ==

Refmora helps you run an affiliate program from your WooCommerce store. Customers can join through their existing accounts, share referral links, follow their earnings, and request a withdrawal. You can manage affiliates, review commissions, and handle payouts from one place.

The free plugin uses your store's base currency. All features included in the free package work without payment, a license key, or activation. They do not expire.

= Free features =

= 🤝 Affiliate accounts and dashboard =

* Add an Affiliate Area to WooCommerce My Account.
* Review applications before approving affiliates, if needed.
* Set Terms & Conditions for your program.
* Let affiliates view their earnings, available balance, commissions, and withdrawal history.
* Show referral clicks and registered customers while masking referred-customer contact details.
* Choose a custom Affiliate Area URL slug.

= 🔗 Referral links and sharing =

* Give affiliates their own referral links and Share & Earn icons.
* Generate affiliate links for pages and products on the same store.
* Offer top-selling product links for affiliates to share.
* View Top Refer Links with referral URLs, clicks per link, affiliate names, and copy buttons.
* Set your referral URL parameter, cookie duration, first- or last-referral attribution, and repeat-purchase policy.
* Use signed referral cookies and self-referral checks.

= 💰 Commissions and earnings =

* Choose percentage-based or fixed-per-unit commissions.
* Set product-specific rates and exclusions. Parent product rules also cover variations.
* Make commissions available when an order is Completed, or set a waiting period of 1–365 days.
* Adjust earnings when an order is refunded.
* Reserve withdrawal amounts to help prevent the same balance from being used twice.

= 💳 Manual withdrawals and payouts =

* Add custom manual payout methods and payment instructions.
* Set a minimum withdrawal amount.
* Review and approve withdrawal requests before payment.
* Record settlement after paying the affiliate outside the plugin.

Manual payout methods are included in the free plugin. Automatic payments through PayPal Payouts or Stripe Connect require the separate Refmora Pro add-on.

= 📊 Overview and activity reports =

* View pending commissions, available earnings, awaiting payouts, completed payouts, referral clicks, and registered customers.
* Choose Daily, Weekly, Monthly, This Year, Last Year, or Lifetime date ranges in the free Overview.
* Compare clicks and completed referred orders using activity bars, counts, and percentages.
* Let approved affiliates view their own lifetime activity above the withdrawal card.
* Search admin records and filter by status.
* Browse paginated lists: generally ten records per admin page and five per affiliate data table.

The Overview also shows recent activity. The earnings and referrals history chart is a Pro add-on feature.

= ⚙️ Settings and WooCommerce support =

* Edit plain-text email notifications.
* Adjust the appearance and add custom CSS scoped to the plugin.
* Back up, restore, or reset settings using JSON files.
* Clear Refmora affiliate enrollments and program history with administrator access and typed confirmation. WooCommerce customers and orders, plugin settings, product rules, and license remain unchanged.
* Use WooCommerce order CRUD, High-Performance Order Storage (HPOS), and attribution hooks for classic checkout and Checkout Blocks.

= Optional Refmora Pro add-on =

Refmora Pro is a separately distributed add-on. It is not included in this WordPress.org download and requires the free plugin and an active Pro license.

Pro adds:

* PayPal Payouts API payments in Sandbox and Live environments.
* Stripe Connect onboarding and API payments in Test and Live environments.
* Payment sending after withdrawal approval, provider status checks, and payment reconciliation.
* Exchange-rate conversion through FOX/WOOCS, CURCY, or manually configured currency pairs.
* CSV exports for affiliates, commissions, withdrawals, referred customers, and daily referral clicks, with optional date ranges.
* An earnings and referrals history chart combining earnings, total clicks, and completed referred orders, using the Overview date filter.

Automatic payments still require administrator approval and an explicit Send payment action. Provider eligibility, supported countries and currencies, available funds, and fees apply.

Free and Pro use the same affiliate accounts, settings, and financial history. General settings, JSON settings backups, and every other free feature remain available without a license. Premium implementation code, including the earnings chart code and styles, lives only in the separate add-on.

After directory publication, free plugin updates use the normal WordPress.org update system. Licensed Pro add-on updates use the separate WP Methods service and do not replace the free plugin.

[Learn more about Refmora Pro](https://wpmethods.com/product/refmora-best-affiliate-plugin-for-woocommerce/).

= Documentation and support =

The [Refmora documentation](https://wpmethods.com/refmora-documentation) covers setup, referral tracking, commissions, manual payouts, caching, privacy, and troubleshooting. Pro-only chapters are marked as add-on features.

If Refmora is useful to your store, you can also [☕ support its development](https://buymeacoffee.com/ajharrashed).

== Installation ==

1. Install and activate WooCommerce.
2. Install and activate Refmora from the Plugins screen.
3. Go to WooCommerce → Refmora Affiliate → Settings. No license key is needed for the free plugin.
4. Set up affiliate enrollment, commission rules, referral tracking, and manual payout methods.
5. Ask customers to join through My Account → Affiliate Area.
6. Place a test referral order using a separate buyer account.

If the Affiliate Area returns a 404 error, resave the plugin settings or WordPress permalinks, then clear your page caches.

== Screenshots ==

1. Refmora overview — affiliate management, referral sharing, commissions, and withdrawals for WooCommerce.
2. Affiliate dashboard — referral links, earnings, balances, click statistics, and withdrawal requests.
3. Admin dashboard — free program overview and referral statistics, alongside the earnings and referrals chart available with the separate Pro add-on.
4. History and reporting — commission records, withdrawal history, and payment statuses.
5. Withdrawal management — review requests, approve or reject withdrawals, and view payment details.
6. Commissions and earnings — follow commissions, order references, adjustments, and earning statuses.
7. Payout methods — custom manual methods in the free plugin. Automatic PayPal Payouts and Stripe Connect payments require the separate Pro add-on.

== Frequently Asked Questions ==

= Do I need a license to use the free plugin? =

No. Affiliate enrollment, referral tracking, commissions, settings, sharing tools, manual payout methods, and settings backup, restore, and reset all work without a license key or activation.

= How do manual payouts work? =

1. An affiliate requests their entire available balance.
2. Refmora reserves that amount while the request is open. Each affiliate can have one open request at a time.
3. A manager reviews and approves the request.
4. The manager sends payment outside the plugin using the selected manual method.
5. The manager selects Mark paid to record the settlement.

The free plugin does not transfer money automatically. Naming a manual method PayPal does not connect it to PayPal's API.

= What does the Pro add-on include? =

Pro adds PayPal Payouts and Stripe Connect API payments, currency conversion, CSV exports, and the earnings and referrals history chart.

These features are implemented in a separate plugin. The free download contains contextual information about the add-on, rather than premium code locked behind a license.

= Are automatic payments sent without a review? =

No. With Pro, live payments still require administrator approval and an explicit Send payment action. Sandbox and Test payments do not settle real commission balances. Do not switch provider credentials while a payment is processing.

= Which currency does Refmora use? =

The free plugin uses the base currency set under WooCommerce → Settings → General. It does not convert exchange rates. Orders in a different currency require the optional Pro add-on.

Previously saved currency and exchange-rate snapshots remain attached to historical financial records.

= When do commissions become available? =

By default, commissions become available when an order is marked Completed. You can instead set a waiting period of 1–365 days.

Commissions use product totals after discounts, excluding tax and shipping. Refunds adjust earnings. If a refunded commission has already been paid, the adjustment can reduce future balances.

= How do Overview date ranges work? =

Date ranges use the WordPress site's time zone:

* Daily: today.
* Weekly: today and the previous six days.
* Monthly: today and the previous 29 days.
* This Year: January 1 through today.
* Last Year: January 1 through December 31 of the previous calendar year.
* Lifetime: all stored history through today.

Commission summaries filter by commission creation date and show current statuses, balances, and refund adjustments. Awaiting payout uses withdrawal request dates. Referral counters use click dates and customer registration dates.

The latest affiliate and withdrawal lists always show the newest records overall, regardless of the selected date range. Top Refer Links uses lifetime clicks.

These figures summarize the current ledger within the selected range. They are not snapshots of the balance as it stood on a past date.

= What do the clicks and Converted bars mean? =

Total clicks are the 100% baseline. Converted counts referred orders currently marked Completed, including orders whose commissions are still on hold.

The converted percentage is completed referred orders divided by tracked clicks. Repeat purchases can make this percentage exceed 100%; the visual bar itself stops at 100% width.

Approved affiliates see their own lifetime activity. They cannot view another affiliate's records.

= How does the Pro history chart calculate its figures? =

* Earnings uses released commissions grouped by creation date, after refund adjustments. Pending and rejected commissions are excluded.
* Converted uses referred orders currently marked Completed, including commissions still on hold, grouped by commission record creation date in the displayed currency.
* Clicks includes all tracked referral clicks.

The Daily range uses one shared daily point because clicks are stored by day. This Year and Last Year use monthly points. Lifetime combines consecutive months when needed to stay within 30 points while retaining older records.

= How are Top Refer Links ranked? =

Links are ranked by tracked clicks for each affiliate and destination across all recorded dates. Only links with at least one click appear. This ranking stays on a lifetime basis when an Overview date filter is selected.

Public homepages, pages, posts, products, taxonomy pages, and archives can be tracked. Each valid incoming affiliate link counts toward its canonical destination. Normal internal browsing without a referral parameter does not add clicks.

Referral parameters, marketing parameters, and URL fragments are removed when identifying the canonical destination, so variants of the same link share a counter. Different public destinations have separate counters.

Tracking excludes arbitrary request parameters, search terms, previews, private or password-protected content, cart, checkout, account pages, admin pages, REST endpoints, feeds, and missing pages. Self-referrals, blocked or ineligible affiliates, and visits disallowed by the consent filter are also excluded.

Repeated visits to the same affiliate and destination are suppressed for 30 minutes using a signed cookie that holds up to 12 recent pairs.

= Where can I browse and copy referral links? =

The Overview shows up to five Top Refer Links without pagination. See More Refer Links opens the dedicated Refer Links tab between Affiliates and Commissions.

The admin Refer Links table has ten rows per page, All/10+/100+ click filters, and URL or affiliate search. The manager Customers list also supports search and ten-row pagination. Affiliate referral links, commissions, withdrawals, and customers use five rows per page. Each table keeps its own page parameter.

Copy Link always copies the affiliate's referral URL. View Link opens a new tab: admins open the canonical destination without a referral parameter, while affiliates open their own referral URL.

The Referral clicks and Registered customers metrics are clickable. In the affiliate dashboard, they jump to the relevant personal sections. In the admin dashboard, they open Refer Links or the referral-attributed Customers list.

= How should I configure caching? =

* Exclude refm_ref, or your custom referral parameter, from full-page caching.
* Keep My Account, Cart, Checkout, and personalized pages for logged-in users uncached.
* In LiteSpeed, use Do Not Cache Query Strings. Do not use Drop Query String for referral parameters.
* Apply matching exclusions at your hosting or CDN level, then clear existing caches.

= What does a settings backup include? =

A JSON backup includes program, sharing, email, and appearance settings. It is a settings backup, not a database backup.

Financial records, license state, payment-provider credentials, and product-specific overrides are excluded. Restore validates JSON files up to 1 MB. Reset requires typing RESET and preserves financial history.

Only administrators can manage settings backups.

The separate Clear data tool requires typing CLEAR DATA. It permanently removes Refmora affiliate enrollments, referral links and counters, referred-customer records, commissions, and withdrawals. It keeps WooCommerce customers and orders, settings, product rules, and license. Clear data is unavailable while a payout is processing. Orders attributed before a clear cannot recreate the removed commission history.

= Can I update the free plugin without a key? =

Yes. After directory publication, the free plugin uses the standard WordPress.org update system. Pro add-on updates are handled separately and never replace the free package with externally downloaded code.

== Privacy and External Services ==

= What the free plugin connects to =

The free plugin does not contact WP Methods for license verification or updates, send telemetry, or load external assets. Standard WordPress.org updates are handled by WordPress.

Notifications use WordPress wp_mail and your site's configured email service. Refmora does not bundle a separate SMTP service.

Social sharing links open Facebook, WhatsApp, Telegram, X, or LinkedIn only when a visitor clicks them. The chosen platform then processes that visit under its own terms. No social SDKs are included.

The separately installed Pro add-on documents its own WP Methods, Stripe, and PayPal communications and credentials.

= Referral cookies and stored data =

* refm_referral: a signed cookie used to attribute orders to an affiliate.
* refm_link_clicks: a signed cookie that suppresses repeated referral visits for 30 minutes, across up to 12 recent affiliate and URL-hash pairs.

Referral clicks are stored as daily aggregate counts. The shared link directory stores each canonical public destination URL and its SHA-256 hash once. Daily counters contain the affiliate ID, link ID, local click date, and count. Lifetime summaries contain the affiliate ID, link ID, and total count.

Visitor IP addresses, browser fingerprints, arbitrary request parameters, and search terms are not stored in referral analytics. New customer accounts registered with a valid referral cookie can be associated with an affiliate.

You can connect your cookie consent manager through the refm_tracking_allowed filter. Configure consent behavior to match your store's privacy policy.

= Access, export, and retention =

Affiliates see referred customers' first names and masked email and phone details. Managers can access affiliate and financial information to process withdrawals.

WordPress personal data export and erasure hooks are supported. Affiliate daily counters and lifetime summaries are included in these tools. Shared public URL metadata contains no affiliate identifiers.

Financial records are retained for store reconciliation. Deactivating the plugin does not delete records. Review Settings → Privacy and set an appropriate retention policy for your store.

== Developer Notes ==

= Referral analytics storage =

Referral tracking uses a unified URL-based system:

* refm_links stores canonical public URLs, referenced by numeric link_id values.
* refm_link_daily stores daily counts for date-filtered metrics, charts, and daily CSV exports.
* refm_link_totals stores one indexed lifetime total per affiliate and link.

Each valid click updates the daily and lifetime counters atomically. Top Refer Links reads the indexed lifetime summary rather than summing daily history. Ranking indexes support global and affiliate-specific click totals.

Product IDs, product titles, and product-specific counters are not part of referral analytics.

= Referral storage upgrade =

The one-time upgrade to this storage system starts referral counters fresh. Older affiliate-wide, product-specific, and repeated-URL counters are not imported. The obsolete refm_clicks, refm_link_clicks, and refm_url_clicks database tables are removed.

Financial records and customer attribution are preserved. Once the upgrade is complete, current daily and lifetime counters are retained through ordinary requests, deactivation, and reactivation.

= Database and financial records =

Tables use the site's database prefix:

* refm_commissions
* refm_payouts
* refm_customers
* refm_links
* refm_link_daily
* refm_link_totals

Database transactions and named locks serialize balance changes. Financial data is deliberately preserved on deactivation. Back up the site before making structural changes or removing records.

= Extension hooks =

Available hooks include:

* refm_effective_settings
* refm_exchange_rate
* refm_clean_exchange_rates
* refm_exchange_settings
* refm_export_data_request
* refm_export_data_panel
* refm_admin_overview_chart — receives the validated range and currency.
* refm_affiliate_activity_chart — receives the signed-in affiliate ID. Add-ons must enforce record ownership and access permissions.

Premium code belongs in the separate add-on plugin, not in a hidden directory inside the free package.

== Changelog ==
= 1.1.1 =
* Added affiliate link with click count section.
* Added Payout methods icons support.
* Added Clear Data Featues
* Fixed some issues

= 1.0.0 =
* Initial release.
