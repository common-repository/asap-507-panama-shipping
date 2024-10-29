=== ASAP Panama Shipping API Integration ===
Contributors: t0gokj88ziy2
Tags: panama, envios, asap
Requires at least: 5.0.0
Tested up to: 5.6.2
Requires PHP: 7.2
Stable tag: 2.0.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

This plugin allows ASAP corporate customers to automate creating shipping orders into ASAP platform.

== Description ==

This plugin allows ASAP corporate customers to integrate and automate creting shipping orders into ASAP's platform.

In order to work shop manager should:
1. Create a fixed priced shipping method
2. Name that method 'ASAP'.

Then, on each paid order it should update the order status to 'Complete' in order to create the shipping request.

The API integration relies on Google Maps Locations API. User should provide this key along with ASAP's shared secret and user's tokens.

If selected the plugin will also declares Panama's provinces to WooCommerce enabling a dropdown in the 'states' (pronvicias) field on both billing and shipping forms at checkout.

WooCommerce is required for this plugin to work.

http://www.asap507.com


== Installation ==

This software should be treated as a WP plugin.



== Changelog ==
= 2.0.0 = 

ASAP Shipping create Shipping box always present in orders. Now you can create a shipping with us no matter if the user selected or not ASAP as a shipping method.

= 0.0.1 =
* Initial Release.