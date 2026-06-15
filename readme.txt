=== Woo Order Archive Manager ===
Contributors: anandurk
Tags: woocommerce, orders, archive, database, performance
Requires at least: 5.0
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Archive old WooCommerce orders into separate database tables to keep your store fast without losing any order data.

== Description ==

Over time, WooCommerce orders pile up in your database. Every order adds rows to `wp_posts`, `wp_postmeta`, `wp_comments`, and several WooCommerce tables. On stores with tens of thousands of orders, this slows down admin pages, checkout queries, and report generation.

Woo Order Archive Manager moves completed, cancelled, or refunded orders out of the live tables and into dedicated archive tables. Your store gets faster, your order data stays safe, and you can restore any order back to WooCommerce whenever you need it.

**How it works**

Orders are copied to archive tables row by row inside database transactions. If anything goes wrong during the copy, the transaction rolls back — the original order is untouched. Only after a successful copy and verification does the plugin remove the order from the live tables.

**Key features**

* Moves orders to dedicated archive tables — not just a status change
* Restores archived orders back to WooCommerce with the original order ID preserved
* Permanently deletes archived orders when you no longer need them
* Dry run mode — preview exactly what will happen before making any changes
* Verification layer — row counts are checked before any live data is deleted
* Refund support — refund posts and their meta are archived alongside the parent order
* Subscription protection — orders linked to active WooCommerce Subscriptions are skipped automatically
* Activity log — every archive, restore, and delete is recorded with timestamps
* Database visualiser — see how much space your order tables are actually using
* Archive health check — verifies table integrity and flags any orphaned rows
* Batch processing — handles large stores without timing out

**What gets archived**

When an order is archived, the following data moves with it:

* The order post (`wp_posts`)
* All order meta (`wp_postmeta`)
* Order line items (`woocommerce_order_items`)
* Order item meta (`woocommerce_order_itemmeta`)
* Order notes (`wp_comments`)
* Order note meta (`wp_commentmeta`)
* Refund posts and their meta

**Compatibility**

This version supports legacy post-based order storage only. Stores using WooCommerce High-Performance Order Storage (HPOS) will see an admin notice and the plugin will remain inactive. HPOS support is planned for a future release.

WooCommerce Subscriptions is detected automatically. Orders linked to active subscriptions are skipped during archiving to avoid breaking renewal billing.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate the plugin through the Plugins screen in WordPress
3. Go to **Order Archive** in the WordPress admin sidebar
4. Review the Overview tab to see your current database usage
5. Go to the Archive Orders tab to start your first archive

**Requirements**

* WordPress 5.0 or higher
* WooCommerce (latest recommended)
* PHP 8.2 or higher
* MySQL with InnoDB table engine (required for transaction support)

== Frequently Asked Questions ==

= Will archiving break my WooCommerce reports? =

The plugin removes analytics cache rows from `wp_wc_order_stats` and related lookup tables when archiving. WooCommerce can regenerate these from order data. Reports will reflect the archive once regenerated.

= Can I restore an order after archiving it? =

Yes. Go to the Archived Orders tab, select Restore, and run the batch. The order comes back with its original ID, meta, items, notes, and refunds intact.

= What happens if archiving fails halfway through? =

Each order is wrapped in a database transaction. If any step fails, the transaction rolls back for that order and it stays in the live tables unchanged. The activity log records the error with a message.

= Does this work with WooCommerce Subscriptions? =

The plugin detects active subscriptions and skips any order that is a renewal order or has an active subscription attached to it. This prevents breaking the billing chain.

= Is HPOS supported? =

Not in version 1.0. If HPOS is enabled on your store, the plugin displays an admin notice and stays inactive. HPOS support is on the roadmap for a future release.

= Does it support multisite? =

Not tested on multisite in version 1.0. Each site in a network has its own table prefix, so the plugin should create separate archive tables per site, but this has not been verified.

= What is dry run mode? =

Dry run processes all the copy and verification steps inside a database transaction, then rolls everything back instead of committing. No data is changed. The activity log records the result as a dry run so you can see exactly what would have happened.

= Can I change the batch size? =

Yes. Add this to your theme's `functions.php` or a custom plugin:

`add_filter( 'hw_woam_batch_size', function() { return 100; } );`

The default is 50 orders per batch. Increase it on fast servers, decrease it on shared hosting if you see timeouts.

== Screenshots ==

1. Overview tab showing database size and archive health
2. Archive Orders step flow — select filters and review impact before running
3. Archived Orders tab with inventory breakdown and restore/delete controls
4. Activity log showing recent archive and restore operations

== Changelog ==

= 1.0.0 =
* Initial release
* Archive, restore, and permanently delete WooCommerce orders
* Dry run mode with full transaction rollback
* Archive verification before any live data is deleted
* Refund post archiving and restoration
* WooCommerce Subscriptions guard
* Analytics lookup table cleanup on archive
* Database visualiser showing table sizes
* Archive health and integrity checker
* Batch processing with configurable batch size
* Full activity log

== Upgrade Notice ==

= 1.0.0 =
Initial release. No upgrade steps required.