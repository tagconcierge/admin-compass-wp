=== Admin Compass ===
Contributors: tagconcierge
Tags: admin, search, navigation, productivity, global search
Requires at least: 5.1.0
Tested up to: 6.6.2
Requires PHP: 7.0
Stable tag: 1.2.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html


Admin Compass provides fast, global search functionality for your WordPress admin area.

== Description ==

Admin Compass improves how you navigate your WordPress WP-Admin Dashboard. With a simple interface inspired by MacOS tools like Raycast or Alfred, it offers fast search capabilities, allowing you to quickly find and access posts, pages, products, settings, and more.

**This is open beta which means some features still need testing, see FAQ for more details.**

Key Features:

* Fast search across all your WordPress content thanks to dedicate search index
* Elegant, minimalistic interface doesn't clutter your Admin area
* Keyboard navigation for quick access
* Searches posts, pages, products, and WordPress settings
* Upcoming PRO version will cover WooCommerce products and orders

Whether you're managing a small blog or a large e-commerce site, Admin Compass helps you navigate your WordPress admin area with ease and efficiency.

== Installation ==

1. Upload the `admin-compass` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Use the keyboard shortcut (default: Ctrl + Shift + F) or click the "magnifying glass" icon in the admin bar to open the search palette

== Frequently Asked Questions ==

= Is this production ready? =

This is currently an open beta, ready for testing. It doesn't perform any destructive operations since it generates a dedicated search index. On the other hand we are securing the access to the search index.

As of today we recommend testing it in staging environment and pay special attention to the search index that has following naming convention:

`/wp-content/admin_compass_[random characters].db`

We recommend securing it manually using .htaccess

= How do I open the search palette? =

You can open the search palette by using the keyboard shortcut Ctrl + Shift + F, or by clicking the "magnifying glass" icon in the WordPress admin top-bar.

= Can I change the keyboard shortcut? =

Currently, the keyboard shortcut is not customizable, but we're planning to add this feature in a future update.

= Is this plugin compatible with WooCommerce? =

It will be compatible with upcoming PRO version. Once available it will be able to search for products and orders if WooCommerce is installed and activated.

= How secure is the search index? =

The search index is stored in a SQLite database with a randomized filename, located in a protected directory within your WordPress installation. The database file permissions are set to be restrictive,

= Can I use this plugin on a multisite installation? =

While the plugin should work on individual sites within a multisite installation, it has not been extensively tested in a multisite environment. Use with caution and test thoroughly.

== Changelog ==

= 1.2.0 =
* Clicking outside Search Palette closes it
* Handle updates and search index recreation
* Improved admin settings naming
* Fix JS error

= 1.1.0 =
* Protect search index from public access

= 1.0.0 =
* Initial release

== Screenshots ==

1. Admin Compass search palette in action

