=== Accept Bitcoini SV Payments via Anypay Gateway for WooCommerce ===
Contributors: brandonbryant
Donate link: https://anypayinc.com
Tags: bitcoin, payments, BSV, cryptocurrency, satoshi 
Requires PHP: 5.6
Requires at least: 4.0
Tested up to: 4.9.7
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept Bitcoins at your Wordpress Woocoerce store by simply installing an setting your bitcoin address.


Example store: http://anypaymugs.com

How does it work?

* The Buyer prepares the order and click on the "proceed to Anypay" button.
* The Buyer is directed to anypayapp.com/invoice/{invoice-id}
* The Buyer scans the invocie QR code and pays the invoice.
* The Buyer is redirected to the stores order page and the order is updated with payment complete status

This plugin is powered by Anypay Inc. payments API


== Installation ==

This Plugin requires Woocommerce. Please make sure you have Woocommerce installed.

Installation via WordPress Plugin Manager:

Go to WordPress Admin panel > Plugins > Add New in the admin panel.
Enter "Anypay Payment Gateway" in the search box.
Click Install Now.
Enter your bitcoin address or handcash handle to Anypay Gateway Settings: Admin > WooCommerce > Settings > Checkout tab > Anypay Gateway.
An Anypay access token will be created for the store and saved in the plugin options 

== 3rd party service ==

This plugin relies on a third-party service from Anypay Inc. The plugin uses Anypay to generate BitcoinSV invoices for the customer to pay.  
Once the customer pays then Anypay will send a webhook to indicate a payment was made and will forward the Bitcoins to the owners wallet.

External endpoints used:

- https://i1.wp.com/anypayinc.com/wp-content/uploads/2019/02/anypayMark_256.png, Anypay logo png
- POST https://api.anypay.global/anonymous-accounts, creates an anonymous Anypay account 
- PUT https://api.anypay.global/addresses/BSV, set Anypay BSV payout address
- PUT https://api.anypay.global/account, updates Anypay account attributes
- GET https://api.anypay.global/convert/{$value}-{$currency}/to-BSV, Converts order total to BSV equivalent 
- POST https://api.anypay.global/invoices, creates Anypay Invoice
- https://pos.anypay.global/invoices/{uid}, Displays invoice to be paid 

Link to the service website: https://anypayinc.com
Link to the services’ a terms of use and/or privacy policies: https://anypayinc.com/woo-terms/ 
