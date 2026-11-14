=== Corporate Book - PDF Corporate Book, 3D Corporate Book, PDF embed, PDF viewer ===
Contributors: aditya
Tags: corporate book, 3d book, pdf viewer, pdf embed, pdf
Requires at least: 4.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turn any PDF into a realistic 3D Corporate Book, 2D Corporate Book, PDF viewer, PDF embed or PDF slider.

== Description ==

Corporate Book creates a PDF Corporate Book, 3D Corporate Book, PDF viewer and PDF embed for WordPress sites. Provide the PDF link, and Corporate Book transforms your PDF into an immersive 3D Corporate Book.

**Engage with Dynamic 3D Corporate Books!** Transform your flat PDFs into captivating and immersive 3D Corporate Books. Say goodbye to static documents and bring your PDFs to life!

### Viewer Modes available in Corporate Book
- **3D Corporate Book** - Your PDFs and images become interactive, lifelike books, powered by WebGL.
- **2D Corporate Book** - A classic flippable book built with CSS and HTML5. Faster on low-end devices.
- **PDF embed and PDF Viewer** - A vertical reader that looks like a browser's embedded PDF viewer. Good for documents with irregular layouts.
- **PDF Slider** - Shows PDF pages as slides.

### File Formats Supported by Corporate Book
- **PDF / PDF Corporate Book** - Built on PDF.js. All it takes is a link to the PDF file.
- **Image / Image Corporate Book** - Turn a collection of images into a flippable album or portfolio.

### How easy is Corporate Book? Just 5 Steps!

1. Install the Corporate Book plugin.
2. Create a new Corporate Book post.
3. Provide a link to your PDF file and save the post.
4. Copy the generated shortcode, for example `[corporate_book id="123"][/corporate_book]`, and paste it into a page or post.
5. Publish the page or post, and your interactive 3D Corporate Book is ready to flip!

### Corporate Book Feature List
* **Realistic 3D Corporate Book** powered by WebGL.
* **Reliable 2D Corporate Book** for low-end devices.
* WordPress post structure for creating books, and a shortcode to display them.
* Supports both **PDF and image formats**.
* Embedded inline Corporate Book as the default display.
* Popup (lightbox) display with `type="thumb"`, ideal for many books on one page.
* Table of contents for PDF documents, plus a custom table of contents creator.
* **Slider Mode** shows PDF pages as slides.
* Page thumbnails preview.
* **Deep linking** to specific pages via the share button.
* Categories for organizing Corporate Books, and a shortcode to show a whole category.
* **RTL mode** for right-to-left languages.
* Supports more than 1000 pages and files over 500MB.
* Partial loading (on-demand pages): large files start with a small amount of data.

### TOP FEATURES
All of these are set for every book at once in **Corporate Book > Global Settings**.

* **Global Settings** - Control display mode, size, background, flip duration, page mode, sound and autoplay for all Corporate Books at once.
* **PDF Links** - Links inside your PDFs are clickable.
* **PDF Links Target** - Open PDF links in the same tab or a new tab.
* **Controls Customization** - Choose which controls to show or hide.
* **Search Control** - Add a search button to the toolbar.
* **Paper Flexibility** - Set how stiff the 3D pages are.
* **Text Translation** - Translate the text of the controls and buttons, or use your translation plugin.
* **Google Analytics** - Track when readers open Corporate Books.
* **Stage Padding** - Set the padding between the 3D Corporate Book and its container.
* **Zoom on Mouse Scroll** - Turn mouse-wheel zoom on or off.
* **Zoom Ratio** - Choose how much each zoom step zooms.
* **Share Prefix** - Change the share-link prefix. The default is `corporate-book-`.
* **PDF Attachment Page** - PDF media attachment pages show the PDF as a Corporate Book.
* **PDF Partial Loading Size** - Tune how much of the PDF each request loads.
* **Spiral 3D Corporate Book** - Show a spiral binding on 3D books.
* **3D Hybrid Corporate Book** - 3D on computers and the lighter 2D book on phones, chosen automatically.
* **Calendar Mode** - 3D books bound at the top that flip upward, like a wall calendar.
* **3D Tilt and Rotation** - Lean the 3D book back or to the side, and rotate PDF pages.
* **Book Shelf** - `[corporate_book books="all" shelf="true"]` shows your books on a shelf.
* **Trigger Lightbox and Link Lightbox** - Open a book in a popup from a button, a text link or your own HTML: `type="button"`, `type="link"` or `type="custom"`.
* **Corporate Book Post URLs** - Give every book its own page at `/corporate-book/book-name/`.
* **Auto Generate Thumb** - The book editor makes the thumbnail from the first page of the PDF.

### Print copies (offline)
Readers can buy printed books as well as the digital subscription.

**Set up**

1. **Global Settings > Print:** keep **Sell Print Copies** on, check **Shipping by Distance** (one band per line, `Up to km from Noida | Extra per book`), and optionally set **Free Shipping Above**, **Cash on Delivery** and **Order Email**.
2. **Each book > Book tab > Print copy:** turn on **Sell Print Copy**, enter the **Print Price**, the **Shipping Charge** for one copy near Noida, and **Copies in Stock** (empty = print on demand).
3. Razorpay keys from the Subscription tab are used for print orders too.

**How readers buy**

* The "preview has ended" panel shows **Buy print copy**; the Subscription page lists every book for sale under **Print copies**; a floating **Cart (n)** link follows them around the site.
* The **Cart** page (created automatically, `[corporate_book_cart]`) has quantities (1-10, within stock), the delivery address (name, 10-digit mobile, email, house and building, street, landmark, city, state, 6-digit PIN), a live shipping quote and payment by Razorpay or cash on delivery. Guests sign in or sign up first; their cart is kept.
* Shipping per copy = the book's shipping charge + the extra for the distance band. The distance is the straight-line distance from Noida to the region of the PIN code (accurate to the region, not to the kilometre). Army post PIN codes use the farthest band.
* **My account** shows **My print orders** (status, courier and tracking) and the saved **Delivery address**.

**How you fulfil orders**

* **Corporate Books > Print Orders** lists orders with customer, books, total, payment and status. Open an order to see the address and phone, and set the status (Paid / Cash on delivery, Shipped, Delivered, Cancelled, Refunded), courier, tracking number and link.
* Customers are emailed when an order is placed, shipped (with tracking), delivered, cancelled or refunded; you get an email for each new order.
* Stock goes down when an order is paid or placed with cash on delivery, and back up when it is cancelled or refunded. Unpaid online orders are removed after two days.
* With the Razorpay webhook set up, a payment counts even if the reader closes the page, and a refund in Razorpay marks the order Refunded.
* Printed books are GST-exempt in India (HSN 4901), so no tax lines are added; prices are final.

### Realistic book and reading desk
* **Realistic Book** (Global Settings > General, on by default): hard 3D cover, visible page thickness, soft curling paper, a slight tilt and a slower page turn. The cover opens by itself when the book comes into view, and the top page lifts when the mouse nears its outer bottom corner. 3D mode only; readers who ask for reduced motion skip the opening animation.
* **Reading Desk** (on by default): embedded books lie on a wooden desk with a desk lamp, a steaming cup of coffee, a pen and reading glasses. A lamp button in the corner switches the light on and off; the choice is remembered in the reader's browser. On phones only the lamp is shown.

### Library page
Add `[corporate_book_library]` to a page to show every book as a cover card (cover, title, Read Magazine button), grouped by year, with year buttons and a filter list of years and categories. The same cover card shows in post lists.

### Subscription
Lock Corporate Books behind a subscription. Set it up in **Corporate Book > Global Settings > Subscription**:

1. Install a social login plugin (for example Nextend Social Login) so readers can sign in with Google or LinkedIn, and paste its shortcode in **Social Login Shortcode**.
2. Set **Subscription Lock** to True and paste your **Subscribe Page Link**.
3. In **Subscriber Roles**, list the roles your membership plugin gives paying members, for example `cb_member`. Do not use `subscriber`: WordPress gives it to every new account.
4. Open each book and click **Update**. The free preview (the first 10% of pages by default) and the thumbnail are made automatically when you pick a PDF or open a book that has none.

How it works:

* Everyone, signed in or not, sees the thumbnail, cover, title and the free preview. A Preview button sits under each thumbnail.
* Subscribers read every page. Everyone else reads the free preview, then sees a "Your free preview has ended" panel with Subscribe and Sign in buttons.
* Saving a locked book moves its full PDF into a protected folder. Only readers with access can load it.
* Each book can override the global setting with **Required** or **Free**. Authors and editors always see the full book.
* Pages with locked books are not cached, so one reader's view is never shown to another.
* Image books: the lock limits the pages shown, but the image files stay public in the Media Library. Use a PDF book for paid content.

### Accounts and Razorpay payments
Readers sign in, create an account and sign out inside the book. To sell subscriptions without another plugin:

1. In **Global Settings > Subscription**, paste your **Razorpay Key ID** and **Razorpay Key Secret**. Use Test Mode keys first.
2. Set **Subscription Plans**, one per line: `Name | Price | Days of access`, for example `Monthly | 399 | 30`.
3. Readers pick a plan in the "Your free preview has ended" panel and pay with Razorpay Checkout. Access runs for the plan's days; they pay again to renew.

The plugin creates a **Subscription** page for you (`[corporate_book_subscription]`): one card per plan with its price and a Subscribe button, plus sign in, sign up and sign out. Add `[corporate_book_account]` to any page for a box with sign in, plans and sign out. Administrators see each reader's subscription in the Users list and can change the "Subscription active until" date on the reader's profile.

Readers never see WordPress itself. The plugin creates a **My account** page (`[corporate_book_profile]`) where readers edit their name, email, phone and company, change or reset their password, see their subscription and payment history, and subscribe or renew. New readers get the Corporate Book Reader role. Readers get no WordPress toolbar, and opening the dashboard or the WordPress sign-up, lost-password or reset screens takes them to My account. Staff who can write posts keep the normal WordPress screens.

**Razorpay webhook (recommended):** in Razorpay Dashboard > Webhooks, add the URL shown under Global Settings > Subscription > Razorpay Webhook Secret, with the events payment.captured and refund.processed, and paste the same secret in that field. Payments then count even if the reader closes the tab, and refunds take the paid days back. Use **Test Razorpay connection** to check your keys.

Readers get an emailed receipt after each payment and a reminder 3 days before access ends.

**Continue with Google:** paste a Google OAuth Client ID in Global Settings > Subscription > Google Client ID. Readers who use it are signed in, or get a reader account the first time. The sign-in box then loads Google's script from accounts.google.com and the site checks the sign-in with oauth2.googleapis.com. See Google's terms and privacy policy.

Razorpay Checkout loads from checkout.razorpay.com when a reader picks a plan, and the plugin calls api.razorpay.com to create and confirm payments. See Razorpay's terms and privacy policy.

On nginx, add this rule so the protected folder cannot be opened directly (Apache uses the included .htaccess):

`location ^~ /wp-content/uploads/corporate-book-protected/ { deny all; }`

Developers can change who counts as subscribed with the `corporate_book_user_is_subscribed` filter ( `$subscribed`, `$user`, `$book_id` ).

== Installation ==

1. Upload the `3d-corporate-book` folder to `/wp-content/plugins/`, or upload the zip in **Plugins > Add New**.
2. Activate **Corporate Book**.
3. Go to **Corporate Book > Add New**.

== Changelog ==

= 1.0.0 =
* First release of Corporate Book.
* Subscription lock: sign-in gate, free preview, Subscribe link and protected PDFs.
